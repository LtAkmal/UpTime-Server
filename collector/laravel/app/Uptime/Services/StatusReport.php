<?php

namespace Pterodactyl\Uptime\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Uptime\Models\UptimeNode;
use Pterodactyl\Uptime\Models\UptimeEvent;
use Pterodactyl\Uptime\Models\UptimeOutage;
use Pterodactyl\Uptime\Models\UptimeAnomaly;
use Pterodactyl\Uptime\Protocol\UptimeFormula;

/**
 * Public status and uptime figures, computed from accepted events and the derived
 * outages with the published formula. Nothing here can be set by hand.
 */
class StatusReport
{
    public const STATES = [
        'online' => ['Online', 'A valid signed heartbeat arrived within the heartbeat timeout.'],
        'degraded' => ['Degraded', 'Signed heartbeats arrive, but the agent reports failed local health checks.'],
        'delayed' => ['Monitoring delayed', 'The last heartbeat is older than the timeout. Downtime is counted only after the tolerance as well.'],
        'offline' => ['Offline', 'No valid signed heartbeat within the timeout plus tolerance.'],
        'verification_failed' => ['Verification failed', 'Stored records failed integrity verification, so figures for this node are not verified.'],
        'disabled' => ['Monitoring disabled', 'An administrator disabled monitoring for this node.'],
        'no_data' => ['Not enough data', 'Monitoring has not received its first signed event yet.'],
    ];

    public const OVERALL = [
        'operational' => 'All systems operational',
        'degraded' => 'Degraded performance',
        'partial_outage' => 'Partial outage',
        'major_outage' => 'Major outage',
        'unavailable' => 'Monitoring unavailable',
        'no_data' => 'Monitoring not started',
    ];

    public const WINDOWS = ['24h' => 86_400_000, '7d' => 604_800_000, '30d' => 2_592_000_000];

    /** Collector checks older than this make the overall state "monitoring unavailable". */
    public const COLLECTOR_STALE_MS = 600_000;

    public function __construct(private UptimeSettings $settings)
    {
    }

    public static function nowMs(): int
    {
        return CarbonImmutable::now()->getTimestampMs();
    }

    public function state(UptimeNode $node, int $now): string
    {
        if (!$node->isActive()) {
            return 'disabled';
        }
        if ($node->verification_state === UptimeNode::VERIFICATION_FAILED) {
            return 'verification_failed';
        }
        if (is_null($node->last_received_at)) {
            return 'no_data';
        }
        $age = $now - $node->last_received_at;
        if ($age > ($node->timeout_seconds + $node->tolerance_seconds) * 1000) {
            return 'offline';
        }
        if ($age > $node->timeout_seconds * 1000 || $node->lastEvent?->type === 'stop') {
            return 'delayed';
        }

        return $node->lastEvent?->status === 'degraded' ? 'degraded' : 'online';
    }

    /**
     * Confirmed downtime inside [from, to): stored outages clipped to the window plus
     * the ongoing silence after the last event.
     */
    public function downtimeMs(UptimeNode $node, int $from, int $to): int
    {
        $stored = (int) DB::table('uptime_outages')
            ->where('uptime_node_id', $node->id)
            ->where('end_ms', '>', $from)
            ->where('start_ms', '<', $to)
            ->sum(DB::raw('LEAST(end_ms, ' . $to . ') - GREATEST(start_ms, ' . $from . ')'));
        if ($node->last_received_at && ($open = UptimeFormula::openOutage($node->last_received_at, $node->params(), $to))) {
            $stored += max(0, min($open['end'], $to) - max($open['start'], $from));
        }

        return $stored;
    }

    /**
     * The formula for one window. Outages only exist after monitoring began, so the
     * clipped downtime is already inside the covered part.
     */
    public function window(UptimeNode $node, int $from, int $to): array
    {
        $w = UptimeFormula::calculate([], $node->monitoring_started_at, $from, $to);
        if ($w['covered_ms'] > 0) {
            $w['downtime_ms'] = min($this->downtimeMs($node, max($from, (int) $node->monitoring_started_at), $to), $w['covered_ms']);
            $w['uptime_percent'] = ($w['covered_ms'] - $w['downtime_ms']) / $w['covered_ms'] * 100;
        }

        return $w;
    }

    /**
     * Uptime for the last 24 hours, 7 days, 30 days and since monitoring began.
     *
     * @return array<string, array>
     */
    public function windows(UptimeNode $node, int $now): array
    {
        $out = [];
        foreach (self::WINDOWS as $name => $length) {
            $out[$name] = $this->window($node, $now - $length, $now);
        }
        $out['all'] = $this->window($node, $node->monitoring_started_at ?? $now, $now);

        return $out;
    }

