<?php
namespace ISF;

if (!defined('ABSPATH')) {
    exit;
}


/**
 * Translation Handler
 *
 * Manages multilingual support for enrollment forms.
 * Currently supports English and Spanish.
 */


class TranslationHandler {

    /**
     * Supported languages
     */
    public const LANGUAGES = [
        'en' => 'English',
        'es' => 'Español',
    ];

    /**
     * Current language
     */
    private static ?string $current_language = null;

    /**
     * Translation strings cache
     */
    private static ?array $strings = null;

    /**
     * Get current language for an instance
     */
    public static function get_current_language(array $instance): string {
        // Check if feature is enabled
        if (!FeatureManager::is_enabled($instance, 'spanish_translation')) {
            return 'en';
        }

        $config = FeatureManager::get_feature($instance, 'spanish_translation');

        // Check URL parameter first
        if (isset($_GET['lang']) && $_GET['lang'] !== '' && array_key_exists($_GET['lang'], self::LANGUAGES)) {
            $lang = sanitize_text_field($_GET['lang']);
            self::set_language_cookie($lang);
            return $lang;
        }

        // Check cookie
        if (isset($_COOKIE['isf_language']) && $_COOKIE['isf_language'] !== '' && array_key_exists($_COOKIE['isf_language'], self::LANGUAGES)) {
            return $_COOKIE['isf_language'];
        }

        // Auto-detect from browser if enabled
        if (!empty($config['auto_detect'])) {
            $browser_lang = self::detect_browser_language();
            if ($browser_lang) {
                return $browser_lang;
            }
        }

        // Return default
        return $config['default_language'] ?? 'en';
    }

    /**
     * Set language cookie
     */
    public static function set_language_cookie(string $language): void {
        if (!headers_sent()) {
            setcookie('isf_language', $language, time() + (365 * 24 * 60 * 60), '/');
        }
    }

    /**
     * Detect language from browser Accept-Language header
     */
    private static function detect_browser_language(): ?string {
        if (empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
            return null;
        }

        $accept = strtolower($_SERVER['HTTP_ACCEPT_LANGUAGE']);

        // Check for Spanish
        if (strpos($accept, 'es') !== false) {
            return 'es';
        }

        return null;
    }

    /**
     * Get translation string
     */
    public static function get(string $key, string $language = 'en', array $replacements = []): string {
        $strings = self::get_strings($language);
        $text = $strings[$key] ?? $key;

        // Apply replacements
        foreach ($replacements as $placeholder => $value) {
            $text = str_replace('{' . $placeholder . '}', $value, $text);
        }

        return $text;
    }

    /**
     * Get all strings for a language
     */
    public static function get_strings(string $language = 'en'): array {
        if ($language === 'en') {
            return self::get_english_strings();
        }

        if ($language === 'es') {
            return self::get_spanish_strings();
        }

        return self::get_english_strings();
    }

    /**
     * Get all strings for JavaScript
     */
    public static function get_js_strings(string $language = 'en'): array {
        $strings = self::get_strings($language);

        // Return subset needed for JavaScript
        return array_filter($strings, function($key) {
            return strpos($key, 'js_') === 0 ||
                   strpos($key, 'error_') === 0 ||
                   strpos($key, 'button_') === 0 ||
                   strpos($key, 'label_') === 0;
        }, ARRAY_FILTER_USE_KEY);
    }

