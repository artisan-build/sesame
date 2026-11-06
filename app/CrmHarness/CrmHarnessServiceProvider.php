<?php

namespace App\CrmHarness;

use Aws\Sns\MessageValidator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * LOCAL CRM-COMPATIBLE HARNESS. Mirrors the brief's "CRM-side wiring" so the
 * loop can close without the real Growth staging app. The same validator
 * binding and production guard are what Growth itself needs.
 */
class CrmHarnessServiceProvider extends ServiceProvider
{
    /** @var array<string, string> certificates by URL, kept across Octane requests */
    private static array $certificates = [];

    #[\Override]
    public function register(): void
    {
        // Resolve MessageValidator from the container everywhere so the host
        // pattern can be swapped. An empty pattern falls back to amazonaws.com.
        $this->app->singleton(MessageValidator::class, fn (): MessageValidator => new MessageValidator(
            static fn (string $url): string => self::$certificates[$url] ??= Http::connectTimeout(3)->timeout(5)
                ->withOptions(['allow_redirects' => false, 'verify' => config('services.sns.ca_bundle') ?: true])
                ->get($url)->throw()->body(),
            hostNamePattern: config('services.sns.cert_host_pattern', ''),
        ));
    }

    public function boot(): void
    {
        self::guard($this->app->isProduction());
        if ($this->app->isProduction()) {
            return; // the harness never exists on production
        }
        Route::post('/crm-harness/sns', SnsWebhookController::class);
    }

    /** Production refuses to boot if its config points at the simulator. */
    public static function guard(bool $production): void
    {
        if ($production && (config('services.ses.endpoint') || config('services.sns.cert_host_pattern'))) {
            throw new RuntimeException('SES simulator config present in production.');
        }
    }

    public static function flushCertificates(): void
    {
        self::$certificates = [];
    }
}