    /**
     * Per-day (UTC) uptime for the history bars. Days before monitoring began have no
     * data rather than 100%.
     *
     * @return list<array{date: string, uptime: float|null, downtime_ms: int, coverage: float}>
     */
    public function daily(UptimeNode $node, int $now, int $days = 30): array
    {
        $today = CarbonImmutable::createFromTimestampMs($now, 'UTC')->startOfDay();
        $first = $today->subDays($days - 1);
        $outages = UptimeOutage::query()->where('uptime_node_id', $node->id)->where('end_ms', '>', $first->getTimestampMs())
            ->get(['start_ms', 'end_ms'])->map(fn ($o) => ['start' => $o->start_ms, 'end' => $o->end_ms])->all();
        if ($node->last_received_at && ($open = UptimeFormula::openOutage($node->last_received_at, $node->params(), $now))) {
            $outages[] = $open;
        }
        $result = [];
        for ($d = $first; $d <= $today; $d = $d->addDay()) {
            $from = $d->getTimestampMs();
            $w = UptimeFormula::calculate($outages, $node->monitoring_started_at, $from, min($d->addDay()->getTimestampMs(), $now));
            $result[] = ['date' => $d->toDateString(), 'uptime' => $w['uptime_percent'], 'downtime_ms' => $w['downtime_ms'], 'coverage' => $w['coverage_percent']];
        }

        return $result;
    }

    /**
     * @return array{start: int, end: int, cause: string}|null
     */
    public function currentOutage(UptimeNode $node, int $now): ?array
    {
        return $node->last_received_at && $node->isActive() ? UptimeFormula::openOutage($node->last_received_at, $node->params(), $now) : null;
    }

    public function hasPublicAnomaly(UptimeNode $node): bool
    {
        return UptimeAnomaly::query()->where('uptime_node_id', $node->id)->whereNull('resolved_at')
            ->whereIn('severity', [UptimeAnomaly::WARNING, UptimeAnomaly::CRITICAL])->exists();
    }

    public function collectorHealthy(int $now): bool
    {
        $last = (int) $this->settings->get('last_check_ms');

        return $last > 0 && $now - $last <= self::COLLECTOR_STALE_MS;
    }

    /**
     * Public summary of one node. No internal identifiers, addresses or admin data.
     */
    public function node(UptimeNode $node, int $now, bool $withHistory = true): array
    {
        $state = $this->state($node, $now);
        $last = $node->lastEvent;
        $windows = $this->windows($node, $now);
        $current = $this->currentOutage($node, $now);
        $incidents = UptimeOutage::query()->where('uptime_node_id', $node->id)->count() + ($current ? 1 : 0);

        return [
            'id' => $node->public_id,
            'name' => $node->display_name,
            'state' => $state,
            'state_label' => self::STATES[$state][0],
            'state_description' => self::STATES[$state][1],
            'verified' => $node->verification_state === UptimeNode::VERIFICATION_VALID,
            'verification' => [
                'state' => $node->verification_state,
                'checked_at' => $node->verified_at?->toIso8601String(),
                'events_checked' => $node->verified_events,
                'not_verified_from' => $node->verification_failed_at ? CarbonImmutable::createFromTimestampMs($node->verification_failed_at)->toIso8601String() : null,
            ],
            'anomaly' => $this->hasPublicAnomaly($node),
            'monitoring_started_at' => $node->monitoring_started_at,
            'last_heartbeat_at' => $node->last_received_at,
            'events' => $node->events_count,
            'params' => $node->params(),
            'windows' => $windows,
            'incidents' => $incidents,
            'current_incident' => $current,
            'agent' => $last ? [
                'version' => $last->agent_version,
                'commit' => $last->agent_commit,
                'sha256' => $last->agent_sha256,
                'published_release' => $this->settings->trustedBuilds()[$last->agent_sha256] ?? null,
            ] : null,
            'key_fingerprint' => $node->keys()->where('status', 'active')->value('fingerprint'),
            'history' => $withHistory ? $this->daily($node, $now) : [],
        ];
    }

    /**
     * @param list<array> $nodes public node summaries
     */
    public function overall(array $nodes, int $now): string
    {
        $monitored = array_filter($nodes, fn ($n) => $n['state'] !== 'disabled');
        if ($monitored === [] || count(array_filter($monitored, fn ($n) => $n['state'] === 'no_data')) === count($monitored)) {
            return 'no_data';
        }
        if (!$this->collectorHealthy($now)) {
            return 'unavailable';
        }
        $count = fn (array $states) => count(array_filter($monitored, fn ($n) => in_array($n['state'], $states, true)));
        $offline = $count(['offline']);
        if ($offline > 0) {
            return $offline === count($monitored) ? 'major_outage' : 'partial_outage';
        }
        if ($count(['degraded', 'delayed', 'verification_failed']) > 0) {
            return 'degraded';
        }

        return 'operational';
    }

    /**
     * Recent events for the public log summary.
     */
    public function recentEvents(UptimeNode $node, int $limit = 25): array
    {
        return UptimeEvent::query()->where('uptime_node_id', $node->id)->orderByDesc('id')->limit($limit)
            ->get(['id', 'seq', 'type', 'status', 'received_at', 'event_hash', 'agent_version'])
            ->map(fn (UptimeEvent $e) => [
                'id' => $e->id, 'seq' => $e->seq, 'type' => $e->type, 'status' => $e->status,
                'received_at' => $e->received_at, 'event_hash' => $e->event_hash, 'agent_version' => $e->agent_version,
            ])->all();
    }
}