    /**
     * English strings
     */
    private static function get_english_strings(): array {
        return [
            // Navigation
            'nav_next' => 'Continue',
            'nav_back' => 'Back',
            'nav_submit' => 'Submit Enrollment',
            'nav_save_later' => 'Save & Continue Later',

            // Progress
            'progress_step' => 'Step {current} of {total}',
            'progress_step1' => 'Program',
            'progress_step2' => 'Account',
            'progress_step3' => 'Information',
            'progress_step4' => 'Schedule',
            'progress_step5' => 'Confirm',

            // Step 1 - Program Selection
            'step1_title' => 'Select Your Device',
            'step1_description' => 'Choose the device you would like to enroll in the program.',
            'device_thermostat' => 'Smart Thermostat',
            'device_thermostat_desc' => 'Control your home\'s temperature remotely',
            'device_dcu' => 'Outdoor Switch',
            'device_dcu_desc' => 'For central air conditioning units',
            'label_has_ac' => 'Do you have central air conditioning?',
            'label_cycling_level' => 'Cycling Level',
            'cycling_50' => '50% Cycling',
            'cycling_100' => '100% Cycling',

            // Step 2 - Account Validation
            'step2_title' => 'Verify Your Account',
            'step2_description' => 'Enter your utility account information to verify eligibility.',
            'label_account_number' => 'Account Number',
            'label_zip_code' => 'ZIP Code',
            'account_help' => 'Find your account number on your utility bill.',
            'validating_account' => 'Validating your account...',
            'validation_success' => 'Account verified successfully!',

            // Step 3 - Customer Information
            'step3_title' => 'Your Information',
            'step3_description' => 'Please provide your contact and property information.',
            'label_first_name' => 'First Name',
            'label_last_name' => 'Last Name',
            'label_email' => 'Email Address',
            'label_email_confirm' => 'Confirm Email',
            'label_phone' => 'Phone Number',
            'label_alt_phone' => 'Alternate Phone',
            'label_address' => 'Street Address',
            'label_city' => 'City',
            'label_state' => 'State',
            'label_property_type' => 'Property Type',
            'property_single' => 'Single Family Home',
            'property_multi' => 'Multi-Family/Townhouse',
            'property_condo' => 'Condo/Apartment',
            'property_mobile' => 'Mobile Home',
            'label_own_rent' => 'Do you own or rent?',
            'own' => 'Own',
            'rent' => 'Rent',
            'label_landlord_name' => 'Landlord Name',
            'label_landlord_phone' => 'Landlord Phone',
            'label_thermostat_count' => 'Number of Thermostats',

            // Step 4 - Scheduling
            'step4_title' => 'Schedule Installation',
            'step4_description' => 'Select a convenient time for your installation appointment.',
            'label_schedule_date' => 'Appointment Date',
            'label_schedule_time' => 'Time Window',
            'time_am' => 'Morning (8:00 AM - 11:00 AM)',
            'time_md' => 'Midday (11:00 AM - 2:00 PM)',
            'time_pm' => 'Afternoon (2:00 PM - 5:00 PM)',
            'time_ev' => 'Evening (5:00 PM - 8:00 PM)',
            'schedule_later' => 'I\'ll schedule later',
            'schedule_later_desc' => 'A representative will call to schedule your appointment.',
            'loading_dates' => 'Loading available dates...',
            'no_slots_available' => 'No appointment slots available. Please try a different date.',

            // Step 5 - Confirmation
            'step5_title' => 'Review & Confirm',
            'step5_description' => 'Please review your information and accept the terms.',
            'section_contact' => 'Contact Information',
            'section_property' => 'Property Information',
            'section_device' => 'Device Selection',
            'section_schedule' => 'Installation Appointment',
            'label_terms' => 'Terms and Conditions',
            'terms_accept' => 'I have read and agree to the program terms and conditions.',
            'terms_link' => 'View Program Rules',

            // Success
            'success_title' => 'Enrollment Complete!',
            'success_message' => 'Thank you for enrolling in the program.',
            'success_confirmation' => 'Your confirmation number is:',
            'success_email' => 'A confirmation email has been sent to {email}.',
            'success_next' => 'What happens next?',
            'success_next_1' => 'You will receive a confirmation email shortly.',
            'success_next_2' => 'A technician will arrive during your scheduled time.',
            'success_next_3' => 'Installation typically takes 30-60 minutes.',

            // Errors
            'error_required' => 'This field is required.',
            'error_email_invalid' => 'Please enter a valid email address.',
            'error_email_mismatch' => 'Email addresses do not match.',
            'error_phone_invalid' => 'Please enter a valid 10-digit phone number.',
            'error_zip_invalid' => 'Please enter a valid ZIP code.',
            'error_account_invalid' => 'Unable to verify your account. Please check your information.',
            'error_account_enrolled' => 'This account is already enrolled in the program.',
            'error_network' => 'A network error occurred. Please try again.',
            'error_generic' => 'An error occurred. Please try again.',
            'error_select_time' => 'Please select an appointment time.',
            'error_accept_terms' => 'You must accept the terms and conditions.',

            // Auto-save
            'autosaved' => 'Auto-saved',
            'restore_prompt' => 'You have unsaved progress from {time}',
            'restore_yes' => 'Restore',
            'restore_no' => 'Start Fresh',
            'progress_restored' => 'Progress restored!',

            // Language toggle
            'language_toggle' => 'Español',
            'language_current' => 'English',

            // Miscellaneous
            'loading' => 'Loading...',
            'submitting' => 'Submitting...',
            'please_wait' => 'Please wait...',
            'edit' => 'Edit',
            'optional' => 'Optional',
            'required' => 'Required',
        ];
    }

