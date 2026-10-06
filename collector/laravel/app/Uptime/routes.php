<?php

use Illuminate\Support\Facades\Route;
use Pterodactyl\Http\Middleware\AdminAuthenticate;
use Pterodactyl\Uptime\Http\Controllers\AgentController;
use Pterodactyl\Uptime\Http\Controllers\StatusController;
use Pterodactyl\Http\Middleware\RequireTwoFactorAuthentication;
use Pterodactyl\Uptime\Http\Controllers\AdminUptimeController;

/*
|--------------------------------------------------------------------------
| UpTime-Server collector routes
|--------------------------------------------------------------------------
*/

$publicId = '[a-z0-9][a-z0-9-]{2,39}';

// Public status pages (no login). The session only lets the shared site navigation
// show the visitor's sign-in state.
Route::middleware(['web', 'auth.session'])->group(function () use ($publicId) {
    Route::get('/status', [StatusController::class, 'index'])->name('uptime.status');
    Route::get('/status/nodes/{publicId}', [StatusController::class, 'node'])->where('publicId', $publicId)->name('uptime.status.node');
});
Route::get('/status/assets/uptime.css', [StatusController::class, 'stylesheet'])->name('uptime.status.css');

// Public read-only API (no session, no credentials).
Route::prefix('/api/status')->middleware('throttle:uptime-public')->group(function () use ($publicId) {
    Route::get('/summary', [StatusController::class, 'apiSummary'])->name('uptime.api.summary');
    Route::get('/nodes/{publicId}', [StatusController::class, 'apiNode'])->where('publicId', $publicId)->name('uptime.api.node');
    Route::get('/nodes/{publicId}/proof', [StatusController::class, 'apiProof'])->where('publicId', $publicId)->name('uptime.api.proof');
});

// Agent API: authenticated by Ed25519 signatures or a one-time enrollment token.
Route::prefix('/api/uptime/agent')->group(function () use ($publicId) {
    Route::post('/enroll', [AgentController::class, 'enroll'])->middleware('throttle:uptime-enroll')->name('uptime.agent.enroll');
    Route::post('/events', [AgentController::class, 'events'])->middleware('throttle:uptime-events')->name('uptime.agent.events');
    Route::get('/nodes/{publicId}/head', [AgentController::class, 'head'])->where('publicId', $publicId)->middleware('throttle:uptime-public')->name('uptime.agent.head');
});

// Administration: the panel's own admin authentication, 2FA requirement and CSRF.
Route::middleware(['web', 'auth.session', RequireTwoFactorAuthentication::class, AdminAuthenticate::class])
    ->prefix('/admin/uptime')
    ->group(function () {
        Route::get('/', [AdminUptimeController::class, 'index'])->name('admin.uptime');
        Route::get('/nodes/new', [AdminUptimeController::class, 'create'])->name('admin.uptime.nodes.new');
        Route::post('/nodes', [AdminUptimeController::class, 'store'])->name('admin.uptime.nodes.store');
        Route::get('/nodes/{node}', [AdminUptimeController::class, 'view'])->whereNumber('node')->name('admin.uptime.nodes.view');
        Route::patch('/nodes/{node}', [AdminUptimeController::class, 'update'])->whereNumber('node')->name('admin.uptime.nodes.update');
        Route::post('/nodes/{node}/token', [AdminUptimeController::class, 'token'])->whereNumber('node')->name('admin.uptime.nodes.token');
        Route::post('/nodes/{node}/keys', [AdminUptimeController::class, 'registerKey'])->whereNumber('node')->name('admin.uptime.nodes.keys');
        Route::post('/nodes/{node}/keys/{key}/revoke', [AdminUptimeController::class, 'revokeKey'])->whereNumber(['node', 'key'])->name('admin.uptime.nodes.keys.revoke');
        Route::post('/nodes/{node}/verify', [AdminUptimeController::class, 'verify'])->whereNumber('node')->middleware('throttle:10,1')->name('admin.uptime.nodes.verify');
        Route::post('/nodes/{node}/archive', [AdminUptimeController::class, 'archive'])->whereNumber('node')->name('admin.uptime.nodes.archive');
        Route::post('/nodes/{node}/notes', [AdminUptimeController::class, 'note'])->whereNumber('node')->name('admin.uptime.nodes.notes');
        Route::post('/anomalies/{anomaly}/resolve', [AdminUptimeController::class, 'resolveAnomaly'])->whereNumber('anomaly')->name('admin.uptime.anomalies.resolve');
        Route::get('/incidents', [AdminUptimeController::class, 'incidents'])->name('admin.uptime.incidents');
        Route::get('/settings', [AdminUptimeController::class, 'settings'])->name('admin.uptime.settings');
        Route::post('/settings', [AdminUptimeController::class, 'saveSettings'])->name('admin.uptime.settings.save');
    });
