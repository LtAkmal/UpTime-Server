<?php

namespace Pterodactyl\Uptime\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Contracts\View\View;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Uptime\Models\UptimeNode;
use Pterodactyl\Uptime\Models\UptimeNote;
use Pterodactyl\Uptime\Models\UptimeOutage;
use Pterodactyl\Uptime\Services\ReleaseInfo;
use Pterodactyl\Uptime\Services\ProofBuilder;
use Pterodactyl\Uptime\Services\StatusReport;
use Pterodactyl\Uptime\Services\UptimeSettings;

/**
 * Public status page and read-only public API. Only public node data is used: display
 * name, public id, state, figures, keys' public parts and signed events.
 */
class StatusController extends Controller
{
    public function __construct(
        private StatusReport $report,
        private ReleaseInfo $release,
        private UptimeSettings $settings,
    ) {
    }

    public function index(): View
    {
        abort_unless($this->settings->statusPageEnabled(), 404);
        $data = $this->summary();

        return view('uptime::public.index', $data + [
            'release' => $this->release->collector(),
            'repository' => $this->settings->repositoryUrl(),
            'commitUrl' => fn (string $c) => $this->release->commitUrl($c),
            'layout' => config('uptime.public_layout', 'storefront.layout'),
        ]);
    }

    public function node(string $publicId): View
    {
        abort_unless($this->settings->statusPageEnabled(), 404);
        $node = $this->find($publicId);
        $now = StatusReport::nowMs();

        return view('uptime::public.node', [
            'node' => $this->report->node($node, $now),
            'incidents' => $this->incidents($node, $now),
            'recent' => $this->report->recentEvents($node),
            'keys' => $node->keys()->orderBy('id')->get(['fingerprint', 'status', 'registered_at', 'revoked_at']),
            'now' => $now,
            'release' => $this->release->collector(),
            'repository' => $this->settings->repositoryUrl(),
            'commitUrl' => fn (string $c) => $this->release->commitUrl($c),
            'layout' => config('uptime.public_layout', 'storefront.layout'),
        ]);
    }

    public function stylesheet(): Response
    {
        return response((string) file_get_contents(__DIR__ . '/../../assets/uptime.css'), 200, [
            'Content-Type' => 'text/css; charset=utf-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    public function apiSummary(): JsonResponse
    {
        abort_unless($this->settings->statusPageEnabled(), 404);
        $data = $this->summary(false);
        $release = $this->release->collector();

        return response()->json([
            'generated_at' => $data['now'],
            'overall' => $data['overall'],
            'overall_label' => StatusReport::OVERALL[$data['overall']],
            'counts' => $data['counts'],
            'nodes' => $data['nodes'],
            'collector' => ['version' => $release['version'], 'commit' => $release['commit'], 'repository' => $release['repository'], 'files_match_release' => $release['matches']],
            'independent_monitors' => 0,
        ]);
    }

    public function apiNode(string $publicId): JsonResponse
    {
        abort_unless($this->settings->statusPageEnabled(), 404);
        $node = $this->find($publicId);
        $now = StatusReport::nowMs();

        return response()->json($this->report->node($node, $now) + ['incidents_list' => $this->incidents($node, $now)]);
    }

    public function apiProof(Request $request, ProofBuilder $proofs, string $publicId): JsonResponse
    {
        abort_unless($this->settings->statusPageEnabled(), 404);
        $node = $this->find($publicId);
        $now = StatusReport::nowMs();
        $to = $this->time($request->query('to')) ?? $now;
        $from = $this->time($request->query('from')) ?? $to - 86_400_000;
        $after = ctype_digit((string) $request->query('after', '')) ? (int) $request->query('after') : null;
        if ($from >= $to) {
            return response()->json(['error' => 'invalid_range', 'message' => 'from must be before to.'], 422);
        }

        return response()->json($proofs->build($node, $from, $to, $after, url('/api/status/nodes/' . $node->public_id . '/proof')))
            ->header('Cache-Control', 'no-store');
    }

    private function find(string $publicId): UptimeNode
    {
        return UptimeNode::query()->with('lastEvent')->where('public_id', $publicId)->where('is_public', true)->whereNull('archived_at')->firstOrFail();
    }

    private function summary(bool $withHistory = true): array
    {
        $now = StatusReport::nowMs();
        $nodes = UptimeNode::query()->with('lastEvent')->where('is_public', true)->whereNull('archived_at')->orderBy('display_name')->get()
            ->map(fn (UptimeNode $n) => $this->report->node($n, $now, $withHistory))->all();
        $count = fn (array $states) => count(array_filter($nodes, fn ($n) => in_array($n['state'], $states, true)));

        return [
            'now' => $now,
            'nodes' => $nodes,
            'overall' => $this->report->overall($nodes, $now),
            'counts' => [
                'monitored' => $count(array_keys(array_diff_key(StatusReport::STATES, ['disabled' => 1]))),
                'online' => $count(['online']),
                'degraded' => $count(['degraded', 'delayed']),
                'offline' => $count(['offline']),
                'verification_failed' => $count(['verification_failed']),
                'incidents' => count(array_filter($nodes, fn ($n) => !is_null($n['current_incident']))),
            ],
            'collectorHealthy' => $this->report->collectorHealthy($now),
        ];
    }

    private function incidents(UptimeNode $node, int $now): array
    {
        $notes = UptimeNote::query()->where('uptime_node_id', $node->id)->get()->groupBy('uptime_outage_id');
        $list = UptimeOutage::query()->where('uptime_node_id', $node->id)->orderByDesc('start_ms')->limit(50)->get()
            ->map(fn (UptimeOutage $o) => [
                'start' => $o->start_ms, 'end' => $o->end_ms, 'duration_ms' => $o->durationMs(), 'cause' => $o->cause, 'ongoing' => false,
                'notes' => ($notes[$o->id] ?? collect())->map(fn (UptimeNote $n) => ['body' => $n->body, 'at' => $n->created_at->toIso8601String()])->values()->all(),
            ])->all();
        if ($current = $this->report->currentOutage($node, $now)) {
            array_unshift($list, ['start' => $current['start'], 'end' => null, 'duration_ms' => $current['end'] - $current['start'], 'cause' => $current['cause'], 'ongoing' => true, 'notes' => []]);
        }

        return $list;
    }

    private function time(mixed $value): ?int
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        if (ctype_digit($value)) {
            return (int) $value;
        }

        try {
            return CarbonImmutable::parse($value, 'UTC')->getTimestampMs();
        } catch (\Throwable) {
            return null;
        }
    }
}
