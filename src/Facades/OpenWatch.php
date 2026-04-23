<?php

namespace Dorvianes\OpenWatch\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static bool send(array $payload)
 */
class OpenWatch extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'openwatch.transport';
    }
}
