# FormFlow (FormFlow Pro)

**Version:** 2.8.4
**Requires WordPress:** 6.0+
**Requires PHP:** 8.0+
**License:** GPL v2 or later

A white-label, API-integrated WordPress plugin for multi-step enrollment and scheduling forms. Originally built for EnergyWise Rewards utility programs, now available as a flexible platform for any enrollment workflow.

## Description

FormFlow (powered by the FormFlow Pro platform) provides multi-step enrollment and scheduling forms with a pluggable connector architecture. The bundled IntelliSource connector integrates with the PowerPortal IntelliSOURCE API, but the platform can be extended with custom connectors for any backend system.

## Architecture (v2.0.0+)

The plugin uses a **two-tier architecture**:

### Core Platform
- Multi-step form engine with progress tracking
- Per-instance feature toggles (16+ features)
- White-label branding system
- Analytics, webhooks, and notifications
- Security features (fraud detection, rate limiting)
- **Embeddable widgets** (v2.1.0) - Embed forms on any website
- **Async queue processing** (v2.1.0) - Action Scheduler integration
- **Redis/Object Cache support** (v2.1.0) - High-performance caching

### API Connectors
Connectors are modular plugins that integrate with specific backend systems:
- **IntelliSource Connector** (bundled) - PowerPortal IntelliSOURCE API for utility programs
- **Custom Connectors** - Build your own for any API (Salesforce, custom REST, etc.)

### Key Features

- **Multi-step Enrollment Forms** - 5-step wizard with progress tracking
- **Scheduling Forms** - Appointment booking with calendar interface
- **API Integration** - Real-time account validation, enrollment submission, and scheduling
- **Multiple Instances** - Support for multiple utilities with separate configurations
- **Demo/Test Modes** - Safe testing without affecting production API
- **Session Management** - Save and resume functionality with email links
- **Analytics Dashboard** - Track form completions, drop-offs, and conversion rates
- **Webhook Support** - Real-time notifications on form events
- **GDPR Compliance** - Data export/erasure request tracking

### Per-Instance Features (v1.6.0+)

All features can be toggled on/off per form instance:

**Form Experience**
- **Inline Field Validation** - Real-time validation feedback as users type
- **Auto-Save Drafts** - Automatically save form progress to server and localStorage
- **Spanish Translation** - Full bilingual support with auto-detection and toggle

**Notifications**
- **SMS Notifications** - Send text confirmations via Twilio
- **Team Notifications** - Slack/Microsoft Teams alerts on enrollments
- **Admin Email Digest** - Daily/weekly enrollment summary emails

**Scheduling**
- **Appointment Self-Service** - Let customers reschedule/cancel via email link
- **Capacity Management** - Daily caps, blackout dates, and waitlist

**Analytics & Tracking**
- **UTM Tracking** - Track marketing campaign effectiveness with attribution reports
- **A/B Testing** - Test form variations with conversion tracking
- **Visitor Analytics** - Cross-session visitor tracking with first-party cookies
- **Marketing Attribution** - Multi-touch attribution models (first-touch, last-touch, linear, time-decay, position-based)
- **Handoff Tracking** - Track redirects to external enrollment systems with completion matching
- **GTM/GA4 Integration** - Google Tag Manager and GA4 Measurement Protocol support
- **Microsoft Clarity** - Session recording and heatmap integration

**Integrations**
- **Document Upload** - Allow customers to upload photos/documents
- **CRM Integration** - Sync enrollments to Salesforce, HubSpot, Zoho, or custom API
- **Calendar Integration** - Create Google/Outlook calendar events for appointments

**Advanced**
- **PWA Support** - Progressive Web App with offline capability and install prompt
- **Chatbot Assistant** - AI-powered help during enrollment (OpenAI, Dialogflow, or built-in)
- **Fraud Detection** - Detect and prevent fraudulent submissions with risk scoring

## Installation

1. Upload the `formflow` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Navigate to **FormFlow** in the admin menu
4. Create a new form instance and configure API credentials

### Configuration

Add to `wp-config.php` for enhanced security:

```php
define('ISF_ENCRYPTION_KEY', 'your-32-character-key-here');
```

## Usage

### Shortcodes

**Enrollment Form**
```
[isf_form instance="your-instance-slug"]
```
Optional attributes:
- `class` - Additional CSS class for the form container

