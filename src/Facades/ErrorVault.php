<?php

namespace ErrorVault\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static bool isEnabled()
 * @method static bool report(\Throwable $exception, array $context = [])
 * @method static bool reportError(string $message, string $severity = 'error', ?string $file = null, ?int $line = null, array $context = [])
 * @method static void flush()
 * @method static array verify()
 * @method static array|null stats()
 * @method static mixed config(string $key, $default = null)
 *
 * @see \ErrorVault\Laravel\ErrorVault
 */
class ErrorVault extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return \ErrorVault\Laravel\ErrorVault::class;
    }
}
