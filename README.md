# UpTime-Server

Open-source, auditable uptime monitoring for hosting nodes.

Each monitored node runs a small agent that signs every heartbeat with its own
Ed25519 key. A collector verifies the signatures, appends the events to a per-node
hash chain and publishes a status page, uptime figures and downloadable proofs.
Anyone can re-check a proof with the verifier in this repository.

> **Cryptographically signed and publicly auditable uptime records.**
> Monitoring data is independently verifiable from published source code, release
> metadata, signed events, and hash-chain proofs.

## Honest trust model

This is **not** "tamper-proof" and does not claim to be. What it gives you:

| What a customer can check | How |
| --- | --- |
| Every heartbeat was signed by the node's registered key | Ed25519 signature over the canonical payload |
| No stored event was modified, removed, inserted or reordered | SHA-256 hash chain; the agent signs each link |
| The published uptime follows the published formula | `uptime-verify` recomputes it from the events |
| Which agent build produced the events | Version, commit and binary SHA-256 inside each signed event |
| The code that does all of this | This repository, release tags and `SHA256SUMS` |

What it **cannot** prove:

- **The current deployment is not independently operated by a third-party monitor.**
  The collector and the nodes are run by the same operator. In the initial
  deployment the panel, the collector and a node may even run on the same machine.
- Someone with root, database, deployment, signing-key or DNS control can change the
  system. Signatures and hash chains make such changes *detectable*, not impossible:
  for example, the operator could stop recording events, or run a modified agent
  with a key it controls.
- Public source code alone cannot prove that the software running on a server is
  unmodified. Reproducible builds and published checksums show *consistency*.
- If the collector is unreachable, nodes appear offline for that time.

Independent external monitors (operated by someone else, on a different network)
are the way to close the biggest gap. They are designed but **not implemented yet**;
see [docs/external-monitors.md](docs/external-monitors.md). The status page shows the
number of independent monitors, which is currently 0.

Read [docs/threat-model.md](docs/threat-model.md) before relying on this system.

## Architecture

```
  Monitored node (one per server)            Collector (Laravel, inside the panel)
 ┌───────────────────────────────┐           ┌──────────────────────────────────────┐
 │ uptime-agent (Go, static)     │  HTTPS    │ /api/uptime/agent/*                  │
 │  • Ed25519 key (never leaves) ├──────────►│  • verify signature, sequence, clock │
 │  • signed boot/start/         │  signed   │  • append to hash chain (append-only)│
 │    heartbeat/stop events      │  events   │  • derive outages (published formula)│
 │  • hash chain head, sequence  │           │ /status  /api/status/*  (public)     │
 └───────────────────────────────┘           │ /admin/uptime  (panel admins)        │
                                             │ uptime:verify-chain (hourly)         │
                                             └──────────────────┬───────────────────┘
                                                                │ proof JSON
                                                                ▼
                                             ┌──────────────────────────────────────┐
                                             │ anyone: uptime-verify <proof URL>    │
                                             │  re-checks signatures, links,        │
                                             │  sequences and the uptime summary    │
                                             └──────────────────────────────────────┘
```

| Component | Path | Language |
| --- | --- | --- |
| Agent | `cmd/uptime-agent`, `internal/agent` | Go, standard library only |
| Protocol, formula, proof verification | `protocol` | Go, standard library only |
| Independent verifier | `cmd/uptime-verify` | Go |
| Collector, public dashboard, admin pages | `collector/laravel` | PHP (Laravel, for Pterodactyl panels) |

