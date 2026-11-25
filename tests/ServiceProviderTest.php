<?php

namespace ErrorVault\Laravel\Tests;

use ErrorVault\Laravel\ErrorVault;
use ErrorVault\Laravel\ErrorVaultServiceProvider;
use ErrorVault\Laravel\HealthMonitor;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Support\Facades\Log;
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

        $this->app->make(ExceptionHandler::class)->report(new RuntimeException('boom'));

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === 'https://errorvault.test/api/v1/errors'
            && $request['message'] === 'boom');
    }

    /**
     * bootstrap/app.php's withExceptions() registers its callback with
     * afterResolving(Handler::class). It must still run with the package
     * installed, or the app's own report/render/ignore rules are lost.
     */
    public function test_app_exception_configuration_still_runs(): void
    {
        $this->app->forgetInstance(ExceptionHandler::class);
        $ran = false;
        $this->app->afterResolving(Handler::class, function () use (&$ran) {
            $ran = true;
        });

        $handler = $this->app->make(ExceptionHandler::class);

        $this->assertInstanceOf(Handler::class, $handler);
        $this->assertTrue($ran, 'withExceptions() callback did not run');
    }

    public function test_ignored_exceptions_are_not_sent(): void
    {
        Http::fake();

        $handler = $this->app->make(ExceptionHandler::class);
        $handler->ignore(IgnoredByApp::class);
        $handler->report(new IgnoredByApp('quiet'));

        Http::assertNothingSent();
    }

    /**
     * Collision resolves the app's handler early, then binds its own wrapper,
     * which passes reportable() through. Reporting must still reach Error-Vault.
     */
    public function test_reports_through_a_wrapper_that_passes_reportable_through(): void
    {
        Http::fake();
        $this->app->forgetInstance(ExceptionHandler::class);
        $inner = $this->app->make(Handler::class);
        $this->app->instance(ExceptionHandler::class, new PassThroughHandler($inner));

        $provider = new ErrorVaultServiceProvider($this->app);
        (fn () => $this->registerExceptionHandler())->call($provider);
        $this->app->make(ExceptionHandler::class)->report(new RuntimeException('via wrapper'));

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['message'] === 'via wrapper');
    }

    /**
     * When the handler is first built inside callAfterResolving() (as with
     * Collision's console wrapper), Laravel runs the callback twice.
     */
    public function test_handler_built_during_registration_reports_once(): void
    {
        Http::fake();
        $this->app->forgetInstance(ExceptionHandler::class);
        $inner = $this->app->make(Handler::class);
        $this->app->singleton(ExceptionHandler::class, fn () => new PassThroughHandler($inner));

        $provider = new ErrorVaultServiceProvider($this->app);
        (fn () => $this->registerExceptionHandler())->call($provider);
        $this->app->make(ExceptionHandler::class)->report(new RuntimeException('once'));

        Http::assertSentCount(1);
    }

    public function test_errorvault_failure_does_not_stop_laravel_logging(): void
    {
        $this->app->instance(ErrorVault::class, new class([]) extends ErrorVault {
            public function report(\Throwable $exception, array $context = []): bool
            {
                throw new RuntimeException('portal client broke');
            }
        });
        Log::spy();

        $this->app->make(ExceptionHandler::class)->report(new RuntimeException('original'));

        Log::shouldHaveReceived('error')->withArgs(fn ($message) => $message === 'original')->once();
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

class IgnoredByApp extends RuntimeException
{
}

/** Shaped like Collision's console handler: the contract plus a forwarded reportable(). */
class PassThroughHandler implements ExceptionHandler
{
    public function __construct(private Handler $inner)
    {
    }

    public function report(\Throwable $e)
    {
        $this->inner->report($e);
    }

    public function shouldReport(\Throwable $e)
    {
        return $this->inner->shouldReport($e);
    }

    public function render($request, \Throwable $e)
    {
        return $this->inner->render($request, $e);
    }

    public function renderForConsole($output, \Throwable $e)
    {
        $this->inner->renderForConsole($output, $e);
    }

    public function reportable(callable $reportUsing)
    {
        return $this->inner->reportable($reportUsing);
    }
}
