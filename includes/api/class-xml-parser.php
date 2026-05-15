<?php
namespace ISF\Api;

if (!defined('ABSPATH')) {
    exit;
}


/**
 * XML Parser
 *
 * Converts XML responses from the PowerPortal API to PHP arrays.
 * Based on the legacy xml2array function from inc_xmlparser.php
 */


class XmlParser {

    /**
     * Convert XML string to array
     *
     * @param string $xml_string The XML content to parse
     * @param bool $get_attributes Whether to include attributes in the result
     * @return array The parsed XML as an associative array
     */
    public static function parse(string $xml_string, bool $get_attributes = true): array {
        if (empty($xml_string)) {
            return [];
        }

        $xml_string = trim($xml_string);

        // Check if XML parser is available
        if (!function_exists('xml_parser_create')) {
            throw new \RuntimeException('XML parser extension is not available');
        }

        // Create and configure parser
        $parser = xml_parser_create();
        xml_parser_set_option($parser, XML_OPTION_CASE_FOLDING, 0);
        xml_parser_set_option($parser, XML_OPTION_SKIP_WHITE, 1);

        // Parse XML into values array
        $xml_values = [];
        if (!xml_parse_into_struct($parser, $xml_string, $xml_values)) {
            $error = xml_error_string(xml_get_error_code($parser));
            $line = xml_get_current_line_number($parser);
            xml_parser_free($parser);
            throw new \RuntimeException("XML parsing error: {$error} at line {$line}");
        }

        xml_parser_free($parser);

        if (empty($xml_values)) {
            return [];
        }

        // Build the array structure
        return self::build_array($xml_values, $get_attributes);
    }

    /**
     * Build array structure from parsed XML values
     *
     * @param array $xml_values The parsed XML structure from xml_parse_into_struct
     * @param bool $get_attributes Whether to include attributes
     * @return array The final array structure
     */
    private static function build_array(array $xml_values, bool $get_attributes): array {
        $xml_array = [];
        $parent = [];
        $current = &$xml_array;

        foreach ($xml_values as $data) {
            $tag = $data['tag'];
            $type = $data['type'];
            $level = $data['level'];
            $value = $data['value'] ?? null;
            $attributes = $data['attributes'] ?? null;

            // Build result for this tag
            $result = self::build_result($value, $attributes, $get_attributes);

            switch ($type) {
                case 'open':
                    // Opening tag: descend into this element
                    $parent[$level - 1] = &$current;

                    if (!is_array($current) || !array_key_exists($tag, $current)) {
                        // New tag
                        $current[$tag] = $result;
                        $current = &$current[$tag];
                    } else {
                        // Duplicate tag - make it an array of elements
                        if (isset($current[$tag][0])) {
                            $current[$tag][] = $result;
                        } else {
                            $current[$tag] = [$current[$tag], $result];
                        }
                        $last = count($current[$tag]) - 1;
                        $current = &$current[$tag][$last];
                    }
                    break;

                case 'complete':
                    // Self-closing or empty tag
                    if (!isset($current[$tag])) {
                        $current[$tag] = $result;
                    } else {
                        // Duplicate tag
                        if ((is_array($current[$tag]) && !$get_attributes)
                            || (isset($current[$tag][0]) && is_array($current[$tag][0]) && $get_attributes)) {
                            $current[$tag][] = $result;
                        } else {
                            $current[$tag] = [$current[$tag], $result];
                        }
                    }
                    break;

                case 'close':
                    // Closing tag: move back up to parent
                    $current = &$parent[$level - 1];
                    break;
            }
        }

        return $xml_array;
    }

    /**
     * Build the result value/array for a tag
     *
     * @param mixed $value The tag value
     * @param array|null $attributes The tag attributes
     * @param bool $get_attributes Whether to include attributes
     * @return mixed The result (string or array)
     */
    private static function build_result(mixed $value, ?array $attributes, bool $get_attributes): mixed {
        if (!$get_attributes) {
            return $value ?? '';
        }

        $result = [];

        if ($value !== null) {
            $result['value'] = $value;
        }

        if (!empty($attributes)) {
            $result['attr'] = [];
            foreach ($attributes as $attr => $val) {
                $result['attr'][$attr] = $val;
            }
        }

        return $result;
    }

    /**
     * Parse XML and return a simplified array (values only, no attributes)
     *
     * @param string $xml_string The XML content
     * @return array Simplified array
     */
    public static function parse_simple(string $xml_string): array {
        return self::parse($xml_string, false);
    }

    /**
     * Extract a value from a parsed XML array using dot notation path
     *
     * @param array $array The parsed array
     * @param string $path Dot-notation path (e.g., "message.status.value")
     * @param mixed $default Default value if path not found
     * @return mixed The value at the path
     */
    public static function get_value(array $array, string $path, mixed $default = null): mixed {
        $keys = explode('.', $path);
        $current = $array;

        foreach ($keys as $key) {
            if (!is_array($current) || !array_key_exists($key, $current)) {
                return $default;
            }
            $current = $current[$key];
        }

        return $current;
    }

    /**
     * Check if a node has a value
     *
     * @param array|null $node The node to check
     * @return bool Whether the node has a value
     */
    public static function node_has_value(?array $node): bool {
        return isset($node['value']);
    }

    /**
     * Get the value from a node
     *
     * @param array|null $node The node
     * @param mixed $default Default if no value
     * @return mixed The node value
     */
    public static function node_value(?array $node, mixed $default = ''): mixed {
        return $node['value'] ?? $default;
    }

    /**
     * Get an attribute from a node
     *
     * @param array|null $node The node
     * @param string $attr The attribute name
     * @param mixed $default Default if not found
     * @return mixed The attribute value
     */
    public static function node_attr(?array $node, string $attr, mixed $default = null): mixed {
        return $node['attr'][$attr] ?? $default;
    }
}
