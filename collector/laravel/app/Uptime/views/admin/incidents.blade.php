@extends('layouts.admin')
@php use Pterodactyl\Uptime\Http\Format; @endphp

@section('title')
    Uptime incidents
@endsection

@section('content-header')
    <h1>Incidents &amp; anomalies<small>Derived from signed events; read-only.</small></h1>
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">Admin</a></li>
        <li><a href="{{ route('admin.uptime') }}">Uptime Monitoring</a></li>
        <li class="active">Incidents</li>
    </ol>
@endsection

@section('content')
    @include('uptime::admin.nav')
    <div class="box">
        <div class="box-header with-border"><h3 class="box-title">Confirmed downtime</h3></div>
        <div class="box-body table-responsive no-padding">
            <table class="table table-condensed table-hover">
                <tr><th>Node</th><th>Started (UTC)</th><th>Ended (UTC)</th><th>Duration</th><th>Cause</th></tr>
                @forelse($outages as $o)
                    <tr>
                        <td>@if($n = $nodes[$o->uptime_node_id] ?? null)<a href="{{ route('admin.uptime.nodes.view', $n->id) }}">{{ $n->display_name }}</a>@endif</td>
                        <td>{{ Format::time($o->start_ms) }}</td><td>{{ Format::time($o->end_ms) }}</td><td>{{ Format::duration($o->durationMs()) }}</td><td>{{ $o->cause }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-muted text-center">No confirmed downtime recorded.</td></tr>
                @endforelse
            </table>
        </div>
        @if($outages->hasPages())<div class="box-footer">{{ $outages->links() }}</div>@endif
    </div>
    @include('uptime::admin.anomalies', ['anomalies' => $anomalies, 'title' => 'All anomalies (latest 100)'])
@endsection
