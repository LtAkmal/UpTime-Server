@extends('layouts.admin')
@php use Pterodactyl\Uptime\Http\Format; @endphp

@section('title')
    {{ $node->display_name }} · Uptime
@endsection

@section('content-header')
    <h1>{{ $node->display_name }}<small><code>{{ $node->public_id }}</code> · {{ $summary['state_label'] }}</small></h1>
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">Admin</a></li>
        <li><a href="{{ route('admin.uptime') }}">Uptime Monitoring</a></li>
        <li class="active">{{ $node->display_name }}</li>
    </ol>
@endsection

@section('content')
    @include('uptime::admin.nav')
    @if($token)
        <div class="alert alert-info">
            <h4 style="margin-top:0"><i class="fa fa-key"></i> One-time enrollment token (valid {{ \Pterodactyl\Uptime\Services\EnrollmentService::TOKEN_MINUTES }} minutes, shown only now)</h4>
            <p><input type="text" readonly class="form-control" style="font-family:monospace" value="{{ $token }}" aria-label="Enrollment token" onclick="this.select()"></p>
            <p style="margin-bottom:4px">On the node, with <code>collector_url = {{ $collectorUrl }}</code> in <code>/etc/uptime-agent/agent.conf</code>, run the command below and paste the token when it waits for input (it is read from standard input, so it never appears in the shell history or process list):</p>
            <pre style="margin:0">sudo -u uptime-agent uptime-agent enroll --config /etc/uptime-agent/agent.conf --token-file -{{ $keys->where('status', 'active')->isNotEmpty() ? ' --rotate-key' : '' }}
