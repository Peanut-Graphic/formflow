<?php

namespace ISF\Tests\Helpers;

/**
 * Thrown from a fake store to halt a handler at a chosen write (extends \Error
 * so the handlers' catch (\Exception) blocks do not swallow it).
 */
final class HaltAtWrite extends \Error
{
    public array $data;

    public function __construct(array $data)
    {
        parent::__construct('halt');
        $this->data = $data;
    }
}
