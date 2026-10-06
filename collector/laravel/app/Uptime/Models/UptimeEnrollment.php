<?php

namespace Pterodactyl\Uptime\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A one-time enrollment token (only its SHA-256 is stored).
 *
 * @property int $id
 * @property int $uptime_node_id
 * @property string $token_hash
 * @property string $purpose
 * @property \Carbon\CarbonImmutable $expires_at
 * @property \Carbon\CarbonImmutable|null $used_at
 * @property string|null $used_fingerprint
 * @property int|null $created_by
 */
class UptimeEnrollment extends Model
{
    protected $table = 'uptime_enrollments';

    protected $guarded = ['id'];

    protected $casts = ['expires_at' => 'immutable_datetime', 'used_at' => 'immutable_datetime'];
}
