@extends('layouts.admin')

@section('title')
    Uptime settings
@endsection

@section('content-header')
    <h1>Uptime settings</h1>
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">Admin</a></li>
        <li><a href="{{ route('admin.uptime') }}">Uptime Monitoring</a></li>
        <li class="active">Settings</li>
    </ol>
@endsection

@section('content')
    @include('uptime::admin.nav')
    <div class="row">
        <div class="col-md-7">
            <form method="POST" action="{{ route('admin.uptime.settings.save') }}" class="box box-primary">
                @csrf
                <div class="box-body">
                    <div class="checkbox"><label><input type="hidden" name="status_page_enabled" value="0"><input type="checkbox" name="status_page_enabled" value="1" @checked($settings->statusPageEnabled())> Public status page enabled (<code>/status</code>)</label></div>
                    <div class="form-group">
                        <label for="repository_url">Public source repository</label>
                        <input id="repository_url" name="repository_url" class="form-control" required value="{{ old('repository_url', $settings->repositoryUrl()) }}">
                        <p class="help-block">HTTPS URL linked from the status page; commit links are built from it.</p>
                    </div>
                    <div class="form-group">
                        <label for="trusted_builds">Published agent releases</label>
                        <textarea id="trusted_builds" name="trusted_builds" rows="6" class="form-control" style="font-family:monospace" placeholder="0.1.0 3f5a…(64 hex)">{{ old('trusted_builds', $settings->get('trusted_builds')) }}</textarea>
                        <p class="help-block">One line per release: <code>&lt;version&gt; &lt;sha256&gt;</code>, copied from the release's SHA256SUMS. Agents reporting another checksum are flagged and shown as "not in the published release list".</p>
                    </div>
                </div>
                <div class="box-footer"><button class="btn btn-primary">Save</button></div>
            </form>
        </div>
        <div class="col-md-5">
            <div class="box">
                <div class="box-header with-border"><h3 class="box-title">Collector release</h3></div>
                <div class="box-body">
                    <dl>
                        <dt>Version</dt><dd>{{ $release['version'] }}</dd>
                        <dt>Commit</dt><dd><code>{{ $release['commit'] }}</code></dd>
                        <dt>Installed files digest</dt><dd><code style="word-break:break-all">{{ $release['installed_digest'] }}</code></dd>
                        <dt>Release manifest digest</dt><dd><code style="word-break:break-all">{{ $release['release_digest'] ?? 'none (not installed with install.sh)' }}</code></dd>
                    </dl>
                    <p class="help-block">Compare with <code>scripts/collector-digest.sh</code> run on the same commit of the UpTime-Server repository.</p>
                </div>
            </div>
        </div>
    </div>
@endsection
