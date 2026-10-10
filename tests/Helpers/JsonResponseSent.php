<?php

namespace ISF\Tests\Helpers;

/**
 * Thrown by wp_send_json_* stubs so a handler stops where WordPress would die().
 *
 * Extends \Error (not \Exception) so a handler's catch (\Exception) around a
 * success response cannot swallow it — matching wp_send_json_*()'s exit.
 */
final class JsonResponseSent extends \Error
{
    public bool $ok;
    /** @var mixed */
    public $payload;

    public function __construct(bool $ok, $payload)
    {
        parent::__construct($ok ? 'json-success' : 'json-error');
        $this->ok      = $ok;
        $this->payload = $payload;
    }
}
