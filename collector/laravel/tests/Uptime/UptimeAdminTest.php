<?php

namespace Pterodactyl\Tests\Integration\Uptime;

use Pterodactyl\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Pterodactyl\Uptime\Models\UptimeKey;
use Pterodactyl\Uptime\Models\UptimeNode;
use Pterodactyl\Uptime\Models\UptimeNote;
use Pterodactyl\Uptime\Models\UptimeEvent;
use Pterodactyl\Uptime\Protocol\Protocol;
use Pterodactyl\Uptime\Models\UptimeOutage;
use Pterodactyl\Uptime\Models\UptimeEnrollment;

class UptimeAdminTest extends UptimeTestCase
{
    private function asAdmin(?User $admin = null): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($admin ?? $this->admin())->withHeaders(['Accept' => 'text/html']);
    }

    private function enrollWith(string $token, ?string &$secret = null): \Illuminate\Testing\TestResponse
    {
        $pair = sodium_crypto_sign_keypair();
        $secret = sodium_crypto_sign_secretkey($pair);
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/uptime/agent/enroll', ['token' => $token, 'public_key' => base64_encode(sodium_crypto_sign_publickey($pair)), 'protocol' => 1, 'agent_version' => '0.1.0']);
    }

    private function tokenFor(UptimeNode $node, User $admin): string
    {
        $this->asAdmin($admin)->post(route('admin.uptime.nodes.token', $node->id))->assertRedirect()->assertSessionHas('uptime_token');

        return (string) session('uptime_token');
    }

    public function testOnlyAdministratorsReachTheAdminArea(): void
    {
        $customer = User::factory()->create();
        $this->actingAs($customer)->withHeaders(['Accept' => 'text/html'])->get(route('admin.uptime'))->assertForbidden();
        $this->actingAs($customer)->post(route('admin.uptime.nodes.store'), ['display_name' => 'X'])->assertForbidden();
        $this->app['auth']->forgetGuards();
        // The panel's admin middleware refuses guests outright.
        $this->withHeaders(['Accept' => 'text/html'])->get(route('admin.uptime'))->assertForbidden();

        $admin = $this->admin();
        foreach (['admin.uptime', 'admin.uptime.nodes.new', 'admin.uptime.incidents', 'admin.uptime.settings'] as $name) {
            $this->asAdmin($admin)->get(route($name))->assertOk();
        }
        $this->asAdmin($admin)->get(route('admin.uptime.nodes.view', $this->node->id))->assertOk()->assertSee('nd-test-01');
    }

    public function testAdminCreatesANodeAndTheActionIsAudited(): void
    {
        $admin = $this->admin();
        $this->asAdmin($admin)->post(route('admin.uptime.nodes.store'), [
            'display_name' => 'Singapore 2', 'is_public' => '1', 'monitoring_enabled' => '1',
            'interval_seconds' => 30, 'timeout_seconds' => 40, 'tolerance_seconds' => 30, 'skew_seconds' => 120,
        ])->assertSessionHasErrors('timeout_seconds');
        $this->asAdmin($admin)->post(route('admin.uptime.nodes.store'), [
            'display_name' => 'Singapore 2', 'is_public' => '1', 'monitoring_enabled' => '1',
            'interval_seconds' => 30, 'timeout_seconds' => 90, 'tolerance_seconds' => 30, 'skew_seconds' => 120,
        ])->assertRedirect();
        $node = UptimeNode::query()->where('display_name', 'Singapore 2')->sole();
        $this->assertTrue(Protocol::validNodeId($node->public_id));
        $this->assertUptimeActivity('admin:uptime.node.created', $admin);
    }

    public function testEnrollmentTokenIsHashedOneTimeAndExpires(): void
    {
        $admin = $this->admin();
        $node = UptimeNode::query()->create(['public_id' => 'nd-new-01', 'display_name' => 'New']);
        $token = $this->tokenFor($node, $admin);
        $this->assertSame(1, UptimeEnrollment::query()->where('token_hash', hash('sha256', $token))->count());
        $this->assertSame(0, UptimeEnrollment::query()->where('token_hash', $token)->count(), 'stored hashed');
        // Shown on the next page view only.
        $this->asAdmin($admin)->get(route('admin.uptime.nodes.view', $node->id))->assertSee($token);
        $this->asAdmin($admin)->get(route('admin.uptime.nodes.view', $node->id))->assertDontSee($token);

        $this->withHeaders(['Accept' => 'application/json']);
        $this->enrollWith($token)->assertCreated()->assertJsonPath('node_id', 'nd-new-01')->assertJsonPath('head', Protocol::GENESIS);
        $this->enrollWith($token)->assertForbidden()->assertJsonPath('error', 'invalid_token');
        $this->assertSame(1, UptimeKey::query()->where('uptime_node_id', $node->id)->count());

        $expired = $this->tokenFor($node, $admin);
        $this->advance(31 * 60);
        $this->enrollWith($expired)->assertForbidden();
        $this->enrollWith('not-a-real-token-at-all-123456')->assertForbidden();
        $this->assertTrue(DB::table('uptime_anomalies')->where('kind', 'enrollment_failed')->exists());
    }

    public function testKeyRotationReplacesTheOldKeyAndContinuesTheChain(): void
    {
        $admin = $this->admin();
        $this->send('boot')->assertCreated();
        $this->beats(2);
        $oldKey = $this->key;

        $token = $this->tokenFor($this->node, $admin);
        $response = $this->enrollWith($token, $newSecret)->assertCreated()->assertJsonPath('head', $this->head);
        $this->assertSame(UptimeKey::STATUS_ROTATED, $oldKey->refresh()->status);
        $this->assertNotNull($oldKey->revoked_at);

        // The old key is rejected from now on…
        $this->advance(30);
        $this->send()->assertForbidden()->assertJsonPath('error', 'revoked_key');
        // …and the new key continues the same chain with its own sequence.
        $this->key = UptimeKey::query()->where('fingerprint', $response->json('fingerprint'))->sole();
        $this->secret = $newSecret;
        $this->seq = 0;
        $this->send('start')->assertCreated();
        $this->assertSame(4, UptimeEvent::query()->count());
        $result = app(\Pterodactyl\Uptime\Services\ChainVerifier::class)->verify($this->node->refresh());
        $this->assertTrue($result['valid'], (string) $result['reason']);
    }

    public function testManualKeyRegistrationAndRevocation(): void
    {
        $admin = $this->admin();
        $node = UptimeNode::query()->create(['public_id' => 'nd-manual', 'display_name' => 'Manual']);
        $public = base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()));
        $this->asAdmin($admin)->post(route('admin.uptime.nodes.keys', $node->id), ['public_key' => $public])->assertSessionHasErrors('confirm');
        $this->asAdmin($admin)->post(route('admin.uptime.nodes.keys', $node->id), ['public_key' => 'short', 'confirm' => '1'])->assertSessionHasErrors('public_key');
        $this->asAdmin($admin)->post(route('admin.uptime.nodes.keys', $node->id), ['public_key' => $public, 'confirm' => '1'])->assertRedirect();
        $key = UptimeKey::query()->where('uptime_node_id', $node->id)->sole();
        $this->assertSame('manual', $key->source);

        $this->asAdmin($admin)->post(route('admin.uptime.nodes.keys.revoke', [$node->id, $key->id]), ['reason' => 'lost', 'confirm' => '1'])->assertSessionHasErrors('reason');
        $this->asAdmin($admin)->post(route('admin.uptime.nodes.keys.revoke', [$this->node->id, $key->id]), ['reason' => 'wrong node', 'confirm' => '1'])->assertNotFound();
        $this->asAdmin($admin)->post(route('admin.uptime.nodes.keys.revoke', [$node->id, $key->id]), ['reason' => 'machine replaced', 'confirm' => '1'])->assertRedirect();
        $this->assertSame(UptimeKey::STATUS_REVOKED, $key->refresh()->status);
        $this->assertUptimeActivity('admin:uptime.key-revoked', $admin);
    }

    public function testMonitoringDataCannotBeEditedByAdministrators(): void
    {
        $admin = $this->admin();
        $this->send('boot')->assertCreated();
        $this->advance(400);
        $this->send()->assertCreated();
        $outage = UptimeOutage::query()->sole();

        // No route can change events, outages or verification results.
        $writable = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), 'admin/uptime') && array_intersect($r->methods(), ['POST', 'PATCH', 'PUT', 'DELETE']))
            ->map(fn ($r) => $r->uri())->values()->all();
        foreach ($writable as $uri) {
            $this->assertDoesNotMatchRegularExpression('#events|outages|uptime_percent|verification#', $uri);
        }

        // Parameters are locked once monitoring started (uptime cannot be recomputed with
        // different rules), and unknown fields are ignored.
        $before = $this->node->refresh()->only(['timeout_seconds', 'tolerance_seconds', 'head_hash', 'events_count', 'verification_state']);
        $this->asAdmin($admin)->patch(route('admin.uptime.nodes.update', $this->node->id), [
            'display_name' => 'Jakarta 1', 'is_public' => '1', 'monitoring_enabled' => '1',
            'timeout_seconds' => 3600, 'tolerance_seconds' => 3600, 'head_hash' => str_repeat('0', 64),
            'events_count' => 0, 'verification_state' => 'valid',
        ])->assertRedirect();
        $this->assertSame($before, $this->node->refresh()->only(array_keys($before)));
        $this->assertSame($outage->end_ms, $outage->refresh()->end_ms);

        // Administrator notes are separate and labelled; the outage is unchanged.
        $this->asAdmin($admin)->post(route('admin.uptime.nodes.notes', $this->node->id), ['outage_id' => $outage->id, 'body' => 'Upstream network maintenance.'])->assertRedirect();
        $this->assertSame(1, UptimeNote::query()->count());
        $this->assertSame($outage->durationMs(), $outage->refresh()->durationMs());
        $this->app['auth']->forgetGuards();
        $this->get('/status/nodes/nd-test-01')->assertOk()->assertSee('Administrator note')->assertSee('Upstream network maintenance.');
    }

    public function testPublicDisplayAndArchiving(): void
    {
        $admin = $this->admin();
        $this->send('boot')->assertCreated();
        $this->app['auth']->forgetGuards();
        $this->get('/status')->assertSee('Jakarta 1');

        $this->asAdmin($admin)->patch(route('admin.uptime.nodes.update', $this->node->id), ['display_name' => 'Jakarta 1', 'is_public' => '0', 'monitoring_enabled' => '1'])->assertRedirect();
        $this->app['auth']->forgetGuards();
        $this->get('/status')->assertDontSee('Jakarta 1');
        $this->get('/status/nodes/nd-test-01')->assertNotFound();
        $this->getJson('/api/status/nodes/nd-test-01/proof')->assertNotFound();

        $this->asAdmin($admin)->post(route('admin.uptime.nodes.archive', $this->node->id), ['confirm' => '1'])->assertRedirect();
        $this->assertNotNull($this->node->refresh()->archived_at);
        $this->assertSame(1, UptimeEvent::query()->count(), 'evidence kept');
        $this->advance(30);
        $this->send()->assertNotFound();
        $this->assertUptimeActivity('admin:uptime.node.archived', $admin);
    }

    public function testAnomalyReviewIsAudited(): void
    {
        $admin = $this->admin();
        $this->send('boot')->assertCreated();
        $this->advance(30);
        $this->send('heartbeat', ['mono_ms' => 5])->assertCreated();
        $anomaly = DB::table('uptime_anomalies')->where('kind', 'monotonic_regression')->first();
        $this->asAdmin($admin)->post(route('admin.uptime.anomalies.resolve', $anomaly->id), ['note' => 'Agent test, expected.'])->assertRedirect();
        $this->assertNotNull(DB::table('uptime_anomalies')->where('id', $anomaly->id)->value('resolved_at'));
        $this->assertUptimeActivity('admin:uptime.anomaly.resolved', $admin);
    }

    private function assertUptimeActivity(string $event, User $actor): void
    {
        $this->assertTrue(DB::table('activity_logs')->where('event', $event)->where('actor_id', $actor->id)->exists(), "missing activity {$event}");
    }
}