    /**
     * Spanish strings
     */
    private static function get_spanish_strings(): array {
        return [
            // Navigation
            'nav_next' => 'Continuar',
            'nav_back' => 'Atrás',
            'nav_submit' => 'Enviar Inscripción',
            'nav_save_later' => 'Guardar y Continuar Después',

            // Progress
            'progress_step' => 'Paso {current} de {total}',
            'progress_step1' => 'Programa',
            'progress_step2' => 'Cuenta',
            'progress_step3' => 'Información',
            'progress_step4' => 'Programar',
            'progress_step5' => 'Confirmar',

            // Step 1 - Program Selection
            'step1_title' => 'Seleccione Su Dispositivo',
            'step1_description' => 'Elija el dispositivo que desea inscribir en el programa.',
            'device_thermostat' => 'Termostato Inteligente',
            'device_thermostat_desc' => 'Controle la temperatura de su hogar de forma remota',
            'device_dcu' => 'Interruptor Exterior',
            'device_dcu_desc' => 'Para unidades de aire acondicionado central',
            'label_has_ac' => '¿Tiene aire acondicionado central?',
            'label_cycling_level' => 'Nivel de Ciclo',
            'cycling_50' => 'Ciclo del 50%',
            'cycling_100' => 'Ciclo del 100%',

            // Step 2 - Account Validation
            'step2_title' => 'Verifique Su Cuenta',
            'step2_description' => 'Ingrese la información de su cuenta de servicios públicos para verificar la elegibilidad.',
            'label_account_number' => 'Número de Cuenta',
            'label_zip_code' => 'Código Postal',
            'account_help' => 'Encuentre su número de cuenta en su factura de servicios públicos.',
            'validating_account' => 'Verificando su cuenta...',
            'validation_success' => '¡Cuenta verificada exitosamente!',

            // Step 3 - Customer Information
            'step3_title' => 'Su Información',
            'step3_description' => 'Por favor proporcione su información de contacto y propiedad.',
            'label_first_name' => 'Nombre',
            'label_last_name' => 'Apellido',
            'label_email' => 'Correo Electrónico',
            'label_email_confirm' => 'Confirmar Correo Electrónico',
            'label_phone' => 'Número de Teléfono',
            'label_alt_phone' => 'Teléfono Alternativo',
            'label_address' => 'Dirección',
            'label_city' => 'Ciudad',
            'label_state' => 'Estado',
            'label_property_type' => 'Tipo de Propiedad',
            'property_single' => 'Casa Unifamiliar',
            'property_multi' => 'Multifamiliar/Casa Adosada',
            'property_condo' => 'Condominio/Apartamento',
            'property_mobile' => 'Casa Móvil',
            'label_own_rent' => '¿Es propietario o inquilino?',
            'own' => 'Propietario',
            'rent' => 'Inquilino',
            'label_landlord_name' => 'Nombre del Propietario',
            'label_landlord_phone' => 'Teléfono del Propietario',
            'label_thermostat_count' => 'Número de Termostatos',

            // Step 4 - Scheduling
            'step4_title' => 'Programar Instalación',
            'step4_description' => 'Seleccione un horario conveniente para su cita de instalación.',
            'label_schedule_date' => 'Fecha de la Cita',
            'label_schedule_time' => 'Horario',
            'time_am' => 'Mañana (8:00 AM - 11:00 AM)',
            'time_md' => 'Mediodía (11:00 AM - 2:00 PM)',
            'time_pm' => 'Tarde (2:00 PM - 5:00 PM)',
            'time_ev' => 'Noche (5:00 PM - 8:00 PM)',
            'schedule_later' => 'Programaré después',
            'schedule_later_desc' => 'Un representante le llamará para programar su cita.',
            'loading_dates' => 'Cargando fechas disponibles...',
            'no_slots_available' => 'No hay horarios disponibles. Por favor intente otra fecha.',

            // Step 5 - Confirmation
            'step5_title' => 'Revisar y Confirmar',
            'step5_description' => 'Por favor revise su información y acepte los términos.',
            'section_contact' => 'Información de Contacto',
            'section_property' => 'Información de la Propiedad',
            'section_device' => 'Dispositivo Seleccionado',
            'section_schedule' => 'Cita de Instalación',
            'label_terms' => 'Términos y Condiciones',
            'terms_accept' => 'He leído y acepto los términos y condiciones del programa.',
            'terms_link' => 'Ver Reglas del Programa',

            // Success
            'success_title' => '¡Inscripción Completa!',
            'success_message' => 'Gracias por inscribirse en el programa.',
            'success_confirmation' => 'Su número de confirmación es:',
            'success_email' => 'Se ha enviado un correo de confirmación a {email}.',
            'success_next' => '¿Qué sigue?',
            'success_next_1' => 'Recibirá un correo de confirmación en breve.',
            'success_next_2' => 'Un técnico llegará durante su horario programado.',
            'success_next_3' => 'La instalación generalmente toma de 30 a 60 minutos.',

            // Errors
            'error_required' => 'Este campo es obligatorio.',
            'error_email_invalid' => 'Por favor ingrese un correo electrónico válido.',
            'error_email_mismatch' => 'Los correos electrónicos no coinciden.',
            'error_phone_invalid' => 'Por favor ingrese un número de teléfono válido de 10 dígitos.',
            'error_zip_invalid' => 'Por favor ingrese un código postal válido.',
            'error_account_invalid' => 'No se pudo verificar su cuenta. Por favor verifique su información.',
            'error_account_enrolled' => 'Esta cuenta ya está inscrita en el programa.',
            'error_network' => 'Ocurrió un error de red. Por favor intente de nuevo.',
            'error_generic' => 'Ocurrió un error. Por favor intente de nuevo.',
            'error_select_time' => 'Por favor seleccione un horario para la cita.',
            'error_accept_terms' => 'Debe aceptar los términos y condiciones.',

            // Auto-save
            'autosaved' => 'Guardado automáticamente',
            'restore_prompt' => 'Tiene progreso sin guardar de {time}',
            'restore_yes' => 'Restaurar',
            'restore_no' => 'Empezar de Nuevo',
            'progress_restored' => '¡Progreso restaurado!',

            // Language toggle
            'language_toggle' => 'English',
            'language_current' => 'Español',

            // Miscellaneous
            'loading' => 'Cargando...',
            'submitting' => 'Enviando...',
            'please_wait' => 'Por favor espere...',
            'edit' => 'Editar',
            'optional' => 'Opcional',
            'required' => 'Obligatorio',
        ];
    }

