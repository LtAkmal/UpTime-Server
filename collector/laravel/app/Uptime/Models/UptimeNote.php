<?php

namespace Pterodactyl\Uptime\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A non-authoritative administrator note shown next to an incident. It never
 * changes monitoring data.
 *
 * @property int $id
 * @property int $uptime_node_id
 * @property int|null $uptime_outage_id
 * @property string $body
 * @property int|null $created_by
 * @property \Carbon\CarbonImmutable $created_at
 */
class UptimeNote extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'uptime_notes';

    protected $guarded = ['id'];

    protected $casts = ['created_at' => 'immutable_datetime'];
}
