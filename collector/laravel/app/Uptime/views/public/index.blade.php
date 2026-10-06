@extends($layout)
@php use Pterodactyl\Uptime\Http\Format; use Pterodactyl\Uptime\Services\StatusReport; @endphp

@section('title', 'System Status')
@section('description', 'Live node status and cryptographically signed, publicly auditable uptime records.')
@section('head')
    <link rel="stylesheet" href="{{ route('uptime.status.css') }}?v={{ filemtime(base_path('app/Uptime/assets/uptime.css')) }}">
@endsection

@section('content')
<div class="ut">
    <section class="ut-hero">
        <div class="ut-wrap">
            <p class="ut-eyebrow">Transparency</p>
            <h1>System Status</h1>
            <p class="ut-lead">Cryptographically signed and publicly auditable uptime records for our hosting nodes.</p>
            <div class="ut-overall" role="status">
                <span class="ut-dot {{ Format::overallTone($overall) }}" aria-hidden="true"></span>
                <strong>{{ StatusReport::OVERALL[$overall] }}</strong>
                <span class="ut-meta">Updated <time datetime="{{ Format::iso($now) }}">{{ Format::time($now) }}</time> · times in UTC</span>
            </div>
            @if(!$collectorHealthy && $overall !== 'no_data')
                <div class="ut-notice warn">The collector's scheduled checks are not running, so offline alerts and integrity checks may be delayed. Figures below are still computed from the signed events received.</div>
            @endif
            <div class="ut-notice">Current deployment is not independently operated by a third-party monitor. <a href="#how-verified">Read how verification works and its limits.</a></div>
        </div>
    </section>

    <div class="ut-wrap">
        <dl class="ut-stats" aria-label="Overview">
            <div class="ut-stat"><dt>Monitored nodes</dt><dd>{{ $counts['monitored'] }}</dd></div>
            <div class="ut-stat"><dt>Online</dt><dd>{{ $counts['online'] }}</dd></div>
            <div class="ut-stat"><dt>Degraded / delayed</dt><dd>{{ $counts['degraded'] }}</dd></div>
            <div class="ut-stat"><dt>Offline</dt><dd>{{ $counts['offline'] }}</dd></div>
            <div class="ut-stat"><dt>Current incidents</dt><dd>{{ $counts['incidents'] }}</dd></div>
        </dl>

        <section class="ut-section" aria-labelledby="nodes-heading">
            <h2 id="nodes-heading">Nodes</h2>
            @if(empty($nodes))
                <div class="ut-card ut-muted">No nodes are published yet. Monitoring has not started.</div>
            @else
                <div class="ut-grid">
                    @foreach($nodes as $node)
                        @include('uptime::public.partials.card', ['node' => $node, 'now' => $now])
                    @endforeach
                </div>
            @endif
        </section>

        @include('uptime::public.partials.transparency')
        @include('uptime::public.partials.source')

        <section class="ut-section" aria-labelledby="states-heading">
            <div class="ut-card">
                <h2 id="states-heading">What the states mean</h2>
                <dl class="ut-kv">
                    @foreach(StatusReport::STATES as [$label, $description])
                        <dt>{{ $label }}</dt><dd>{{ $description }}</dd>
                    @endforeach
                    <dt>Verified</dt><dd>The collector re-checked every stored signature and hash link of the node within the last hours.</dd>
                    <dt>Monitoring anomaly detected</dt><dd>The collector recorded something unusual (for example a clock or build change) that an administrator has not reviewed yet. Signed data is unchanged.</dd>
                </dl>
            </div>
        </section>
    </div>
</div>
@endsection
