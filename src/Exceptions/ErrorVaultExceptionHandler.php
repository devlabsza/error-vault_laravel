<?php

namespace ErrorVault\Laravel\Exceptions;

use ErrorVault\Laravel\ErrorVault;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Throwable;

class ErrorVaultExceptionHandler implements ExceptionHandler
{
    /**
     * The original exception handler
     */
    protected ExceptionHandler $handler;

    /**
     * The ErrorVault instance
     */
    protected ErrorVault $errorVault;

    /**
     * Constructor
     */
    public function __construct(ExceptionHandler $handler, ErrorVault $errorVault)
    {
        $this->handler = $handler;
        $this->errorVault = $errorVault;
    }

    /**
     * Report an exception
     */
    public function report(Throwable $e): void
    {
        // Send to ErrorVault
        $this->errorVault->report($e);

        // Call original handler
        $this->handler->report($e);
    }

    /**
     * Determine if the exception should be reported
     */
    public function shouldReport(Throwable $e): bool
    {
        return $this->handler->shouldReport($e);
    }

    /**
     * Render an exception into an HTTP response
     */
    public function render($request, Throwable $e)
    {
        return $this->handler->render($request, $e);
    }

    /**
     * Render an exception for the console
     */
    public function renderForConsole($output, Throwable $e): void
    {
        $this->handler->renderForConsole($output, $e);
    }
}
