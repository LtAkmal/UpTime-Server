@extends($layout)
@php use Pterodactyl\Uptime\Http\Format; @endphp

@section('title', $node['name'] . ' · Status')
@section('description', 'Signed uptime records and verification details for ' . $node['name'] . '.')
@section('head')
    <link rel="stylesheet" href="{{ route('uptime.status.css') }}?v={{ filemtime(base_path('app/Uptime/assets/uptime.css')) }}">
@endsection

@section('content')
<div class="ut">
    <section class="ut-hero">
        <div class="ut-wrap">
            <p class="ut-eyebrow"><a href="{{ route('uptime.status') }}">System status</a> / node</p>
            <h1>{{ $node['name'] }}</h1>
            <div class="ut-badges">
                <span class="ut-badge {{ Format::stateTone($node['state']) }}"><span class="ut-dot {{ Format::stateTone($node['state']) }}" aria-hidden="true"></span>{{ $node['state_label'] }}</span>
                @if($node['verification']['state'] === 'valid')<span class="ut-badge ok">✓ Verified · chain valid</span>
                @elseif($node['verification']['state'] === 'failed')<span class="ut-badge bad">Not verified</span>
                @else<span class="ut-badge">Verification pending</span>@endif
                @if($node['anomaly'])<span class="ut-badge warn">Monitoring anomaly detected</span>@endif
            </div>
            <p class="ut-lead" style="margin-top:12px">{{ $node['state_description'] }}</p>
            @if($node['verification']['state'] === 'failed')
                <div class="ut-notice bad" role="alert">Integrity verification failed: stored records from {{ $node['verification']['not_verified_from'] ? \Carbon\CarbonImmutable::parse($node['verification']['not_verified_from'])->utc()->format('j M Y, H:i:s') . ' UTC' : 'an unknown point' }} onward could not be verified. Uptime figures for this node are not verified.</div>
            @endif
            @if($node['current_incident'])
                <div class="ut-notice bad" role="status">Ongoing incident since {{ Format::time($node['current_incident']['start']) }} ({{ Format::duration($now - $node['current_incident']['start']) }}).</div>
            @endif
        </div>
    </section>

    <div class="ut-wrap">
        <section class="ut-section" aria-labelledby="uptime-heading">
            <div class="ut-card">
                <h2 id="uptime-heading">Uptime</h2>
                <dl class="ut-windows">
                    @foreach(['24h' => 'Last 24 hours', '7d' => 'Last 7 days', '30d' => 'Last 30 days', 'all' => 'All time'] as $key => $label)
                        @php([$value, $note] = Format::window($node['windows'][$key]))
                        <div><dt>{{ $label }}</dt><dd>{{ $value }}@if($note)<small>{{ $note }}</small>@endif<small>downtime {{ Format::duration($node['windows'][$key]['downtime_ms']) }}</small></dd></div>
                    @endforeach
                </dl>
                @include('uptime::public.partials.history', ['history' => $node['history']])
                <dl class="ut-kv" style="margin-top:14px">
                    <dt>Monitoring started</dt><dd>{{ Format::time($node['monitoring_started_at']) }}</dd>
                    <dt>Last verified heartbeat</dt><dd>{{ Format::time($node['last_heartbeat_at']) }} ({{ Format::ago($node['last_heartbeat_at'], $now) }})</dd>
                    <dt>Incidents</dt><dd>{{ $node['incidents'] }}</dd>
                    <dt>Parameters</dt><dd>heartbeat every {{ $node['params']['interval_ms'] / 1000 }} s · timeout {{ $node['params']['timeout_ms'] / 1000 }} s · tolerance {{ $node['params']['tolerance_ms'] / 1000 }} s · clock window ±{{ $node['params']['skew_ms'] / 1000 }} s (fixed since monitoring began)</dd>
                </dl>
            </div>
        </section>

        <div class="ut-two">
            <section class="ut-section" aria-labelledby="incidents-heading">
                <h2 id="incidents-heading">Incidents</h2>
                @if(empty($incidents))
                    <div class="ut-card ut-muted">No confirmed downtime recorded.</div>
                @else
                    <div class="ut-table-wrap">
                        <table class="ut-table">
                            <thead><tr><th scope="col">Started (UTC)</th><th scope="col">Duration</th><th scope="col">Cause</th></tr></thead>
                            <tbody>
                                @foreach($incidents as $i)
                                    <tr>
                                        <td>{{ Format::time($i['start']) }}@if($i['ongoing']) <span class="ut-badge bad">ongoing</span>@endif
                                            @foreach($i['notes'] as $note)
                                                <div class="ut-note"><strong>Administrator note</strong> (not part of the signed record): {{ $note['body'] }}</div>
                                            @endforeach
                                        </td>
                                        <td>{{ Format::duration($i['duration_ms']) }}</td>
                                        <td>{{ $i['cause'] === 'reboot' ? 'Reboot (signed boot event)' : 'No valid heartbeat' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>

            <section class="ut-section" aria-labelledby="agent-heading">
                <h2 id="agent-heading">Agent &amp; keys</h2>
                <div class="ut-card">
                    <dl class="ut-kv">
                        <dt>Agent version</dt><dd>{{ $node['agent']['version'] ?? '—' }}</dd>
                        <dt>Source commit</dt>
                        <dd>
                            @if($node['agent'] && ($url = $commitUrl($node['agent']['commit'])))<a class="ut-mono" href="{{ $url }}" rel="noopener">{{ $node['agent']['commit'] }}</a>
                            @else <span class="ut-muted">{{ $node['agent']['commit'] ?? '—' }}</span>@endif
                            <span class="ut-small ut-muted">(embedded at build time, reported in each signed event)</span>
                        </dd>
                        <dt>Binary SHA-256</dt><dd class="ut-mono">{{ $node['agent']['sha256'] ?? '—' }}</dd>
                        <dt>Published release</dt>
                        <dd>@if(!$node['agent'])—@elseif($node['agent']['published_release'])<span class="ut-badge ok">matches {{ $node['agent']['published_release'] }}</span>@else<span class="ut-badge warn">not in the published release list</span>@endif</dd>
                        @foreach($keys as $k)
                            <dt>{{ $k->status === 'active' ? 'Signing key' : 'Former key (' . $k->status . ')' }}</dt>
                            <dd><span class="ut-mono">{{ $k->fingerprint }}</span><br><span class="ut-small ut-muted">Ed25519 · SHA-256 fingerprint · valid from {{ Format::time($k->registered_at) }}@if($k->revoked_at) until {{ Format::time($k->revoked_at) }}@endif</span></dd>
                        @endforeach
                    </dl>
                </div>
            </section>
        </div>

        <section class="ut-section" id="proof" aria-labelledby="proof-heading">
            <div class="ut-card">
                <h2 id="proof-heading">Verification &amp; proof</h2>
                <dl class="ut-kv">
                    <dt>Chain status</dt>
                    <dd>@if($node['verification']['state'] === 'valid')<span class="ut-badge ok">Chain valid</span>@elseif($node['verification']['state'] === 'failed')<span class="ut-badge bad">Chain broken</span>@else<span class="ut-badge">Not checked yet</span>@endif</dd>
                    <dt>Last full check</dt><dd>{{ $node['verification']['checked_at'] ? \Carbon\CarbonImmutable::parse($node['verification']['checked_at'])->utc()->format('j M Y, H:i:s') . ' UTC' : '—' }} · {{ number_format($node['verification']['events_checked']) }} events</dd>
                    <dt>Event log range</dt><dd>{{ Format::time($node['monitoring_started_at']) }} → {{ Format::time($node['last_heartbeat_at']) }} · {{ number_format($node['events']) }} signed events</dd>
                </dl>
                <div class="ut-actions">
                    <a class="ut-btn primary" href="{{ route('uptime.api.proof', $node['id']) }}">Proof JSON · last 24 h</a>
                    <a class="ut-btn" href="{{ route('uptime.api.proof', ['publicId' => $node['id'], 'from' => $now - 604800000, 'to' => $now]) }}">Last 7 days</a>
                    @if($node['monitoring_started_at'])<a class="ut-btn" href="{{ route('uptime.api.proof', ['publicId' => $node['id'], 'from' => $node['monitoring_started_at'], 'to' => $now]) }}">Since monitoring began</a>@endif
                </div>
                <p class="ut-small ut-muted" style="margin-top:14px">Check a proof yourself (Go 1.24+, from the source repository):</p>
                <pre><code>go run ./cmd/uptime-verify '{{ route('uptime.api.proof', $node['id']) }}'</code></pre>
                <p class="ut-small ut-muted">It re-checks every Ed25519 signature, hash link, sequence number and key validity in the proof and recomputes the uptime with the published formula. A proof shows the records are consistent and signed by the node's key; it cannot show that the collector received every heartbeat that was sent.</p>
            </div>
        </section>

        <section class="ut-section" aria-labelledby="events-heading">
            <h2 id="events-heading">Recent signed events</h2>
            <div class="ut-table-wrap">
                <table class="ut-table">
                    <thead><tr><th scope="col">Received (UTC)</th><th scope="col">Sequence</th><th scope="col">Type</th><th scope="col">Agent status</th><th scope="col">Event hash</th></tr></thead>
                    <tbody>
                        @forelse($recent as $e)
                            <tr><td>{{ Format::time($e['received_at']) }}</td><td>{{ $e['seq'] }}</td><td>{{ $e['type'] }}</td><td>{{ $e['status'] }}</td><td class="ut-mono" title="{{ $e['event_hash'] }}">{{ Format::short($e['event_hash'], 16) }}</td></tr>
                        @empty
                            <tr><td colspan="5" class="ut-muted">No events yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        @include('uptime::public.partials.transparency')
        @include('uptime::public.partials.source')
    </div>
</div>
@endsection
