<?php
namespace ISF;

if (!defined('ABSPATH')) {
    exit;
}


/**
 * Document Upload Handler
 *
 * Handles file uploads for enrollment forms (photos, documents).
 */


class DocumentUpload {

    /**
     * Upload directory name
     */
    private const UPLOAD_DIR = 'isf-uploads';

    /**
     * Database instance
     */
    private Database\Database $db;

    /**
     * Constructor
     */
    public function __construct() {
        $this->db = new Database\Database();
    }

    /**
     * Process file upload
     */
    public function process_upload(array $file, array $instance, string $session_id): array {
        // Check if feature is enabled
        if (!FeatureManager::is_enabled($instance, 'document_upload')) {
            return [
                'success' => false,
                'error' => __('File uploads are not enabled for this form.', 'formflow'),
            ];
        }

        $config = FeatureManager::get_feature($instance, 'document_upload');

        // Validate file
        $validation = $this->validate_file($file, $config);
        if (!$validation['valid']) {
            return [
                'success' => false,
                'error' => $validation['error'],
            ];
        }

        // Check file count limit
        $existing_count = $this->get_upload_count($session_id);
        $max_files = $config['max_files'] ?? 3;

        if ($existing_count >= $max_files) {
            return [
                'success' => false,
                'error' => sprintf(
                    __('Maximum of %d files allowed.', 'formflow'),
                    $max_files
                ),
            ];
        }

        // Get upload directory
        $upload_dir = $this->get_upload_directory($instance['id'], $session_id);

        if (!$upload_dir) {
            return [
                'success' => false,
                'error' => __('Unable to create upload directory.', 'formflow'),
            ];
        }

        // Generate unique filename
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $filename = wp_generate_uuid4() . '.' . $extension;
        $filepath = $upload_dir['path'] . '/' . $filename;

        // Move uploaded file
        if (!move_uploaded_file($file['tmp_name'], $filepath)) {
            return [
                'success' => false,
                'error' => __('Failed to save uploaded file.', 'formflow'),
            ];
        }

        // Store file record
        $file_id = $this->store_file_record([
            'instance_id' => $instance['id'],
            'session_id' => $session_id,
            'original_name' => sanitize_file_name($file['name']),
            'stored_name' => $filename,
            'file_path' => $filepath,
            'file_url' => $upload_dir['url'] . '/' . $filename,
            'file_type' => $file['type'],
            'file_size' => $file['size'],
        ]);

        if (!$file_id) {
            // Clean up file if record failed
            @unlink($filepath);
            return [
                'success' => false,
                'error' => __('Failed to record file upload.', 'formflow'),
            ];
        }

        $this->db->log('info', 'File uploaded', [
            'filename' => $file['name'],
            'size' => $file['size'],
        ], $instance['id']);

        return [
            'success' => true,
            'file_id' => $file_id,
            'filename' => $file['name'],
            'url' => $upload_dir['url'] . '/' . $filename,
            'size' => $file['size'],
            'type' => $file['type'],
        ];
    }

    /**
     * Validate uploaded file
     */
    private function validate_file(array $file, array $config): array {
        // Check for upload errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $error_messages = [
                UPLOAD_ERR_INI_SIZE => __('File exceeds server limit.', 'formflow'),
                UPLOAD_ERR_FORM_SIZE => __('File exceeds form limit.', 'formflow'),
                UPLOAD_ERR_PARTIAL => __('File was only partially uploaded.', 'formflow'),
                UPLOAD_ERR_NO_FILE => __('No file was uploaded.', 'formflow'),
                UPLOAD_ERR_NO_TMP_DIR => __('Missing temporary folder.', 'formflow'),
                UPLOAD_ERR_CANT_WRITE => __('Failed to write file.', 'formflow'),
            ];