    /**
     * Get email strings for a language
     */
    public static function get_email_strings(string $language = 'en'): array {
        if ($language === 'es') {
            return [
                'subject_confirmation' => 'Confirmación de Inscripción - {program_name}',
                'greeting' => 'Estimado/a {name},',
                'confirmation_intro' => 'Gracias por inscribirse en {program_name}. Su inscripción ha sido recibida.',
                'confirmation_number' => 'Su número de confirmación es: {confirmation}',
                'appointment_details' => 'Detalles de su cita:',
                'appointment_date' => 'Fecha: {date}',
                'appointment_time' => 'Hora: {time}',
                'no_appointment' => 'Un representante le contactará para programar su instalación.',
                'questions' => 'Si tiene preguntas, por favor llame al {phone}.',
                'closing' => 'Gracias,',
                'team' => 'El Equipo de {program_name}',
            ];
        }

        return [
            'subject_confirmation' => 'Enrollment Confirmation - {program_name}',
            'greeting' => 'Dear {name},',
            'confirmation_intro' => 'Thank you for enrolling in {program_name}. Your enrollment has been received.',
            'confirmation_number' => 'Your confirmation number is: {confirmation}',
            'appointment_details' => 'Your appointment details:',
            'appointment_date' => 'Date: {date}',
            'appointment_time' => 'Time: {time}',
            'no_appointment' => 'A representative will contact you to schedule your installation.',
            'questions' => 'If you have questions, please call {phone}.',
            'closing' => 'Thank you,',
            'team' => 'The {program_name} Team',
        ];
    }

    /**
     * Render language toggle HTML
     */
    public static function render_language_toggle(array $instance, string $current_language): string {
        if (!FeatureManager::is_enabled($instance, 'spanish_translation')) {
            return '';
        }

        $config = FeatureManager::get_feature($instance, 'spanish_translation');

        if (empty($config['show_language_toggle'])) {
            return '';
        }

        $other_language = $current_language === 'en' ? 'es' : 'en';
        $toggle_text = self::get('language_toggle', $current_language);
        $current_url = remove_query_arg('lang');
        $toggle_url = add_query_arg('lang', $other_language, $current_url);

        return sprintf(
            '<div class="isf-language-toggle">
                <a href="%s" class="isf-language-link" data-lang="%s">
                    <span class="isf-language-icon">🌐</span>
                    <span class="isf-language-text">%s</span>
                </a>
            </div>',
            esc_url($toggle_url),
            esc_attr($other_language),
            esc_html($toggle_text)
        );
    }
}
