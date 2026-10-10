<?php
/**
 * Minimal WP_REST_* / WP_Error doubles for Brain Monkey unit tests.
 *
 * Shared (require_once) so every test that needs them gets the same, complete
 * shape regardless of which test file PHPUnit loads first.
 */

if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response
    {
        public function __construct(private $data = null, private int $status = 200) {}
        public function get_status(): int { return $this->status; }
        public function get_data() { return $this->data; }
    }
}

if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request
    {
        public array $headers = [];
        public array $params = [];
        public function __construct(array $params = []) { $this->params = $params; }
        public function get_header($key) { return $this->headers[$key] ?? null; }
        public function get_param($key) { return $this->params[$key] ?? null; }
        public function get_params(): array { return $this->params; }
        public function get_json_params() { return $this->params; }
        public function get_body(): string { return (string) json_encode($this->params); }
    }
}

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        public function __construct(public $code = '', public $message = '', public $data = []) {}
        public function get_error_code() { return $this->code; }
        public function get_error_data() { return $this->data; }
    }
}
