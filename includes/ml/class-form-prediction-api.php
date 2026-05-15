<?php
namespace ISF\ML;

if (!defined('ABSPATH')) {
    exit;
}


/**
 * Form Prediction API
 *
 * REST API controller for form completion prediction endpoints.
 * Provides access to ML-powered form optimization and training.
 */


use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

class FormPredictionApi {

    /**
     * API namespace
     */
    private const NAMESPACE = 'formflow/v1';

    /**
     * Form prediction instance
     */
    private FormPrediction $predictor;

    /**
     * Constructor
     */
    public function __construct() {
        $this->predictor = new FormPrediction();
    }

    /**
     * Register REST API routes
     */
    public function register_routes(): void {
        // GET /formflow/v1/ml/health - Health check
        register_rest_route(self::NAMESPACE, '/ml/health', [
            'methods' => 'GET',
            'callback' => [$this, 'check_health'],
            'permission_callback' => [$this, 'permission_read'],
        ]);

        // POST /formflow/v1/ml/predict - Predict form completion
        register_rest_route(self::NAMESPACE, '/ml/predict', [
            'methods' => 'POST',
            'callback' => [$this, 'predict'],
            'permission_callback' => [$this, 'permission_read'],
            'args' => [
                'form_data' => [
                    'required' => true,
                    'type' => 'object',
                    'description' => 'Form field data for prediction analysis',
                ],
            ],
        ]);

        // POST /formflow/v1/ml/train - Trigger model training
        register_rest_route(self::NAMESPACE, '/ml/train', [
            'methods' => 'POST',
            'callback' => [$this, 'train'],
            'permission_callback' => [$this, 'permission_admin'],
        ]);
    }

    /**
     * Health check endpoint
     *
     * Returns the status of the ML service and plugin integration.
     *
     * @param WP_REST_Request $request REST API request
     * @return WP_REST_Response
     */
    public function check_health(WP_REST_Request $request): WP_REST_Response {
        $is_available = $this->predictor->is_available();

        return new WP_REST_Response([
            'status' => $is_available ? 'healthy' : 'unavailable',
            'service_available' => $is_available,
            'timestamp' => current_time('mysql'),
        ], $is_available ? 200 : 503);
    }

    /**
     * Predict form completion endpoint
     *
     * Analyzes form data and returns completion probability prediction
     * along with recommended optimizations.
     *
     * @param WP_REST_Request $request REST API request
     * @return WP_REST_Response|WP_Error
     */
    public function predict(WP_REST_Request $request) {
        $form_data = $request->get_param('form_data');

        if (empty($form_data) || !is_array($form_data)) {
            return new WP_Error(
                'invalid_form_data',
                __('Form data must be a non-empty object', 'formflow'),
                ['status' => 400]
            );
        }

        // Sanitize form data
        $sanitized_data = $this->sanitize_form_data($form_data);

        // Get prediction from ML service
        $prediction = $this->predictor->predict_completion($sanitized_data);

        if ($prediction === null) {
            return new WP_Error(
                'ml_service_unavailable',
                __('ML service is currently unavailable. Please try again later.', 'formflow'),
                ['status' => 503]
            );
        }

        return new WP_REST_Response([
            'prediction' => $prediction,
            'timestamp' => current_time('mysql'),
        ], 200);
    }

    /**
     * Train model endpoint
     *
     * Triggers retraining of the ML model using accumulated historical data.
     * Admin-only endpoint.
     *
     * @param WP_REST_Request $request REST API request
     * @return WP_REST_Response|WP_Error
     */
    public function train(WP_REST_Request $request) {
        // Additional check (should already be verified by permission_callback)
        if (!current_user_can('manage_options')) {
            return new WP_Error(
                'rest_forbidden',
                __('Only administrators can trigger model training.', 'formflow'),
                ['status' => 403]
            );
        }

        $success = $this->predictor->train_model();

        if (!$success) {
            return new WP_Error(
                'training_failed',
                __('Failed to trigger model training. Please check logs and service availability.', 'formflow'),
                ['status' => 500]
            );
        }

        return new WP_REST_Response([
            'status' => 'training_initiated',
            'message' => __('Model training has been queued.', 'formflow'),
            'timestamp' => current_time('mysql'),
        ], 202);
    }

    /**
     * Permission check: Read access
     *
     * Allows read access to any user who can read posts.
     *
     * @return bool
     */
    public function permission_read(): bool {
        return current_user_can('read');
    }

    /**
     * Permission check: Admin access
     *
     * Restricts to users with manage_options capability (administrators).
     *
     * @return bool
     */
    public function permission_admin(): bool {
        return current_user_can('manage_options');
    }

    /**
     * Sanitize form data for ML service
     *
     * Removes sensitive information and ensures data is in the correct format
     * for ML service consumption.
     *
     * @param array $form_data Raw form data from request
     * @return array Sanitized form data
     *
     * @access private
     */
    private function sanitize_form_data(array $form_data): array {
        $sanitized = [];

        foreach ($form_data as $key => $value) {
            $key = sanitize_key($key);

            // Skip sensitive fields
            if (in_array($key, ['password', 'secret', 'token', 'api_key'], true)) {
                continue;
            }

            // Sanitize different value types
            if (is_array($value)) {
                // Recursively sanitize nested arrays
                $sanitized[$key] = $this->sanitize_form_data($value);
            } elseif (is_string($value)) {
                $sanitized[$key] = sanitize_text_field($value);
            } elseif (is_numeric($value) || is_bool($value)) {
                $sanitized[$key] = $value;
            } else {
                // Skip unsupported types
                continue;
            }
        }

        return $sanitized;
    }
}
