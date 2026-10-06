<?php

namespace Pterodactyl\Tests\Integration\Uptime;

use Pterodactyl\Models\Node;
use Pterodactyl\Models\Location;
use Pterodactyl\Uptime\Protocol\Protocol;
use Pterodactyl\Uptime\Models\UptimeEnrollment;
use Pterodactyl\Uptime\Protocol\UptimeFormula;
use Pterodactyl\Uptime\Services\ChainVerifier;
use Pterodactyl\Uptime\Services\UptimeSettings;

class UptimePublicTest extends UptimeTestCase
{
    private Node $ptero;

    public function setUp(): void
    {
        parent::setUp();

        $this->ptero = Node::factory()->create(['location_id' => Location::factory()->create()->id, 'fqdn' => 'wings-internal.example.test', 'name' => 'internal-node-name']);
        $this->node->forceFill(['node_id' => $this->ptero->id])->save();
        app(UptimeSettings::class)->set('trusted_builds', '0.1.0 ' . str_repeat('c', 64));
        $this->app['auth']->forgetGuards();
    }

    private function populate(): void
    {
        $this->send('boot')->assertCreated();
        $this->beats(4);
        $this->advance(400);
        $this->send()->assertCreated();
        $this->beats(2);
        app(ChainVerifier::class)->record($this->node->refresh());
        $this->artisan('uptime:check');
        $this->advance(1); // ranges are half-open: [from, now)
    }

    public function testStatusPageIsPublicAndShowsVerifiedStatusAndSourceLinks(): void
    {
        $this->populate();
        UptimeEnrollment::query()->create(['uptime_node_id' => $this->node->id, 'token_hash' => hash('sha256', 'secret-token-value-xyz'), 'purpose' => 'enroll', 'expires_at' => now()->addHour()]);

        $html = (string) $this->withHeaders(['Accept' => 'text/html'])->get('/status')->assertOk()->getContent();
        foreach ([
            'System Status', 'All systems operational', 'Jakarta 1', 'Online', '✓ Verified',
            'Cryptographically signed and publicly auditable uptime records',
            'Monitoring data is independently verifiable from published source code, release metadata, signed events, and hash-chain proofs.',
            'Current deployment is not independently operated by a third-party monitor.',
            'How uptime is verified', 'https://github.com/LtAkmal/UpTime-Server', 'docs/verification.md', 'Collector release',
            'Independent monitors so far: <strong>0</strong>', 'View proof',
        ] as $expected) {
            $this->assertStringContainsString($expected, $html);
        }
        foreach ([
            'wings-internal.example.test', 'internal-node-name', '127.0.0.1', 'secret-token-value-xyz',
            hash('sha256', 'secret-token-value-xyz'), 'tamper-proof', 'impossible to manipulate', '100% secure',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $html);
        }
        $this->assertStringContainsString('<h1>System Status</h1>', $html);
        $this->assertStringContainsString('aria-label="30-day history', $html);
    }

    public function testNodePageShowsUptimeIncidentsBuildAndKeyMetadata(): void
    {
        $this->populate();
        $html = (string) $this->withHeaders(['Accept' => 'text/html'])->get('/status/nodes/nd-test-01')->assertOk()->getContent();
        foreach (['Jakarta 1', 'Last 24 hours', 'All time', 'No valid heartbeat', '4 min 40 s', 'Agent version', '0.1.0',
            str_repeat('c', 64), 'matches 0.1.0', $this->key->fingerprint, 'Chain valid', 'Proof JSON', 'uptime-verify', 'Recent signed events'] as $expected) {
            $this->assertStringContainsString($expected, $html);
        }
        $this->assertStringNotContainsString('wings-internal.example.test', $html);
        $this->withHeaders(['Accept' => 'text/html'])->get('/status/nodes/nd-unknown')->assertNotFound();
        $this->withHeaders(['Accept' => 'text/html'])->get('/status/assets/uptime.css')->assertOk()->assertHeader('Content-Type', 'text/css; charset=utf-8');
    }

