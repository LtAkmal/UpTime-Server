<?php

namespace Pterodactyl\Uptime\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Uptime\Models\UptimeKey;
use Pterodactyl\Uptime\Models\UptimeNode;
use Pterodactyl\Uptime\Models\UptimeEvent;
use Pterodactyl\Uptime\Models\UptimeOutage;
use Pterodactyl\Uptime\Protocol\Protocol;
use Pterodactyl\Uptime\Models\UptimeAnomaly;
use Pterodactyl\Uptime\Protocol\UptimeFormula;
use Pterodactyl\Uptime\Protocol\ProtocolException;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Verifies and appends agent events. Rejections never change stored data. Order of
 * checks: schema and canonical form → node → key → signature → (locked) idempotency →
 * sequence → timestamp window → chain head → append.
 */
class EventIngestor
{
    public function __construct(private UptimeAlerts $alerts, private UptimeSettings $settings)
    {
    }

    /**
     * @return array{status: string, event: UptimeEvent}
     *
     * @throws IngestException
     */
    public function ingest(string $payload, string $signature): array
    {
        try {
            $p = Protocol::parsePayload($payload);
        } catch (ProtocolException $e) {
            throw new IngestException('invalid_payload', 400, $e->getMessage());
        }
        $sig = base64_decode($signature, true);
        if ($sig === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw new IngestException('invalid_signature', 400, 'Signature must be base64 Ed25519.');
        }

        /** @var UptimeNode|null $node */
        $node = UptimeNode::query()->where('public_id', $p['node'])->first();
        if (!$node || !is_null($node->archived_at)) {
            throw new IngestException('unknown_node', 404, 'Unknown node or key.');
        }
        if (!$node->monitoring_enabled) {
            throw new IngestException('monitoring_disabled', 403, 'Monitoring is disabled for this node.');
        }
        /** @var UptimeKey|null $key */
        $key = UptimeKey::query()->where('uptime_node_id', $node->id)->where('fingerprint', $p['key'])->first();
        if (!$key) {
            $this->alerts->anomaly($node, 'unknown_key', UptimeAnomaly::WARNING, "Event signed with key {$p['key']}, which is not registered for this node.");
            throw new IngestException('unknown_key', 403, 'Unknown node or key.');
        }
        if ($key->status !== UptimeKey::STATUS_ACTIVE) {
            $this->alerts->anomaly($node, 'revoked_key', UptimeAnomaly::WARNING, "Event signed with {$key->status} key {$key->fingerprint}.");
            throw new IngestException('revoked_key', 403, 'This key is no longer valid for the node.');
        }
        if (!Protocol::verifySignature((string) base64_decode($key->public_key), $payload, $sig)) {
            $this->alerts->anomaly($node, 'invalid_signature', UptimeAnomaly::CRITICAL, "Signature verification failed for an event claiming key {$key->fingerprint}, sequence {$p['seq']}.");
            throw new IngestException('invalid_signature', 403, 'Signature verification failed.');
        }

        $payloadHash = Protocol::payloadHash($payload);
        $eventHash = Protocol::eventHash($p['prev'], $payloadHash);

        $rejection = null;
        try {
            return DB::transaction(function () use ($node, $key, $p, $payload, $signature, $payloadHash, $eventHash, &$rejection) {
            /** @var UptimeNode $node */
            $node = UptimeNode::query()->lockForUpdate()->findOrFail($node->id);
            /** @var UptimeKey $key */
            $key = UptimeKey::query()->lockForUpdate()->findOrFail($key->id);
            $now = CarbonImmutable::now()->getTimestampMs();

            $existing = UptimeEvent::query()->where('event_hash', $eventHash)->first();
            if ($existing) {
                if ($existing->id === $node->last_event_id) {
                    return ['status' => 'duplicate', 'event' => $existing];
                }
                $rejection = ['replay', UptimeAnomaly::NOTICE, "An already accepted event (#{$existing->id}, sequence {$p['seq']}) was sent again after newer events."];
                throw new IngestException('replay', 409, 'This event was already accepted earlier.', ['last_seq' => $key->last_seq, 'head' => $node->head_hash]);
            }
            if ($p['seq'] <= $key->last_seq) {
                if (UptimeEvent::query()->where('uptime_key_id', $key->id)->where('seq', $p['seq'])->exists()) {
                    $rejection = ['duplicate_sequence', UptimeAnomaly::CRITICAL, "Key {$key->fingerprint} signed different content for sequence {$p['seq']} (possible cloned agent or state rollback)."];
                    throw new IngestException('duplicate_sequence', 409, 'This sequence number was already used with different content.', ['last_seq' => $key->last_seq, 'head' => $node->head_hash]);
                }
                throw new IngestException('stale_sequence', 409, 'Sequence numbers must increase.', ['last_seq' => $key->last_seq, 'head' => $node->head_hash]);
            }
            $skew = $node->skew_seconds * 1000;
            if (abs($p['ts'] - $now) > $skew) {
                $rejection = ['clock_skew', UptimeAnomaly::WARNING, sprintf('Agent clock differs from the collector by %.1f s (accepted: %d s).', ($p['ts'] - $now) / 1000, $node->skew_seconds)];
                throw new IngestException('timestamp_out_of_window', 422, 'The event timestamp is outside the accepted window.', ['server_time' => $now]);
            }
            if ($p['prev'] !== $node->head_hash) {
                throw new IngestException('prev_mismatch', 409, 'The event does not continue the current chain head.', ['head' => $node->head_hash, 'last_seq' => $key->last_seq]);
            }

            /** @var UptimeEvent|null $previous */
            $previous = $node->last_event_id ? UptimeEvent::query()->find($node->last_event_id) : null;
            try {
                $event = UptimeEvent::query()->create([
                    'uptime_node_id' => $node->id,
                    'uptime_key_id' => $key->id,
                    'seq' => $p['seq'],
                    'type' => $p['type'],
                    'payload' => $payload,
                    'payload_hash' => $payloadHash,
                    'prev_hash' => $p['prev'],
                    'event_hash' => $eventHash,
                    'signature' => $signature,
                    'verification' => 'valid',
                    'received_at' => $now,
                    'agent_ts' => $p['ts'],
                    'mono_ms' => $p['mono_ms'],
                    'boot_session' => $p['boot'],
                    'status' => $p['status'],
                    'agent_version' => $p['agent_version'],
                    'agent_commit' => $p['agent_commit'],
                    'agent_sha256' => $p['agent_sha256'],
                ]);
            } catch (UniqueConstraintViolationException) {
                throw new IngestException('prev_mismatch', 409, 'The chain moved; fetch the head and try again.', ['head' => $node->head_hash, 'last_seq' => $key->last_seq]);
            }

            $key->forceFill(['last_seq' => $p['seq']])->save();
            $wasOffline = $node->alert_state === 'offline';
            $node->forceFill([
                'head_hash' => $eventHash,
                'last_event_id' => $event->id,
                'last_received_at' => $now,
                'events_count' => $node->events_count + 1,
                'monitoring_started_at' => $node->monitoring_started_at ?? $now,
                'alert_state' => 'online',
            ])->save();

            if ($previous) {
                foreach (UptimeFormula::gapOutages($previous->point(), $event->point(), $node->params()) as $o) {
                    UptimeOutage::query()->create(['uptime_node_id' => $node->id, 'start_ms' => $o['start'], 'end_ms' => $o['end'], 'cause' => $o['cause'], 'before_event_id' => $event->id]);
                }
            }
            $this->observe($node, $event, $previous, $p, $now);
            if ($wasOffline) {
                $this->alerts->notify($node, "uptime:online:{$event->id}", "{$node->display_name} is back online", 'A valid signed heartbeat was received again at ' . CarbonImmutable::createFromTimestampMs($now)->toIso8601String() . '.');
            }

            return ['status' => 'accepted', 'event' => $event];
            });
        } catch (IngestException $e) {
            // Recorded after the rollback so that the anomaly itself is kept.
            if ($rejection) {
                $this->alerts->anomaly($node, $rejection[0], $rejection[1], $rejection[2]);
            }

            throw $e;
        }
    }