**Enrollment Button (with Handoff Tracking)**
```
[isf_enroll_button instance="your-instance-slug"]
```
Renders a CTA button that either opens the ISF form or redirects to an external enrollment system with full attribution tracking.

Optional attributes:
- `text` - Button text (default: "Enroll Now")
- `class` - Additional CSS class for the button
- `external` - Override URL for external handoff (uses instance config by default)

### Creating a Form Instance

1. Go to **FormFlow > Add New**
2. Select utility (Delmarva DE, Delmarva MD, Pepco MD, Pepco DC)
3. Enter API endpoint and password
4. Configure email settings
5. Save and use the generated shortcode

## Supported Utilities

| Utility | States | Account Format |
|---------|--------|----------------|
| Delmarva Power | DE, MD | Variable length |
| Pepco | DC, MD | 10 digits |

## Form Flow

### Enrollment (5 Steps)

1. **Program Selection** - Device type (thermostat/DCU), AC confirmation
2. **Account Validation** - Account number, ZIP code, cycling level
3. **Customer Information** - Contact details, address, property info
4. **Scheduling** - Appointment date/time selection (optional)
5. **Confirmation** - Terms acceptance, final submission

### Scheduler (2 Steps)

1. **Account Verification** - Account number, ZIP code
2. **Appointment Selection** - Date/time picker with availability

## API Integration

### Endpoints Used

- `/prospects/validate.xml` - Account validation
- `/prospects/enroll.xml` - Enrollment submission
- `/field_service_requests/scheduling.xml` - Get available slots
- `/field_service_requests/schedule` - Book appointment
- `/promo_codes` - Get promotional codes

### Error Handling

| Error Code | Description | Plugin Behavior |
|------------|-------------|-----------------|
| 03 | Already enrolled | Shows message, redirects to scheduler |
| 21 | Medical condition | Shows acknowledgment modal |
| 02 (status) | Already enrolled | Shows message with scheduler link |

### Field Mapping

The plugin automatically maps form fields to API parameters:

| Form Field | API Parameter |
|------------|---------------|
| `first_name` | `fname` |
| `last_name` | `lname` |
| `phone` | `dayPhone` |
| `street` | `address` |
| `cycling_level` | `level` |
| `thermostat_count` | `eqCount-15` |

### Contract Codes

| Level | Thermostat | DCU |
|-------|------------|-----|
| 50% | 01 | 04 |
| 75% | 05 | 08 |
| 100% | 09 | 12 |

## Security Features

### Implemented Protections

- **SQL Injection** - All queries use `$wpdb->prepare()`
- **XSS Prevention** - Output escaping with `esc_html()`, `esc_attr()`
- **CSRF Protection** - Nonce verification on all AJAX requests
- **Input Sanitization** - Field-specific sanitization
- **Rate Limiting** - Configurable (default: 120 requests/60 seconds)
- **Encryption** - AES-256-CBC for stored sensitive data
- **Direct Access** - All PHP files check for `ABSPATH`

### Data Protection

- API passwords encrypted at rest
- Form data encrypted in database
- Sensitive data masked in logs
- Automatic session cleanup

## Database Tables

| Table | Purpose |
|-------|---------|
| `wp_isf_instances` | Form configurations |
| `wp_isf_submissions` | Form submissions |
| `wp_isf_logs` | Activity logging |
| `wp_isf_analytics` | Step tracking |
| `wp_isf_retry_queue` | Failed submission retries |
| `wp_isf_webhooks` | Webhook configurations |
| `wp_isf_api_usage` | API call tracking |
| `wp_isf_resume_tokens` | Save & continue tokens |
| `wp_isf_scheduled_reports` | Report scheduling |
| `wp_isf_audit_log` | Admin action audit |
| `wp_isf_gdpr_requests` | GDPR request tracking |
| `wp_isf_waitlist` | Capacity waitlist entries |
| `wp_isf_uploads` | Document uploads |
| `wp_isf_ab_assignments` | A/B test variation assignments |
| `wp_isf_ab_conversions` | A/B test conversions |
| `wp_isf_chat_logs` | Chatbot conversation logs |
| `wp_isf_fraud_logs` | Fraud detection analysis |
| `wp_isf_fraud_fingerprints` | Blocked device fingerprints |
| `wp_isf_visitors` | Anonymous visitor tracking |
| `wp_isf_touches` | Marketing touchpoints |
| `wp_isf_handoffs` | External enrollment redirects |
| `wp_isf_external_completions` | Inbound completion data |

