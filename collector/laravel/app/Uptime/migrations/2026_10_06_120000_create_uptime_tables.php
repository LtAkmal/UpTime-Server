<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/*
 * UpTime-Server collector tables. Accepted events are append-only: the application
 * never updates or deletes them, and on MySQL/MariaDB triggers refuse UPDATE and
 * DELETE as a second line of defence against accidental changes. Outages are derived
 * data that can be rebuilt from the events at any time (uptime:rebuild-outages).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('uptime_nodes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('public_id', 40)->unique();
            $table->string('display_name', 80);
            $table->unsignedInteger('node_id')->nullable(); // private link to a Pterodactyl node
            $table->boolean('is_public')->default(true);
            $table->boolean('monitoring_enabled')->default(true);
            $table->timestamp('archived_at')->nullable();
            // Monitoring parameters; locked once the first event is accepted.
            $table->unsignedInteger('interval_seconds')->default(30);
            $table->unsignedInteger('timeout_seconds')->default(90);
            $table->unsignedInteger('tolerance_seconds')->default(30);
            $table->unsignedInteger('skew_seconds')->default(120);
            // Chain position (cache of the last accepted event).
            $table->char('head_hash', 64)->default(str_repeat('0', 64));
            $table->unsignedBigInteger('last_event_id')->nullable();
            $table->unsignedBigInteger('monitoring_started_at')->nullable(); // Unix ms
            $table->unsignedBigInteger('last_received_at')->nullable(); // Unix ms
            $table->unsignedBigInteger('events_count')->default(0);
            // Integrity verification (recomputable cache).
            $table->string('verification_state', 16)->default('pending');
            $table->timestamp('verified_at')->nullable();
            $table->unsignedBigInteger('verified_events')->default(0);
            $table->unsignedBigInteger('verified_through_id')->nullable();
            $table->unsignedBigInteger('verification_failed_event_id')->nullable();
            $table->unsignedBigInteger('verification_failed_at')->nullable(); // receipt ms of the first broken event
            $table->string('verification_error', 255)->nullable(); // administrators only
            $table->string('alert_state', 16)->default('unknown');
            $table->timestamps();

            $table->foreign('node_id')->references('id')->on('nodes')->nullOnDelete();
        });

        Schema::create('uptime_keys', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('uptime_node_id');
            $table->char('fingerprint', 64)->unique();
            $table->string('public_key', 64);
            $table->string('status', 16)->default('active'); // active | rotated | revoked
            $table->string('source', 16); // enrollment | manual
            $table->unsignedBigInteger('registered_at'); // Unix ms
            $table->unsignedBigInteger('revoked_at')->nullable(); // Unix ms
            $table->string('revoke_reason', 255)->nullable();
            $table->unsignedBigInteger('last_seq')->default(0);
            $table->unsignedInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['uptime_node_id', 'status']);
            $table->foreign('uptime_node_id')->references('id')->on('uptime_nodes');
        });

        Schema::create('uptime_enrollments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('uptime_node_id');
            $table->char('token_hash', 64)->unique(); // SHA-256 of the one-time token
            $table->string('purpose', 16); // enroll | rotate
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->char('used_fingerprint', 64)->nullable();
            $table->unsignedInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('uptime_node_id')->references('id')->on('uptime_nodes');
        });

        Schema::create('uptime_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('uptime_node_id');
            $table->unsignedBigInteger('uptime_key_id');
            $table->unsignedBigInteger('seq');
            $table->string('type', 16);
            $table->string('payload', 2048); // exact canonical bytes that were signed
            $table->char('payload_hash', 64);
            $table->char('prev_hash', 64);
            $table->char('event_hash', 64)->unique();
            $table->string('signature', 100); // base64 Ed25519 signature
            $table->string('verification', 16); // result at receipt: always "valid" (invalid events are rejected)
            $table->unsignedBigInteger('received_at'); // collector receipt, Unix ms
            // Copies of signed payload fields for queries; checked against the payload by verification.
            $table->unsignedBigInteger('agent_ts');
            $table->unsignedBigInteger('mono_ms');
            $table->char('boot_session', 32);
            $table->string('status', 16);
            $table->string('agent_version', 64);
            $table->string('agent_commit', 40);
            $table->char('agent_sha256', 64);

            // One successor per event (no forks) and one event per key and sequence.
            $table->unique(['uptime_node_id', 'prev_hash']);
            $table->unique(['uptime_key_id', 'seq']);
            $table->index(['uptime_node_id', 'received_at']);
            $table->foreign('uptime_node_id')->references('id')->on('uptime_nodes');
            $table->foreign('uptime_key_id')->references('id')->on('uptime_keys');
        });

        Schema::create('uptime_outages', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('uptime_node_id');
            $table->unsignedBigInteger('start_ms');
            $table->unsignedBigInteger('end_ms');
            $table->string('cause', 16); // no_heartbeat | reboot
            $table->unsignedBigInteger('before_event_id'); // the event that ended it
            $table->timestamp('created_at')->nullable();

            $table->unique(['before_event_id', 'cause', 'start_ms']);
            $table->index(['uptime_node_id', 'end_ms']);
        });

        Schema::create('uptime_anomalies', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('uptime_node_id')->nullable();
            $table->unsignedBigInteger('uptime_event_id')->nullable();
            $table->string('kind', 48);
            $table->string('severity', 16); // notice | warning | critical
            $table->string('detail', 500); // administrators only
            $table->unsignedInteger('occurrences')->default(1);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedInteger('resolved_by')->nullable();
            $table->string('resolution_note', 500)->nullable();

            $table->index(['uptime_node_id', 'kind', 'resolved_at']);
        });

        Schema::create('uptime_notes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('uptime_node_id');
            $table->unsignedBigInteger('uptime_outage_id')->nullable();
            $table->string('body', 1000);
            $table->unsignedInteger('created_by')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('uptime_node_id');
        });

        Schema::create('uptime_settings', function (Blueprint $table) {
            $table->string('key', 64)->primary();
            $table->text('value')->nullable();
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared("CREATE TRIGGER uptime_events_no_update BEFORE UPDATE ON uptime_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uptime_events is append-only'");
            DB::unprepared("CREATE TRIGGER uptime_events_no_delete BEFORE DELETE ON uptime_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uptime_events is append-only'");
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared('DROP TRIGGER IF EXISTS uptime_events_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS uptime_events_no_delete');
        }
        Schema::dropIfExists('uptime_settings');
        Schema::dropIfExists('uptime_notes');
        Schema::dropIfExists('uptime_anomalies');
        Schema::dropIfExists('uptime_outages');
        Schema::dropIfExists('uptime_events');
        Schema::dropIfExists('uptime_enrollments');
        Schema::dropIfExists('uptime_keys');
        Schema::dropIfExists('uptime_nodes');
    }
};
