<div class="box">
    <div class="box-header with-border"><h3 class="box-title">{{ $title }}</h3></div>
    <div class="box-body table-responsive no-padding">
        <table class="table table-condensed">
            <tr><th>Severity</th><th>What</th><th>Details (administrators only)</th><th class="text-right">Count</th><th>Last seen (UTC)</th><th>Review</th></tr>
            @forelse($anomalies as $a)
                <tr>
                    <td><span class="label label-{{ $a->severity === 'critical' ? 'danger' : ($a->severity === 'warning' ? 'warning' : 'default') }}">{{ $a->severity }}</span></td>
                    <td>{{ $a->label() }}</td>
                    <td style="white-space:normal;max-width:480px">{{ $a->detail }}</td>
                    <td class="text-right">{{ $a->occurrences }}</td>
                    <td>{{ $a->last_seen_at->utc()->format('Y-m-d H:i:s') }}</td>
                    <td>
                        @if($a->resolved_at)
                            <span class="text-muted">Reviewed {{ $a->resolved_at->utc()->format('Y-m-d H:i') }}: {{ $a->resolution_note }}</span>
                        @else
                            <form method="POST" action="{{ route('admin.uptime.anomalies.resolve', $a->id) }}" class="form-inline">
                                @csrf
                                <input type="text" name="note" class="form-control input-sm" placeholder="What was found" required minlength="3" maxlength="500" aria-label="Review note">
                                <button class="btn btn-xs btn-default">Mark reviewed</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-muted text-center" style="padding:14px">Nothing to review.</td></tr>
            @endforelse
        </table>
    </div>
</div>
