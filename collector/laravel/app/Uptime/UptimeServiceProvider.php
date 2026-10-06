<?php

namespace Pterodactyl\Uptime;

use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\RateLimiter;

/**
 * UpTime-Server collector for a Pterodactyl panel. Everything lives in app/Uptime; the
 * panel only registers this provider and links to the pages.
 */
class UptimeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/config.php', 'uptime');
        $this->app->singleton(Services\UptimeSettings::class);

        // Registered before any provider boots, so before the panel's own routes: the
        // React catch-all ("/{react}", login required) must never take /status.
        $this->app->booting(fn () => $this->loadRoutesFrom(__DIR__ . '/routes.php'));
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__ . '/views', 'uptime');
        $this->loadMigrationsFrom(__DIR__ . '/migrations');

        RateLimiter::for('uptime-public', fn (Request $r) => Limit::perMinute(120)->by($r->ip()));
        RateLimiter::for('uptime-enroll', fn (Request $r) => Limit::perMinute(10)->by($r->ip()));
        // Several agents may share one address (NAT, single host): 240 events per minute.
        RateLimiter::for('uptime-events', fn (Request $r) => Limit::perMinute(240)->by($r->ip()));

        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\VerifyChainCommand::class,
                Console\CheckCommand::class,
                Console\RebuildOutagesCommand::class,
            ]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('uptime:check')->everyMinute()->withoutOverlapping(5);
            $schedule->command('uptime:verify-chain --all --record')->hourly()->withoutOverlapping(60);
        });
    }
}