            return [
                'valid' => false,
                'error' => $error_messages[$file['error']] ?? __('Upload error occurred.', 'formflow'),
            ];
        }

        // Check file size
        $max_size = ($config['max_file_size_mb'] ?? 10) * 1024 * 1024;
        if ($file['size'] > $max_size) {
            return [
                'valid' => false,
                'error' => sprintf(
                    __('File size exceeds %d MB limit.', 'formflow'),
                    $config['max_file_size_mb'] ?? 10
                ),
            ];
        }

        // Check file extension
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed_types = $config['allowed_types'] ?? ['jpg', 'jpeg', 'png', 'pdf'];

        if (!in_array($extension, $allowed_types)) {
            return [
                'valid' => false,
                'error' => sprintf(
                    __('File type not allowed. Allowed types: %s', 'formflow'),
                    implode(', ', $allowed_types)
                ),
            ];
        }

        // Verify MIME type
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime_type = $finfo->file($file['tmp_name']);

        $allowed_mimes = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ];

        $expected_mime = $allowed_mimes[$extension] ?? null;

        if ($expected_mime && $mime_type !== $expected_mime) {
            // Allow both image/jpeg variations
            if (!($extension === 'jpg' && $mime_type === 'image/jpeg')) {
                return [
                    'valid' => false,
                    'error' => __('File type does not match extension.', 'formflow'),
                ];
            }
        }

        return ['valid' => true];
    }

    /**
     * Get or create upload directory
     */
    private function get_upload_directory(int $instance_id, string $session_id): ?array {
        $wp_upload_dir = wp_upload_dir();

        // Create base directory
        $base_path = $wp_upload_dir['basedir'] . '/' . self::UPLOAD_DIR;
        $base_url = $wp_upload_dir['baseurl'] . '/' . self::UPLOAD_DIR;

        if (!file_exists($base_path)) {
            if (!wp_mkdir_p($base_path)) {
                return null;
            }

            // Add .htaccess to protect uploads
            $htaccess = $base_path . '/.htaccess';
            if (!file_exists($htaccess)) {
                file_put_contents($htaccess, "Options -Indexes\n<Files *.php>\ndeny from all\n</Files>\n");
            }

            // Add index.php
            $index = $base_path . '/index.php';
            if (!file_exists($index)) {
                file_put_contents($index, "<?php\n// Silence is golden.");
            }
        }

        // Create instance/session subdirectory
        $sub_dir = $instance_id . '/' . substr($session_id, 0, 8);
        $full_path = $base_path . '/' . $sub_dir;
        $full_url = $base_url . '/' . $sub_dir;

        if (!file_exists($full_path)) {
            if (!wp_mkdir_p($full_path)) {
                return null;
            }
        }

        return [
            'path' => $full_path,
            'url' => $full_url,
        ];
    }

    /**
     * Store file record in database
     */
    private function store_file_record(array $data): ?int {
        global $wpdb;
        $table = $wpdb->prefix . 'isf_uploads';

        // Ensure table exists
        $this->ensure_uploads_table();

        $result = $wpdb->insert($table, [
            'instance_id' => $data['instance_id'],
            'session_id' => $data['session_id'],
            'original_name' => $data['original_name'],
            'stored_name' => $data['stored_name'],
            'file_path' => $data['file_path'],
            'file_url' => $data['file_url'],
            'file_type' => $data['file_type'],
            'file_size' => $data['file_size'],
            'created_at' => current_time('mysql'),
        ]);

        return $result ? $wpdb->insert_id : null;
    }

    /**
     * Get upload count for a session
     */
    public function get_upload_count(string $session_id): int {
        global $wpdb;
        $table = $wpdb->prefix . 'isf_uploads';

        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            return 0;
        }

        return (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE session_id = %s",
            $session_id
        ));
    }

    /**
     * Get uploads for a session
     */
    public function get_session_uploads(string $session_id): array {
        global $wpdb;
        $table = $wpdb->prefix . 'isf_uploads';

        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            return [];
        }

        return $wpdb->get_results($wpdb->prepare(
            "SELECT id, original_name, file_url, file_type, file_size, created_at
             FROM {$table}
             WHERE session_id = %s
             ORDER BY created_at ASC",
            $session_id
        ), ARRAY_A) ?: [];
    }

    /**
     * Delete an upload
     */
    public function delete_upload(int $file_id, string $session_id): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'isf_uploads';

        // Get file info
        $file = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d AND session_id = %s",
            $file_id,
            $session_id
        ), ARRAY_A);

        if (!$file) {
            return false;
        }

        // Delete physical file
        if (file_exists($file['file_path'])) {
            @unlink($file['file_path']);
        }

        // Delete record
        return (bool)$wpdb->delete($table, ['id' => $file_id]);
    }

    /**
     * Link uploads to submission
     */
    public function link_to_submission(string $session_id, int $submission_id): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'isf_uploads';

        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            return false;
        }

        return (bool)$wpdb->update(
            $table,
            ['submission_id' => $submission_id],
            ['session_id' => $session_id]
        );
    }

    /**
     * Get uploads for a submission
     */
    public function get_submission_uploads(int $submission_id): array {
        global $wpdb;
        $table = $wpdb->prefix . 'isf_uploads';

        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            return [];
        }

        return $wpdb->get_results($wpdb->prepare(
            "SELECT id, original_name, file_url, file_type, file_size, created_at
             FROM {$table}
             WHERE submission_id = %d
             ORDER BY created_at ASC",
            $submission_id
        ), ARRAY_A) ?: [];
    }

    /**
     * Clean up orphaned uploads (no submission after 24 hours)
     */
    public function cleanup_orphaned_uploads(): int {
        global $wpdb;
        $table = $wpdb->prefix . 'isf_uploads';

        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            return 0;
        }

        // Get orphaned files older than 24 hours
        $orphans = $wpdb->get_results(
            "SELECT id, file_path FROM {$table}
             WHERE submission_id IS NULL
             AND created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)",
            ARRAY_A
        );

        $deleted = 0;

        foreach ($orphans as $orphan) {
            // Delete physical file
            if (file_exists($orphan['file_path'])) {
                @unlink($orphan['file_path']);
            }

            // Delete record
            $wpdb->delete($table, ['id' => $orphan['id']]);
            $deleted++;
        }

        if ($deleted > 0) {
            $this->db->log('info', 'Cleaned up orphaned uploads', ['count' => $deleted]);
        }

        return $deleted;
    }

    /**
     * Ensure uploads table exists
     */
    private function ensure_uploads_table(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'isf_uploads';

        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table) {
            return;
        }

        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id INT AUTO_INCREMENT PRIMARY KEY,
            instance_id INT NOT NULL,
            session_id VARCHAR(64) NOT NULL,
            submission_id INT NULL,
            original_name VARCHAR(255) NOT NULL,
            stored_name VARCHAR(255) NOT NULL,
            file_path VARCHAR(500) NOT NULL,
            file_url VARCHAR(500) NOT NULL,
            file_type VARCHAR(100),
            file_size INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_session (session_id),
            INDEX idx_submission (submission_id),
            INDEX idx_instance (instance_id)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Render upload field HTML
     */
    public static function render_upload_field(array $instance, string $session_id): string {
        if (!FeatureManager::is_enabled($instance, 'document_upload')) {
            return '';
        }

        $config = FeatureManager::get_feature($instance, 'document_upload');
        $max_files = $config['max_files'] ?? 3;
        $max_size = $config['max_file_size_mb'] ?? 10;
        $allowed_types = $config['allowed_types'] ?? ['jpg', 'jpeg', 'png', 'pdf'];
        $required = !empty($config['required']);

        $accept = implode(',', array_map(fn($t) => ".{$t}", $allowed_types));

        ob_start();
        ?>
        <div class="isf-upload-field" data-max-files="<?php echo esc_attr($max_files); ?>">
            <div class="isf-upload-dropzone" id="isf-upload-dropzone">
                <div class="isf-upload-icon">
                    <span class="dashicons dashicons-cloud-upload"></span>
                </div>
                <div class="isf-upload-text">
                    <?php esc_html_e('Drag files here or click to upload', 'formflow'); ?>
                </div>
                <div class="isf-upload-info">
                    <?php printf(
                        esc_html__('Max %d files, %d MB each. Allowed: %s', 'formflow'),
                        $max_files,
                        $max_size,
                        strtoupper(implode(', ', $allowed_types))
                    ); ?>
                </div>
                <input type="file"
                       id="isf-file-input"
                       class="isf-file-input"
                       accept="<?php echo esc_attr($accept); ?>"
                       multiple
                       <?php echo $required ? 'required' : ''; ?>>
            </div>

            <div class="isf-upload-list" id="isf-upload-list">
                <!-- Uploaded files will be listed here -->
            </div>

            <input type="hidden" name="upload_session" value="<?php echo esc_attr($session_id); ?>">
        </div>
        <?php
        return ob_get_clean();
    }
}
