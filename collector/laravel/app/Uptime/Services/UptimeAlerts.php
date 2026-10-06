<?php

namespace Pterodactyl\Uptime\Services;

use Carbon\CarbonImmutable;
use Pterodactyl\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Pterodactyl\Uptime\Models\UptimeNode;
use Pterodactyl\Uptime\Models\UptimeAnomaly;
use Pterodactyl\Services\Mail\LocalMailService;

/**
 * Records anomalies (de-duplicated while open) and notifies administrators through the
 * panel's local Mail and notification feed. Customers are never messaged from here:
 * the public status page is their source.
 */
class UptimeAlerts
{
    public function __construct(private LocalMailService $mail)
    {
    }

    /**
     * Records an anomaly, or counts another occurrence of the same open one.
     * Informational entries (key changes) are stored already resolved, one per event.
     */
    public function anomaly(?UptimeNode $node, string $kind, string $severity, string $detail, ?int $eventId = null, bool $notify = true, bool $informational = false): UptimeAnomaly
    {
        $now = CarbonImmutable::now();
        $open = $informational ? null : UptimeAnomaly::query()
            ->where('uptime_node_id', $node?->id)
            ->where('kind', $kind)
            ->whereNull('resolved_at')
            ->first();
        if ($open) {
            $open->forceFill(['occurrences' => $open->occurrences + 1, 'last_seen_at' => $now])->save();

            return $open;
        }

        $anomaly = UptimeAnomaly::query()->create([
            'uptime_node_id' => $node?->id,
            'uptime_event_id' => $eventId,
            'kind' => $kind,
            'severity' => $severity,
            'detail' => mb_substr($detail, 0, 500),
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'resolved_at' => $informational ? $now : null,
            'resolution_note' => $informational ? 'Informational record.' : null,
        ]);

        if ($notify) {
            $label = UptimeAnomaly::LABELS[$kind] ?? $kind;
            $this->notify(
                $node,
                "uptime:anomaly:{$anomaly->id}",
                ($node ? "{$node->display_name}: " : 'Uptime monitoring: ') . $label,
                $label . ".\n\n" . $anomaly->detail . "\n\nReview it under Admin → Uptime Monitoring. Monitoring data is unchanged.",
                $severity === UptimeAnomaly::NOTICE ? 'normal' : 'high',
            );
        }

        return $anomaly;
    }

    /**
     * Sends a local mail (and feed entry) to every root administrator. The key makes it
     * idempotent. Notification failures never break monitoring.
     */
    public function notify(?UptimeNode $node, string $key, string $subject, string $body, string $priority = 'normal'): void
    {
        $send = function () use ($node, $key, $subject, $body, $priority) {
            try {
                $url = $node ? '/admin/uptime/nodes/' . $node->id : '/admin/uptime';
                foreach (User::query()->where('root_admin', true)->get() as $admin) {
                    $this->mail->system($admin, 'maintenance', $subject, $body, $url, $key . ':' . $admin->id, $priority);
                }
            } catch (\Throwable $e) {
                Log::warning('Uptime notification failed.', ['key' => $key, 'exception' => get_class($e)]);
            }
        };

        DB::transactionLevel() > 0 ? DB::afterCommit($send) : $send();
    }
}
