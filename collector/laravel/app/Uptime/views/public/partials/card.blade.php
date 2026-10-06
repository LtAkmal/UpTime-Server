@php use Pterodactyl\Uptime\Http\Format; @endphp
<article class="ut-card" aria-labelledby="node-{{ $node['id'] }}">
    <div class="ut-card-head">
        <div style="min-width:0">
            <h3 id="node-{{ $node['id'] }}"><a href="{{ route('uptime.status.node', $node['id']) }}">{{ $node['name'] }}</a></h3>
            <div class="ut-badges">
                <span class="ut-badge {{ Format::stateTone($node['state']) }}"><span class="ut-dot {{ Format::stateTone($node['state']) }}" aria-hidden="true"></span>{{ $node['state_label'] }}</span>
                @if($node['verification']['state'] === 'valid')
                    <span class="ut-badge ok" title="Signatures and hash chain verified {{ $node['verification']['checked_at'] }}">✓ Verified</span>
                @elseif($node['verification']['state'] === 'failed')
                    <span class="ut-badge bad">Not verified</span>
                @else
                    <span class="ut-badge">Verification pending</span>
                @endif
                @if($node['anomaly'])
                    <span class="ut-badge warn">Monitoring anomaly detected</span>
                @endif
            </div>
        </div>
    </div>
    <p class="ut-small ut-muted" style="margin:0 0 12px">
        Last verified heartbeat: @if($node['last_heartbeat_at'])<time datetime="{{ Format::iso($node['last_heartbeat_at']) }}">{{ Format::ago($node['last_heartbeat_at'], $now) }}</time>@else none yet @endif
        · Monitoring since {{ Format::date($node['monitoring_started_at']) }}
    </p>
    @if($node['current_incident'])
        <div class="ut-notice bad" role="status">Ongoing incident: no valid heartbeat since {{ Format::time($node['last_heartbeat_at']) }} (counted as downtime from {{ Format::time($node['current_incident']['start']) }}).</div>
    @endif
    <dl class="ut-windows">
        @foreach(['24h' => '24 hours', '7d' => '7 days', '30d' => '30 days', 'all' => 'All time'] as $key => $label)
            @php([$value, $note] = Format::window($node['windows'][$key]))
            <div><dt>{{ $label }}</dt><dd>{{ $value }}@if($note)<small>{{ $note }}</small>@endif</dd></div>
        @endforeach
    </dl>
    @include('uptime::public.partials.history', ['history' => $node['history']])
    <div class="ut-actions">
        <a class="ut-btn" href="{{ route('uptime.status.node', $node['id']) }}">Details &amp; incidents</a>
        <a class="ut-btn" href="{{ route('uptime.status.node', $node['id']) }}#proof">View proof</a>
    </div>
</article>
