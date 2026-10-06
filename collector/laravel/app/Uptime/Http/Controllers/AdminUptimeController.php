<?php

namespace Pterodactyl\Uptime\Http\Controllers;

use Carbon\CarbonImmutable;
use Pterodactyl\Models\Node;
use Illuminate\Http\Request;
use Pterodactyl\Facades\Activity;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Prologue\Alerts\AlertsMessageBag;
use Pterodactyl\Uptime\Models\UptimeKey;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Uptime\Models\UptimeNode;
use Pterodactyl\Uptime\Models\UptimeNote;
use Pterodactyl\Uptime\Models\UptimeEvent;
use Pterodactyl\Uptime\Models\UptimeOutage;
use Pterodactyl\Uptime\Models\UptimeAnomaly;
use Pterodactyl\Uptime\Services\ReleaseInfo;
use Pterodactyl\Uptime\Services\StatusReport;
use Pterodactyl\Uptime\Services\UptimeAlerts;
use Pterodactyl\Uptime\Services\ChainVerifier;
use Pterodactyl\Uptime\Services\UptimeSettings;
use Pterodactyl\Uptime\Services\EnrollmentService;

/**
 * Admin → Uptime Monitoring (root administrators only, CSRF-protected forms, every
 * change audited). There is deliberately no way to edit events, hashes, timestamps,
 * outages, uptime figures or verification results here.
 */
class AdminUptimeController extends Controller
{
    public function __construct(
        private AlertsMessageBag $alert,
        private StatusReport $report,
        private UptimeSettings $settings,
        private EnrollmentService $enrollment,
    ) {
    }

    public function index(): View
    {
        $now = StatusReport::nowMs();
        $nodes = UptimeNode::query()->with('lastEvent')->orderByRaw('archived_at IS NOT NULL')->orderBy('display_name')->get();

        return view('uptime::admin.index', [
            'nodes' => $nodes,
            'states' => $nodes->mapWithKeys(fn (UptimeNode $n) => [$n->id => $this->report->state($n, $now)]),
            'uptime' => $nodes->mapWithKeys(fn (UptimeNode $n) => [$n->id => $this->report->window($n, $now - StatusReport::WINDOWS['24h'], $now)]),
            'anomalies' => UptimeAnomaly::query()->whereNull('resolved_at')->orderByDesc('last_seen_at')->limit(50)->get(),
            'collectorHealthy' => $this->report->collectorHealthy($now),
            'now' => $now,
        ]);
    }