## Admin Features

### Dashboard
- Form instance management
- Submission statistics
- Recent activity feed

### Analytics
- Conversion funnel visualization
- Step drop-off analysis
- Device/browser breakdown
- Time-on-step metrics

### Attribution (NEW in 2.3.0)
- Multi-touch attribution models (5 models)
- Campaign performance reports
- Visitor journey reconstruction
- External handoff tracking
- Completion import from CSV or webhook
- CSV export for channels, campaigns, handoffs, and full reports

### Logs
- Filterable activity logs
- API call tracking
- Error monitoring

### Reports
- Scheduled email reports
- CSV export
- Date range filtering

### Settings
- Global configuration
- Rate limiting
- Data retention policies
- Encryption testing

### Diagnostics
The plugin includes a comprehensive diagnostics system for verifying that all components are working correctly.

**Quick Health Check** (Dashboard Widget)
- Database connectivity
- Encryption functionality
- Active form instances
- Cron job scheduling

**Full Diagnostics** (FF Forms > Diagnostics)
- PHP requirements (version, extensions, memory)
- WordPress requirements (version, SSL, permalinks)
- File permissions verification
- Database table integrity
- CRUD operation testing
- Security tests (encryption, nonces, rate limiting)
- API connectivity tests (per instance)
- Field mapper validation
- Scheduled task verification

Results can be exported to JSON or copied to clipboard for support purposes.

## Hooks & Filters

### Actions

```php
// After successful enrollment
do_action('isf_enrollment_completed', $submission_id, $instance_id, $form_data);

// After account validation
do_action('isf_account_validated', $account_number, $instance_id);

// After appointment booked
do_action('isf_appointment_booked', $submission_id, $schedule_data);
```

### Filters

```php
// Modify form data before API submission
add_filter('isf_enrollment_data', function($data, $instance) {
    return $data;
}, 10, 2);

// Customize email content
add_filter('isf_confirmation_email_content', function($content, $form_data) {
    return $content;
}, 10, 2);
```

## Webhooks

Configure webhooks to receive real-time notifications:

### Available Events

- `account.validated` - Account validation successful
- `enrollment.completed` - Enrollment submitted
- `enrollment.failed` - Enrollment failed
- `appointment.scheduled` - Appointment booked
- `form.abandoned` - Form abandoned

### Payload Example

```json
{
  "event": "enrollment.completed",
  "timestamp": "2025-01-15T10:30:00Z",
  "instance_id": 1,
  "data": {
    "submission_id": 123,
    "confirmation_number": "EWR-ABC12345",
    "customer_name": "John Doe"
  }
}
```

## Analytics & Attribution

### Visitor Tracking
The plugin uses first-party cookies to track visitors across sessions. Enable in instance settings under "Visitor Analytics."

**Features:**
- 1-year persistent visitor ID cookie
- Cross-session journey stitching
- All UTM parameters captured automatically
- Page views, form interactions, and completions tracked

### Attribution Models

Five attribution models for marketing analysis:

| Model | Description |
|-------|-------------|
| **First Touch** | 100% credit to first marketing touchpoint |
| **Last Touch** | 100% credit to last touchpoint before conversion |
| **Linear** | Equal credit to all touchpoints |
| **Time Decay** | More credit to recent touchpoints (7-day half-life) |
| **Position Based** | 40% first, 40% last, 20% distributed to middle |

### GTM/GA4 Integration

Push events to Google Tag Manager `dataLayer` for GA4 tracking:

```javascript
// Events pushed automatically:
dataLayer.push({
  event: 'isf_form_view',
  isf_instance: 'pepco-dc',
  isf_visitor_id: 'abc123...'
});
```

**Available Events:**
| Event | Trigger |
|-------|---------|
| `isf_form_view` | Form container visible |
| `isf_form_start` | First field interaction |
| `isf_form_step` | Step transition (includes step number/name) |
| `isf_form_complete` | Successful submission |
| `isf_handoff` | Redirect to external system |

**Server-Side Tracking:**
For enhanced accuracy, enable GA4 Measurement Protocol to send server-side events:
1. Get your GA4 Measurement ID (G-XXXXXXX)
2. Create an API secret in GA4 Admin > Data Streams > Measurement Protocol
3. Configure in **FF Forms > Tools > Analytics Settings**

### Handoff Tracking

