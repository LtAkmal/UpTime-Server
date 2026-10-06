<?php

namespace Pterodactyl\Uptime\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A registered agent public key. Private keys never reach the collector.
 *
 * @property int $id
 * @property int $uptime_node_id
 * @property string $fingerprint
 * @property string $public_key base64 raw 32 bytes
 * @property string $status
 * @property string $source
 * @property int $registered_at
 * @property int|null $revoked_at
 * @property string|null $revoke_reason
 * @property int $last_seq
 * @property int|null $created_by
 * @property UptimeNode $node
 */
class UptimeKey extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_ROTATED = 'rotated';
    public const STATUS_REVOKED = 'revoked';

    protected $table = 'uptime_keys';

    protected $guarded = ['id'];

    protected $casts = [
        'registered_at' => 'integer',
        'revoked_at' => 'integer',
        'last_seq' => 'integer',
    ];

    public function node(): BelongsTo
    {
        return $this->belongsTo(UptimeNode::class, 'uptime_node_id');
    }

    /**
     * Whether the key was valid at a receipt time (Unix ms): from registration up to and
     * including the revocation instant (an event accepted in the same millisecond, just
     * before the revocation, stays valid).
     */
    public function validAt(int $ms): bool
    {
        return $ms >= $this->registered_at && (is_null($this->revoked_at) || $ms <= $this->revoked_at);
    }
}