sudo systemctl restart uptime-agent</pre>
            @if(str_starts_with($collectorUrl, 'http://'))
                <p style="margin:8px 0 0"><strong>Insecure development setup:</strong> this collector URL uses plain HTTP. The agent needs <code>allow_insecure_http = true</code>; use HTTPS for any public deployment.</p>
            @endif
        </div>
    @endif

    <div class="row">
        <div class="col-md-7">
            <div class="box box-primary">
                <div class="box-header with-border"><h3 class="box-title">Status</h3>
                    <div class="box-tools">@if($node->is_public && !$node->archived_at)<a class="btn btn-xs btn-default" href="{{ $publicUrl }}" target="_blank" rel="noopener">Public page <i class="fa fa-external-link"></i></a>@endif</div>
                </div>
                <div class="box-body">
                    <dl class="dl-horizontal" style="margin:0">
                        <dt>State</dt><dd>{{ $summary['state_label'] }} — {{ $summary['state_description'] }}</dd>
                        <dt>Last heartbeat</dt><dd>{{ Format::time($node->last_received_at) }} ({{ Format::ago($node->last_received_at, $now) }})</dd>
                        <dt>Monitoring since</dt><dd>{{ Format::time($node->monitoring_started_at) }}</dd>
                        @foreach(['24h' => '24 hours', '7d' => '7 days', '30d' => '30 days', 'all' => 'All time'] as $k => $label)
                            @php([$pct, $note] = Format::window($summary['windows'][$k]))
                            <dt>Uptime {{ $label }}</dt><dd>{{ $pct }} @if($note)<small class="text-muted">({{ $note }})</small>@endif · downtime {{ Format::duration($summary['windows'][$k]['downtime_ms']) }}</dd>
                        @endforeach
                        <dt>Agent</dt><dd>{{ $node->lastEvent?->agent_version ?? '—' }} · commit <code>{{ $node->lastEvent?->agent_commit ?? '—' }}</code><br><code style="word-break:break-all">{{ $node->lastEvent?->agent_sha256 ?? '' }}</code>
                            @if($node->lastEvent)<br>{!! $summary['agent']['published_release'] ? '<span class="label label-success">matches published ' . e($summary['agent']['published_release']) . '</span>' : '<span class="label label-warning">not in the published release list</span>' !!}@endif</dd>
                    </dl>
                    <p class="text-muted small" style="margin:10px 0 0">Uptime figures, incidents, events and verification results are computed from signed data and cannot be edited.</p>
                </div>
            </div>

            <div class="box">
                <div class="box-header with-border"><h3 class="box-title">Event chain</h3>
                    <div class="box-tools">
                        <form method="POST" action="{{ route('admin.uptime.nodes.verify', $node->id) }}">@csrf<button class="btn btn-xs btn-primary">Verify now</button></form>
                    </div>
                </div>
                <div class="box-body">
                    <dl class="dl-horizontal" style="margin:0">
                        <dt>Verification</dt>
                        <dd>@if($node->verification_state === 'valid')<span class="label label-success">valid</span>@elseif($node->verification_state === 'failed')<span class="label label-danger">failed</span>@else<span class="label label-default">pending</span>@endif
                            {{ $node->verified_at ? 'checked ' . $node->verified_at->utc()->format('Y-m-d H:i:s') . ' UTC' : 'not checked yet' }} · {{ number_format($node->verified_events) }} events</dd>
                        @if($node->verification_state === 'failed')
                            <dt>First broken record</dt><dd>event #{{ $node->verification_failed_event_id }} ({{ Format::time($node->verification_failed_at) }}): {{ $node->verification_error }}</dd>
                        @endif
                        <dt>Events</dt><dd>{{ number_format($node->events_count) }}</dd>
                        <dt>Chain head</dt><dd><code style="word-break:break-all">{{ $node->head_hash }}</code></dd>
                        <dt>Command line</dt><dd><code>php artisan uptime:verify-chain --node={{ $node->public_id }}</code></dd>
                    </dl>
                </div>
                <div class="box-body table-responsive no-padding">
                    <table class="table table-condensed">
                        <tr><th>#</th><th>Received (UTC)</th><th>Seq</th><th>Type</th><th>Status</th><th>Hash</th></tr>
                        @forelse($events as $e)
                            <tr><td>{{ $e->id }}</td><td>{{ Format::time($e->received_at) }}</td><td>{{ $e->seq }}</td><td>{{ $e->type }}</td><td>{{ $e->status }}</td><td><code>{{ Format::short($e->event_hash, 16) }}</code></td></tr>
                        @empty
                            <tr><td colspan="6" class="text-muted text-center">No events yet.</td></tr>
                        @endforelse
                    </table>
                </div>
            </div>

            <div class="box">
                <div class="box-header with-border"><h3 class="box-title">Incidents</h3></div>
                <div class="box-body table-responsive no-padding">
                    <table class="table table-condensed">
                        <tr><th>Started (UTC)</th><th>Duration</th><th>Cause</th><th>Administrator notes</th></tr>
                        @if($summary['current_incident'])
                            <tr class="danger"><td>{{ Format::time($summary['current_incident']['start']) }}</td><td>{{ Format::duration($now - $summary['current_incident']['start']) }} (ongoing)</td><td>no heartbeat</td><td></td></tr>
                        @endif
                        @forelse($outages as $o)
                            <tr>
                                <td>{{ Format::time($o->start_ms) }}</td><td>{{ Format::duration($o->durationMs()) }}</td><td>{{ $o->cause }}</td>
                                <td style="white-space:normal">
                                    @foreach($notes[$o->id] ?? [] as $n)<div class="small"><strong>Administrator note:</strong> {{ $n->body }}</div>@endforeach
                                    <form method="POST" action="{{ route('admin.uptime.nodes.notes', $node->id) }}" class="form-inline" style="margin-top:4px">
                                        @csrf <input type="hidden" name="outage_id" value="{{ $o->id }}">
                                        <input name="body" class="form-control input-sm" placeholder="Add a public note" required minlength="3" maxlength="1000" aria-label="Administrator note">
                                        <button class="btn btn-xs btn-default">Add</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            @if(!$summary['current_incident'])<tr><td colspan="4" class="text-muted text-center">No confirmed downtime.</td></tr>@endif
                        @endforelse
                    </table>
                </div>
                <div class="box-footer small text-muted">Notes are shown publicly as "Administrator note" next to the incident. They never change the signed data or the calculated downtime.</div>
            </div>
        </div>

        <div class="col-md-5">
            <form method="POST" action="{{ route('admin.uptime.nodes.update', $node->id) }}" class="box">
                @csrf @method('PATCH')
                <div class="box-header with-border"><h3 class="box-title">Settings</h3></div>
                <div class="box-body">@include('uptime::admin.form-fields', ['node' => $node, 'locked' => $node->parametersLocked()])</div>
                <div class="box-footer"><button class="btn btn-primary btn-sm" @disabled($node->archived_at)>Save</button></div>
            </form>

            <div class="box">
                <div class="box-header with-border"><h3 class="box-title">Agent keys</h3></div>
                <div class="box-body">
                    @if(!$node->archived_at)
                        <form method="POST" action="{{ route('admin.uptime.nodes.token', $node->id) }}">
                            @csrf
                            <button class="btn btn-sm btn-success"><i class="fa fa-key"></i> {{ $keys->where('status', 'active')->isNotEmpty() ? 'Generate rotation token' : 'Generate enrollment token' }}</button>
                            <span class="help-block">One-time, valid {{ \Pterodactyl\Uptime\Services\EnrollmentService::TOKEN_MINUTES }} minutes, stored only as a hash. A new token cancels any unused earlier one. Enrolling with it replaces the current key.</span>
                        </form>
                    @endif
                </div>
                <div class="box-body table-responsive no-padding">
                    <table class="table table-condensed">
                        <tr><th>Fingerprint (SHA-256)</th><th>Status</th><th>Registered</th></tr>
                        @forelse($keys as $k)
                            <tr>
                                <td><code style="word-break:break-all">{{ $k->fingerprint }}</code><br><small class="text-muted">via {{ $k->source }} · last sequence {{ $k->last_seq }}</small></td>
                                <td>{{ $k->status }}@if($k->revoke_reason)<br><small class="text-muted">{{ $k->revoke_reason }}</small>@endif</td>
                                <td>{{ Format::time($k->registered_at) }}
                                    @if($k->status === 'active')
                                        <form method="POST" action="{{ route('admin.uptime.nodes.keys.revoke', [$node->id, $k->id]) }}" style="margin-top:6px">
                                            @csrf
                                            <input name="reason" class="form-control input-sm" placeholder="Reason" required minlength="5" maxlength="255" aria-label="Revocation reason">
                                            <div class="checkbox checkbox-primary small" style="margin:4px 0">
                                                <input id="confirm-revoke-{{ $k->id }}" type="checkbox" name="confirm" value="1" required>
                                                <label for="confirm-revoke-{{ $k->id }}">I understand this key's future events are rejected</label>
                                            </div>
                                            <button class="btn btn-xs btn-danger">Revoke</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="text-muted text-center">No key registered yet.</td></tr>
                        @endforelse
                    </table>
                </div>
                @if(!$node->archived_at)
                    <form method="POST" action="{{ route('admin.uptime.nodes.keys', $node->id) }}" class="box-footer">
                        @csrf
                        <label for="public_key" class="small">Or register a public key manually (from <code>uptime-agent keygen</code>)</label>
                        <input id="public_key" name="public_key" class="form-control input-sm" style="font-family:monospace" placeholder="base64 Ed25519 public key" maxlength="64" required>
                        <div class="checkbox checkbox-primary small" style="margin:4px 0">
                            <input id="confirm-register" type="checkbox" name="confirm" value="1" required>
                            <label for="confirm-register">I copied this public key from the node myself</label>
                        </div>
                        <button class="btn btn-xs btn-default">Register key</button>
                    </form>
                @endif
            </div>

            @include('uptime::admin.anomalies', ['anomalies' => $anomalies, 'title' => 'Anomalies'])

            @if(!$node->archived_at)
                <form method="POST" action="{{ route('admin.uptime.nodes.archive', $node->id) }}" class="box box-danger">
                    @csrf
                    <div class="box-header with-border"><h3 class="box-title">Archive</h3></div>
                    <div class="box-body small">Stops accepting events, revokes the active key and hides the node from the public page. All events, incidents and proofs are kept; nothing is deleted.
                        <div class="checkbox checkbox-primary">
                            <input id="confirm-archive" type="checkbox" name="confirm" value="1" required>
                            <label for="confirm-archive">Archive this node</label>
                        </div>
                    </div>
                    <div class="box-footer"><button class="btn btn-sm btn-danger">Archive node</button></div>
                </form>
            @endif
        </div>
    </div>
@endsection
