<?php

namespace Pterodactyl\Uptime\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Pterodactyl\Uptime\Models\UptimeNode;
use Pterodactyl\Uptime\Services\StatusReport;
use Pterodactyl\Uptime\Services\UptimeAlerts;
use Pterodactyl\Uptime\Services\UptimeSettings;

/**
 * Runs every minute: records that the collector is alive and notifies administrators
 * when a node goes offline. (Offline state itself is computed at read time from the
 * receipt time, so it never depends on this command.)
 */
class CheckCommand extends Command
{
    protected $signature = 'uptime:check';

    protected $description = 'Detect offline nodes and record the collector health tick.';

    public function handle(StatusReport $report, UptimeAlerts $alerts, UptimeSettings $settings): int
    {
        $now = StatusReport::nowMs();
        $settings->set('last_check_ms', (string) $now);

        foreach (UptimeNode::query()->whereNull('archived_at')->where('monitoring_enabled', true)->whereNotNull('last_received_at')->get() as $node) {
            if ($report->state($node, $now) !== 'offline' || $node->alert_state === 'offline') {
                continue;
            }
            $node->forceFill(['alert_state' => 'offline'])->save();
            $since = CarbonImmutable::createFromTimestampMs($node->last_received_at)->toIso8601String();
            $alerts->notify($node, "uptime:offline:{$node->id}:{$node->last_event_id}", "{$node->display_name} is offline",
                "No valid signed heartbeat since {$since} (timeout {$node->timeout_seconds} s + tolerance {$node->tolerance_seconds} s).", 'high');
            $this->line("offline: {$node->public_id}");
        }

        return 0;
    }
}
