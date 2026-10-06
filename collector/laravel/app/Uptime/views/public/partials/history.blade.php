@php use Pterodactyl\Uptime\Http\Format; @endphp
@php($withData = array_filter($history, fn ($d) => !is_null($d['uptime'])))
<div class="ut-bars" role="img" aria-label="{{ count($history) }}-day history: {{ count($withData) }} days with monitoring data, {{ count(array_filter($withData, fn ($d) => $d['downtime_ms'] > 0)) }} with confirmed downtime.">
    @foreach($history as $day)
        <span class="{{ Format::dayTone($day) }}" title="{{ $day['date'] }}: {{ is_null($day['uptime']) ? 'no monitoring data' : Format::percent($day['uptime']) . ', downtime ' . Format::duration($day['downtime_ms']) }}"></span>
    @endforeach
</div>
<div class="ut-bars-legend" aria-hidden="true"><span>{{ $history[0]['date'] ?? '' }}</span><span>Today (UTC)</span></div>
<details class="ut-small ut-muted">
    <summary>Daily figures</summary>
    <ul>
        @foreach(array_reverse($history) as $day)
            <li>{{ $day['date'] }}: {{ is_null($day['uptime']) ? 'no monitoring data' : Format::percent($day['uptime']) . ' (downtime ' . Format::duration($day['downtime_ms']) . ')' }}</li>
        @endforeach
    </ul>
</details>
