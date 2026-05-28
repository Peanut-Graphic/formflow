<?php
/**
 * SFTP Submission Formatter
 *
 * Renders a submission to CSV (default), JSON, or XML, applies the
 * filename template, handles boolean representation and line endings.
 *
 * Defaults locked 2026-05-26 per Itron's Dominion PTR intake spec:
 *   format          = csv
 *   delimiter       = ,
 *   quoting         = RFC 4180 (quote when value contains
 *                     delimiter / double-quote / CR / LF)
 *   line ending     = CRLF
 *   encoding        = UTF-8 (no BOM)
 *   header row      = yes
 *   boolean rep     = yes / no (lowercase)
 *   filename        = dominion_ptr_{date:Ymd}_{date:His}.csv
 *
 * All defaults are overridable via destination config.
 *
 * @package FormFlow
 * @subpackage Destinations
 * @since 2.9.0
 */

namespace ISF\Destinations\Sftp;

if (!defined('ABSPATH')) {
    exit;
}

class SftpFormatter {

    public const FORMAT_CSV = 'csv';
    public const FORMAT_JSON = 'json';
    public const FORMAT_XML = 'xml';

    /**
     * Apply config defaults so callers can pass partial config.
     */
    public static function defaults(): array {
        return [
            'format'                 => self::FORMAT_CSV,
            'csv_delimiter'          => ',',
            'csv_quote_mode'         => 'rfc4180',
            'csv_line_ending'        => 'crlf', // 'crlf' | 'lf'
            'csv_include_header'     => true,
            'csv_encoding'           => 'utf-8', // 'utf-8' | 'utf-8-bom' | 'ascii'
            'boolean_representation' => 'yes_no', // 'yes_no' | 'y_n' | 'true_false' | '1_0'
            'filename_template'      => '{date:Ymd}_{slug}_{submission_id}.{ext}',
            'column_order'           => [],   // optional explicit field-name order; empty = use payload order
            'column_omit'            => [],   // optional list of field names to drop
        ];
    }

    /**
     * Render a single submission to the configured format.
     *
     * @param array $submission Flat assoc array — field name => value.
     *                          Booleans stay PHP-bool until represent_bool() converts.
     * @param array $config     Destination config (merged with defaults).
     * @return string           Raw bytes ready for upload.
     */
    public static function render(array $submission, array $config): string {
        $config = array_merge(self::defaults(), $config);
        $submission = self::apply_column_filters($submission, $config);
        $submission = self::normalize_values($submission, $config);

        switch ($config['format']) {
            case self::FORMAT_JSON:
                return self::render_json($submission, $config);
            case self::FORMAT_XML:
                return self::render_xml($submission, $config);
            case self::FORMAT_CSV:
            default:
                return self::render_csv($submission, $config);
        }
    }

    /**
     * Compute the remote filename from the template + submission data.
     *
     * Supported tokens (case-sensitive):
     *   {date:FORMAT}      strftime/date format applied to now() in UTC
     *   {slug}             instance_slug from submission metadata
     *   {instance_name}    pretty instance name
     *   {submission_id}    numeric submission id
     *   {ext}              auto from format (csv | json | xml)
     *
     * All token values are sanitized to remove path separators, null
     * bytes, and `..` runs before substitution. The final filename
     * never contains a slash.
     */
    public static function filename(array $submission, array $config): string {
        $config = array_merge(self::defaults(), $config);
        $template = (string) ($config['filename_template'] ?? '{date:Ymd}_{slug}_{submission_id}.{ext}');

        // Replace {date:FORMAT} tokens
        $template = preg_replace_callback(
            '/\{date:([^}]+)\}/',
            function ($m) {
                $fmt = (string) $m[1];
                // gmdate so filenames are deterministic across server tz
                return gmdate($fmt);
            },
            $template
        );

        $ext = self::extension_for_format($config['format']);

        $tokens = [
            '{slug}'          => self::sanitize_token((string) ($submission['instance_slug'] ?? 'submission')),
            '{instance_name}' => self::sanitize_token((string) ($submission['instance_name'] ?? '')),
            '{submission_id}' => self::sanitize_token((string) ($submission['submission_id'] ?? '0')),
            '{ext}'           => $ext,
        ];

        $name = strtr($template, $tokens);

        // Final scrub: no path chars, no nulls, no leading dot.
        $name = str_replace(["\0", "\\"], '', $name);
        $name = preg_replace('#[/\x00]+#', '_', $name);
        $name = ltrim($name, '.');

        return $name ?: 'submission.' . $ext;
    }

    public static function extension_for_format(string $format): string {
        return match ($format) {
            self::FORMAT_JSON => 'json',
            self::FORMAT_XML  => 'xml',
            default           => 'csv',
        };
    }

    // ----- format renderers -----

