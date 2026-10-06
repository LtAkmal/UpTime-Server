# Changelog

## 0.1.0 — 2026-10-06

First release.

- Go agent: Ed25519 key generated on the node, one-time-token enrollment, signed and
  hash-chained boot/start/heartbeat/stop events, reboot detection from the kernel boot id
  and `/proc/uptime`, optional local health checks, bounded retries, hardened systemd unit.
- Protocol v1: canonical payloads, signature context, per-node hash chain, proof format.
- `uptime-verify`: independent verification of proofs and recomputation of uptime.
- Laravel collector for Pterodactyl panels: append-only event store with database
  triggers, replay and clock checks, anomalies, outages, hourly chain verification,
  public `/status` pages and API, admin pages, local mail notifications.
- Reproducible builds with embedded version, commit and build time.
