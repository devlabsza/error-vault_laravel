<?php

namespace ErrorVault\Laravel;

use Illuminate\Support\ServiceProvider;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Console\Scheduling\Schedule;
use ErrorVault\Laravel\Console\SendHealthReportCommand;
use ErrorVault\Laravel\Console\TestConnectionCommand;
use ErrorVault\Laravel\Console\TestErrorCommand;
use ErrorVault\Laravel\Console\DiagnosticsCommand;
use ErrorVault\Laravel\Http\Middleware\TrackHealthRequests;

class ErrorVaultServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Merge config
        $this->mergeConfigFrom(
            __DIR__ . '/../config/errorvault.php',
            'errorvault'
        );

        // Register the health monitor as singleton
        $this->app->singleton(HealthMonitor::class, function ($app) {
            return new HealthMonitor(config('errorvault'));
        });

        // Register the main class as singleton
        $this->app->singleton(ErrorVault::class, function ($app) {
            $errorVault = new ErrorVault(config('errorvault'));

            // Inject health monitor
            $errorVault->setHealthMonitor($app->make(HealthMonitor::class));

            return $errorVault;
        });

        // Register aliases
        $this->app->alias(ErrorVault::class, 'errorvault');
        $this->app->alias(HealthMonitor::class, 'errorvault.health');
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Publish config
        $this->publishes([
            __DIR__ . '/../config/errorvault.php' => config_path('errorvault.php'),
        ], 'errorvault-config');

        // Register exception handler if enabled
        if (config('errorvault.enabled', false)) {
            $this->registerExceptionHandler();
        }

        // Register console commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                SendHealthReportCommand::class,
                TestConnectionCommand::class,
                TestErrorCommand::class,
                DiagnosticsCommand::class,
            ]);
        }

        // Schedule health reports if health monitoring is enabled
        $this->registerHealthSchedule();

        // Register middleware for request tracking
        $this->registerMiddleware();
    }

    /**
     * Register the exception handler
     */
    protected function registerExceptionHandler(): void
    {
        $this->app->extend(ExceptionHandler::class, function ($handler, $app) {
            return new Exceptions\ErrorVaultExceptionHandler($handler, $app->make(ErrorVault::class));
        });
    }

    /**
     * Register health report schedule
     */
    protected function registerHealthSchedule(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            // Heartbeat/ping every 5 minutes (always runs if ErrorVault is enabled)
            if (config('errorvault.enabled', false)) {
                $schedule->call(function () {
                    $errorVault = app(ErrorVault::class);
                    $errorVault->sendPing(false); // Non-blocking
                })
                    ->everyFiveMinutes()
                    ->name('errorvault-heartbeat')
                    ->withoutOverlapping()
                    ->runInBackground();
            }

            // Health monitoring schedule (only if health monitoring is enabled)
            if (config('errorvault.health_monitoring.enabled', false)) {
                $interval = (int) config('errorvault.health_monitoring.report_interval', 5);

                // Use cron expression for custom interval
                $schedule->command('errorvault:health-report --check')
                    ->cron("*/{$interval} * * * *")
                    ->withoutOverlapping()
                    ->runInBackground();
            }
        });
    }

    /**
     * Register middleware for request tracking
     */
    protected function registerMiddleware(): void
    {
        if (!config('errorvault.health_monitoring.enabled', false)) {
            return;
        }

        // Skip if running in console (artisan commands don't need request tracking)
        if ($this->app->runningInConsole()) {
            return;
        }

        // Register alias for optional manual use
        $router = $this->app['router'];
        $router->aliasMiddleware('errorvault.health', TrackHealthRequests::class);

        // Auto-register as global middleware (works across all Laravel versions)
        $kernel = $this->app->make(\Illuminate\Contracts\Http\Kernel::class);
        $kernel->pushMiddleware(TrackHealthRequests::class);
    }
}
