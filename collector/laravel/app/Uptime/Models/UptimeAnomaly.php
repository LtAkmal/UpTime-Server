<?php

namespace Pterodactyl\Uptime\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A suspicious or notable monitoring observation. "detail" is for administrators
 * only; the public page only says that an anomaly was detected.
 *
 * @property int $id
 * @property int|null $uptime_node_id
 * @property int|null $uptime_event_id
 * @property string $kind
 * @property string $severity
 * @property string $detail
 * @property int $occurrences
 * @property \Carbon\CarbonImmutable $first_seen_at
 * @property \Carbon\CarbonImmutable $last_seen_at
 * @property \Carbon\CarbonImmutable|null $resolved_at
 * @property int|null $resolved_by
 * @property string|null $resolution_note
 */
class UptimeAnomaly extends Model
{
    public const NOTICE = 'notice';
    public const WARNING = 'warning';
    public const CRITICAL = 'critical';

    public const LABELS = [
        'invalid_signature' => 'Invalid signature',
        'unknown_key' => 'Unknown or unregistered key',
        'revoked_key' => 'Revoked key used',
        'replay' => 'Replayed event',
        'duplicate_sequence' => 'Same sequence signed twice with different content',
        'clock_skew' => 'Agent clock outside the accepted window',
        'clock_drift' => 'Agent clock drift',
        'clock_rollback' => 'Agent wall clock went backwards',
        'monotonic_regression' => 'Uptime decreased without a reboot event',
        'reboot_without_boot_event' => 'New boot session without a boot event',
        'boot_time_inconsistent' => 'Boot time inconsistent with the previous event',
        'uptime_drift' => 'Uptime and receipt time drifted apart',
        'agent_build_changed' => 'Agent build changed',
        'agent_build_unrecognized' => 'Agent build not in the published release list',
        'agent_commit_changed' => 'Agent source commit changed',
        'chain_conflict' => 'Event did not continue the current chain',
        'chain_verification_failed' => 'Chain verification failed',
        'enrollment_failed' => 'Failed enrollment attempt',
        'key_registered' => 'Agent key registered',
        'key_revoked' => 'Agent key revoked',
        'monitoring_disabled' => 'Monitoring disabled',
    ];

    public $timestamps = false;

    protected $table = 'uptime_anomalies';

    protected $guarded = ['id'];

    protected $casts = [
        'first_seen_at' => 'immutable_datetime',
        'last_seen_at' => 'immutable_datetime',
        'resolved_at' => 'immutable_datetime',
        'occurrences' => 'integer',
    ];

    public function label(): string
    {
        return self::LABELS[$this->kind] ?? $this->kind;
    }
}
