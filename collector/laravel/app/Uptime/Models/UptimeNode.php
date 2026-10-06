<?php

namespace Pterodactyl\Uptime\Models;

use Pterodactyl\Models\Node;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A monitored node. node_id (the Pterodactyl node) is private and never published.
 *
 * @property int $id
 * @property string $public_id
 * @property string $display_name
 * @property int|null $node_id
 * @property bool $is_public
 * @property bool $monitoring_enabled
 * @property \Carbon\CarbonImmutable|null $archived_at
 * @property int $interval_seconds
 * @property int $timeout_seconds
 * @property int $tolerance_seconds
 * @property int $skew_seconds
 * @property string $head_hash
 * @property int|null $last_event_id
 * @property int|null $monitoring_started_at
 * @property int|null $last_received_at
 * @property int $events_count
 * @property string $verification_state
 * @property \Carbon\CarbonImmutable|null $verified_at
 * @property int $verified_events
 * @property int|null $verified_through_id
 * @property int|null $verification_failed_event_id
 * @property int|null $verification_failed_at
 * @property string|null $verification_error
 * @property string $alert_state
 * @property UptimeEvent|null $lastEvent
 */
class UptimeNode extends Model
{
    public const VERIFICATION_PENDING = 'pending';
    public const VERIFICATION_VALID = 'valid';
    public const VERIFICATION_FAILED = 'failed';

    protected $table = 'uptime_nodes';

    protected $guarded = ['id'];

    protected $casts = [
        'is_public' => 'boolean',
        'monitoring_enabled' => 'boolean',
        'archived_at' => 'immutable_datetime',
        'verified_at' => 'immutable_datetime',
        'interval_seconds' => 'integer',
        'timeout_seconds' => 'integer',
        'tolerance_seconds' => 'integer',
        'skew_seconds' => 'integer',
        'last_event_id' => 'integer',
        'monitoring_started_at' => 'integer',
        'last_received_at' => 'integer',
        'events_count' => 'integer',
        'verified_events' => 'integer',
        'verified_through_id' => 'integer',
        'verification_failed_event_id' => 'integer',
        'verification_failed_at' => 'integer',
    ];

    public function getRouteKeyName(): string
    {
        return 'id';
    }

    public function keys(): HasMany
    {
        return $this->hasMany(UptimeKey::class, 'uptime_node_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(UptimeEvent::class, 'uptime_node_id');
    }

    public function outages(): HasMany
    {
        return $this->hasMany(UptimeOutage::class, 'uptime_node_id');
    }

    public function anomalies(): HasMany
    {
        return $this->hasMany(UptimeAnomaly::class, 'uptime_node_id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(UptimeNote::class, 'uptime_node_id');
    }

    public function lastEvent(): BelongsTo
    {
        return $this->belongsTo(UptimeEvent::class, 'last_event_id');
    }

    public function pterodactylNode(): BelongsTo
    {
        return $this->belongsTo(Node::class, 'node_id');
    }

    /**
     * Monitoring parameters in milliseconds (the values published in proofs).
     *
     * @return array{interval_ms: int, timeout_ms: int, tolerance_ms: int, skew_ms: int}
     */
    public function params(): array
    {
        return [
            'interval_ms' => $this->interval_seconds * 1000,
            'timeout_ms' => $this->timeout_seconds * 1000,
            'tolerance_ms' => $this->tolerance_seconds * 1000,
            'skew_ms' => $this->skew_seconds * 1000,
        ];
    }

    /**
     * Parameters are fixed once the first event is accepted, so historical uptime can
     * never be changed by editing them.
     */
    public function parametersLocked(): bool
    {
        return !is_null($this->monitoring_started_at);
    }

    public function isActive(): bool
    {
        return $this->monitoring_enabled && is_null($this->archived_at);
    }
}
