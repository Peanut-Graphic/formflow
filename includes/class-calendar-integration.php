<?php
namespace ISF;

if (!defined('ABSPATH')) {
    exit;
}


/**
 * Calendar Integration Handler
 *
 * Creates calendar events for appointments (Google Calendar, Outlook, iCal).
 */


class CalendarIntegration {

    /**
     * Supported calendar providers
     */
    private const PROVIDERS = ['google', 'outlook', 'ical'];

    /**
     * Database instance
     */
    private Database\Database $db;

    /**
     * Encryption instance
     */
    private Encryption $encryption;

    /**
     * Constructor
     */
    public function __construct() {
        $this->db = new Database\Database();
        $this->encryption = new Encryption();
    }

    /**
     * Create calendar event for appointment
     */
    public function create_event(array $instance, array $submission): array {
        if (!FeatureManager::is_enabled($instance, 'calendar_integration')) {
            return ['success' => false, 'message' => 'Calendar integration not enabled'];
        }

        $config = FeatureManager::get_feature($instance, 'calendar_integration');
        $provider = $config['provider'] ?? 'google';

        if (empty($config['create_events'])) {
            return ['success' => false, 'message' => 'Event creation disabled'];
        }

        $form_data = $submission['form_data'] ?? [];

        // Need schedule data
        if (empty($form_data['schedule_date'])) {
            return ['success' => false, 'message' => 'No appointment date'];
        }

        try {
            $event_data = $this->build_event_data($instance, $submission, $config);

            switch ($provider) {
                case 'google':
                    return $this->create_google_event($config, $event_data, $instance, $submission);
                case 'outlook':
                    return $this->create_outlook_event($config, $event_data, $instance, $submission);
                case 'ical':
                    return $this->generate_ical($config, $event_data, $instance, $submission);
                default:
                    return ['success' => false, 'message' => 'Unknown calendar provider'];
            }
        } catch (\Throwable $e) {
            $this->db->log('error', 'Calendar event creation failed', [
                'provider' => $provider,
                'error' => $e->getMessage(),
            ], $instance['id'], $submission['id'] ?? null);

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Build event data from submission
     */
    private function build_event_data(array $instance, array $submission, array $config): array {
        $form_data = $submission['form_data'] ?? [];
        $content = $instance['settings']['content'] ?? [];

        $customer_name = $submission['customer_name'] ?? trim(($form_data['first_name'] ?? '') . ' ' . ($form_data['last_name'] ?? ''));
        $program_name = $content['program_name'] ?? 'EnergyWise Rewards';

        // Parse title template
        $title = $config['event_title_template'] ?? '{program_name} - {customer_name}';
        $title = str_replace(['{program_name}', '{customer_name}', '{device_type}'], [
            $program_name,
            $customer_name,
            ucfirst($submission['device_type'] ?? 'thermostat'),
        ], $title);

        // Parse description template
        $description = $config['event_description_template'] ?? '';
        if (empty($description)) {
            $description = $this->get_default_description($form_data, $instance);
        } else {
            $description = $this->parse_template($description, $form_data, $instance);
        }

        // Calculate times from time slot
        $date = $form_data['schedule_date'];
        $time_slot = $form_data['schedule_time'] ?? 'AM';
        $times = $this->get_slot_times($time_slot);

        $start = "{$date}T{$times['start']}:00";
        $end = "{$date}T{$times['end']}:00";

        // Get timezone
        $timezone = wp_timezone_string();

        // Build location
        $location = trim(implode(', ', array_filter([
            $form_data['address'] ?? '',
            $form_data['city'] ?? '',
            ($form_data['state'] ?? '') . ' ' . ($form_data['zip'] ?? ''),
        ])));

        return [
            'title' => $title,
            'description' => $description,
            'start' => $start,
            'end' => $end,
            'timezone' => $timezone,
            'location' => $location,
            'attendees' => $this->get_attendees($form_data, $config),
        ];
    }

    /**
     * Get time slot start/end times
     */
    private function get_slot_times(string $slot): array {
        $slots = [
            'AM' => ['start' => '08:00', 'end' => '11:00'],
            'MD' => ['start' => '11:00', 'end' => '14:00'],
            'PM' => ['start' => '14:00', 'end' => '17:00'],
            'EV' => ['start' => '17:00', 'end' => '20:00'],
        ];

        return $slots[strtoupper($slot)] ?? $slots['AM'];
    }

    /**
     * Get default event description
     */
    private function get_default_description(array $form_data, array $instance): string {
        $content = $instance['settings']['content'] ?? [];
        $program_name = $content['program_name'] ?? 'EnergyWise Rewards';

        $lines = [
            "{$program_name} Installation Appointment",
            '',
            'Customer: ' . trim(($form_data['first_name'] ?? '') . ' ' . ($form_data['last_name'] ?? '')),
            'Phone: ' . ($form_data['phone'] ?? 'N/A'),
            'Email: ' . ($form_data['email'] ?? 'N/A'),
            '',
            'Device: ' . ucfirst($form_data['device_type'] ?? 'thermostat'),
        ];

        if (!empty($form_data['confirmation_number'])) {
            $lines[] = 'Confirmation: ' . $form_data['confirmation_number'];
        }

        return implode("\n", $lines);
    }

    /**
     * Parse template string with form data
     */
    private function parse_template(string $template, array $form_data, array $instance): string {
        $content = $instance['settings']['content'] ?? [];

        return preg_replace_callback('/\{(\w+)\}/', function($matches) use ($form_data, $content) {
            $key = $matches[1];
            return $form_data[$key] ?? $content[$key] ?? '';
        }, $template);
    }

    /**
     * Get attendee list
     */
    private function get_attendees(array $form_data, array $config): array {
        $attendees = [];

        if (!empty($config['send_invites']) && !empty($form_data['email'])) {
            $attendees[] = [
                'email' => $form_data['email'],
                'name' => trim(($form_data['first_name'] ?? '') . ' ' . ($form_data['last_name'] ?? '')),
            ];
        }

        return $attendees;
    }

    /**
     * Create Google Calendar event
     */
    private function create_google_event(array $config, array $event_data, array $instance, array $submission): array {
        $access_token = $this->get_google_token($config);

        if (!$access_token) {
            return ['success' => false, 'message' => 'Failed to authenticate with Google'];
        }

        $calendar_id = $config['calendar_id'] ?? 'primary';

        $google_event = [
            'summary' => $event_data['title'],
            'description' => $event_data['description'],
            'location' => $event_data['location'],
            'start' => [
                'dateTime' => $event_data['start'],
                'timeZone' => $event_data['timezone'],
            ],
            'end' => [
                'dateTime' => $event_data['end'],
                'timeZone' => $event_data['timezone'],
            ],
            'reminders' => [
                'useDefault' => false,
                'overrides' => [
                    ['method' => 'email', 'minutes' => 24 * 60],
                    ['method' => 'popup', 'minutes' => 60],
                ],
            ],
        ];

        // Add attendees if configured
        if (!empty($event_data['attendees'])) {
            $google_event['attendees'] = array_map(fn($a) => ['email' => $a['email']], $event_data['attendees']);
            $google_event['guestsCanModify'] = false;
            $google_event['guestsCanInviteOthers'] = false;
        }

        $endpoint = "https://www.googleapis.com/calendar/v3/calendars/" . urlencode($calendar_id) . "/events";

        if (!empty($event_data['attendees'])) {
            $endpoint .= '?sendUpdates=all';
        }

        $response = wp_remote_post($endpoint, [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode($google_event),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            throw new \Exception($response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code === 200 && !empty($body['id'])) {
            $this->store_calendar_reference($submission['id'], 'google', $body['id'], $body['htmlLink'] ?? '');

            $this->db->log('info', 'Google Calendar event created', [
                'event_id' => $body['id'],
            ], $instance['id'], $submission['id']);

            return [
                'success' => true,
                'event_id' => $body['id'],
                'event_link' => $body['htmlLink'] ?? '',
                'provider' => 'google',
            ];
        }

        $error = $body['error']['message'] ?? 'Unknown Google Calendar error';
        throw new \Exception($error);
    }

    /**
     * Get Google OAuth token
     */
    private function get_google_token(array $config): ?string {
        $credentials = $config['api_credentials'] ?? '';

        if (empty($credentials)) {
            return null;
        }

        $creds = json_decode($this->encryption->decrypt($credentials), true);

        if (empty($creds['refresh_token'])) {
            return null;
        }

        $cached = get_transient('isf_google_cal_token');
        if ($cached) {
            return $cached;
        }

        $response = wp_remote_post('https://oauth2.googleapis.com/token', [
            'body' => [
                'client_id' => $creds['client_id'] ?? '',
                'client_secret' => $creds['client_secret'] ?? '',
                'refresh_token' => $creds['refresh_token'],
                'grant_type' => 'refresh_token',
            ],
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!empty($body['access_token'])) {
            $expires = ($body['expires_in'] ?? 3600) - 300;
            set_transient('isf_google_cal_token', $body['access_token'], $expires);
            return $body['access_token'];
        }

        return null;
    }

    /**
     * Create Outlook Calendar event
     */
    private function create_outlook_event(array $config, array $event_data, array $instance, array $submission): array {
        $access_token = $this->get_outlook_token($config);

        if (!$access_token) {
            return ['success' => false, 'message' => 'Failed to authenticate with Outlook'];
        }

        $outlook_event = [
            'subject' => $event_data['title'],
            'body' => [
                'contentType' => 'Text',
                'content' => $event_data['description'],
            ],
            'start' => [
                'dateTime' => $event_data['start'],
                'timeZone' => $event_data['timezone'],
            ],
            'end' => [
                'dateTime' => $event_data['end'],
                'timeZone' => $event_data['timezone'],
            ],
            'location' => [
                'displayName' => $event_data['location'],
            ],
            'reminderMinutesBeforeStart' => 60,
        ];

        // Add attendees
        if (!empty($event_data['attendees'])) {
            $outlook_event['attendees'] = array_map(fn($a) => [
                'emailAddress' => ['address' => $a['email'], 'name' => $a['name'] ?? ''],
                'type' => 'required',
            ], $event_data['attendees']);
        }

        $calendar_id = $config['calendar_id'] ?? '';
        $endpoint = $calendar_id
            ? "https://graph.microsoft.com/v1.0/me/calendars/{$calendar_id}/events"
            : 'https://graph.microsoft.com/v1.0/me/events';

        $response = wp_remote_post($endpoint, [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode($outlook_event),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            throw new \Exception($response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code === 201 && !empty($body['id'])) {
            $this->store_calendar_reference($submission['id'], 'outlook', $body['id'], $body['webLink'] ?? '');

            $this->db->log('info', 'Outlook Calendar event created', [
                'event_id' => $body['id'],
            ], $instance['id'], $submission['id']);

            return [
                'success' => true,
                'event_id' => $body['id'],
                'event_link' => $body['webLink'] ?? '',
                'provider' => 'outlook',
            ];
        }

        $error = $body['error']['message'] ?? 'Unknown Outlook error';
        throw new \Exception($error);
    }

    /**
     * Get Outlook OAuth token
     */
    private function get_outlook_token(array $config): ?string {
        $credentials = $config['api_credentials'] ?? '';

        if (empty($credentials)) {
            return null;
        }

        $creds = json_decode($this->encryption->decrypt($credentials), true);

        if (empty($creds['refresh_token'])) {
            return null;
        }

        $cached = get_transient('isf_outlook_cal_token');
        if ($cached) {
            return $cached;
        }

        $response = wp_remote_post('https://login.microsoftonline.com/common/oauth2/v2.0/token', [
            'body' => [
                'client_id' => $creds['client_id'] ?? '',
                'client_secret' => $creds['client_secret'] ?? '',
                'refresh_token' => $creds['refresh_token'],
                'grant_type' => 'refresh_token',
                'scope' => 'https://graph.microsoft.com/Calendars.ReadWrite',
            ],
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!empty($body['access_token'])) {
            $expires = ($body['expires_in'] ?? 3600) - 300;
            set_transient('isf_outlook_cal_token', $body['access_token'], $expires);
            return $body['access_token'];
        }

        return null;
    }

    /**
     * Generate iCal file content
     */
    private function generate_ical(array $config, array $event_data, array $instance, array $submission): array {
        $uid = 'isf-' . $submission['id'] . '@' . parse_url(home_url(), PHP_URL_HOST);
        $now = gmdate('Ymd\THis\Z');

        $start = new \DateTime($event_data['start'], new \DateTimeZone($event_data['timezone']));
        $end = new \DateTime($event_data['end'], new \DateTimeZone($event_data['timezone']));

        $ical = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//FormFlow//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:' . $uid,
            'DTSTAMP:' . $now,
            'DTSTART:' . $start->format('Ymd\THis'),
            'DTEND:' . $end->format('Ymd\THis'),
            'SUMMARY:' . $this->escape_ical($event_data['title']),
            'DESCRIPTION:' . $this->escape_ical($event_data['description']),
            'LOCATION:' . $this->escape_ical($event_data['location']),
            'BEGIN:VALARM',
            'TRIGGER:-PT1H',
            'ACTION:DISPLAY',
            'DESCRIPTION:Reminder',
            'END:VALARM',
            'END:VEVENT',
            'END:VCALENDAR',
        ];

        $ical_content = implode("\r\n", $ical);

        // Store as attachment
        $upload_dir = wp_upload_dir();
        $ical_dir = $upload_dir['basedir'] . '/isf-ical';

        if (!file_exists($ical_dir)) {
            wp_mkdir_p($ical_dir);
            file_put_contents($ical_dir . '/.htaccess', "Options -Indexes\n");
        }

        $filename = 'appointment-' . $submission['id'] . '.ics';
        $filepath = $ical_dir . '/' . $filename;
        $fileurl = $upload_dir['baseurl'] . '/isf-ical/' . $filename;

        file_put_contents($filepath, $ical_content);

        $this->store_calendar_reference($submission['id'], 'ical', $filename, $fileurl);

        $this->db->log('info', 'iCal file generated', [
            'filename' => $filename,
        ], $instance['id'], $submission['id']);

        return [
            'success' => true,
            'ical_content' => $ical_content,
            'ical_url' => $fileurl,
            'provider' => 'ical',
        ];
    }

    /**
     * Escape string for iCal
     */
    private function escape_ical(string $str): string {
        $str = str_replace(['\\', ';', ',', "\n"], ['\\\\', '\\;', '\\,', '\\n'], $str);
        return $str;
    }

    /**
     * Store calendar reference in submission
     */
    private function store_calendar_reference(int $submission_id, string $provider, string $event_id, string $link = ''): void {
        $submission = $this->db->get_submission($submission_id);

        if ($submission) {
            $form_data = $submission['form_data'] ?? [];
            $form_data['calendar_event'] = [
                'provider' => $provider,
                'event_id' => $event_id,
                'link' => $link,
                'created_at' => current_time('mysql'),
            ];

            $this->db->update_submission($submission_id, ['form_data' => $form_data]);
        }
    }

    /**
     * Delete calendar event
     */
    public function delete_event(array $instance, array $submission): bool {
        $form_data = $submission['form_data'] ?? [];
        $calendar_event = $form_data['calendar_event'] ?? null;

        if (!$calendar_event) {
            return false;
        }

        $config = FeatureManager::get_feature($instance, 'calendar_integration');
        $provider = $calendar_event['provider'];
        $event_id = $calendar_event['event_id'];

        try {
            switch ($provider) {
                case 'google':
                    return $this->delete_google_event($config, $event_id);
                case 'outlook':
                    return $this->delete_outlook_event($config, $event_id);
                case 'ical':
                    // Just remove local file
                    $upload_dir = wp_upload_dir();
                    @unlink($upload_dir['basedir'] . '/isf-ical/' . $event_id);
                    return true;
            }
        } catch (\Throwable $e) {
            $this->db->log('error', 'Failed to delete calendar event', [
                'provider' => $provider,
                'error' => $e->getMessage(),
            ], $instance['id'], $submission['id']);
        }

        return false;
    }

    /**
     * Delete Google Calendar event
     */
    private function delete_google_event(array $config, string $event_id): bool {
        $access_token = $this->get_google_token($config);
        if (!$access_token) {
            return false;
        }

        $calendar_id = $config['calendar_id'] ?? 'primary';
        $endpoint = "https://www.googleapis.com/calendar/v3/calendars/" . urlencode($calendar_id) . "/events/" . urlencode($event_id);

        $response = wp_remote_request($endpoint, [
            'method' => 'DELETE',
            'headers' => ['Authorization' => 'Bearer ' . $access_token],
            'timeout' => 30,
        ]);

        return wp_remote_retrieve_response_code($response) === 204;
    }

    /**
     * Delete Outlook Calendar event
     */
    private function delete_outlook_event(array $config, string $event_id): bool {
        $access_token = $this->get_outlook_token($config);
        if (!$access_token) {
            return false;
        }

        $endpoint = "https://graph.microsoft.com/v1.0/me/events/{$event_id}";

        $response = wp_remote_request($endpoint, [
            'method' => 'DELETE',
            'headers' => ['Authorization' => 'Bearer ' . $access_token],
            'timeout' => 30,
        ]);

        return wp_remote_retrieve_response_code($response) === 204;
    }

    /**
     * Generate "Add to Calendar" links for confirmation page
     */
    public static function get_add_to_calendar_links(array $event_data): array {
        $start = new \DateTime($event_data['start']);
        $end = new \DateTime($event_data['end']);

        $title = urlencode($event_data['title']);
        $description = urlencode($event_data['description'] ?? '');
        $location = urlencode($event_data['location'] ?? '');

        return [
            'google' => sprintf(
                'https://www.google.com/calendar/render?action=TEMPLATE&text=%s&dates=%s/%s&details=%s&location=%s',
                $title,
                $start->format('Ymd\THis\Z'),
                $end->format('Ymd\THis\Z'),
                $description,
                $location
            ),
            'outlook' => sprintf(
                'https://outlook.live.com/calendar/0/action/compose?subject=%s&startdt=%s&enddt=%s&body=%s&location=%s',
                $title,
                $start->format('Y-m-d\TH:i:s'),
                $end->format('Y-m-d\TH:i:s'),
                $description,
                $location
            ),
            'yahoo' => sprintf(
                'https://calendar.yahoo.com/?v=60&title=%s&st=%s&et=%s&desc=%s&in_loc=%s',
                $title,
                $start->format('Ymd\THis'),
                $end->format('Ymd\THis'),
                $description,
                $location
            ),
        ];
    }

    /**
     * Get available providers
     */
    public static function get_providers(): array {
        return [
            'google' => [
                'name' => 'Google Calendar',
                'requires_oauth' => true,
            ],
            'outlook' => [
                'name' => 'Microsoft Outlook',
                'requires_oauth' => true,
            ],
            'ical' => [
                'name' => 'iCal File (Download)',
                'requires_oauth' => false,
            ],
        ];
    }
}
