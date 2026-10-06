<?php

namespace Pterodactyl\Uptime\Http;

use Carbon\CarbonImmutable;

/**
 * Display helpers for the status views. Times are shown in UTC, and uptime is never
 * shown without its coverage when monitoring covers only part of a window.
 */
final class Format
{
    public static function percent(?float $value): string
    {
        if (is_null($value)) {
            return 'No data';
        }
        // Never round a value below 100 up to "100%".
        $shown = floor($value * 1000) / 1000;

        return rtrim(rtrim(number_format($shown, 3, '.', ''), '0'), '.') . '%';
    }

    public static function duration(int $ms): string
    {
        $s = intdiv(max(0, $ms), 1000);
        if ($s < 60) {
            return $s . ' s';
        }
        $parts = [];
        foreach (['d' => 86400, 'h' => 3600, 'min' => 60] as $unit => $size) {
            if ($s >= $size) {
                $parts[] = intdiv($s, $size) . ' ' . $unit;
                $s %= $size;
            }
        }
        if ($s > 0 && count($parts) < 2) {
            $parts[] = $s . ' s';
        }

        return implode(' ', array_slice($parts, 0, 2));
    }

    public static function time(?int $ms): string
    {
        return $ms ? CarbonImmutable::createFromTimestampMs($ms, 'UTC')->format('j M Y, H:i:s') . ' UTC' : '—';
    }

    public static function date(?int $ms): string
    {
        return $ms ? CarbonImmutable::createFromTimestampMs($ms, 'UTC')->format('j M Y') : '—';
    }

    public static function iso(?int $ms): string
    {
        return $ms ? CarbonImmutable::createFromTimestampMs($ms, 'UTC')->toIso8601ZuluString() : '';
    }

    public static function ago(?int $ms, int $now): string
    {
        if (!$ms) {
            return 'never';
        }

        return self::duration($now - $ms) . ' ago';
    }

    public static function stateTone(string $state): string
    {
        return match ($state) {
            'online' => 'ok',
            'degraded', 'delayed' => 'warn',
            'offline', 'verification_failed' => 'bad',
            default => '',
        };
    }

    public static function overallTone(string $overall): string
    {
        return match ($overall) {
            'operational' => 'ok',
            'degraded' => 'warn',
            'partial_outage', 'major_outage' => 'bad',
            default => '',
        };
    }

    public static function dayTone(array $day): string
    {
        if (is_null($day['uptime'])) {
            return '';
        }

        return $day['downtime_ms'] === 0 ? 'ok' : ($day['uptime'] >= 99 ? 'warn' : 'bad');
    }

    /**
     * Uptime with its coverage note, e.g. "99.982%" or "100% · covers 12% of window".
     */
    public static function window(array $w): array
    {
        $note = null;
        if (!is_null($w['uptime_percent']) && $w['coverage_percent'] < 99.995) {
            // Rounded down, but never shown as "0%" when monitoring covers part of the window.
            $pct = $w['coverage_percent'] < 0.1 ? '<0.1' : rtrim(rtrim(number_format(floor($w['coverage_percent'] * 10) / 10, 1, '.', ''), '0'), '.');
            $note = 'monitored ' . $pct . '% of window';
        }

        return [self::percent($w['uptime_percent']), $note];
    }

    public static function short(?string $hash, int $length = 12): string
    {
        return $hash ? substr($hash, 0, $length) . '…' : '—';
    }
}