The collector lives inside a [Pterodactyl](https://pterodactyl.io) panel because that
already provides administrator authentication, CSRF protection, a database, a scheduler
and local notifications. It is self-contained in `app/Uptime` and needs one line in
the panel configuration; see [collector/laravel/INTEGRATION.md](collector/laravel/INTEGRATION.md).

More detail: [docs/architecture.md](docs/architecture.md) ·
[docs/protocol.md](docs/protocol.md) · [docs/api.md](docs/api.md).

## Quick start (single machine, local network)

Requirements: a Pterodactyl panel (Laravel 12, PHP 8.3 with sodium, MySQL/MariaDB),
Go 1.24+ (or Docker) to build the agent, systemd on the node.

```bash
# 1. Collector: install into the panel and migrate.
git clone https://github.com/LtAkmal/UpTime-Server.git && cd UpTime-Server
sudo collector/laravel/install.sh /var/www/pterodactyl --register
cd /var/www/pterodactyl && sudo -u www-data php artisan migrate --force && sudo -u www-data php artisan optimize:clear

# 2. Agent: build and install on the node.
make build            # or: make docker-release
sudo deploy/install-agent.sh dist/uptime-agent
sudoedit /etc/uptime-agent/agent.conf   # collector_url = https://<collector-host>
```

3. In the panel: **Admin → Uptime Monitoring → Add monitored node**, then
   **Generate enrollment token** (one-time, 30 minutes).
4. On the node, enroll (paste the token when asked; it is read from standard input so
   it never appears in the shell history or process list) and start:

   ```bash
   sudo -u uptime-agent uptime-agent enroll --config /etc/uptime-agent/agent.conf --token-file -
   sudo systemctl enable --now uptime-agent
   ```

5. Open `https://<collector-host>/status`.

For a plain-HTTP collector on a private network during development, set
`allow_insecure_http = true` in the agent configuration. The agent then logs an
insecure-development warning on every start. Use HTTPS for anything public.
Full steps: [docs/deployment.md](docs/deployment.md).

## How uptime is calculated

A node is online while valid signed heartbeats arrive within the timeout. Downtime
starts after timeout + tolerance without a valid heartbeat and ends with the next one.
A signed boot event always adds the time between the last event before the reboot
and the boot instant.

```
uptime % = (monitoring window − confirmed downtime) / monitoring window × 100
```

Time before monitoring began is never counted (neither up nor down). Windows that
monitoring only partly covers show their coverage, and a window with no coverage shows
"No data", never 100%. Parameters are fixed when monitoring starts, so past uptime
cannot be recalculated with different rules. Details and examples:
[docs/uptime-formula.md](docs/uptime-formula.md).

## How proofs work

`GET /api/status/nodes/<public-id>/proof?from=…&to=…` returns the node's public keys,
every signed event in the range, the event just before it (anchor) and just after it
(tail), and the collector's uptime summary. Verify one yourself:

```bash
go run ./cmd/uptime-verify 'https://<collector-host>/api/status/nodes/<public-id>/proof'
```

The verifier checks every Ed25519 signature, payload hash, chain link, sequence
number, key validity window and clock window, then recomputes the uptime summary. It
exits non-zero on the first problem and names the event. See
[docs/verification.md](docs/verification.md).

## Verifying the source and the binaries

```bash
git clone https://github.com/LtAkmal/UpTime-Server.git && cd UpTime-Server
git checkout v0.1.0
make docker-release           # pinned Go image, -trimpath, commit time as build time
cat dist/SHA256SUMS           # compare with the release's SHA256SUMS
sha256sum /usr/local/bin/uptime-agent   # compare with the checksum shown on the status page
```

Each signed event carries the agent's version, commit and the SHA-256 of its own
executable, and the status page marks whether that checksum is in the operator's list
of published releases. Public-key fingerprints are `sha256(raw 32-byte Ed25519 public
key)`; `uptime-verify fingerprint <base64 key>` computes one.
See [docs/releasing.md](docs/releasing.md).

## Documentation

- [Architecture](docs/architecture.md)
- [Protocol specification](docs/protocol.md)
- [Threat model and security limitations](docs/threat-model.md)
- [Uptime formula](docs/uptime-formula.md)
- [Verifying proofs, binaries and keys](docs/verification.md)
- [Key enrollment and rotation](docs/key-rotation.md)
- [API reference](docs/api.md)
- [Deployment: local and public](docs/deployment.md)
- [Independent external monitors (roadmap)](docs/external-monitors.md)
- [Testing](docs/testing.md)
- [Releasing](docs/releasing.md)

## Development

```bash
make test              # go vet, go test, gofmt check
make build             # dist/uptime-agent, dist/uptime-verify
make vectors           # regenerate cross-language test vectors (Go ↔ PHP)
```

Collector tests run inside a panel checkout: `collector/laravel/install.sh <panel> --with-tests`,
then `vendor/bin/phpunit tests/Integration/Uptime`. See [docs/testing.md](docs/testing.md).

## Security

Please report vulnerabilities privately; see [SECURITY.md](SECURITY.md). Do not open a
public issue for a vulnerability.

## License

MIT, see [LICENSE](LICENSE).
