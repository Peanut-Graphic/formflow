<?php
namespace ISF;

if (!defined('ABSPATH')) {
    exit;
}


/**
 * Chatbot Assistant
 *
 * Provides AI-powered help during enrollment process.
 */


class ChatbotAssistant {

    /**
     * Supported providers
     */
    private const PROVIDERS = ['custom', 'openai', 'dialogflow'];

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
     * Process a chat message
     */
    public function process_message(array $instance, string $message, array $context = []): array {
        if (!FeatureManager::is_enabled($instance, 'chatbot_assistant')) {
            return ['success' => false, 'message' => 'Chatbot not enabled'];
        }

        $config = FeatureManager::get_feature($instance, 'chatbot_assistant');
        $provider = $config['provider'] ?? 'custom';

        // Log chat interaction
        $this->log_interaction($instance, $message, $context);

        try {
            switch ($provider) {
                case 'openai':
                    return $this->process_openai($config, $message, $context, $instance);
                case 'dialogflow':
                    return $this->process_dialogflow($config, $message, $context, $instance);
                case 'custom':
                default:
                    return $this->process_custom($config, $message, $context, $instance);
            }
        } catch (\Throwable $e) {
            $this->db->log('error', 'Chatbot error', [
                'provider' => $provider,
                'error' => $e->getMessage(),
            ], $instance['id']);

            return [
                'success' => false,
                'response' => __('Sorry, I encountered an error. Please try again or contact support.', 'formflow'),
            ];
        }
    }

    /**
     * Process with custom knowledge base (rule-based)
     */
    private function process_custom(array $config, string $message, array $context, array $instance): array {
        $knowledge_base = $config['knowledge_base'] ?? $this->get_default_knowledge_base($instance);
        $message_lower = strtolower($message);
        $current_step = $context['current_step'] ?? 1;

        // Check for exact matches first
        foreach ($knowledge_base as $item) {
            $triggers = $item['triggers'] ?? [];
            foreach ($triggers as $trigger) {
                if (strpos($message_lower, strtolower($trigger)) !== false) {
                    // Check if answer is step-specific
                    if (!empty($item['steps']) && !in_array($current_step, $item['steps'])) {
                        continue;
                    }
                    return [
                        'success' => true,
                        'response' => $item['response'],
                        'suggestions' => $item['suggestions'] ?? [],
                        'action' => $item['action'] ?? null,
                    ];
                }
            }
        }

        // Check for keyword matches
        $best_match = null;
        $best_score = 0;

        foreach ($knowledge_base as $item) {
            $keywords = $item['keywords'] ?? [];
            $score = 0;

            foreach ($keywords as $keyword) {
                if (strpos($message_lower, strtolower($keyword)) !== false) {
                    $score++;
                }
            }

            if ($score > $best_score) {
                $best_score = $score;
                $best_match = $item;
            }
        }

        if ($best_match && $best_score > 0) {
            return [
                'success' => true,
                'response' => $best_match['response'],
                'suggestions' => $best_match['suggestions'] ?? [],
            ];
        }

        // Default response
        return [
            'success' => true,
            'response' => $this->get_fallback_response($config, $context),
            'suggestions' => $this->get_step_suggestions($current_step, $instance),
        ];
    }

