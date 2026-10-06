<div class="form-group">
    <label for="display_name">Public display name</label>
    <input id="display_name" name="display_name" class="form-control" required maxlength="80" value="{{ old('display_name', $node->display_name ?? '') }}">
    <p class="help-block">Shown on the public status page. Do not use the hostname, IP address or anything internal.</p>
</div>
<div class="form-group">
    <label for="node_id">Pterodactyl node (private link, optional)</label>
    <select id="node_id" name="node_id" class="form-control">
        <option value="">None</option>
        @foreach($pteroNodes as $p)<option value="{{ $p->id }}" @selected((string) old('node_id', $node->node_id ?? '') === (string) $p->id)>{{ $p->name }}</option>@endforeach
    </select>
    <p class="help-block">Only for administrators; never published.</p>
</div>
<div class="checkbox"><label><input type="hidden" name="is_public" value="0"><input type="checkbox" name="is_public" value="1" @checked(old('is_public', $node->is_public ?? true))> Show on the public status page</label></div>
<div class="checkbox"><label><input type="hidden" name="monitoring_enabled" value="0"><input type="checkbox" name="monitoring_enabled" value="1" @checked(old('monitoring_enabled', $node->monitoring_enabled ?? true))> Monitoring enabled (accept events)</label></div>
@if($locked ?? false)
    <p class="text-muted"><i class="fa fa-lock"></i> Heartbeat interval {{ $node->interval_seconds }} s, timeout {{ $node->timeout_seconds }} s, tolerance {{ $node->tolerance_seconds }} s and clock window ±{{ $node->skew_seconds }} s are fixed since monitoring began, so past uptime can never be recalculated with different rules. To change them, archive this node and add a new one.</p>
@else
    <div class="row">
        <div class="form-group col-sm-3"><label for="interval_seconds">Heartbeat interval (s)</label><input id="interval_seconds" type="number" name="interval_seconds" min="5" max="600" class="form-control" value="{{ old('interval_seconds', $node->interval_seconds ?? 30) }}"></div>
        <div class="form-group col-sm-3"><label for="timeout_seconds">Offline timeout (s)</label><input id="timeout_seconds" type="number" name="timeout_seconds" min="10" max="3600" class="form-control" value="{{ old('timeout_seconds', $node->timeout_seconds ?? 90) }}"></div>
        <div class="form-group col-sm-3"><label for="tolerance_seconds">Tolerance (s)</label><input id="tolerance_seconds" type="number" name="tolerance_seconds" min="0" max="3600" class="form-control" value="{{ old('tolerance_seconds', $node->tolerance_seconds ?? 30) }}"></div>
        <div class="form-group col-sm-3"><label for="skew_seconds">Accepted clock difference (±s)</label><input id="skew_seconds" type="number" name="skew_seconds" min="10" max="600" class="form-control" value="{{ old('skew_seconds', $node->skew_seconds ?? 120) }}"></div>
    </div>
    <p class="help-block">The interval must match <code>interval</code> in the agent configuration. Downtime is counted after timeout + tolerance without a valid heartbeat. These values lock when the first event arrives.</p>
@endif
