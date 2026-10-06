<?php

namespace Pterodactyl\Uptime\Protocol;

/**
 * The published uptime formula (docs/uptime-formula.md), identical to protocol/uptime.go.
 * All times are Unix milliseconds (UTC); intervals are half-open [start, end).
 */
final class UptimeFormula
{
    public const CAUSE_NO_HEARTBEAT = 'no_heartbeat';
    public const CAUSE_REBOOT = 'reboot';

    /**
     * Confirmed downtime between two consecutive accepted events.
     *
     * @param array{received: int, type: string, mono: int} $prev
     * @param array{received: int, type: string, mono: int} $next
     * @param array{timeout_ms: int, tolerance_ms: int} $params
     *
     * @return list<array{start: int, end: int, cause: string}>
     */
    public static function gapOutages(array $prev, array $next, array $params): array
    {
        $out = [];
        $grace = $prev['received'] + $params['timeout_ms'] + $params['tolerance_ms'];
        if ($next['type'] === 'boot') {
            $bootAt = min($next['received'] - $next['mono'], $next['received']);
            if ($bootAt > $prev['received']) {
                $out[] = ['start' => $prev['received'], 'end' => $bootAt, 'cause' => self::CAUSE_REBOOT];
            }
        }
        if ($next['received'] > $grace) {
            if (count($out) === 1 && $out[0]['end'] >= $grace) {
                $out[0]['end'] = max($out[0]['end'], $next['received']);
            } else {
                $out[] = ['start' => $grace, 'end' => $next['received'], 'cause' => self::CAUSE_NO_HEARTBEAT];
            }
        }

        return $out;
    }

    /**
     * Ongoing downtime after the last event, if the node has been silent for longer
     * than timeout + tolerance at $endMs.
     *
     * @param array{timeout_ms: int, tolerance_ms: int} $params
     *
     * @return array{start: int, end: int, cause: string}|null
     */
    public static function openOutage(int $lastReceived, array $params, int $endMs): ?array
    {
        $grace = $lastReceived + $params['timeout_ms'] + $params['tolerance_ms'];

        return $endMs > $grace ? ['start' => $grace, 'end' => $endMs, 'cause' => self::CAUSE_NO_HEARTBEAT] : null;
    }

    /**
     * uptime % = (covered - downtime inside covered) / covered × 100, where covered is
     * the part of [from, to) after monitoring began. No coverage: uptime is null.
     *
     * @param iterable<array{start: int, end: int}> $outages
     *
     * @return array{from: int, to: int, covered_ms: int, downtime_ms: int, uptime_percent: float|null, coverage_percent: float}
     */
    public static function calculate(iterable $outages, ?int $monitoringStart, int $from, int $to): array
    {
        $w = ['from' => $from, 'to' => $to, 'covered_ms' => 0, 'downtime_ms' => 0, 'uptime_percent' => null, 'coverage_percent' => 0.0];
        if (!$monitoringStart || $to <= $from) {
            return $w;
        }
        $start = max($from, $monitoringStart);
        if ($start >= $to) {
            return $w;
        }
        $w['covered_ms'] = $to - $start;
        foreach ($outages as $o) {
            $s = max((int) $o['start'], $start);
            $e = min((int) $o['end'], $to);
            if ($e > $s) {
                $w['downtime_ms'] += $e - $s;
            }
        }
        $w['downtime_ms'] = min($w['downtime_ms'], $w['covered_ms']);
        $w['uptime_percent'] = ($w['covered_ms'] - $w['downtime_ms']) / $w['covered_ms'] * 100;
        $w['coverage_percent'] = $w['covered_ms'] / ($to - $from) * 100;

        return $w;
    }
}
