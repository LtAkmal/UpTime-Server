# Changelog

## 0.1.2 — 2026-10-06

Collector only; the agent is unchanged (0.1.0 agent binaries remain current).

- Admin pages: checkboxes use the panel's own markup (`<input>` + `<label for>`), so their
  checked state is shown correctly (they always looked unchecked before).

## 0.1.1 — 2026-10-06

Collector only; the agent is unchanged (0.1.0 agent binaries remain current).

- Status page: a window that monitoring covers by less than 0.1% now says
  "monitored <0.1% of window" instead of "0%".
- The installed-files self-check is recomputed immediately after an upgrade (the cached
  digest is keyed by the release manifest).

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