    /**
     * Accepted-but-suspicious observations. They never block a validly signed event;
     * they are recorded and shown (without details) on the public page.
     */
    private function observe(UptimeNode $node, UptimeEvent $event, ?UptimeEvent $previous, array $p, int $now): void
    {
        $skew = $node->skew_seconds * 1000;
        if (abs($p['ts'] - $now) > $skew / 2) {
            $this->alerts->anomaly($node, 'clock_drift', UptimeAnomaly::NOTICE, sprintf('Agent clock differs from the collector by %.1f s.', ($p['ts'] - $now) / 1000), $event->id, false);
        }

        $trusted = $this->settings->trustedBuilds();
        $buildChanged = $previous && $previous->agent_sha256 !== $event->agent_sha256;
        if ($trusted !== [] && !isset($trusted[$event->agent_sha256]) && (!$previous || $buildChanged || $event->type !== 'heartbeat')) {
            $this->alerts->anomaly($node, 'agent_build_unrecognized', UptimeAnomaly::WARNING, "Agent {$event->agent_version} reports SHA-256 {$event->agent_sha256}, which is not in the published release list.", $event->id);
        }
        if (!$previous) {
            return;
        }

        if ($buildChanged) {
            $sameVersion = $previous->agent_version === $event->agent_version;
            $this->alerts->anomaly($node, 'agent_build_changed', $sameVersion ? UptimeAnomaly::WARNING : UptimeAnomaly::NOTICE,
                "Agent binary changed from {$previous->agent_version} ({$previous->agent_sha256}) to {$event->agent_version} ({$event->agent_sha256})" . ($sameVersion ? ' without a version change.' : '.'), $event->id);
        }
        if ($previous->agent_commit !== $event->agent_commit) {
            $this->alerts->anomaly($node, 'agent_commit_changed', UptimeAnomaly::NOTICE, "Agent source commit changed from {$previous->agent_commit} to {$event->agent_commit}.", $event->id);
        }
        if ($event->agent_ts < $previous->agent_ts) {
            $this->alerts->anomaly($node, 'clock_rollback', UptimeAnomaly::WARNING, sprintf('Agent wall clock went back %.1f s between events.', ($previous->agent_ts - $event->agent_ts) / 1000), $event->id);
        }
        if ($event->boot_session === $previous->boot_session) {
            if ($event->mono_ms < $previous->mono_ms) {
                $this->alerts->anomaly($node, 'monotonic_regression', UptimeAnomaly::WARNING, "Time since boot decreased from {$previous->mono_ms} ms to {$event->mono_ms} ms without a new boot session.", $event->id);
            } elseif (abs(($event->mono_ms - $previous->mono_ms) - ($event->received_at - $previous->received_at)) > $skew) {
                $this->alerts->anomaly($node, 'uptime_drift', UptimeAnomaly::WARNING, 'Agent uptime advanced ' . ($event->mono_ms - $previous->mono_ms) . ' ms while ' . ($event->received_at - $previous->received_at) . ' ms passed at the collector.', $event->id);
            }
        } elseif ($event->type !== 'boot') {
            $this->alerts->anomaly($node, 'reboot_without_boot_event', UptimeAnomaly::WARNING, "Boot session changed on a {$event->type} event.", $event->id);
        } elseif ($event->received_at - $event->mono_ms < $previous->received_at - $skew) {
            $this->alerts->anomaly($node, 'boot_time_inconsistent', UptimeAnomaly::WARNING, 'The boot event claims the machine booted before the previous event from the old boot session was received.', $event->id);
        }
    }
}