    public function testPublicApiReturnsOnlySafeData(): void
    {
        $this->populate();
        $summary = $this->getJson('/api/status/summary')->assertOk()
            ->assertJsonPath('overall', 'operational')->assertJsonPath('counts.online', 1)->assertJsonPath('independent_monitors', 0)
            ->assertJsonPath('nodes.0.verified', true)->json();
        $encoded = json_encode($summary);
        foreach (['wings-internal', 'internal-node-name', '"node_id"', 'verification_error', 'token', 'alert_state', 'detail'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded);
        }
        $this->getJson('/api/status/nodes/nd-test-01')->assertOk()->assertJsonPath('state', 'online')->assertJsonPath('agent.published_release', '0.1.0');
    }

    public function testProofIsSelfContainedAndRecomputable(): void
    {
        $this->populate();
        $proof = $this->getJson('/api/status/nodes/nd-test-01/proof?from=' . ($this->nowMs() - 86_400_000) . '&to=' . $this->nowMs())->assertOk()
            ->assertJsonPath('format', 'uptime-server-proof/v1')->assertJsonPath('page.complete', true)->assertJsonPath('anchor', null)->json();
        $this->assertCount(8, $proof['events']);
        $this->assertSame(8, $proof['summary']['events']);
        $this->assertArrayNotHasKey('node_id', $proof['node']);

        // Recompute exactly what uptime-verify does.
        $keys = collect($proof['keys'])->keyBy('fingerprint');
        $prev = Protocol::GENESIS;
        $points = [];
        foreach ($proof['events'] as $e) {
            $p = Protocol::parsePayload($e['payload']);
            $this->assertTrue(Protocol::verifySignature(base64_decode($keys[$p['key']]['public_key']), $e['payload'], base64_decode($e['signature'])));
            $this->assertSame($prev, $e['prev_hash']);
            $this->assertSame($e['event_hash'], Protocol::eventHash($e['prev_hash'], Protocol::payloadHash($e['payload'])));
            $prev = $e['event_hash'];
            $points[] = ['received' => $e['received_at'], 'type' => $p['type'], 'mono' => $p['mono_ms']];
        }
        $params = $proof['node']['params'];
        $outages = [];
        for ($i = 1; $i < count($points); ++$i) {
            array_push($outages, ...UptimeFormula::gapOutages($points[$i - 1], $points[$i], $params));
        }
        if ($proof['tail'] === null && ($open = UptimeFormula::openOutage(end($points)['received'], $params, $proof['range']['to']))) {
            $outages[] = $open;
        }
        $w = UptimeFormula::calculate($outages, $proof['node']['monitoring_started_at'], $proof['range']['from'], $proof['range']['to']);
        $this->assertSame([$w['covered_ms'], $w['downtime_ms']], [$proof['summary']['covered_ms'], $proof['summary']['downtime_ms']]);
        $this->assertSame(280_000, $proof['summary']['downtime_ms']);

        // A later range has the preceding event as its anchor.
        $later = $this->getJson('/api/status/nodes/nd-test-01/proof?from=' . ($proof['events'][3]['received_at'] + 1) . '&to=' . $this->nowMs())->assertOk()->json();
        $this->assertSame($proof['events'][3]['event_hash'], $later['anchor']['event_hash']);
        $this->getJson('/api/status/nodes/nd-test-01/proof?from=10&to=5')->assertStatus(422);
    }

    public function testStatusPageCanBeDisabled(): void
    {
        app(UptimeSettings::class)->set('status_page_enabled', '0');
        $this->withHeaders(['Accept' => 'text/html'])->get('/status')->assertNotFound();
        $this->getJson('/api/status/summary')->assertNotFound();
    }

    public function testNoDataAndOfflineStatesAreShownHonestly(): void
    {
        $this->withHeaders(['Accept' => 'text/html'])->get('/status')->assertOk()->assertSee('Monitoring not started')->assertSee('Not enough data')->assertDontSee('100%');
        $this->send('boot')->assertCreated();
        $this->artisan('uptime:check');
        $this->advance(300);
        $this->artisan('uptime:check');
        $this->withHeaders(['Accept' => 'text/html'])->get('/status')->assertOk()->assertSee('Major outage')->assertSee('Offline')->assertSee('Ongoing incident');
    }
}