Track enrollments that redirect to external systems (e.g., IntelliSOURCE platform):

1. **Configure handoff destination** in instance settings
2. **Use the enroll button shortcode**: `[isf_enroll_button instance="pepco-dc"]`
3. **Token passed in URL**: The redirect appends a `isf_handoff` token
4. **Match completions**: Import completions via CSV or webhook to match against handoffs

### Inbound Webhooks

Receive completion notifications from external systems:

```
POST /wp-json/isf/v1/completions/webhook

Headers:
  X-ISF-Signature: sha256=<hmac_signature>
  Content-Type: application/json

Body:
{
  "instance_id": 1,
  "handoff_token": "abc123...",  // Optional: matches to handoff
  "account_number": "1234567890",
  "customer_email": "customer@example.com",
  "external_id": "EXT-12345",
  "completion_type": "enrollment"
}
```

### REST API Endpoints

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/isf/v1/analytics/touch` | POST | Record marketing touch |
| `/isf/v1/handoff` | GET | Redirect with tracking |
| `/isf/v1/completions/webhook` | POST | Receive external completions |
| `/isf/v1/completions/redirect` | GET | Handle completion redirect |

## Troubleshooting

### Common Issues

**Form not displaying**
- Verify shortcode slug matches instance slug
- Check that instance is active
- Ensure JavaScript is not blocked

**Account validation failing**
- Verify API endpoint URL
- Check API password is correct
- Confirm account number format

**Scheduling not loading**
- Check API connectivity
- Verify account has been validated
- Check browser console for errors

### Debug Mode

Enable WordPress debug mode to see detailed error information:

```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

## Developer Guide

### Creating a Custom Connector

Create a new connector by implementing `ApiConnectorInterface`:

```php
// my-connector/class-my-connector.php
namespace MyPlugin\Connectors;

use ISF\Api\ApiConnectorInterface;
use ISF\Api\AccountValidationResult;
use ISF\Api\EnrollmentResult;

class MyConnector implements ApiConnectorInterface {

    public function get_id(): string {
        return 'my-connector';
    }

    public function get_name(): string {
        return 'My Custom API';
    }

    public function get_config_fields(): array {
        return [
            'api_key' => [
                'label' => 'API Key',
                'type' => 'password',
                'required' => true,
            ],
        ];
    }

    public function validate_account(array $data, array $config): AccountValidationResult {
        // Implement your validation logic
        return new AccountValidationResult(['is_valid' => true]);
    }

    public function submit_enrollment(array $form_data, array $config): EnrollmentResult {
        // Implement your enrollment logic
        return new EnrollmentResult(['success' => true]);
    }

    // ... implement other interface methods
}
```

Register your connector:

```php
add_action('isf_register_connectors', function($registry) {
    require_once __DIR__ . '/class-my-connector.php';
    $registry->register(new MyPlugin\Connectors\MyConnector());
});
```

### White-Label Branding

Configure branding via code:

```php
add_filter('isf_branding_settings', function($settings) {
    $settings['plugin_name'] = 'My Enrollment Platform';
    $settings['primary_color'] = '#FF5722';
    $settings['logo_url'] = 'https://example.com/logo.png';
    $settings['show_powered_by'] = false;
    return $settings;
});
```

Or use the admin settings: **FormFlow Pro > Settings > Branding**

### Available Hooks

See `includes/class-hooks.php` for full documentation. Key hooks include:

**Connectors:**
- `isf_register_connectors` - Register custom connectors
- `isf_connectors_loaded` - After all connectors loaded

**Forms:**
- `isf_form_data` - Modify form data before submission
- `isf_enrollment_completed` - After successful enrollment
- `isf_validate_step` - Custom step validation

**Branding:**
- `isf_branding_settings` - Modify branding configuration
- `isf_branding_css` - Add custom CSS

**Notifications:**
- `isf_confirmation_email_content` - Modify email content
- `isf_sms_message` - Modify SMS content

## Changelog

