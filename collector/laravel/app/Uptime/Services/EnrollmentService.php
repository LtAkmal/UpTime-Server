<?php

namespace Pterodactyl\Uptime\Services;

use Carbon\CarbonImmutable;
use Pterodactyl\Models\User;
use Pterodactyl\Facades\Activity;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Uptime\Models\UptimeKey;
use Pterodactyl\Uptime\Models\UptimeNode;
use Pterodactyl\Uptime\Protocol\Protocol;
use Pterodactyl\Uptime\Models\UptimeAnomaly;
use Pterodactyl\Uptime\Models\UptimeEnrollment;

/**
 * One-time enrollment tokens and agent key registration. Only token hashes and public
 * keys are stored; private keys are generated on the node and never sent here.
 */
class EnrollmentService
{
    public const TOKEN_MINUTES = 30;

    public function __construct(private UptimeAlerts $alerts)
    {
    }

    /**
     * Creates a token and returns it in plain text exactly once. Any earlier unused
     * token for the node stops working.
     */
    public function createToken(UptimeNode $node, User $admin): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        DB::transaction(function () use ($node, $admin, $token) {
            UptimeEnrollment::query()->where('uptime_node_id', $node->id)->whereNull('used_at')->update(['expires_at' => CarbonImmutable::now()]);
            UptimeEnrollment::query()->create([
                'uptime_node_id' => $node->id,
                'token_hash' => hash('sha256', $token),
                'purpose' => $this->activeKey($node) ? 'rotate' : 'enroll',
                'expires_at' => CarbonImmutable::now()->addMinutes(self::TOKEN_MINUTES),
                'created_by' => $admin->id,
            ]);
        });
        Activity::event('admin:uptime.enrollment-token')->actor($admin)->property(['node' => $node->public_id])->log();

        return $token;
    }

    /**
     * Consumes a token and registers the agent's public key. With an existing active
     * key this is a rotation: the old key stops being accepted at this instant.
     *
     * @throws IngestException
     */
    public function enroll(string $token, string $publicKey, string $ip): UptimeKey
    {
        if (preg_match('/^[A-Za-z0-9_-]{20,128}$/', $token) !== 1) {
            throw new IngestException('invalid_token', 403, 'The enrollment token is invalid, expired or already used.');
        }
        $raw = base64_decode($publicKey, true);
        if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new IngestException('invalid_public_key', 422, 'public_key must be a base64 Ed25519 public key.');
        }

        $result = DB::transaction(function () use ($token, $raw) {
            /** @var UptimeEnrollment|null $enrollment */
            $enrollment = UptimeEnrollment::query()->lockForUpdate()->where('token_hash', hash('sha256', $token))->first();
            if (!$enrollment || $enrollment->used_at || $enrollment->expires_at->isPast()) {
                return null;
            }
            /** @var UptimeNode $node */
            $node = UptimeNode::query()->lockForUpdate()->findOrFail($enrollment->uptime_node_id);
            if ($node->archived_at) {
                return null;
            }
            $fingerprint = Protocol::fingerprint($raw);
            if (UptimeKey::query()->where('fingerprint', $fingerprint)->exists()) {
                throw new IngestException('key_in_use', 409, 'This public key is already registered; generate a new key.');
            }
            $key = $this->register($node, base64_encode($raw), 'enrollment', null);
            $enrollment->forceFill(['used_at' => CarbonImmutable::now(), 'used_fingerprint' => $fingerprint])->save();

            return $key;
        });

        if (!$result) {
            $this->alerts->anomaly(null, 'enrollment_failed', UptimeAnomaly::NOTICE, "Enrollment attempt with an invalid, expired or used token from {$ip}.");
            throw new IngestException('invalid_token', 403, 'The enrollment token is invalid, expired or already used.');
        }
        Activity::event('uptime:agent.enrolled')->property(['node' => $result->node->public_id, 'fingerprint' => $result->fingerprint])->log();

        return $result;
    }

    /**
     * Registers a public key pasted by an administrator.
     */
    public function registerManual(UptimeNode $node, string $publicKey, User $admin): UptimeKey
    {
        $raw = base64_decode(trim($publicKey), true);
        if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new \InvalidArgumentException('The public key must be a base64 Ed25519 public key (44 characters).');
        }
        if (UptimeKey::query()->where('fingerprint', Protocol::fingerprint($raw))->exists()) {
            throw new \InvalidArgumentException('This public key is already registered.');
        }
        $key = DB::transaction(fn () => $this->register(UptimeNode::query()->lockForUpdate()->findOrFail($node->id), base64_encode($raw), 'manual', $admin));
        Activity::event('admin:uptime.key-registered')->actor($admin)->property(['node' => $node->public_id, 'fingerprint' => $key->fingerprint])->log();

        return $key;
    }

    public function revoke(UptimeKey $key, string $reason, User $admin): void
    {
        if ($key->status !== UptimeKey::STATUS_ACTIVE) {
            return;
        }
        $key->forceFill(['status' => UptimeKey::STATUS_REVOKED, 'revoked_at' => CarbonImmutable::now()->getTimestampMs(), 'revoke_reason' => mb_substr($reason, 0, 255)])->save();
        Activity::event('admin:uptime.key-revoked')->actor($admin)->property(['node' => $key->node->public_id, 'fingerprint' => $key->fingerprint, 'reason' => $reason])->log();
        $this->alerts->anomaly($key->node, 'key_revoked', UptimeAnomaly::NOTICE, "Key {$key->fingerprint} was revoked by {$admin->username}: {$reason}", null, true, true);
    }

    public function activeKey(UptimeNode $node): ?UptimeKey
    {
        return UptimeKey::query()->where('uptime_node_id', $node->id)->where('status', UptimeKey::STATUS_ACTIVE)->first();
    }

    private function register(UptimeNode $node, string $publicKey, string $source, ?User $admin): UptimeKey
    {
        $now = CarbonImmutable::now()->getTimestampMs();
        $replaced = UptimeKey::query()->where('uptime_node_id', $node->id)->where('status', UptimeKey::STATUS_ACTIVE)->get();
        foreach ($replaced as $old) {
            $old->forceFill(['status' => UptimeKey::STATUS_ROTATED, 'revoked_at' => $now, 'revoke_reason' => 'Replaced by a newly registered key.'])->save();
        }
        $raw = (string) base64_decode($publicKey);
        $key = UptimeKey::query()->create([
            'uptime_node_id' => $node->id,
            'fingerprint' => Protocol::fingerprint($raw),
            'public_key' => $publicKey,
            'status' => UptimeKey::STATUS_ACTIVE,
            'source' => $source,
            'registered_at' => $now,
            'created_by' => $admin?->id,
        ]);
        $this->alerts->anomaly($node, 'key_registered', UptimeAnomaly::NOTICE,
            ($replaced->isEmpty() ? 'Agent key registered' : 'Agent key rotated (previous key ' . $replaced->pluck('fingerprint')->implode(', ') . ' no longer accepted)')
            . " via {$source}: {$key->fingerprint}.", null, true, true);

        return $key;
    }
}
