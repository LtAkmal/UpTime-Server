# Event protocol, version 1

This is the normative description of what an agent signs, what the collector stores
and what a verifier checks. The Go reference implementation is `protocol/`; the PHP
collector implements the same rules in `collector/laravel/app/Uptime/Protocol`. Both
are tested against the shared vectors in `testdata/vectors/protocol-v1.json`.

## Keys and fingerprints

- Algorithm: Ed25519 (RFC 8032). One key pair per agent, generated on the node.
- The private key is stored as PKCS#8 PEM in `<state_dir>/agent.key`, mode `0600`, and
  never leaves the node. The agent refuses a key file readable by group or others.
- Public keys are exchanged as standard base64 of the raw 32 bytes.
- **Fingerprint** = lowercase hex `SHA-256(raw 32-byte public key)` (64 characters).

## Canonical payload

A payload is a flat JSON object in canonical form:

- keys match `[a-z0-9_]{1,32}`, are unique and sorted by byte value;
- values are either strings or non-negative integers;
- strings contain only printable ASCII `0x20`–`0x7E` except `"` and `\` (so no escape
  sequences ever occur), at most 256 bytes;
- integers are decimal, no sign, no leading zeros, at most 2^53 − 1;
- no whitespace anywhere.

The collector verifies the signature over the **exact received bytes**, then decodes
them and re-encodes the result; if the bytes differ, the event is rejected. Because of
these rules there is exactly one encoding for every payload, in every language.

### Fields (all required, no others allowed)

| Field | Type | Meaning |
| --- | --- | --- |
| `proto` | int | Protocol version, `1` |
| `type` | string | `boot` (first event of a new boot session), `start` (agent restarted, same boot), `heartbeat`, `stop` (graceful shutdown) |
| `node` | string | Public node id assigned by the collector, `^[a-z0-9][a-z0-9-]{2,39}$` |
| `key` | string | Fingerprint of the signing key |
| `seq` | int | Per-key sequence number, strictly increasing, gaps allowed, ≥ 1 |
| `ts` | int | Agent wall clock, Unix milliseconds (UTC) |
| `mono_ms` | int | Time since boot in ms (Linux `CLOCK_BOOTTIME` via `/proc/uptime`) |
| `boot` | string | 32 hex: first 16 bytes of `SHA-256("uptime-server boot\n" + kernel boot_id)`; changes on every reboot |
| `run` | string | 32 hex: random id of this agent process |
| `status` | string | `ok`, `degraded` (a configured local check failed) or `stopping` |
| `checks_failed` | int | Number of failed local checks (0–64); never labels, addresses or paths |
| `prev` | string | Event hash of the previous event in the node's chain (64 zeros for the first) |
| `agent_version` | string | Release version, `^[A-Za-z0-9._+-]{1,64}$` |
| `agent_commit` | string | Git commit embedded at build time, or `unknown` |
| `agent_build` | string | Build time embedded at build time (commit time), or `unknown` |
| `agent_sha256` | string | SHA-256 of the running agent executable |

Deliberately **absent**: IP addresses, hostnames, FQDNs, tokens, environment values,
paths, process lists, kernel command line, hardware serials, customer data.

## Signature

```
signature = Ed25519-Sign(private_key, "uptime-server/event/v1\n" || canonical_payload)
```

The context prefix makes an UpTime-Server signature unusable for anything else. The
signature is transmitted as standard base64 (64 bytes raw).

## Hash chain

```
payload_hash = SHA-256(canonical_payload)                       (hex)
event_hash   = SHA-256(raw(prev_hash) || raw(payload_hash))     (hex, 32 + 32 bytes input)
```

Each event's `prev` is the `event_hash` of the node's previous accepted event. Because
`prev` is inside the signed payload, the agent's signature covers the link: changing,
removing, inserting or reordering any stored event breaks the chain from that point,
and fixing it would need the agent's private key for every later event.

The chain is per node and continues across key rotations: the first event signed with
a new key links to the node's current head (returned by the enrollment response).
Sequence numbers are per key.

## Acceptance rules (collector)

Checked in this order; a rejected event never changes stored data.

1. Body ≤ 8 KiB JSON; `payload` ≤ 2048 bytes; `signature` base64 of 64 bytes.
2. Payload canonical and schema-valid (400 `invalid_payload`).
3. Node exists, is not archived (404 `unknown_node`) and monitoring is enabled (403).
4. Key registered for that node (403 `unknown_key`) and active (403 `revoked_key`).
5. Signature valid (403 `invalid_signature`; recorded as a critical anomaly).
6. With the node row locked:
   - an identical event that is the current head → 200 `duplicate` (idempotent retry);
   - an identical older event → 409 `replay`;
   - `seq` ≤ the key's last sequence → 409 `duplicate_sequence` if that sequence exists
     with other content (critical anomaly), else 409 `stale_sequence`;
   - `|ts − receipt time| > skew` (default 120 s) → 422 `timestamp_out_of_window`;
   - `prev` ≠ current head → 409 `prev_mismatch` with the current `head` and `last_seq`.
7. Append the event with the collector's receipt time, update the head, derive outages.

Accepted-but-suspicious events are kept and recorded as anomalies (shown publicly only
as "Monitoring anomaly detected"): clock drift over half the window, wall clock going
back, `mono_ms` decreasing within one boot session, uptime advancing differently from
receipt time, a new boot session without a `boot` event, a boot instant before the
previous event, a changed agent binary or commit, a binary not in the published list.

## Agent behaviour

- State (`state.json`, mode 0600) holds the sequence, chain head, last boot session
  and the hashes of recently signed events. It is written **before** each send, so a
  sequence number is never reused after a crash.
- Transient failures (network, 429, 5xx) are retried with the same signed bytes and
  exponential backoff while the event is younger than 60 s; then it is discarded and the
  next event gets a new sequence number (a gap, which is allowed).
- On `prev_mismatch` the agent follows the reported head **only if it signed that event
  itself** (for example the collector accepted an event whose reply was lost). A head it
  never signed means another agent uses the same key or the data is not this node's; the
  agent refuses and logs an error.
- On SIGTERM the agent sends a best-effort `stop` event.

## Clock tolerance

The collector uses its own receipt time for availability. The agent clock must be
within ±`skew_seconds` (default 120 s, fixed per node once monitoring starts) of the
receipt time. Run NTP on nodes and collector.

## Versioning

A future incompatible change gets a new `proto` value, a new signature context
(`uptime-server/event/v2`) and a new proof format string.