    /**
     * Process with OpenAI
     */
    private function process_openai(array $config, string $message, array $context, array $instance): array {
        $api_key = $this->encryption->decrypt($config['api_key'] ?? '');

        if (empty($api_key)) {
            return $this->process_custom($config, $message, $context, $instance);
        }

        $system_prompt = $this->build_system_prompt($config, $context, $instance);
        $model = $config['model'] ?? 'gpt-3.5-turbo';

        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode([
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $system_prompt],
                    ['role' => 'user', 'content' => $message],
                ],
                'max_tokens' => 300,
                'temperature' => 0.7,
            ]),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            throw new \Exception($response->get_error_message());
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!empty($body['choices'][0]['message']['content'])) {
            return [
                'success' => true,
                'response' => $body['choices'][0]['message']['content'],
                'provider' => 'openai',
            ];
        }

        throw new \Exception($body['error']['message'] ?? 'OpenAI error');
    }

    /**
     * Process with Dialogflow
     */
    private function process_dialogflow(array $config, string $message, array $context, array $instance): array {
        $project_id = $config['project_id'] ?? '';
        $session_id = $context['session_id'] ?? wp_generate_uuid4();

        $access_token = $this->get_dialogflow_token($config);

        if (!$access_token) {
            return $this->process_custom($config, $message, $context, $instance);
        }

        $endpoint = "https://dialogflow.googleapis.com/v2/projects/{$project_id}/agent/sessions/{$session_id}:detectIntent";

        $response = wp_remote_post($endpoint, [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode([
                'queryInput' => [
                    'text' => [
                        'text' => $message,
                        'languageCode' => $context['language'] ?? 'en',
                    ],
                ],
                'queryParams' => [
                    'contexts' => $this->build_dialogflow_context($context),
                ],
            ]),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            throw new \Exception($response->get_error_message());
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!empty($body['queryResult']['fulfillmentText'])) {
            return [
                'success' => true,
                'response' => $body['queryResult']['fulfillmentText'],
                'intent' => $body['queryResult']['intent']['displayName'] ?? '',
                'provider' => 'dialogflow',
            ];
        }

        return $this->process_custom($config, $message, $context, $instance);
    }

    /**
     * Get Dialogflow access token
     */
    private function get_dialogflow_token(array $config): ?string {
        $credentials = $config['api_credentials'] ?? '';

        if (empty($credentials)) {
            return null;
        }

        $creds = json_decode($this->encryption->decrypt($credentials), true);

        if (empty($creds['private_key'])) {
            return null;
        }

        $cached = get_transient('isf_dialogflow_token');
        if ($cached) {
            return $cached;
        }

        // Create JWT
        $header = base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $now = time();
        $claims = base64_encode(json_encode([
            'iss' => $creds['client_email'],
            'scope' => 'https://www.googleapis.com/auth/dialogflow',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ]));

        $signature = '';
        openssl_sign("$header.$claims", $signature, $creds['private_key'], 'SHA256');
        $signature = base64_encode($signature);

        $jwt = "$header.$claims.$signature";

        $response = wp_remote_post('https://oauth2.googleapis.com/token', [
            'body' => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ],
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!empty($body['access_token'])) {
            set_transient('isf_dialogflow_token', $body['access_token'], 3000);
            return $body['access_token'];
        }

        return null;
    }

    /**
     * Build system prompt for AI
     */
    private function build_system_prompt(array $config, array $context, array $instance): string {
        $content = $instance['settings']['content'] ?? [];
        $program_name = $content['program_name'] ?? 'EnergyWise Rewards';
        $bot_name = $config['bot_name'] ?? 'EnergyWise Assistant';
        $current_step = $context['current_step'] ?? 1;

        $step_info = $this->get_step_info($current_step, $instance);

        return <<<PROMPT
You are {$bot_name}, a helpful assistant for the {$program_name} enrollment form.

Current context:
- The user is on Step {$current_step}: {$step_info['name']}
- Form type: Energy savings program enrollment
- Device type: {$context['device_type']}

Your role:
1. Help users understand the enrollment process
2. Answer questions about the program benefits
3. Clarify what information is needed at each step
4. Provide guidance on form fields
5. Never ask for sensitive information directly

Keep responses concise (2-3 sentences max). Be friendly and helpful.
If you don't know something specific about their account, direct them to customer support.

Program details:
- Free installation of smart thermostat or outdoor switch
- Helps manage energy during peak demand
- No cost to participate
- Customers can choose cycling level (50%, 75%, or 100%)
PROMPT;
    }

    /**
     * Build Dialogflow context
     */
    private function build_dialogflow_context(array $context): array {
        return [
            [
                'name' => 'enrollment-context',
                'lifespanCount' => 5,
                'parameters' => [
                    'current_step' => $context['current_step'] ?? 1,
                    'device_type' => $context['device_type'] ?? '',
                    'language' => $context['language'] ?? 'en',
                ],
            ],
        ];
    }

    /**
     * Get default knowledge base
     */
    private function get_default_knowledge_base(array $instance): array {
        $content = $instance['settings']['content'] ?? [];
        $program_name = $content['program_name'] ?? 'EnergyWise Rewards';
        $support_phone = $content['support_phone'] ?? '';

        return [
            // Program questions
            [
                'triggers' => ['what is this program', 'what is energywise', 'tell me about'],
                'keywords' => ['program', 'about', 'what'],
                'response' => "{$program_name} is a free energy savings program. We install a smart device that helps manage energy during peak demand times, reducing strain on the power grid and helping the environment.",
                'suggestions' => ['How does it work?', 'Is it really free?', 'What are the benefits?'],
            ],
            [
                'triggers' => ['how does it work', 'how it works'],
                'keywords' => ['how', 'work', 'function'],
                'response' => "During peak energy demand periods, your device may briefly cycle your AC or adjust your thermostat. Most customers don't notice any difference in comfort, and you can override at any time.",
            ],
            [
                'triggers' => ['is it free', 'cost', 'pay', 'charge'],
                'keywords' => ['free', 'cost', 'price', 'pay'],
                'response' => "Yes! The program is completely free. There's no cost for the device, installation, or participation. You may also receive incentives for participating.",
            ],
            [
                'triggers' => ['benefits', 'why should i', 'advantages'],
                'keywords' => ['benefit', 'advantage', 'why'],
                'response' => "Benefits include: free smart device, professional installation, potential bill credits, and you're helping reduce energy demand which benefits everyone. Plus, you maintain control and can override anytime.",
            ],

            // Account questions
            [
                'triggers' => ['account number', 'where is my account', 'find account'],
                'keywords' => ['account', 'number', 'find', 'where'],
                'response' => "Your account number is on your utility bill, usually near the top. It's typically 10-12 digits. If you can't find it, you can call customer service for help.",
                'steps' => [2],
            ],
            [
                'triggers' => ['zip code', 'wrong zip', 'zip doesn\'t match'],
                'keywords' => ['zip', 'code', 'postal'],
                'response' => "Please enter the ZIP code associated with your utility account - the service address where you receive your bill. This must match what's on file.",
                'steps' => [2],
            ],

            // Cycling level
            [
                'triggers' => ['cycling level', 'what level', '50%', '75%', '100%'],
                'keywords' => ['cycling', 'level', 'percent'],
                'response' => "Cycling level determines how often your device may be adjusted during peak times. 50% = minimal cycling, 100% = maximum participation (and usually higher incentives). Most customers choose 75%.",
                'steps' => [2],
            ],

            // Scheduling
            [
                'triggers' => ['appointment', 'schedule', 'installation', 'when'],
                'keywords' => ['appointment', 'schedule', 'install', 'when', 'time'],
                'response' => "After completing the form, you can schedule a convenient installation appointment. Our technicians work during daytime hours and will call before arriving.",
                'steps' => [4],
            ],
            [
                'triggers' => ['reschedule', 'change appointment', 'cancel'],
                'keywords' => ['reschedule', 'change', 'cancel', 'modify'],
                'response' => "You can reschedule or cancel your appointment using the link in your confirmation email, or by calling our support line.",
            ],

            // Technical
            [
                'triggers' => ['thermostat', 'what thermostat', 'which device'],
                'keywords' => ['thermostat', 'device', 'equipment'],
                'response' => "We install a smart thermostat that connects to your WiFi and can be controlled from your phone. It's compatible with most HVAC systems and comes with all necessary accessories.",
            ],
            [
                'triggers' => ['outdoor switch', 'dcu', 'cycling unit'],
                'keywords' => ['outdoor', 'switch', 'dcu', 'cycling'],
                'response' => "The outdoor cycling unit (DCU) is installed near your AC unit. It's a small device that can briefly cycle your air conditioner during peak demand. Installation takes about 30 minutes.",
            ],

            // Privacy/Security
            [
                'triggers' => ['privacy', 'data', 'information safe', 'secure'],
                'keywords' => ['privacy', 'data', 'secure', 'safe', 'information'],
                'response' => "Your information is secure. We only use your data to process your enrollment and schedule installation. We never sell your information to third parties.",
            ],

            // Help/Support
            [
                'triggers' => ['help', 'support', 'contact', 'phone', 'call'],
                'keywords' => ['help', 'support', 'contact', 'phone', 'call', 'speak'],
                'response' => $support_phone
                    ? "Need more help? Call our support team at {$support_phone}. We're happy to assist with any questions about your enrollment."
                    : "Need more help? Please contact your utility's customer service department for assistance with your enrollment.",
            ],
        ];
    }

    /**
     * Get step-specific suggestions
     */
    private function get_step_suggestions(int $step, array $instance): array {
        $suggestions = [
            1 => ['What devices are available?', 'How does the program work?', 'Is it really free?'],
            2 => ['Where is my account number?', 'What cycling level should I choose?', 'Why does ZIP need to match?'],
            3 => ['Why do you need my email?', 'Is my information secure?', 'Can I use a different address?'],
            4 => ['How long is installation?', 'Can I reschedule later?', 'What if I\'m not home?'],
            5 => ['What happens after I submit?', 'When will I be contacted?', 'How do I get my incentive?'],
        ];

        return $suggestions[$step] ?? ['How can I help you?'];
    }

    /**
     * Get step information
     */
    private function get_step_info(int $step, array $instance): array {
        $steps = [
            1 => ['name' => 'Program Selection', 'description' => 'Choose device type'],
            2 => ['name' => 'Account Validation', 'description' => 'Verify utility account'],
            3 => ['name' => 'Customer Information', 'description' => 'Contact and address details'],
            4 => ['name' => 'Scheduling', 'description' => 'Schedule installation appointment'],
            5 => ['name' => 'Confirmation', 'description' => 'Review and submit enrollment'],
        ];

        return $steps[$step] ?? ['name' => 'Unknown', 'description' => ''];
    }

    /**
     * Get fallback response
     */
    private function get_fallback_response(array $config, array $context): string {
        $responses = [
            "I'm not sure I understand. Could you rephrase your question?",
            "I'd be happy to help! Can you tell me more about what you need?",
            "Let me help you with that. What specific question do you have about the enrollment?",
        ];

        return $responses[array_rand($responses)];
    }

    /**
     * Log chat interaction
     */
    private function log_interaction(array $instance, string $message, array $context): void {
        global $wpdb;
        $table = $wpdb->prefix . 'isf_chat_logs';

        // Ensure table exists
        $this->ensure_chat_table();

        $wpdb->insert($table, [
            'instance_id' => $instance['id'],
            'session_id' => $context['session_id'] ?? '',
            'step' => $context['current_step'] ?? 0,
            'message' => substr($message, 0, 1000),
            'created_at' => current_time('mysql'),
        ]);
    }

    /**
     * Ensure chat logs table exists
     */
    private function ensure_chat_table(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'isf_chat_logs';

        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table) {
            return;
        }

        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id INT AUTO_INCREMENT PRIMARY KEY,
            instance_id INT NOT NULL,
            session_id VARCHAR(64),
            step INT,
            message TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_instance (instance_id),
            INDEX idx_session (session_id)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Render chatbot widget
     */
    public static function render_widget(array $instance): string {
        if (!FeatureManager::is_enabled($instance, 'chatbot_assistant')) {
            return '';
        }

        $config = FeatureManager::get_feature($instance, 'chatbot_assistant');
        $position = $config['position'] ?? 'bottom-right';
        $bot_name = $config['bot_name'] ?? 'EnergyWise Assistant';
        $welcome = $config['welcome_message'] ?? 'Hi! How can I help you today?';

        ob_start();
        ?>
        <div id="isf-chatbot" class="isf-chatbot isf-chatbot-<?php echo esc_attr($position); ?>">
            <button type="button" class="isf-chatbot-toggle" aria-label="<?php esc_attr_e('Open chat', 'formflow'); ?>">
                <span class="isf-chatbot-icon">💬</span>
                <span class="isf-chatbot-close">✕</span>
            </button>

            <div class="isf-chatbot-window">
                <div class="isf-chatbot-header">
                    <span class="isf-chatbot-name"><?php echo esc_html($bot_name); ?></span>
                    <span class="isf-chatbot-status"><?php esc_html_e('Online', 'formflow'); ?></span>
                </div>

                <div class="isf-chatbot-messages" id="isf-chatbot-messages">
                    <div class="isf-chatbot-message isf-chatbot-bot">
                        <div class="isf-chatbot-bubble"><?php echo esc_html($welcome); ?></div>
                    </div>
                </div>

                <div class="isf-chatbot-suggestions" id="isf-chatbot-suggestions">
                    <!-- Suggestions will be added dynamically -->
                </div>

                <form class="isf-chatbot-input" id="isf-chatbot-form">
                    <input type="text"
                           id="isf-chatbot-input"
                           placeholder="<?php esc_attr_e('Type your question...', 'formflow'); ?>"
                           autocomplete="off">
                    <button type="submit" aria-label="<?php esc_attr_e('Send', 'formflow'); ?>">
                        <span>➤</span>
                    </button>
                </form>
            </div>
        </div>

        <style>
        .isf-chatbot {
            position: fixed;
            z-index: 9998;
        }
        .isf-chatbot-bottom-right {
            bottom: 20px;
            right: 20px;
        }
        .isf-chatbot-bottom-left {
            bottom: 20px;
            left: 20px;
        }
        .isf-chatbot-toggle {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: #0073aa;
            border: none;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            display: flex;
            align-items: center;
            justify-content: center;
            transition: transform 0.2s, background 0.2s;
        }
        .isf-chatbot-toggle:hover {
            transform: scale(1.05);
            background: #005a87;
        }
        .isf-chatbot-icon,
        .isf-chatbot-close {
            font-size: 24px;
            color: #fff;
        }
        .isf-chatbot-close {
            display: none;
        }
        .isf-chatbot.open .isf-chatbot-icon {
            display: none;
        }
        .isf-chatbot.open .isf-chatbot-close {
            display: block;
        }
        .isf-chatbot-window {
            display: none;
            position: absolute;
            bottom: 70px;
            right: 0;
            width: 350px;
            max-width: calc(100vw - 40px);
            height: 450px;
            max-height: calc(100vh - 100px);
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 5px 40px rgba(0,0,0,0.16);
            flex-direction: column;
            overflow: hidden;
        }
        .isf-chatbot-bottom-left .isf-chatbot-window {
            right: auto;
            left: 0;
        }
        .isf-chatbot.open .isf-chatbot-window {
            display: flex;
            animation: slideIn 0.3s ease-out;
        }
        @keyframes slideIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .isf-chatbot-header {
            background: #0073aa;
            color: #fff;
            padding: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .isf-chatbot-name {
            font-weight: 600;
        }
        .isf-chatbot-status {
            font-size: 12px;
            opacity: 0.8;
        }
        .isf-chatbot-messages {
            flex: 1;
            overflow-y: auto;
            padding: 15px;
        }
        .isf-chatbot-message {
            margin-bottom: 12px;
            display: flex;
        }
        .isf-chatbot-bot {
            justify-content: flex-start;
        }
        .isf-chatbot-user {
            justify-content: flex-end;
        }
        .isf-chatbot-bubble {
            max-width: 80%;
            padding: 10px 14px;
            border-radius: 18px;
            line-height: 1.4;
            font-size: 14px;
        }
        .isf-chatbot-bot .isf-chatbot-bubble {
            background: #f0f0f0;
            border-bottom-left-radius: 4px;
        }
        .isf-chatbot-user .isf-chatbot-bubble {
            background: #0073aa;
            color: #fff;
            border-bottom-right-radius: 4px;
        }
        .isf-chatbot-suggestions {
            padding: 0 15px;
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }
        .isf-chatbot-suggestion {
            background: #f5f5f5;
            border: 1px solid #ddd;
            border-radius: 16px;
            padding: 6px 12px;
            font-size: 12px;
            cursor: pointer;
            transition: background 0.2s;
        }
        .isf-chatbot-suggestion:hover {
            background: #e8e8e8;
        }
        .isf-chatbot-input {
            display: flex;
            padding: 10px;
            border-top: 1px solid #eee;
            gap: 8px;
        }
        .isf-chatbot-input input {
            flex: 1;
            border: 1px solid #ddd;
            border-radius: 20px;
            padding: 10px 15px;
            font-size: 14px;
            outline: none;
        }
        .isf-chatbot-input input:focus {
            border-color: #0073aa;
        }
        .isf-chatbot-input button {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #0073aa;
            border: none;
            color: #fff;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .isf-chatbot-input button:hover {
            background: #005a87;
        }
        .isf-chatbot-typing {
            display: flex;
            gap: 4px;
            padding: 10px 14px;
        }
        .isf-chatbot-typing span {
            width: 8px;
            height: 8px;
            background: #999;
            border-radius: 50%;
            animation: typing 1.4s infinite ease-in-out;
        }
        .isf-chatbot-typing span:nth-child(2) { animation-delay: 0.2s; }
        .isf-chatbot-typing span:nth-child(3) { animation-delay: 0.4s; }
        @keyframes typing {
            0%, 80%, 100% { transform: scale(0.8); opacity: 0.5; }
            40% { transform: scale(1); opacity: 1; }
        }
        </style>

        <script>
        (function() {
            const chatbot = document.getElementById('isf-chatbot');
            const toggle = chatbot.querySelector('.isf-chatbot-toggle');
            const form = document.getElementById('isf-chatbot-form');
            const input = document.getElementById('isf-chatbot-input');
            const messages = document.getElementById('isf-chatbot-messages');
            const suggestions = document.getElementById('isf-chatbot-suggestions');

            // Toggle chat window
            toggle.addEventListener('click', function() {
                chatbot.classList.toggle('open');
                if (chatbot.classList.contains('open')) {
                    input.focus();
                }
            });

            // Send message
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                const message = input.value.trim();
                if (!message) return;

                addMessage(message, 'user');
                input.value = '';
                suggestions.innerHTML = '';
                showTyping();

                // Send to server
                fetch(isfAjax.url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        action: 'isf_chatbot',
                        nonce: isfAjax.nonce,
                        instance_id: '<?php echo esc_js($instance['id']); ?>',
                        message: message,
                        current_step: window.isfCurrentStep || 1,
                        session_id: window.isfSessionId || ''
                    })
                })
                .then(r => r.json())
                .then(data => {
                    hideTyping();
                    if (data.success && data.data.response) {
                        addMessage(data.data.response, 'bot');
                        if (data.data.suggestions) {
                            showSuggestions(data.data.suggestions);
                        }
                    }
                })
                .catch(() => {
                    hideTyping();
                    addMessage('<?php echo esc_js(__('Sorry, something went wrong. Please try again.', 'formflow')); ?>', 'bot');
                });
            });

            function addMessage(text, type) {
                const div = document.createElement('div');
                div.className = 'isf-chatbot-message isf-chatbot-' + type;
                div.innerHTML = '<div class="isf-chatbot-bubble">' + escapeHtml(text) + '</div>';
                messages.appendChild(div);
                messages.scrollTop = messages.scrollHeight;
            }

            function showTyping() {
                const typing = document.createElement('div');
                typing.id = 'isf-chatbot-typing';
                typing.className = 'isf-chatbot-message isf-chatbot-bot';
                typing.innerHTML = '<div class="isf-chatbot-typing"><span></span><span></span><span></span></div>';
                messages.appendChild(typing);
                messages.scrollTop = messages.scrollHeight;
            }

            function hideTyping() {
                const typing = document.getElementById('isf-chatbot-typing');
                if (typing) typing.remove();
            }

            function showSuggestions(items) {
                suggestions.innerHTML = items.map(s =>
                    '<button type="button" class="isf-chatbot-suggestion">' + escapeHtml(s) + '</button>'
                ).join('');
            }

            suggestions.addEventListener('click', function(e) {
                if (e.target.classList.contains('isf-chatbot-suggestion')) {
                    input.value = e.target.textContent;
                    form.dispatchEvent(new Event('submit'));
                }
            });

            function escapeHtml(text) {
                const div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            }

            // Auto-open after delay if configured
            <?php if (!empty($config['auto_open_delay']) && $config['auto_open_delay'] > 0): ?>
            setTimeout(function() {
                if (!chatbot.classList.contains('open') && !sessionStorage.getItem('isf_chat_opened')) {
                    chatbot.classList.add('open');
                    sessionStorage.setItem('isf_chat_opened', '1');
                }
            }, <?php echo intval($config['auto_open_delay']) * 1000; ?>);
            <?php endif; ?>
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * Get available providers
     */
    public static function get_providers(): array {
        return [
            'custom' => [
                'name' => 'Built-in Knowledge Base',
                'requires' => [],
            ],
            'openai' => [
                'name' => 'OpenAI (GPT)',
                'requires' => ['api_key'],
            ],
            'dialogflow' => [
                'name' => 'Google Dialogflow',
                'requires' => ['project_id', 'api_credentials'],
            ],
        ];
    }
}
