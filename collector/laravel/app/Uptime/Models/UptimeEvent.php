<?php

namespace Pterodactyl\Uptime\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An accepted, signed event. Append-only: the model refuses updates and deletes, and
 * database triggers refuse them too (MySQL/MariaDB). Corrections are new events or
 * separate administrator notes, never edits.
 *
 * @property int $id
 * @property int $uptime_node_id
 * @property int $uptime_key_id
 * @property int $seq
 * @property string $type
 * @property string $payload
 * @property string $payload_hash
 * @property string $prev_hash
 * @property string $event_hash
 * @property string $signature
 * @property string $verification
 * @property int $received_at
 * @property int $agent_ts
 * @property int $mono_ms
 * @property string $boot_session
 * @property string $status
 * @property string $agent_version
 * @property string $agent_commit
 * @property string $agent_sha256
 */
class UptimeEvent extends Model
{
    public $timestamps = false;

    protected $table = 'uptime_events';

    protected $guarded = ['id'];

    protected $casts = [
        'seq' => 'integer',
        'received_at' => 'integer',
        'agent_ts' => 'integer',
        'mono_ms' => 'integer',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Uptime events are append-only.'));
        static::deleting(fn () => throw new \LogicException('Uptime events are append-only.'));
    }

    public function key(): BelongsTo
    {
        return $this->belongsTo(UptimeKey::class, 'uptime_key_id');
    }

    /**
     * @return array{received: int, type: string, mono: int}
     */
    public function point(): array
    {
        return ['received' => $this->received_at, 'type' => $this->type, 'mono' => $this->mono_ms];
    }

    /**
     * The public proof representation.
     */
    public function toProof(): array
    {
        return [
            'id' => $this->id,
            'received_at' => $this->received_at,
            'payload' => $this->payload,
            'signature' => $this->signature,
            'payload_hash' => $this->payload_hash,
            'prev_hash' => $this->prev_hash,
            'event_hash' => $this->event_hash,
        ];
    }
}
