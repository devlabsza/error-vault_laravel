<?php

namespace ErrorVault\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use ErrorVault\Laravel\HealthMonitor;
use Symfony\Component\HttpFoundation\Response;

class TrackHealthRequests
{
    /**
     * The health monitor instance.
     */
    protected HealthMonitor $healthMonitor;

    /**
     * Create a new middleware instance.
     */
    public function __construct(HealthMonitor $healthMonitor)
    {
        $this->healthMonitor = $healthMonitor;
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Track this request for health monitoring
        $this->healthMonitor->trackRequest();

        return $next($request);
    }
}
