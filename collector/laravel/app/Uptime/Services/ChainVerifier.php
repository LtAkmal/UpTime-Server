<?php

namespace Pterodactyl\Uptime\Services;

use Carbon\CarbonImmutable;
use Pterodactyl\Uptime\Models\UptimeKey;
use Pterodactyl\Uptime\Models\UptimeNode;
use Pterodactyl\Uptime\Models\UptimeEvent;
use Pterodactyl\Uptime\Protocol\Protocol;
use Pterodactyl\Uptime\Models\UptimeAnomaly;
use Pterodactyl\Uptime\Protocol\ProtocolException;

/**
 * Re-verifies a node's stored chain from the first event: canonical payload, schema,
 * key validity, Ed25519 signature, payload hash, signed and stored previous hash,
 * event hash, sequence order, receipt order, timestamp window, and that the queryable
 * columns match the signed payload. verify() never writes; record() stores the result.
 */
class ChainVerifier
{
    public function __construct(private UptimeAlerts $alerts)
    {
    }

    /**
     * @param iterable<UptimeEvent>|null $events override (tests); defaults to the stored chain
     *
     * @return array{valid: bool, checked: int, last_event_id: int|null, failed_event_id: int|null, failed_at: int|null, reason: string|null}
     */
    public function verify(UptimeNode $node, ?iterable $events = null): array
    {
        $keys = UptimeKey::query()->where('uptime_node_id', $node->id)->get()->keyBy('id');
        $events ??= UptimeEvent::query()->where('uptime_node_id', $node->id)->orderBy('id')->lazyById(1000);
        $skew = $node->skew_seconds * 1000;

        $expectPrev = Protocol::GENESIS;
        $lastSeq = [];
        $lastReceived = 0;
        $checked = 0;
        $lastId = null;
        $lastReceivedAt = null;
        $fail = function (UptimeEvent $e, string $reason) use (&$checked, &$lastId) {
            return [
                'valid' => false, 'checked' => $checked, 'last_event_id' => $lastId,
                'failed_event_id' => $e->id, 'failed_at' => $e->received_at, 'reason' => $reason,
            ];
        };

        foreach ($events as $e) {
            try {
                $p = Protocol::parsePayload($e->payload);
            } catch (ProtocolException $ex) {
                return $fail($e, 'payload is not a valid canonical payload: ' . $ex->getMessage());
            }
            /** @var UptimeKey|null $key */
            $key = $keys->get($e->uptime_key_id);
            if ($p['node'] !== $node->public_id) {
                return $fail($e, 'payload belongs to a different node');
            }
            if (!$key || $key->fingerprint !== $p['key']) {
                return $fail($e, 'stored key does not match the signed key fingerprint');
            }
            if (!$key->validAt($e->received_at)) {
                return $fail($e, 'key was not valid at the receipt time');
            }
            $sig = base64_decode($e->signature, true);
            if ($sig === false || !Protocol::verifySignature((string) base64_decode($key->public_key), $e->payload, $sig)) {
                return $fail($e, 'invalid signature');
            }
            if (Protocol::payloadHash($e->payload) !== $e->payload_hash) {
                return $fail($e, 'payload hash mismatch');
            }
            if ($p['prev'] !== $e->prev_hash) {
                return $fail($e, 'stored previous hash differs from the signed previous hash');
            }
            if ($e->prev_hash !== $expectPrev) {
                return $fail($e, 'chain broken: previous hash does not match the preceding event (missing, reordered or modified event)');
            }
            if (Protocol::eventHash($e->prev_hash, $e->payload_hash) !== $e->event_hash) {
                return $fail($e, 'event hash mismatch');
            }
            if (isset($lastSeq[$key->id]) && $p['seq'] <= $lastSeq[$key->id]) {
                return $fail($e, 'sequence number did not increase');
            }
            if ($e->received_at < $lastReceived) {
                return $fail($e, 'receipt time went backwards');
            }
            if (abs($p['ts'] - $e->received_at) > $skew) {
                return $fail($e, 'receipt time is outside the accepted window of the signed timestamp');
            }
            $columns = [
                'seq' => $e->seq === $p['seq'], 'type' => $e->type === $p['type'], 'agent_ts' => $e->agent_ts === $p['ts'],
                'mono_ms' => $e->mono_ms === $p['mono_ms'], 'boot_session' => $e->boot_session === $p['boot'],
                'status' => $e->status === $p['status'], 'agent_sha256' => $e->agent_sha256 === $p['agent_sha256'],
                'agent_version' => $e->agent_version === $p['agent_version'], 'agent_commit' => $e->agent_commit === $p['agent_commit'],
            ];
            if ($bad = array_keys(array_filter($columns, fn ($ok) => !$ok))) {
                return $fail($e, 'stored column(s) ' . implode(', ', $bad) . ' differ from the signed payload');
            }
            if ($checked === 0 && $node->monitoring_started_at !== $e->received_at) {
                return $fail($e, 'monitoring start does not match the first event');
            }

            $expectPrev = $e->event_hash;
            $lastSeq[$key->id] = $p['seq'];
            $lastReceived = $e->received_at;
            $lastId = $e->id;
            $lastReceivedAt = $e->received_at;
            ++$checked;
        }

        $result = ['valid' => true, 'checked' => $checked, 'last_event_id' => $lastId, 'failed_event_id' => null, 'failed_at' => null, 'reason' => null];
        if ($node->head_hash !== $expectPrev || $node->last_event_id !== $lastId) {
            $result = ['valid' => false, 'reason' => 'chain head does not match the last stored event (events were removed or added outside the collector)', 'failed_event_id' => $lastId, 'failed_at' => $lastReceivedAt] + $result;
        }

        return $result;
    }

    /**
     * Verifies and stores the result on the node (the cached verification state). A new
     * failure creates a critical anomaly and notifies administrators.
     */
    public function record(UptimeNode $node): array
    {
        $result = $this->verify($node);
        $wasFailed = $node->verification_state === UptimeNode::VERIFICATION_FAILED;
        $node->forceFill([
            'verification_state' => $result['valid'] ? UptimeNode::VERIFICATION_VALID : UptimeNode::VERIFICATION_FAILED,
            'verified_at' => CarbonImmutable::now(),
            'verified_events' => $result['checked'],
            'verified_through_id' => $result['last_event_id'],
            'verification_failed_event_id' => $result['failed_event_id'],
            'verification_failed_at' => $result['failed_at'],
            'verification_error' => $result['reason'] ? mb_substr($result['reason'], 0, 255) : null,
        ])->save();

        if (!$result['valid'] && !$wasFailed) {
            $this->alerts->anomaly($node, 'chain_verification_failed', UptimeAnomaly::CRITICAL,
                "Integrity verification failed at event #{$result['failed_event_id']}: {$result['reason']}. Public uptime for this node is shown as not verified.", $result['failed_event_id']);
        }

        return $result;
    }
}