    public function create(): View
    {
        return view('uptime::admin.create', ['pteroNodes' => Node::query()->orderBy('name')->get(['id', 'name'])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateNode($request, true);
        $node = UptimeNode::query()->create($data + ['public_id' => 'nd-' . bin2hex(random_bytes(5))]);
        Activity::event('admin:uptime.node.created')->actor($request->user())->property(['node' => $node->public_id, 'name' => $node->display_name])->log();
        $this->alert->success('Monitored node created. Generate an enrollment token to connect its agent.')->flash();

        return redirect()->route('admin.uptime.nodes.view', $node->id);
    }

    public function view(Request $request, UptimeNode $node): View
    {
        $now = StatusReport::nowMs();

        return view('uptime::admin.node', [
            'node' => $node->loadMissing('lastEvent'),
            'summary' => $this->report->node($node, $now),
            'keys' => $node->keys()->orderByDesc('id')->get(),
            'outages' => UptimeOutage::query()->where('uptime_node_id', $node->id)->orderByDesc('start_ms')->limit(30)->get(),
            'notes' => UptimeNote::query()->where('uptime_node_id', $node->id)->orderByDesc('id')->get()->groupBy('uptime_outage_id'),
            'anomalies' => UptimeAnomaly::query()->where('uptime_node_id', $node->id)->orderByDesc('last_seen_at')->limit(30)->get(),
            'events' => UptimeEvent::query()->where('uptime_node_id', $node->id)->orderByDesc('id')->limit(15)->get(),
            'pteroNodes' => Node::query()->orderBy('name')->get(['id', 'name']),
            'token' => $request->session()->get('uptime_token'),
            'publicUrl' => url('/status/nodes/' . $node->public_id),
            'collectorUrl' => url('/'),
            'release' => app(ReleaseInfo::class)->collector(),
            'now' => $now,
        ]);
    }

    public function update(Request $request, UptimeNode $node, UptimeAlerts $alerts): RedirectResponse
    {
        abort_if((bool) $node->archived_at, 404);
        $data = $this->validateNode($request, !$node->parametersLocked());
        $before = $node->only(array_keys($data));
        $node->forceFill($data)->save();
        $changes = array_filter($data, fn ($v, $k) => $before[$k] != $v, ARRAY_FILTER_USE_BOTH);
        Activity::event('admin:uptime.node.updated')->actor($request->user())->property(['node' => $node->public_id, 'changes' => $changes])->log();
        if (array_key_exists('monitoring_enabled', $changes) && !$node->monitoring_enabled) {
            $alerts->anomaly($node, 'monitoring_disabled', UptimeAnomaly::NOTICE, "Monitoring disabled by {$request->user()->username}.", null, true, true);
        }
        $this->alert->success('Node settings saved.')->flash();

        return redirect()->route('admin.uptime.nodes.view', $node->id);
    }

    public function token(Request $request, UptimeNode $node): RedirectResponse
    {
        abort_if((bool) $node->archived_at, 404);
        $token = $this->enrollment->createToken($node, $request->user());

        // Shown once on the next page view only; never stored in plain text.
        return redirect()->route('admin.uptime.nodes.view', $node->id)->with('uptime_token', $token);
    }

    public function registerKey(Request $request, UptimeNode $node): RedirectResponse
    {
        abort_if((bool) $node->archived_at, 404);
        $request->validate(['public_key' => 'required|string|max:64', 'confirm' => 'accepted']);
        try {
            $key = $this->enrollment->registerManual($node, (string) $request->input('public_key'), $request->user());
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()->withErrors(['public_key' => $e->getMessage()]);
        }
        $this->alert->success("Key {$key->fingerprint} registered. On the node run: uptime-agent set-node --node {$node->public_id}")->flash();

        return redirect()->route('admin.uptime.nodes.view', $node->id);
    }

    public function revokeKey(Request $request, UptimeNode $node, UptimeKey $key): RedirectResponse
    {
        abort_unless($key->uptime_node_id === $node->id, 404);
        $data = $request->validate(['reason' => 'required|string|min:5|max:255', 'confirm' => 'accepted']);
        $this->enrollment->revoke($key, $data['reason'], $request->user());
        $this->alert->success('Key revoked. Events signed with it are rejected from now on; earlier events stay valid.')->flash();

        return redirect()->route('admin.uptime.nodes.view', $node->id);
    }

    public function verify(Request $request, UptimeNode $node, ChainVerifier $verifier): RedirectResponse
    {
        $result = $verifier->record($node);
        Activity::event('admin:uptime.node.verified')->actor($request->user())->property(['node' => $node->public_id, 'valid' => $result['valid'], 'checked' => $result['checked']])->log();
        $result['valid']
            ? $this->alert->success("Chain verified: {$result['checked']} events valid.")->flash()
            : $this->alert->danger("Verification failed at event #{$result['failed_event_id']}: {$result['reason']}")->flash();

        return redirect()->route('admin.uptime.nodes.view', $node->id);
    }

    public function archive(Request $request, UptimeNode $node): RedirectResponse
    {
        $request->validate(['confirm' => 'accepted']);
        $node->forceFill(['archived_at' => CarbonImmutable::now(), 'monitoring_enabled' => false, 'is_public' => false])->save();
        UptimeKey::query()->where('uptime_node_id', $node->id)->where('status', UptimeKey::STATUS_ACTIVE)
            ->update(['status' => UptimeKey::STATUS_REVOKED, 'revoked_at' => CarbonImmutable::now()->getTimestampMs(), 'revoke_reason' => 'Node archived.']);
        Activity::event('admin:uptime.node.archived')->actor($request->user())->property(['node' => $node->public_id])->log();
        $this->alert->success('Node archived. Its events and history are kept; it no longer accepts events.')->flash();

        return redirect()->route('admin.uptime');
    }

    public function note(Request $request, UptimeNode $node): RedirectResponse
    {
        $data = $request->validate(['outage_id' => 'nullable|integer', 'body' => 'required|string|min:3|max:1000']);
        if (!empty($data['outage_id'])) {
            abort_unless(UptimeOutage::query()->where('uptime_node_id', $node->id)->whereKey($data['outage_id'])->exists(), 404);
        }
        UptimeNote::query()->create(['uptime_node_id' => $node->id, 'uptime_outage_id' => $data['outage_id'] ?? null, 'body' => $data['body'], 'created_by' => $request->user()->id]);
        Activity::event('admin:uptime.note.created')->actor($request->user())->property(['node' => $node->public_id, 'outage' => $data['outage_id'] ?? null])->log();
        $this->alert->success('Administrator note added. Monitoring data is unchanged.')->flash();

        return redirect()->route('admin.uptime.nodes.view', $node->id);
    }

    public function resolveAnomaly(Request $request, UptimeAnomaly $anomaly): RedirectResponse
    {
        $data = $request->validate(['note' => 'required|string|min:3|max:500']);
        if (!$anomaly->resolved_at) {
            $anomaly->forceFill(['resolved_at' => CarbonImmutable::now(), 'resolved_by' => $request->user()->id, 'resolution_note' => $data['note']])->save();
            Activity::event('admin:uptime.anomaly.resolved')->actor($request->user())->property(['anomaly' => $anomaly->id, 'kind' => $anomaly->kind])->log();
        }
        $this->alert->success('Anomaly marked as reviewed.')->flash();

        return redirect()->back();
    }

    public function incidents(): View
    {
        return view('uptime::admin.incidents', [
            'outages' => UptimeOutage::query()->orderByDesc('start_ms')->paginate(50),
            'nodes' => UptimeNode::query()->get()->keyBy('id'),
            'anomalies' => UptimeAnomaly::query()->orderByDesc('last_seen_at')->limit(100)->get(),
        ]);
    }

    public function settings(): View
    {
        return view('uptime::admin.settings', ['settings' => $this->settings, 'release' => app(ReleaseInfo::class)->collector()]);
    }

    public function saveSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'repository_url' => ['required', 'string', 'max:200', 'regex:#^https://[A-Za-z0-9.-]+(/[A-Za-z0-9._~/-]*)?$#'],
            'trusted_builds' => 'nullable|string|max:20000',
            'status_page_enabled' => 'boolean',
        ]);
        foreach (preg_split('/\R/', (string) ($data['trusted_builds'] ?? '')) ?: [] as $i => $line) {
            if (trim($line) !== '' && preg_match('/^\s*[A-Za-z0-9._+-]{1,64}\s+[0-9a-f]{64}\s*$/', $line) !== 1) {
                return redirect()->back()->withInput()->withErrors(['trusted_builds' => 'Line ' . ($i + 1) . ' must be "<version> <sha256>".']);
            }
        }
        $this->settings->set('repository_url', rtrim($data['repository_url'], '/'));
        $this->settings->set('trusted_builds', trim((string) ($data['trusted_builds'] ?? '')));
        $this->settings->set('status_page_enabled', $request->boolean('status_page_enabled') ? '1' : '0');
        Activity::event('admin:uptime.settings.updated')->actor($request->user())->property(['repository_url' => $data['repository_url'], 'status_page_enabled' => $request->boolean('status_page_enabled')])->log();
        $this->alert->success('Uptime settings saved.')->flash();

        return redirect()->route('admin.uptime.settings');
    }

    /**
     * Node settings. Monitoring parameters can only be set before monitoring starts.
     */
    private function validateNode(Request $request, bool $withParams): array
    {
        $rules = [
            'display_name' => 'required|string|min:2|max:80',
            'node_id' => 'nullable|integer|exists:nodes,id',
            'is_public' => 'boolean',
            'monitoring_enabled' => 'boolean',
        ];
        if ($withParams) {
            $rules += [
                'interval_seconds' => 'required|integer|min:5|max:600',
                'timeout_seconds' => 'required|integer|max:3600|min:' . max(10, 2 * (int) $request->input('interval_seconds', 30)),
                'tolerance_seconds' => 'required|integer|min:0|max:3600',
                'skew_seconds' => 'required|integer|min:10|max:600',
            ];
        }
        $data = $request->validate($rules, ['timeout_seconds.min' => 'The timeout must be at least twice the heartbeat interval.']);
        $data['is_public'] = $request->boolean('is_public');
        $data['monitoring_enabled'] = $request->boolean('monitoring_enabled', true);

        return $data;
    }
}