    private static function render_csv(array $submission, array $config): string {
        $delim = (string) ($config['csv_delimiter'] ?? ',');
        $eol = ($config['csv_line_ending'] === 'lf') ? "\n" : "\r\n";
        $quote_mode = (string) ($config['csv_quote_mode'] ?? 'rfc4180');

        $rows = [];
        if (!empty($config['csv_include_header'])) {
            $rows[] = self::csv_row(array_keys($submission), $delim, $quote_mode);
        }
        $rows[] = self::csv_row(array_values($submission), $delim, $quote_mode);

        $body = implode($eol, $rows) . $eol;

        return self::apply_encoding($body, (string) $config['csv_encoding']);
    }

    private static function render_json(array $submission, array $config): string {
        $body = wp_json_encode($submission, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        return self::apply_encoding((string) $body, (string) $config['csv_encoding']);
    }

    private static function render_xml(array $submission, array $config): string {
        $eol = ($config['csv_line_ending'] === 'lf') ? "\n" : "\r\n";
        $lines = ['<?xml version="1.0" encoding="UTF-8"?>', '<submission>'];
        foreach ($submission as $key => $value) {
            $tag = self::sanitize_xml_tag($key);
            $lines[] = sprintf('  <%s>%s</%s>', $tag, htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8'), $tag);
        }
        $lines[] = '</submission>';
        return self::apply_encoding(implode($eol, $lines) . $eol, (string) $config['csv_encoding']);
    }

    // ----- helpers -----

    /**
     * RFC 4180-style CSV row: quote only when a value contains the
     * delimiter, a double-quote, CR, or LF. Double-quotes inside
     * quoted values are escaped by doubling.
     */
    private static function csv_row(array $values, string $delim, string $quote_mode): string {
        $parts = [];
        foreach ($values as $v) {
            $v = (string) $v;
            $needs_quote = $quote_mode === 'always'
                || str_contains($v, $delim)
                || str_contains($v, '"')
                || str_contains($v, "\r")
                || str_contains($v, "\n");

            if ($needs_quote) {
                $v = '"' . str_replace('"', '""', $v) . '"';
            }
            $parts[] = $v;
        }
        return implode($delim, $parts);
    }

    private static function apply_column_filters(array $submission, array $config): array {
        // Drop omitted columns
        if (!empty($config['column_omit']) && is_array($config['column_omit'])) {
            foreach ($config['column_omit'] as $col) {
                unset($submission[$col]);
            }
        }

        // Reorder per explicit column_order (any extras keep their original order at the end)
        if (!empty($config['column_order']) && is_array($config['column_order'])) {
            $ordered = [];
            foreach ($config['column_order'] as $col) {
                if (array_key_exists($col, $submission)) {
                    $ordered[$col] = $submission[$col];
                    unset($submission[$col]);
                }
            }
            $submission = $ordered + $submission;
        }

        return $submission;
    }

    private static function normalize_values(array $submission, array $config): array {
        $bool_mode = (string) ($config['boolean_representation'] ?? 'yes_no');

        foreach ($submission as $key => $value) {
            if (is_bool($value)) {
                $submission[$key] = self::represent_bool($value, $bool_mode);
            } elseif ($value === null) {
                $submission[$key] = '';
            } elseif (is_array($value) || is_object($value)) {
                // Flatten complex values to JSON so the CSV stays one row per submission.
                $submission[$key] = wp_json_encode($value);
            }
        }
        return $submission;
    }

    private static function represent_bool(bool $value, string $mode): string {
        return match ($mode) {
            'y_n'       => $value ? 'Y' : 'N',
            'true_false'=> $value ? 'true' : 'false',
            '1_0'       => $value ? '1' : '0',
            default     => $value ? 'yes' : 'no', // yes_no (Itron spec)
        };
    }

    private static function apply_encoding(string $body, string $encoding): string {
        switch ($encoding) {
            case 'utf-8-bom':
                return "\xEF\xBB\xBF" . $body;
            case 'ascii':
                // Best-effort transliterate; non-ASCII becomes '?'.
                if (function_exists('iconv')) {
                    return (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $body);
                }
                return $body;
            case 'utf-8':
            default:
                return $body;
        }
    }

    private static function sanitize_token(string $value): string {
        // Strip path separators, control chars, and dot-runs.
        $value = preg_replace('#[/\\\\\x00-\x1F]+#', '_', $value);
        $value = preg_replace('/\.{2,}/', '_', $value);
        return $value ?? '';
    }

    private static function sanitize_xml_tag(string $name): string {
        $name = preg_replace('/[^A-Za-z0-9_-]/', '_', $name) ?? 'field';
        if ($name === '' || !preg_match('/^[A-Za-z_]/', $name)) {
            $name = '_' . $name;
        }
        return $name;
    }
}
