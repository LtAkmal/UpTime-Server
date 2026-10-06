<?php

namespace Pterodactyl\Tests\Integration\Uptime;

use Illuminate\Support\Facades\DB;
use Pterodactyl\Uptime\Models\UptimeOutage;
use Pterodactyl\Uptime\Services\StatusReport;

class UptimeStatusTest extends UptimeTestCase
{
    private function report(): StatusReport
    {
        return app(StatusReport::class);
    }

    private function state(): string
    {
        return $this->report()->state($this->node->refresh()->load('lastEvent'), $this->nowMs());
    }

    public function testStatesFollowTheReceiptTime(): void
    {
        $this->assertSame('no_data', $this->state());
        $this->send('boot')->assertCreated();
        $this->assertSame('online', $this->state());

        $this->advance(91); // older than the 90 s timeout
        $this->assertSame('delayed', $this->state(), 'a stale heartbeat is never "online"');
        $this->advance(30); // 121 s > timeout + tolerance
        $this->assertSame('offline', $this->state());

        $this->send()->assertCreated();
        $this->assertSame('online', $this->state());
        $this->advance(30);
        $this->send('heartbeat', ['status' => 'degraded', 'checks_failed' => 1])->assertCreated();
        $this->assertSame('degraded', $this->state());

        $this->node->forceFill(['monitoring_enabled' => false])->save();
        $this->assertSame('disabled', $this->state());
    }

    public function testDowntimeIsCountedAfterTimeoutPlusToleranceAndEndsWithTheNextHeartbeat(): void
    {
        $start = $this->nowMs();
        $this->send('boot')->assertCreated();
        $this->beats(10); // 5 minutes of heartbeats
        $silentFrom = $this->nowMs();
        $this->advance(600); // 10 minutes of silence
        $this->send()->assertCreated();

        $outage = UptimeOutage::query()->sole();
        $this->assertSame($silentFrom + 120_000, $outage->start_ms, 'downtime starts after timeout + tolerance');
        $this->assertSame($this->nowMs(), $outage->end_ms, 'and ends with the next valid heartbeat');
        $this->assertSame('no_heartbeat', $outage->cause);

        $this->beats(2);
        $now = $this->nowMs();
        $w = $this->report()->windows($this->node->refresh(), $now);
        // Monitoring began at $start: the window before it is not counted.
        $this->assertSame($now - $start, $w['24h']['covered_ms']);
        $this->assertSame(480_000, $w['24h']['downtime_ms']);
        $this->assertEqualsWithDelta(($now - $start - 480_000) / ($now - $start) * 100, $w['24h']['uptime_percent'], 1e-9);
        $this->assertLessThan(2, $w['24h']['coverage_percent']);
        foreach (['7d', '30d', 'all'] as $window) {
            $this->assertSame(480_000, $w[$window]['downtime_ms'], $window);
            $this->assertSame($now - $start, $w[$window]['covered_ms'], $window);
        }

        // Ongoing silence is counted at read time, before the next heartbeat arrives.
        $this->advance(300);
        $w = $this->report()->windows($this->node->refresh(), $this->nowMs());
        $this->assertSame(480_000 + 180_000, $w['all']['downtime_ms']);
        $this->assertNotNull($this->report()->currentOutage($this->node, $this->nowMs()));
    }

    public function testNoDataBeforeMonitoringStarts(): void
    {
        $w = $this->report()->windows($this->node, $this->nowMs());
        $this->assertNull($w['24h']['uptime_percent']);
        $this->assertSame(0, $w['all']['covered_ms']);
        $this->getJson('/api/status/nodes/nd-test-01')->assertOk()->assertJsonPath('state', 'no_data')->assertJsonPath('windows.24h.uptime_percent', null);
        $history = $this->report()->daily($this->node, $this->nowMs());
        $this->assertCount(30, $history);
        $this->assertNull($history[0]['uptime'], 'days before monitoring are "no data", not 100%');
    }

    public function testOfflineAndRecoveryNotifyAdministratorsOnce(): void
    {
        $this->admin();
        $this->send('boot')->assertCreated();
        $this->artisan('uptime:check')->assertExitCode(0);
        $this->assertSame(0, DB::table('local_messages')->where('subject', 'like', 'Jakarta 1 is%')->count());

        $this->advance(200);
        $this->artisan('uptime:check')->assertExitCode(0);
        $this->artisan('uptime:check')->assertExitCode(0);
        $admins = \Pterodactyl\Models\User::query()->where('root_admin', true)->count();
        $this->assertSame($admins, DB::table('local_messages')->where('subject', 'Jakarta 1 is offline')->count(), 'one message per administrator, sent once');
        $this->assertSame('offline', $this->node->refresh()->alert_state);

        $this->send()->assertCreated();
        $this->assertSame($admins, DB::table('local_messages')->where('subject', 'Jakarta 1 is back online')->count());
        $this->assertSame('online', $this->node->refresh()->alert_state);
        $this->assertTrue($this->report()->collectorHealthy($this->nowMs()));
    }

    public function testRebuiltOutagesMatchTheStoredOnes(): void
    {
        $this->send('boot')->assertCreated();
        $this->beats(2);
        $this->advance(400);
        $this->send()->assertCreated();
        $this->advance(10);
        $this->boot = str_repeat('7', 32);
        $this->bootedAt = $this->nowMs() - 5_000;
        $this->send('boot')->assertCreated();

        $this->artisan('uptime:rebuild-outages')->expectsOutputToContain('MATCH')->assertExitCode(0);
        // Derived data that drifts from the events is reported (and can be rebuilt).
        UptimeOutage::query()->first()->forceFill(['end_ms' => 1])->save();
        $this->artisan('uptime:rebuild-outages')->expectsOutputToContain('DIFFERS')->assertExitCode(1);
        $this->artisan('uptime:rebuild-outages', ['--apply' => true])->assertExitCode(0);
        $this->artisan('uptime:rebuild-outages')->expectsOutputToContain('MATCH')->assertExitCode(0);
    }
}
