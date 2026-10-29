<?php

namespace ErrorVault\Laravel\Tests;

use ErrorVault\Laravel\ErrorVault;
use ErrorVault\Laravel\ErrorVaultServiceProvider;
use ErrorVault\Laravel\Exceptions\ErrorVaultExceptionHandler;
use ErrorVault\Laravel\HealthMonitor;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;
use RuntimeException;

/**
 * Boots the package inside a real Laravel application, so CI catches
 * framework-version incompatibilities the unit tests cannot.
 */
class ServiceProviderTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ErrorVaultServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('errorvault.enabled', true);
        $app['config']->set('errorvault.api_endpoint', 'https://errorvault.test/api/v1/errors');
        $app['config']->set('errorvault.api_token', 'test-token');
        $app['config']->set('errorvault.backup.enabled', true);
    }

    public function test_services_and_commands_are_registered(): void
    {
        $this->assertInstanceOf(ErrorVault::class, $this->app->make('errorvault'));
        $this->assertInstanceOf(HealthMonitor::class, $this->app->make('errorvault.health'));

        $commands = array_keys(Artisan::all());
        foreach (['errorvault:health-report', 'errorvault:test-connection', 'errorvault:test-error', 'errorvault:diagnostics', 'errorvault:run-backup'] as $command) {
            $this->assertContains($command, $commands);
        }
    }

    public function test_exception_handler_reports_to_errorvault(): void
    {
        Http::fake();

        $handler = $this->app->make(ExceptionHandler::class);
        $this->assertInstanceOf(ErrorVaultExceptionHandler::class, $handler);

        $handler->report(new RuntimeException('boom'));

        Http::assertSent(fn ($request) => $request->url() === 'https://errorvault.test/api/v1/errors'
            && $request['message'] === 'boom');
    }

    public function test_heartbeat_and_backup_poll_are_scheduled(): void
    {
        $names = collect($this->app->make(Schedule::class)->events())
            ->map(fn ($event) => $event->description)
            ->all();

        $this->assertContains('errorvault-heartbeat', $names);
        $this->assertContains('errorvault-backup-poll', $names);
    }
}
