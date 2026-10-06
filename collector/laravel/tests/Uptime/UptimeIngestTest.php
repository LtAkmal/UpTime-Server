<?php

namespace Pterodactyl\Tests\Integration\Uptime;

use Pterodactyl\Uptime\Models\UptimeKey;
use Pterodactyl\Uptime\Models\UptimeNode;
use Pterodactyl\Uptime\Models\UptimeEvent;
use Pterodactyl\Uptime\Protocol\Protocol;
use Pterodactyl\Uptime\Models\UptimeOutage;
use Pterodactyl\Uptime\Models\UptimeAnomaly;
use Pterodactyl\Uptime\Services\UptimeSettings;

class UptimeIngestTest extends UptimeTestCase
{
    public function testValidSignedEventIsAcceptedAndChained(): void
    {
        $this->send('boot')->assertCreated()->assertJsonPath('status', 'accepted')->assertJsonPath('seq', 1);
        $first = UptimeEvent::query()->sole();
        $this->assertSame(Protocol::GENESIS, $first->prev_hash);
        $this->assertSame('valid', $first->verification);
        $this->assertSame($this->nowMs(), $first->received_at);

        $this->beats(2);
        $node = $this->node->refresh();
        $this->assertSame(3, $node->events_count);
        $this->assertSame($this->head, $node->head_hash);
        $this->assertSame($first->received_at, $node->monitoring_started_at);
        $events = UptimeEvent::query()->orderBy('id')->get();
        $this->assertSame($events[0]->event_hash, $events[1]->prev_hash);
        $this->assertSame($events[1]->event_hash, $events[2]->prev_hash);
        $this->assertSame(3, $this->key->refresh()->last_seq);
    }

    public function testInvalidSignaturesAndKeysAreRejected(): void
    {
        $this->admin();
        $payload = $this->payload('boot');
        // Signature by another key.
        [, $otherSecret] = $this->registerKey(UptimeNode::query()->create(['public_id' => 'nd-other', 'display_name' => 'Other']));
        $this->postEvent($payload, $this->sign($payload, $otherSecret))->assertForbidden()->assertJsonPath('error', 'invalid_signature');
        // Modified payload (signature over the original).
        $signature = $this->sign($payload);
        $this->postEvent(str_replace('"status":"ok"', '"status":"degraded"', $payload), $signature)->assertForbidden()->assertJsonPath('error', 'invalid_signature');
        // Garbage signature.
        $this->postEvent($payload, base64_encode(random_bytes(64)))->assertForbidden();
        $this->postEvent($payload, 'not-base64!')->assertStatus(400);
        // Unknown node and unregistered key.
        $this->postEvent($this->payload('boot', ['node' => 'nd-nope']))->assertNotFound()->assertJsonPath('error', 'unknown_node');
        $this->postEvent($this->payload('boot', ['key' => str_repeat('d', 64)]))->assertForbidden()->assertJsonPath('error', 'unknown_key');
        // Non-canonical bytes are rejected even with a valid signature over them.
        $spaced = str_replace(',"type"', ', "type"', $payload);
        $this->postEvent($spaced, $this->sign($spaced))->assertStatus(400)->assertJsonPath('error', 'invalid_payload');

        $this->assertSame(0, UptimeEvent::query()->count(), 'nothing stored');
        $anomaly = UptimeAnomaly::query()->where('kind', 'invalid_signature')->sole();
        $this->assertSame('critical', $anomaly->severity);
        $this->assertSame(3, $anomaly->occurrences, 'repeated failures are counted, not duplicated');
        $this->assertTrue(UptimeAnomaly::query()->where('kind', 'unknown_key')->exists());
        $this->assertDatabaseHas('local_messages', ['subject' => 'Jakarta 1: Invalid signature']);
    }

    public function testRevokedKeyAndDisabledNodeAreRejected(): void
    {
        $this->send('boot')->assertCreated();
        $this->key->forceFill(['status' => UptimeKey::STATUS_REVOKED, 'revoked_at' => $this->nowMs()])->save();
        $this->advance(30);
        $this->send()->assertForbidden()->assertJsonPath('error', 'revoked_key');

        $this->key->forceFill(['status' => UptimeKey::STATUS_ACTIVE, 'revoked_at' => null])->save();
        $this->node->forceFill(['monitoring_enabled' => false])->save();
        $this->send()->assertForbidden()->assertJsonPath('error', 'monitoring_disabled');
        $this->assertSame(1, UptimeEvent::query()->count());
    }

