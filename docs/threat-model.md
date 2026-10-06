# Threat model and security limitations

UpTime-Server aims for **auditability**, not for absolute tamper resistance. This page
says what it defends against, what it only makes detectable, and what it cannot address.

## Assets

- The published uptime figures and incident history.
- The integrity of the stored event history.
- Agent private keys (one per node).
- Enrollment tokens (short-lived, one-time).
- Administrator access to the collector.

## Actors

| Actor | Capabilities assumed |
| --- | --- |
| Internet attacker | Can send arbitrary HTTP requests to the collector, replay captured traffic, observe plain-HTTP traffic. |
| Compromised customer server | Runs inside a node's containers; no host access. |
| Operator (us) | Root on collector and nodes, database access, deployment and DNS control, all private keys. |
| Customer / auditor | Reads the status page, proofs and source code; runs `uptime-verify`. |

## What is prevented

| Threat | Control |
| --- | --- |
| Forged heartbeats | Ed25519 signature with the node's registered key; unknown and revoked keys rejected. |
| Replay of captured events | Strictly increasing per-key sequence numbers; identical older events rejected; the event hash chain (`prev`) must continue the current head. |
| Delayed or pre-dated events | Agent timestamp must be within ±skew of the collector receipt time. |
| Agent registration by strangers | One-time enrollment token, stored only as SHA-256, valid 30 minutes, rate-limited endpoint. |
| Cross-site requests to admin actions | Panel administrator authentication, 2FA policy and CSRF tokens; no admin action is reachable from the public API. |
| Data leaks from the public page/API | Only display name, public id, figures, public keys and signed payloads (which contain no host details) are published. |
| Accidental edits of history | Model refuses updates/deletes; MySQL/MariaDB triggers refuse `UPDATE`/`DELETE` on `uptime_events`; no admin route edits events, outages, uptime or verification results. |
| Retroactive rule changes | Monitoring parameters lock when the first event is accepted. |

## What is detectable (not preventable)

| Change | How it is detected |
| --- | --- |
| Editing or deleting stored events in the database | Hourly re-verification (`uptime:verify-chain --all --record`) and any external `uptime-verify` run fail at the first broken event; the public page shows "Not verified". |
| Editing derived outages | `uptime:rebuild-outages` reports a difference; proofs' summaries no longer match the events. |
| Replacing the agent binary | The signed `agent_sha256` changes; recorded as an anomaly and shown as "not in the published release list" unless it matches a published checksum. |
| Clock manipulation on a node | Drift, rollback and uptime/receipt inconsistencies are recorded as anomalies. |
| Cloned agent (same key on two machines) | Equivocation (same sequence, different content) and chain conflicts; the agent refuses to follow a head it did not sign. |

## What is NOT addressed

These are inherent to a system run by a single operator. They are stated publicly on
the status page.

1. **The operator controls everything.** With root on the collector and the nodes, the
   operator can generate keys, run a modified agent, stop recording, drop the database
   and start a new chain, or change the collector code. Signatures only prove that the
   holder of a registered key signed an event.
2. **Not independent.** The current deployment is not independently operated by a
   third-party monitor. In the initial deployment the collector and a node may run on
   the same machine, so a machine failure can take both down: the gap is counted as
   downtime (the conservative choice), but nothing outside proves what happened.
3. **Omission.** A proof shows the recorded events are consistent; it cannot show that
   every heartbeat that was sent was recorded, or that downtime outside recorded gaps
   did not happen.
4. **Source ≠ deployment.** Published source and reproducible builds let anyone check
   that a binary with a given SHA-256 comes from a given commit. Nothing proves which
   binary actually runs on a server: the reported `agent_sha256` is self-reported by
   that binary. The collector's file digest is likewise a self-check.
5. **Key compromise.** Whoever has a node's private key can sign events for it until
   the key is revoked. Revocation stops acceptance from that instant; earlier events
   stay valid.
6. **Plain HTTP in development.** Without TLS, an observer can read events (which
   contain no secrets) and enrollment tokens. Signatures still prevent forgery. Use
   HTTPS for any public deployment.
7. **Availability.** The collector is a single service. If it is unreachable, nodes
   appear offline; if its scheduler stops, offline notifications and integrity checks
   pause and the public page says "Monitoring unavailable".

## Mitigation roadmap

- Independent external monitors that sign their own observations (see
  [external-monitors.md](external-monitors.md)). Recommended: at least two, run by
  different parties on different networks.
- Periodic publication of chain heads to an external, append-only location (for
  example signed Git tags or a transparency log), so a rewritten database cannot match
  previously published heads.
- Hardware-backed agent keys (TPM) where available.
