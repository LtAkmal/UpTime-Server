# Architecture

## Components

### Agent (`cmd/uptime-agent`, `internal/agent`)

A static Go binary (standard library only) running as the unprivileged system user
`uptime-agent` under systemd. Per node it:

- generates its Ed25519 key in `/var/lib/uptime-agent` (mode 0700/0600);
- enrolls once with a one-time token (only the public key is sent);
- sends a signed `boot` (new boot session) or `start` (agent restart) event, then a
  `heartbeat` every interval, and a `stop` event on SIGTERM;
- reports the time since boot (`/proc/uptime`), a hash of the kernel boot id, its own
  version, commit and executable SHA-256, and how many optional local checks failed;
- keeps its chain position in `state.json`, written before each send.

Configuration: `/etc/uptime-agent/agent.conf` (no secrets). Secrets are never accepted
on the command line.

### Collector (`collector/laravel`)

Laravel code installed into a Pterodactyl panel as `app/Uptime` with one service
provider. Chosen over a separate Go service because the panel already has
administrator authentication with 2FA policy, CSRF protection, MySQL/MariaDB, a
scheduler, an activity (audit) log and local mail/notifications; a separate service
would duplicate all of them.

| Part | Responsibility |
| --- | --- |
| `AgentController` + `EventIngestor` | verify and append events, derive outages, record anomalies |
| `EnrollmentService` | one-time tokens (hashed), key registration, rotation, revocation |
| `ChainVerifier` + `uptime:verify-chain` | full re-verification of stored chains (read-only unless `--record`) |
| `StatusReport` + `UptimeFormula` | states, uptime windows, daily history |
| `ProofBuilder` | public proof documents |
| `CheckCommand` (`uptime:check`) | every minute: collector health tick, offline notifications |
| `RebuildOutagesCommand` | recompute derived outages from events and compare |
| `StatusController` | `/status`, `/status/nodes/{id}`, `/api/status/*` |
| `AdminUptimeController` | `/admin/uptime/*` |

Tables: `uptime_nodes`, `uptime_keys`, `uptime_enrollments`, `uptime_events`
(append-only, triggers refuse UPDATE/DELETE), `uptime_outages` (derived, rebuildable),
`uptime_anomalies`, `uptime_notes` (administrator annotations), `uptime_settings`.

Scheduled (through the panel's `schedule:run`):

- `uptime:check` every minute;
- `uptime:verify-chain --all --record` hourly.

### Public dashboard

Blade views rendered by the collector, using the storefront layout of the panel (or a
minimal standalone layout). Pages: `/status` (overview, node cards, how verification
works, source and release) and `/status/nodes/{public-id}` (uptime windows, 30-day bars,
incidents with administrator notes, agent build, key fingerprints, chain status, proof
links, recent signed events). No JavaScript is required.

### Verifier (`cmd/uptime-verify`, `protocol`)

Independent re-implementation in Go of everything the collector checks, used by anyone
against public proofs.

## Data flow

```
agent ──(signed event)──► POST /api/uptime/agent/events
                            │ schema, node, key, signature
                            │ lock node row: duplicate / replay / sequence / clock / chain head
                            ▼
                         uptime_events (append-only) ──► uptime_outages (derived)
                            │                                   │
            hourly uptime:verify-chain                StatusReport (read time)
                            │                                   │
                            ▼                                   ▼
                  node.verification_state            /status, /api/status/*, proofs
```

## Deployment shapes

- **Now (development / initial):** panel + collector + one node on the same machine or
  LAN, agent talking to the collector over HTTP or HTTPS. Not independent.
- **Recommended:** collector on its own host behind HTTPS; agents on every node;
  public status page on the collector or a separate host; two or more independent
  external monitors (roadmap). See [deployment.md](deployment.md).

Nothing in the code hardcodes an IP address, hostname or domain: the agent gets the
collector URL from its configuration and the collector builds URLs from the panel's
`APP_URL`.
