<?php

namespace ErrorVault\Laravel\Exceptions;

use ErrorVault\Laravel\ErrorVault;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Throwable;

/**
 * @deprecated 1.5.1 No longer registered: the service provider now adds a
 * reportable() callback to the app's own handler. Removed in 2.0.
 */
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
        // Respect Laravel's own reporting filters (dontReport, shouldReport, etc.).
        if ($this->handler->shouldReport($e)) {
            $this->errorVault->report($e);
        }

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
