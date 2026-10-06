<?php

namespace Pterodactyl\Uptime\Services;

use Illuminate\Support\Facades\DB;
use Pterodactyl\Uptime\Models\UptimeKey;
use Pterodactyl\Uptime\Models\UptimeNode;
use Pterodactyl\Uptime\Models\UptimeEvent;

/**
 * Builds public proof documents (format uptime-server-proof/v1, see docs/verification.md
 * in the UpTime-Server repository). A proof contains the public keys, the signed events
 * of the range, the event just before it (anchor) and just after it (tail), and the
 * collector's uptime summary, which uptime-verify recomputes independently.
 */
class ProofBuilder
{
    public const PAGE_SIZE = 1000;

    public function __construct(private ReleaseInfo $release)
    {
    }

    public function build(UptimeNode $node, int $from, int $to, ?int $after, string $nextUrlBase): array
    {
        $now = StatusReport::nowMs();
        $to = min($to, $now);
        $from = min($from, $to - 1);

        $events = UptimeEvent::query()->where('uptime_node_id', $node->id)
            ->where('received_at', '>=', $from)->where('received_at', '<', $to)
            ->when($after, fn ($q) => $q->where('id', '>', $after))
            ->orderBy('id')->limit(self::PAGE_SIZE + 1)->get();
        $more = $events->count() > self::PAGE_SIZE;
        $events = $events->take(self::PAGE_SIZE);

        $anchor = UptimeEvent::query()->where('uptime_node_id', $node->id)->where('received_at', '<', $from)->orderByDesc('id')->first();
        $tail = $more ? null : UptimeEvent::query()->where('uptime_node_id', $node->id)->where('received_at', '>=', $to)->orderBy('id')->first();
        // The summary uses the whole range on every page, so the "open outage" rule needs
        // to know whether any event follows the range, independent of paging.
        $anyAfter = $tail ?? UptimeEvent::query()->where('uptime_node_id', $node->id)->where('received_at', '>=', $to)->exists();

        $covered = 0;
        $downtime = 0;
        if ($node->monitoring_started_at && $to > max($from, $node->monitoring_started_at)) {
            $start = max($from, $node->monitoring_started_at);
            $covered = $to - $start;
            $downtime = (int) DB::table('uptime_outages')->where('uptime_node_id', $node->id)
                ->where('end_ms', '>', $start)->where('start_ms', '<', $to)
                ->sum(DB::raw('LEAST(end_ms, ' . $to . ') - GREATEST(start_ms, ' . $start . ')'));
            if (!$anyAfter && $node->last_received_at) {
                $grace = $node->last_received_at + ($node->timeout_seconds + $node->tolerance_seconds) * 1000;
                $downtime += max(0, $to - max($grace, $start));
            }
            $downtime = min($downtime, $covered);
        }
        $outages = DB::table('uptime_outages')->where('uptime_node_id', $node->id)->where('end_ms', '>', $from)->where('start_ms', '<', $to)
            ->orderBy('start_ms')->limit(500)->get(['start_ms', 'end_ms', 'cause'])
            ->map(fn ($o) => ['start' => (int) $o->start_ms, 'end' => (int) $o->end_ms, 'cause' => $o->cause])->all();

        $release = $this->release->collector();

        return [
            'format' => 'uptime-server-proof/v1',
            'generated_at' => $now,
            'collector' => [
                'software' => 'UpTime-Server collector (Laravel)',
                'version' => $release['version'],
                'commit' => $release['commit'],
                'repository' => $release['repository'],
            ],
            'node' => [
                'id' => $node->public_id,
                'name' => $node->display_name,
                'monitoring_started_at' => $node->monitoring_started_at ?? 0,
                'params' => $node->params(),
            ],
            'keys' => UptimeKey::query()->where('uptime_node_id', $node->id)->orderBy('id')->get()->map(fn (UptimeKey $k) => [
                'fingerprint' => $k->fingerprint,
                'public_key' => $k->public_key,
                'algorithm' => 'ed25519',
                'registered_at' => $k->registered_at,
                'revoked_at' => $k->revoked_at,
            ])->all(),
            'range' => ['from' => $from, 'to' => $to],
            'anchor' => $anchor?->toProof(),
            'events' => $events->map(fn (UptimeEvent $e) => $e->toProof())->values()->all(),
            'tail' => $tail?->toProof(),
            'page' => [
                'complete' => !$more,
                'next' => $more ? $nextUrlBase . '?' . http_build_query(['from' => $from, 'to' => $to, 'after' => $events->last()->id]) : '',
            ],
            'summary' => [
                'events' => UptimeEvent::query()->where('uptime_node_id', $node->id)->where('received_at', '>=', $from)->where('received_at', '<', $to)->count(),
                'covered_ms' => $covered,
                'downtime_ms' => $downtime,
                'outages' => $outages,
            ],
        ];
    }
}
