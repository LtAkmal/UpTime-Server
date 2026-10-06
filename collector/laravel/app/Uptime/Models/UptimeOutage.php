<?php

namespace Pterodactyl\Uptime\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Confirmed downtime derived from the events (rebuildable with uptime:rebuild-outages).
 *
 * @property int $id
 * @property int $uptime_node_id
 * @property int $start_ms
 * @property int $end_ms
 * @property string $cause
 * @property int $before_event_id
 */
class UptimeOutage extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'uptime_outages';

    protected $guarded = ['id'];

    protected $casts = ['start_ms' => 'integer', 'end_ms' => 'integer', 'before_event_id' => 'integer'];

    public function durationMs(): int
    {
        return $this->end_ms - $this->start_ms;
    }
}
