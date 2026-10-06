<?php

namespace Pterodactyl\Uptime\Services;

use Illuminate\Support\Facades\DB;

/**
 * Collector settings (uptime_settings table). Nothing here is secret.
 */
class UptimeSettings
{
    public const DEFAULTS = [
        'repository_url' => 'https://github.com/LtAkmal/UpTime-Server',
        'status_page_enabled' => '1',
        // One published agent release per line: "<version> <sha256>".
        'trusted_builds' => '',
        'last_check_ms' => '',
    ];

    /** @var array<string, string|null>|null */
    private ?array $cache = null;

    public function get(string $key): string
    {
        $this->cache ??= DB::table('uptime_settings')->pluck('value', 'key')->all();

        return (string) ($this->cache[$key] ?? self::DEFAULTS[$key] ?? '');
    }

    public function set(string $key, ?string $value): void
    {
        DB::table('uptime_settings')->updateOrInsert(['key' => $key], ['value' => $value]);
        $this->cache = null;
    }

    public function repositoryUrl(): string
    {
        $url = rtrim($this->get('repository_url'), '/');

        return preg_match('#^https://[A-Za-z0-9.-]+(/[A-Za-z0-9._~/-]*)?$#', $url) === 1 ? $url : '';
    }

    /**
     * Published agent builds: sha256 => version.
     *
     * @return array<string, string>
     */
    public function trustedBuilds(): array
    {
        $builds = [];
        foreach (preg_split('/\R/', $this->get('trusted_builds')) ?: [] as $line) {
            if (preg_match('/^\s*([A-Za-z0-9._+-]{1,64})\s+([0-9a-f]{64})\s*$/', $line, $m) === 1) {
                $builds[$m[2]] = $m[1];
            }
        }

        return $builds;
    }

    public function statusPageEnabled(): bool
    {
        return $this->get('status_page_enabled') === '1';
    }
}
