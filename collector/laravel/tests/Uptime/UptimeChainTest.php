<?php

namespace Pterodactyl\Tests\Integration\Uptime;

use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use Pterodactyl\Uptime\Models\UptimeNode;
use Pterodactyl\Uptime\Models\UptimeEvent;
use Pterodactyl\Uptime\Models\UptimeAnomaly;
use Pterodactyl\Uptime\Services\ChainVerifier;

class UptimeChainTest extends UptimeTestCase
{
    private function chain(int $beats = 6): void
    {
        $this->send('boot')->assertCreated();
        $this->beats($beats);
    }

    /**
     * Simulates someone with direct database access (the append-only triggers dropped),
     * then restores the triggers.
     */
    private function tamper(callable $change): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS uptime_events_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS uptime_events_no_delete');
        try {
            $change();
        } finally {
            DB::unprepared("CREATE TRIGGER uptime_events_no_update BEFORE UPDATE ON uptime_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uptime_events is append-only'");
            DB::unprepared("CREATE TRIGGER uptime_events_no_delete BEFORE DELETE ON uptime_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uptime_events is append-only'");
        }
    }

    private function verify(): array
    {
        return app(ChainVerifier::class)->verify($this->node->refresh());
    }

    private function eventId(int $n): int
    {
        return (int) UptimeEvent::query()->orderBy('id')->skip($n - 1)->value('id');
    }

    public function testValidChainPassesAndTheCommandExitsZero(): void
    {
        $this->chain();
        $result = $this->verify();
        $this->assertTrue($result['valid']);
        $this->assertSame(7, $result['checked']);

        $this->artisan('uptime:verify-chain', ['--node' => 'nd-test-01'])->expectsOutputToContain('VALID')->assertExitCode(0);
        $this->assertSame('pending', $this->node->refresh()->verification_state, 'read-only without --record');
        $this->artisan('uptime:verify-chain', ['--all' => true, '--record' => true])->assertExitCode(0);
        $this->assertSame('valid', $this->node->refresh()->verification_state);
    }

    public function testStoredEventsCannotBeChangedOrDeletedNormally(): void
    {
        $this->chain(1);
        $event = UptimeEvent::query()->first();
        try {
            $event->forceFill(['status' => 'degraded'])->save();
            $this->fail('model update allowed');
        } catch (\LogicException) {
        }
        try {
            $event->delete();
            $this->fail('model delete allowed');
        } catch (\LogicException) {
        }
        // Even raw queries are refused by the triggers.
        try {
            DB::table('uptime_events')->where('id', $event->id)->update(['received_at' => 1]);
            $this->fail('raw update allowed');
        } catch (QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }
        try {
            DB::table('uptime_events')->where('id', $event->id)->delete();
            $this->fail('raw delete allowed');
        } catch (QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }
    }

    public function testModifiedPayloadFailsFromTheFirstBrokenEvent(): void
    {
        $this->chain();
        $target = $this->eventId(4);
        $this->tamper(fn () => DB::table('uptime_events')->where('id', $target)->update([
            'payload' => str_replace('"status":"ok"', '"status":"degraded"', UptimeEvent::query()->find($target)->payload),
            'status' => 'degraded',
        ]));
        $result = $this->verify();
        $this->assertFalse($result['valid']);
        $this->assertSame($target, $result['failed_event_id']);
        $this->assertSame(3, $result['checked']);
        $this->assertStringContainsString('signature', $result['reason']);

        $this->artisan('uptime:verify-chain', ['--node' => 'nd-test-01'])->expectsOutputToContain("first broken record is event #{$target}")->assertExitCode(1);
    }

    public function testDeletedMiddleEventBreaksTheChain(): void
    {
        $this->chain();
        $target = $this->eventId(3);
        $this->tamper(fn () => DB::table('uptime_events')->where('id', $target)->delete());
        $result = $this->verify();
        $this->assertFalse($result['valid']);
        $this->assertSame($this->eventId(3), $result['failed_event_id'], 'fails at the event after the gap');
        $this->assertStringContainsString('chain broken', $result['reason']);
    }

    public function testChangedHashesAndColumnsAreDetected(): void
    {
        foreach ([
            'prev_hash' => fn ($id) => ['prev_hash' => str_repeat('1', 64)],
            'payload_hash' => fn ($id) => ['payload_hash' => str_repeat('2', 64)],
            'event_hash' => fn ($id) => ['event_hash' => str_repeat('3', 64)],
            'received_at' => fn ($id) => ['received_at' => UptimeEvent::query()->find($id)->received_at + 600_000],
            'type' => fn ($id) => ['type' => 'boot'],
            'mono_ms' => fn ($id) => ['mono_ms' => 1],
        ] as $column => $change) {
            $this->resetUptime();
            $this->chain(4);
            $target = $this->eventId(3);
            $values = $change($target);
            $this->tamper(fn () => DB::table('uptime_events')->where('id', $target)->update($values));
            $result = $this->verify();
            $this->assertFalse($result['valid'], "{$column} change not detected");
            $this->assertContains($result['failed_event_id'], [$target, $this->eventId(4)], "{$column}: wrong failure point");
        }
    }

    public function testEventsFromAnotherNodeOrKeyAreRejectedByVerification(): void
    {
        $this->chain(2);
        $other = UptimeNode::query()->create(['public_id' => 'nd-other', 'display_name' => 'Other']);
        [$otherKey] = $this->registerKey($other);
        $target = $this->eventId(2);
        $this->tamper(fn () => DB::table('uptime_events')->where('id', $target)->update(['uptime_key_id' => $otherKey->id]));
        $result = $this->verify();
        $this->assertFalse($result['valid']);
        $this->assertSame($target, $result['failed_event_id']);
    }

    public function testFailureChangesThePublicStateAndNotifiesOnce(): void
    {
        $admin = $this->admin();
        $this->chain();
        app(ChainVerifier::class)->record($this->node->refresh());
        $this->get('/status')->assertOk()->assertSee('Verified');

        $target = $this->eventId(5);
        $this->tamper(fn () => DB::table('uptime_events')->where('id', $target)->update(['payload_hash' => str_repeat('0', 64)]));
        $this->artisan('uptime:verify-chain', ['--all' => true, '--record' => true])->assertExitCode(1);
        $this->artisan('uptime:verify-chain', ['--all' => true, '--record' => true])->assertExitCode(1);

        $node = $this->node->refresh();
        $this->assertSame('failed', $node->verification_state);
        $this->assertSame($target, $node->verification_failed_event_id);
        $this->assertSame(1, UptimeAnomaly::query()->where('kind', 'chain_verification_failed')->count());
        $this->assertSame(\Pterodactyl\Models\User::query()->where('root_admin', true)->count(), DB::table('local_messages')->where('subject', 'Jakarta 1: Chain verification failed')->count(), 'one message per administrator, sent once');

        $this->get('/status')->assertOk()->assertSee('Not verified')->assertSee('Verification failed')->assertDontSee('✓ Verified');
        $this->getJson('/api/status/nodes/nd-test-01')->assertOk()->assertJsonPath('verified', false)->assertJsonPath('state', 'verification_failed');
        $this->assertNotNull($admin);
    }
}