    public function testReplayDuplicatesAndSequenceRules(): void
    {
        $bootPayload = $this->payload('boot');
        $this->postEvent($bootPayload)->assertCreated();
        $this->seq = 1;
        $this->head = UptimeEvent::query()->sole()->event_hash;

        // Retrying the latest event (lost reply) is idempotent.
        $this->postEvent($bootPayload)->assertOk()->assertJsonPath('status', 'duplicate')->assertJsonPath('event_hash', $this->head);
        $this->assertSame(1, UptimeEvent::query()->count());

        $this->beats(2);
        // Replaying an older accepted event is rejected.
        $this->postEvent($bootPayload)->assertStatus(409)->assertJsonPath('error', 'replay');
        // Same sequence with different content: equivocation.
        $this->postEvent($this->payload('heartbeat', ['seq' => 2, 'status' => 'degraded']))->assertStatus(409)->assertJsonPath('error', 'duplicate_sequence');
        $this->assertSame('critical', UptimeAnomaly::query()->where('kind', 'duplicate_sequence')->value('severity'));
        // A lower, never-used sequence is stale.
        $this->key->forceFill(['last_seq' => 10])->save();
        $this->postEvent($this->payload('heartbeat', ['seq' => 5]))->assertStatus(409)->assertJsonPath('error', 'stale_sequence')->assertJsonPath('last_seq', 10);
        $this->key->forceFill(['last_seq' => 3])->save();

        // Gaps are allowed (unsent heartbeats).
        $this->advance(30);
        $this->send('heartbeat', ['seq' => 9])->assertCreated();
        $this->assertSame(9, $this->key->refresh()->last_seq);
        $this->assertSame(4, UptimeEvent::query()->count());
    }

    public function testTimestampWindowIsEnforced(): void
    {
        $this->send('boot')->assertCreated();
        $this->advance(30);
        $this->send('heartbeat', ['ts' => $this->nowMs() - 121_000])->assertStatus(422)->assertJsonPath('error', 'timestamp_out_of_window');
        $this->send('heartbeat', ['ts' => $this->nowMs() + 121_000])->assertStatus(422);
        $this->assertTrue(UptimeAnomaly::query()->where('kind', 'clock_skew')->exists());
        // Within the window but drifting more than half of it: accepted and flagged.
        $this->send('heartbeat', ['ts' => $this->nowMs() + 70_000])->assertCreated();
        $this->assertTrue(UptimeAnomaly::query()->where('kind', 'clock_drift')->exists());
    }

    public function testOutOfOrderEventsAreRejectedWithTheChainHead(): void
    {
        $this->send('boot')->assertCreated();
        $this->advance(30);
        $this->send('heartbeat', ['prev' => str_repeat('1', 64)])->assertStatus(409)
            ->assertJsonPath('error', 'prev_mismatch')->assertJsonPath('head', $this->head)->assertJsonPath('last_seq', 1);
        $this->assertSame(1, UptimeEvent::query()->count());
    }

    public function testMonotonicAndBuildAnomaliesAreRecordedWithoutBlockingSignedEvents(): void
    {
        app(UptimeSettings::class)->set('trusted_builds', '0.1.0 ' . str_repeat('c', 64));
        $this->send('boot')->assertCreated();
        $this->advance(30);
        // Time since boot went down without a reboot.
        $this->send('heartbeat', ['mono_ms' => 1000])->assertCreated();
        $this->assertSame('warning', UptimeAnomaly::query()->where('kind', 'monotonic_regression')->value('severity'));
        // Wall clock went back.
        $this->advance(30);
        $this->send('heartbeat', ['ts' => $this->nowMs() - 65_000, 'mono_ms' => $this->nowMs() - $this->bootedAt])->assertCreated();
        $this->assertTrue(UptimeAnomaly::query()->where('kind', 'clock_rollback')->exists());
        // New boot session without a boot event.
        $this->advance(30);
        $this->send('heartbeat', ['boot' => str_repeat('e', 32)])->assertCreated();
        $this->assertTrue(UptimeAnomaly::query()->where('kind', 'reboot_without_boot_event')->exists());
        // Binary replaced without a version change, and not a published build.
        $this->advance(30);
        $this->send('start', ['boot' => str_repeat('e', 32), 'agent_sha256' => str_repeat('f', 64)])->assertCreated();
        $this->assertSame('warning', UptimeAnomaly::query()->where('kind', 'agent_build_changed')->value('severity'));
        $this->assertTrue(UptimeAnomaly::query()->where('kind', 'agent_build_unrecognized')->exists());
        $this->assertSame(5, UptimeEvent::query()->count(), 'signed events are kept');
    }

    public function testRebootIsRecordedAsDowntime(): void
    {
        $this->send('boot')->assertCreated();
        $this->beats(3);
        $lastBefore = $this->nowMs();
        // Agent back after 20 s, but the machine only booted 15 s ago.
        $this->advance(20);
        $this->boot = str_repeat('9', 32);
        $this->bootedAt = $this->nowMs() - 15_000;
        $this->send('boot')->assertCreated();
        $outage = UptimeOutage::query()->sole();
        $this->assertSame('reboot', $outage->cause);
        $this->assertSame([$lastBefore, $lastBefore + 5_000], [$outage->start_ms, $outage->end_ms]);
        $this->assertFalse(UptimeAnomaly::query()->where('kind', 'reboot_without_boot_event')->exists());
    }

    public function testRequestsAreBoundedAndValidated(): void
    {
        $this->postJson('/api/uptime/agent/events', ['payload' => str_repeat('a', 9000), 'signature' => 'x'])->assertStatus(413);
        $this->postJson('/api/uptime/agent/events', ['payload' => 5])->assertStatus(422);
        $this->call('POST', '/api/uptime/agent/events', [], [], [], ['CONTENT_TYPE' => 'text/plain'], 'hello')->assertStatus(400);
        $this->getJson('/api/uptime/agent/events')->assertStatus(405);
    }
}
