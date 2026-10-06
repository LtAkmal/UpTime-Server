# API reference

All responses are JSON. Times are Unix milliseconds (UTC) unless noted.

## Public API (no authentication, 120 requests/minute per address)

### `GET /api/status/summary`

Overall state, counts and every public node.

```json
{
  "generated_at": 1791230400000,
  "overall": "operational",
  "overall_label": "All systems operational",
  "counts": {"monitored": 1, "online": 1, "degraded": 0, "offline": 0, "verification_failed": 0, "incidents": 0},
  "nodes": [ { "...": "see node below" } ],
  "collector": {"version": "0.1.0", "commit": "<40 hex>", "repository": "https://github.com/LtAkmal/UpTime-Server", "files_match_release": true},
  "independent_monitors": 0
}
```

### `GET /api/status/nodes/{public-id}`

```json
{
  "id": "nd-0a1b2c3d4e", "name": "Jakarta 1",
  "state": "online", "state_label": "Online", "state_description": "...",
  "verified": true,
  "verification": {"state": "valid", "checked_at": "2026-10-06T12:00:00+00:00", "events_checked": 2880, "not_verified_from": null},
  "anomaly": false,
  "monitoring_started_at": 1791100000000, "last_heartbeat_at": 1791230399000, "events": 2880,
  "params": {"interval_ms": 30000, "timeout_ms": 90000, "tolerance_ms": 30000, "skew_ms": 120000},
  "windows": {"24h": {"from": 0, "to": 0, "covered_ms": 0, "downtime_ms": 0, "uptime_percent": 99.98, "coverage_percent": 100}, "7d": {}, "30d": {}, "all": {}},
  "incidents": 2, "current_incident": null,
  "agent": {"version": "0.1.0", "commit": "<hex>", "sha256": "<hex>", "published_release": "0.1.0"},
  "key_fingerprint": "<64 hex>",
  "history": [{"date": "2026-10-06", "uptime": 100, "downtime_ms": 0, "coverage": 100}],
  "incidents_list": [{"start": 0, "end": 0, "duration_ms": 0, "cause": "no_heartbeat", "ongoing": false, "notes": [{"body": "...", "at": "..."}]}]
}
```

`notes` are administrator annotations; they are not part of the signed record.

### `GET /api/status/nodes/{public-id}/proof?from=&to=&after=`

`from`/`to`: Unix ms or ISO 8601 (default: the last 24 hours; `to` is capped at now).
`after`: paging cursor (event id), set by `page.next`. Up to 1000 events per page.
Format `uptime-server-proof/v1`:

```json
{
  "format": "uptime-server-proof/v1",
  "generated_at": 0,
  "collector": {"software": "...", "version": "...", "commit": "...", "repository": "..."},
  "node": {"id": "...", "name": "...", "monitoring_started_at": 0, "params": {"interval_ms": 0, "timeout_ms": 0, "tolerance_ms": 0, "skew_ms": 0}},
  "keys": [{"fingerprint": "...", "public_key": "<base64>", "algorithm": "ed25519", "registered_at": 0, "revoked_at": null}],
  "range": {"from": 0, "to": 0},
  "anchor": null,
  "events": [{"id": 1, "received_at": 0, "payload": "<canonical JSON>", "signature": "<base64>", "payload_hash": "...", "prev_hash": "...", "event_hash": "..."}],
  "tail": null,
  "page": {"complete": true, "next": ""},
  "summary": {"events": 0, "covered_ms": 0, "downtime_ms": 0, "outages": [{"start": 0, "end": 0, "cause": "no_heartbeat"}]}
}
```

`anchor` is the last event before the range (`null` if none: the range starts at the
genesis hash); `tail` is the first event after it (`null` if none yet: silence after
the last event up to `to` counts as ongoing downtime). Verify with `uptime-verify`.

## Agent API (no session or cookies)

### `POST /api/uptime/agent/enroll` (10/minute per address)

```json
{"token": "<one-time token>", "public_key": "<base64 32 bytes>", "protocol": 1, "agent_version": "0.1.0"}
```

`201 {"node_id", "fingerprint", "head", "interval_s"}` · `403 invalid_token` ·
`409 key_in_use` · `422 invalid_public_key | invalid_request`.

### `POST /api/uptime/agent/events` (240/minute per address)

```json
{"payload": "<canonical JSON string>", "signature": "<base64 Ed25519 signature>"}
```

`201 {"status": "accepted", "event_hash", "seq"}` · `200 {"status": "duplicate", ...}` ·
errors `{"error": "<code>", "message": "...", ...}`:

| Status | `error` | Extra fields |
| --- | --- | --- |
| 400 | `invalid_payload`, `invalid_signature` (format), `invalid_request` | |
| 403 | `unknown_key`, `revoked_key`, `invalid_signature`, `monitoring_disabled` | |
| 404 | `unknown_node` | |
| 409 | `prev_mismatch`, `stale_sequence`, `duplicate_sequence`, `replay` | `head`, `last_seq` |
| 413 | `too_large` | |
| 422 | `timestamp_out_of_window` | `server_time` |
| 429 | rate limited | |

### `GET /api/uptime/agent/nodes/{public-id}/head?key=<fingerprint>`

`{"head": "<event hash>", "last_seq": <n>}` — public chain position (also in proofs),
used after enrollment or a lost state file.

## Admin (panel session, root administrators, CSRF)

| Route | Purpose |
| --- | --- |
| `GET /admin/uptime` | nodes, states, open anomalies |
| `GET /admin/uptime/nodes/new`, `POST /admin/uptime/nodes` | add a monitored node |
| `GET /admin/uptime/nodes/{id}` | node detail: status, chain, incidents, keys, anomalies |
| `PATCH /admin/uptime/nodes/{id}` | display name, public flag, monitoring enabled, Pterodactyl node link; parameters only before monitoring starts |
| `POST /admin/uptime/nodes/{id}/token` | one-time enrollment/rotation token |
| `POST /admin/uptime/nodes/{id}/keys` | register a public key manually |
| `POST /admin/uptime/nodes/{id}/keys/{key}/revoke` | revoke a key (reason required) |
| `POST /admin/uptime/nodes/{id}/verify` | re-verify the chain now |
| `POST /admin/uptime/nodes/{id}/notes` | administrator note on an incident |
| `POST /admin/uptime/nodes/{id}/archive` | archive (keeps all evidence) |
| `POST /admin/uptime/anomalies/{id}/resolve` | mark an anomaly reviewed |
| `GET /admin/uptime/incidents`, `GET/POST /admin/uptime/settings` | incidents, settings |

There is no route that edits events, outages, uptime figures or verification results.
Every admin change is written to the panel's activity log (`admin:uptime.*`).

## Artisan commands

| Command | Description |
| --- | --- |
| `uptime:verify-chain --node=<id> \| --all [--record]` | verify chains; exits 1 on failure and names the first broken event; never modifies events |
| `uptime:check` | collector health tick and offline notifications (every minute) |
| `uptime:rebuild-outages [--node=<id>] [--apply]` | recompute derived outages and compare (exit 1 if different) |