### 2.8.6 (2026-05-15)
- Fix fatal — move ABSPATH guard after namespace declaration in 100 namespaced files (cd6f378 regression that 500'd sites). Prod hotfixed 2026-05-15; durable release.

### 2.8.3 (2025-12-29)
- Fixed scheduled reports cron error (optional frequency parameter)
- Database class refactored with 8 traits for better maintainability
- File reduced from 3,110 to 1,405 lines

### 2.8.2 (2025-12-28)
- Fixed translation loading warning for WordPress 6.7+ (init hook)

### 2.8.0 (2025-12-27)
- React SPA admin interface
- Enhanced analytics dashboard
- Improved form builder UI

### 2.7.0 (2025-12-20)
- Added GDPR compliance tools
- Data retention policy management
- Audit logging for admin actions

### 2.6.0 (2025-12-15)
- Resume token feature for "save and continue later"
- Retry queue for failed API submissions
- Webhook management improvements

### 2.5.0 (2025-12-14)
- Scheduled reports with email delivery
- Enhanced API usage tracking
- Rate limiting improvements

### 2.4.0 (2025-12-13)
- Database trait architecture
- Improved code organization
- Performance optimizations

### 2.3.0 (2025-12-13)
**Analytics & Attribution Module**

- **Visitor Tracking**: Cross-session visitor identification
  - First-party cookie with 1-year persistence
  - Visitor ID generation and management
  - Cross-session journey stitching
  - Device and browser fingerprinting (optional)

- **Marketing Attribution**: Multi-touch attribution reporting
  - 5 attribution models: first-touch, last-touch, linear, time-decay, position-based
  - Campaign performance dashboard
  - Visitor journey reconstruction
  - Top campaigns by conversion rate

- **Touch Recording**: Track all marketing touchpoints
  - UTM parameters (source, medium, campaign, term, content)
  - Ad click IDs (gclid, fbclid, msclkid)
  - Referrer and landing page tracking
  - Form view, start, step, and completion events

- **Handoff Tracking**: External enrollment redirects
  - Unique handoff tokens for attribution matching
  - Full UTM context preserved through redirect
  - Configurable destination URLs per instance
  - Optional interstitial message before redirect
  - `[isf_enroll_button]` shortcode with tracking

- **Completion Receiving**: Match external completions to handoffs
  - REST API webhook endpoint for inbound completions
  - HMAC signature verification for security
  - CSV import wizard with field mapping
  - Automatic matching via token, account number, or email

- **GTM/GA4 Integration**: Google Tag Manager and Analytics
  - Automatic `dataLayer` event pushing
  - GA4 Measurement Protocol for server-side events
  - Microsoft Clarity project ID support
  - Pre-configured GTM tag/trigger/variable templates

- **Admin Features**
  - Attribution Report dashboard with visual funnel
  - Import Completions wizard (4-step process)
  - Analytics Settings page for global configuration
  - Per-instance visitor analytics and handoff settings

- Added database tables: `wp_isf_visitors`, `wp_isf_touches`, `wp_isf_handoffs`, `wp_isf_external_completions`
- Added REST endpoints: `/isf/v1/handoff`, `/isf/v1/completions/webhook`
- Added features: `visitor_analytics`, `handoff_tracking`

### 2.2.0 (2025-12-13)
**Admin UX Overhaul & Security Audit**

- **Instance Editor Redesign**: Complete overhaul of the form instance editor
  - Multi-step wizard with 7 logical steps (Basics, API, Fields, Scheduling, Content, Email, Features)
  - Quick-edit mode toggle for power users (shows all settings on one page)
  - WYSIWYG editors for Terms content and Email body (WordPress TinyMCE integration)
  - Wizard navigation with step indicators and progress tracking
  - Sticky sidebar with publish status, shortcode, and quick actions

- **Drag-and-Drop Functionality**: jQuery UI Sortable integration
  - Form field reordering with drag handles
  - Blocked dates reordering in scheduling
  - Dashboard form instances table reordering with AJAX persistence
  - Added `display_order` column to database with migration

- **Admin Navigation Consolidation**: Reduced cognitive load
  - Consolidated menu from 11 items to 6 items
  - Combined pages: Data (Submissions/Analytics/Activity), Automation (Webhooks/Reports), Tools (Settings/Diagnostics/Compliance)
  - Added breadcrumb navigation
  - Inner tab navigation for sub-sections
  - Legacy URL redirects for backward compatibility

- **UI Enhancements**
  - Standardized tabs across all admin pages
  - Added help tooltips for complex settings
  - Quick actions bar on dashboard
  - Feature cards with toggle switches
  - Mode cards for Test/Demo mode selection
  - Mini statistics in editor sidebar

- **Security Fixes** (from comprehensive audit)
  - Added `sanitize()` instance method to Security class
  - Added try-catch for session ID generation
  - Created comprehensive `AUDIT_REPORT.md` documenting all findings

- **Documentation**
  - Added `AUDIT_REPORT.md` with security findings
  - Updated `ROADMAP.md` with version history
  - Created `USER_MANUAL.md` (comprehensive user guide)

### 2.1.0 (2025-12-12)
**Scalability & Embeddability Enhancements**

- **Embeddable Widget System**: Embed forms on any external website
  - JavaScript snippet for easy embedding: `<div data-isf-token="abc123"></div>`
  - iFrame embedding option for complete isolation
  - REST API endpoints for form operations
  - CORS support with configurable allowed origins
  - Automatic height adjustment for iframe embeds
  - Public API: `ISFEmbed.init()`, `ISFEmbed.getInstance()`

- **Async Queue Processing**: Action Scheduler integration for background tasks
  - Queued API calls with automatic retries
  - Queued email, SMS, and webhook delivery
  - CRM sync processing in background
  - Exponential backoff for failed tasks
  - Queue statistics and monitoring
  - Falls back to synchronous processing if Action Scheduler unavailable

- **Redis/Object Cache Support**: High-performance caching layer
  - Direct Redis support with `ISF_REDIS_*` constants
  - WordPress object cache integration
  - Transient fallback for basic setups
  - Cache helper functions: `isf_cache()->get()`, `->set()`, `->remember()`
  - Rate limiting with cache backend
  - Instance-specific cache invalidation
  - Health check and diagnostics

- **Performance Improvements**
  - Cached instance configurations
  - Cached schedule slots (5-minute TTL)
  - Cached feature settings
  - Reduced database queries

- **New Helper Functions**
  - `isf_cache()` - Cache manager instance
  - `isf_queue()` - Queue manager instance
  - `isf_embed()` - Embed handler instance

### 2.0.0 (2025-12-12)
**White-Label Platform Architecture**

- **Two-Tier Architecture**: Separated core platform from API-specific code
  - Core platform handles forms, features, admin, analytics
  - Connectors are modular plugins for backend integration
  - IntelliSource connector bundled for backward compatibility

- **White-Label Branding**: Full customization support
  - Plugin name, colors, logos
  - Email branding
  - "Powered by" attribution toggle
  - Admin UI theming

- **Connector System**: Pluggable API architecture
  - `ApiConnectorInterface` for custom integrations
  - `ConnectorRegistry` for managing connectors
  - Presets support for multi-tenant scenarios

- **Hooks API**: Comprehensive extensibility
  - 40+ documented actions and filters
  - `ISF\Hooks` class with constants for all hook names
  - Full documentation in code

- **Developer Experience**
  - Clear separation of concerns
  - Documented extension points
  - Example connector implementations

### 1.8.0 (2025-12-12)
**Phase 3 Features: PWA, Integrations & AI**

- **PWA Support**: Progressive Web App functionality
  - Installable on mobile home screens
  - Service worker with offline caching
  - Background sync for form submissions
  - Customizable app name, colors, and icons
  - "Add to Home Screen" install prompt

- **CRM Integration**: Sync enrollments to external CRM systems
  - Salesforce: OAuth authentication, Lead/Contact creation
  - HubSpot: Contact creation with property mapping
  - Zoho CRM: Lead sync with module support
  - Custom API: Webhook-style integration for any CRM
  - Configurable field mapping and sync timing

- **Calendar Integration**: Appointment calendar events
  - Google Calendar: Create events with OAuth
  - Microsoft Outlook: Graph API integration
  - iCal: Generate downloadable .ics files
  - Custom event titles and descriptions
  - Optional calendar invites to customers

- **Chatbot Assistant**: AI-powered enrollment help
  - Built-in knowledge base with common Q&A
  - OpenAI GPT integration for natural language
  - Google Dialogflow support
  - Context-aware responses based on current step
  - Customizable appearance and auto-open behavior

- **Fraud Detection**: Prevent fraudulent submissions
  - Duplicate account detection
  - IP velocity monitoring
  - Device fingerprint tracking
  - Disposable email domain blocking
  - VPN/proxy detection
  - Bot behavior analysis
  - Configurable risk scoring (0-100)
  - Actions: flag, block, or challenge

- Added database tables: `wp_isf_chat_logs`, `wp_isf_fraud_logs`, `wp_isf_fraud_fingerprints`
- Added "Advanced" feature category in admin
- Added admin configuration panels for all new features

### 1.7.0 (2025-12-12)
**Phase 2 Features: Advanced Scheduling, Analytics & Integrations**

- **Spanish Translation**: Full bilingual English/Spanish support
  - Browser language auto-detection
  - Cookie-based language persistence
  - Language toggle button on forms
  - Complete translation coverage (labels, errors, emails, navigation)

- **Appointment Self-Service**: Customer appointment management via email link
  - Secure token-based authentication with configurable expiry
  - Reschedule appointments with capacity validation
  - Cancel appointments with optional reason requirement
  - Configurable deadlines for reschedule/cancel actions
  - Confirmation emails for all changes

- **Capacity Management**: Advanced scheduling controls
  - Daily appointment caps
  - Per-slot appointment limits
  - Blackout dates (single dates and ranges)
  - Waitlist with automatic notification when slots open

- **Document Upload**: File upload support for forms
  - Configurable max files and file size limits
  - MIME type verification for security
  - Secure storage with .htaccess protection
  - Automatic orphan cleanup after 24 hours
  - Links uploaded files to submissions

- **Enhanced UTM Tracking**: Full marketing attribution
  - Captures UTM parameters, referrer, landing page
  - Tracks Google/Facebook/Microsoft ad click IDs
  - First-touch attribution for UTM, last-touch for ad IDs
  - Attribution reporting by source, medium, campaign
  - Top performing campaigns dashboard

- **A/B Testing**: Form variation testing
  - Multiple variation support with weighted traffic split
  - Conversion tracking with configurable goals
  - Statistical significance calculation
  - Form element overrides (heading, button text, CSS)
  - Session or cookie-based visitor tracking

- Added database tables: `wp_isf_waitlist`, `wp_isf_uploads`, `wp_isf_ab_assignments`, `wp_isf_ab_conversions`
- Added admin configuration panels for all new features

### 1.6.0 (2025-12-12)
**Major Feature Release: Per-Instance Feature Toggles**

- Added FeatureManager for per-instance feature configuration
- Inline Field Validation: Real-time validation with success icons, phone/ZIP formatting
- Auto-Save Drafts: Automatic form progress saving to server and localStorage with restore prompt
- SMS Notifications: Twilio integration for enrollment confirmations and appointment reminders
- Team Notifications: Slack and Microsoft Teams webhook alerts for enrollments and failures
- Admin Email Digest: Daily/weekly enrollment summary emails with comparison stats
- UTM Tracking: Marketing campaign parameter capture and storage
- Added Features section to instance editor with toggle switches and configuration panels
- All features can be enabled/disabled per form instance (a la carte)

### 1.5.3 (2025-12-13)
- Enhanced rate limiting controls with presets (Strict, Normal, Relaxed, Very Relaxed)
- Added option to disable rate limiting entirely for troubleshooting 429 errors
- Fixed default rate limit from 10 to 120 requests/minute (less aggressive)
- Added auto-rescheduling of missing cron events on plugin init
- Fixed mock API invalid account test in diagnostics
- Diagnostics now shows warning when rate limiting is disabled

### 1.5.2 (2025-12-12)
- Added comprehensive diagnostics system with full health checks
- Added dashboard widget for quick system health status
- Added tests for PHP/WordPress requirements, database, security, and API connectivity
- Added export and clipboard copy for diagnostic results

### 1.5.1 (2025-01-15)
- Removed debug logging statements from production code
- Code cleanup and optimization

### 1.5.0 (2025-01-15)
- Added email toggle setting to prevent duplicate confirmation emails
- Added complete contract code mapping (21 codes)
- Added all required API fields (ownsPrem, llordName, equipment codes, etc.)
- Added medical condition acknowledgment (error code 21)
- Added already enrolled detection (error 03, status 02)
- Added Pepco/Delmarva account number validation
- Added DCU-specific fields (easy access, install time)
- Added promo code filtering
- Added Program Rules popup with full content
- Improved reschedule flow with existing appointment display

### 1.4.x
- Multi-step enrollment form
- Scheduler form
- API integration
- Admin dashboard
- Analytics tracking

## Requirements

- WordPress 6.0 or higher
- PHP 8.0 or higher
- MySQL 5.7 or higher
- OpenSSL extension (for encryption)
- cURL extension (for API calls)

## Support

For support inquiries, please contact Peanut Graphic.

## License

This plugin is licensed under the GPL v2 or later.

```
This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.
```
