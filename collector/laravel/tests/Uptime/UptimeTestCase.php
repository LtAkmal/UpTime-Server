<?php

namespace Pterodactyl\Tests\Integration\Uptime;

use Carbon\CarbonImmutable;
use Pterodactyl\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Pterodactyl\Uptime\Models\UptimeKey;
use Pterodactyl\Uptime\Models\UptimeNode;
use Pterodactyl\Uptime\Protocol\Protocol;
use Pterodactyl\Tests\Integration\IntegrationTestCase;

/**
 * Shared helpers: a node with a real Ed25519 key, and an "agent" that signs canonical
 * payloads exactly like uptime-agent does.
 */
abstract class UptimeTestCase extends IntegrationTestCase
{
    protected UptimeNode $node;
    protected UptimeKey $key;
    protected string $secret;
    protected int $seq = 0;
    protected string $head = Protocol::GENESIS;
    protected string $boot;
    protected int $bootedAt; // Unix ms when the simulated machine booted

    public function setUp(): void
    {
        parent::setUp();

        defined('LARAVEL_START') || define('LARAVEL_START', microtime(true));
        $this->resetUptime();
    }

    /**
     * Empties the uptime tables and creates a fresh node, key and simulated agent.
     */
    protected function resetUptime(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['uptime_events', 'uptime_outages', 'uptime_anomalies', 'uptime_notes', 'uptime_enrollments', 'uptime_keys', 'uptime_nodes', 'uptime_settings'] as $table) {
            DB::table($table)->truncate();
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        DB::table('local_messages')->where('idempotency_key', 'like', 'uptime:%')->delete();
        $this->app['cache']->flush();
        $this->app->forgetInstance(\Pterodactyl\Uptime\Services\UptimeSettings::class);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-06 12:00:00', 'UTC'));
        $this->node = UptimeNode::query()->create(['public_id' => 'nd-test-01', 'display_name' => 'Jakarta 1']);
        [$this->key, $this->secret] = $this->registerKey($this->node);
        $this->boot = str_repeat('a', 32);
        $this->bootedAt = $this->nowMs() - 3_600_000;
        $this->seq = 0;
        $this->head = Protocol::GENESIS;
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    protected function nowMs(): int
    {
        return CarbonImmutable::now()->getTimestampMs();
    }

    protected function advance(int $seconds): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds($seconds));
    }

    /**
     * @return array{0: UptimeKey, 1: string} the key and its secret (Ed25519 64-byte secret key)
     */
    protected function registerKey(UptimeNode $node, string $status = UptimeKey::STATUS_ACTIVE): array
    {
        $pair = sodium_crypto_sign_keypair();
        $public = sodium_crypto_sign_publickey($pair);
        $key = UptimeKey::query()->create([
            'uptime_node_id' => $node->id,
            'fingerprint' => Protocol::fingerprint($public),
            'public_key' => base64_encode($public),
            'status' => $status,
            'source' => 'manual',
            'registered_at' => $this->nowMs() - 60_000,
        ]);

        return [$key, sodium_crypto_sign_secretkey($pair)];
    }

    /**
     * The next payload of the simulated agent (not yet sent).
     */
    protected function payload(string $type = 'heartbeat', array $overrides = []): string
    {
        $fields = array_merge([
            'proto' => 1,
            'type' => $type,
            'node' => $this->node->public_id,
            'key' => $this->key->fingerprint,
            'seq' => $this->seq + 1,
            'ts' => $this->nowMs(),
            'mono_ms' => $this->nowMs() - $this->bootedAt,
            'boot' => $this->boot,
            'run' => str_repeat('b', 32),
            'status' => 'ok',
            'checks_failed' => 0,
            'prev' => $this->head,
            'agent_version' => '0.1.0',
            'agent_commit' => 'unknown',
            'agent_build' => 'unknown',
            'agent_sha256' => str_repeat('c', 64),
        ], $overrides);

        return Protocol::encode($fields);
    }

    protected function sign(string $payload, ?string $secret = null): string
    {
        return base64_encode(sodium_crypto_sign_detached(Protocol::SIGNATURE_CONTEXT . $payload, $secret ?? $this->secret));
    }

    protected function postEvent(string $payload, ?string $signature = null): TestResponse
    {
        return $this->postJson('/api/uptime/agent/events', ['payload' => $payload, 'signature' => $signature ?? $this->sign($payload)]);
    }

    /**
     * Signs and sends the next event and advances the simulated agent on success.
     */
    protected function send(string $type = 'heartbeat', array $overrides = []): TestResponse
    {
        $payload = $this->payload($type, $overrides);
        $response = $this->postEvent($payload);
        if ($response->status() === 201) {
            $this->seq = Protocol::decode($payload)['seq'];
            $this->head = $response->json('event_hash');
        }

        return $response;
    }

    /**
     * Heartbeats every $interval seconds for $count beats (advancing the clock first).
     */
    protected function beats(int $count, int $interval = 30): void
    {
        for ($i = 0; $i < $count; ++$i) {
            $this->advance($interval);
            $this->send()->assertCreated();
        }
    }

    protected function admin(): User
    {
        return User::factory()->create(['root_admin' => true]);
    }
}
