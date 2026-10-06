@extends('layouts.admin')
@php use Pterodactyl\Uptime\Http\Format; use Pterodactyl\Uptime\Services\StatusReport; @endphp

@section('title')
    Uptime Monitoring
@endsection

@section('content-header')
    <h1>Uptime Monitoring<small>Signed heartbeats, hash-chained event logs and the public status page.</small></h1>
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">Admin</a></li>
        <li class="active">Uptime Monitoring</li>
    </ol>
@endsection

@section('content')
    @include('uptime::admin.nav')
    @if(!$collectorHealthy)
        <div class="alert alert-warning">The <code>uptime:check</code> scheduler task has not run in the last 10 minutes. Offline alerts are paused and the public page shows "Monitoring unavailable". Check the panel's cron (<code>schedule:run</code>).</div>
    @endif
    <div class="row">
        <div class="col-xs-12">
            <div class="box box-primary">
                <div class="box-header with-border">
                    <h3 class="box-title">Monitored nodes</h3>
                    <div class="box-tools"><a href="{{ route('admin.uptime.nodes.new') }}" class="btn btn-sm btn-primary">Add monitored node</a></div>
                </div>
                <div class="box-body table-responsive no-padding">
                    <table class="table table-hover">
                        <tr><th>Name (public)</th><th>Public id</th><th>State</th><th>Chain</th><th class="text-right">24 h uptime</th><th>Last heartbeat</th><th class="text-right">Events</th><th>Public</th></tr>
                        @forelse($nodes as $n)
                            @php([$pct, $note] = Format::window($uptime[$n->id]))
                            <tr class="{{ $n->archived_at ? 'text-muted' : '' }}">
                                <td><a href="{{ route('admin.uptime.nodes.view', $n->id) }}">{{ $n->display_name }}</a>@if($n->archived_at) <span class="label label-default">archived</span>@endif</td>
                                <td><code>{{ $n->public_id }}</code></td>
                                <td>{{ StatusReport::STATES[$states[$n->id]][0] }}</td>
                                <td>
                                    @if($n->verification_state === 'valid')<span class="label label-success">valid</span>
                                    @elseif($n->verification_state === 'failed')<span class="label label-danger">failed</span>
                                    @else<span class="label label-default">pending</span>@endif
                                </td>
                                <td class="text-right">{{ $pct }}@if($note)<br><small class="text-muted">{{ $note }}</small>@endif</td>
                                <td>{{ $n->last_received_at ? Format::ago($n->last_received_at, $now) : 'never' }}</td>
                                <td class="text-right">{{ number_format($n->events_count) }}</td>
                                <td>{{ $n->is_public ? 'yes' : 'no' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="text-center text-muted" style="padding:20px">No monitored nodes yet. <a href="{{ route('admin.uptime.nodes.new') }}">Add the first one.</a></td></tr>
                        @endforelse
                    </table>
                </div>
            </div>
        </div>
        <div class="col-xs-12">
            @include('uptime::admin.anomalies', ['anomalies' => $anomalies, 'title' => 'Open anomalies'])
        </div>
    </div>
@endsection
