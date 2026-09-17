<?php

namespace ErrorVault\Laravel\Tests;

use ErrorVault\Laravel\ErrorVault;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ErrorVaultTraceTest extends TestCase
{
    public function test_exception_trace_never_contains_call_arguments(): void
    {
        $exception = $this->capture('super-secret-password');
        $vault = new ErrorVault(['send_immediately' => true]);
        $method = new \ReflectionMethod($vault, 'formatExceptionTrace');
        $trace = $method->invoke($vault, $exception);

        $this->assertStringContainsString('capture()', $trace);
        $this->assertStringNotContainsString('super-secret-password', $trace);
    }

    private function capture(string $secret): RuntimeException
    {
        try {
            throw new RuntimeException('safe message');
        } catch (RuntimeException $e) {
            return $e;
        }
    }
}
