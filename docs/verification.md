# Verifying proofs, binaries and keys

## 1. Verify a node's event chain

Every public node page links to its proof:

```
GET /api/status/nodes/<public-id>/proof?from=<ms|ISO-8601>&to=<ms|ISO-8601>
```

Run the verifier from this repository (Go 1.24+):

```bash
go run ./cmd/uptime-verify 'https://<collector-host>/api/status/nodes/<public-id>/proof?from=2026-10-01T00:00:00Z'
# or a saved file:
go run ./cmd/uptime-verify proof.json
```

It follows `page.next` links (same host only) and checks, for every event:

1. the payload is canonical and matches the version 1 schema;
2. the payload's `node` is the proof's node;
3. the signing key is listed in the proof, its fingerprint is `sha256(public key)`, and it
   was valid at the receipt time (registered ≤ receipt ≤ revoked);
4. the Ed25519 signature over `"uptime-server/event/v1\n" || payload`;
5. `payload_hash = sha256(payload)`;
6. the stored `prev_hash` equals the signed `prev`, and equals the previous event's hash
   (or 64 zeros for the node's first event, or the anchor's hash);
7. `event_hash = sha256(raw(prev_hash) || raw(payload_hash))`;
8. sequence numbers increase per key, receipt times never go backwards;
9. the agent timestamp is within the node's clock window of the receipt time.

Then it recomputes the outages and the uptime of the range with the published formula
and compares them with the collector's summary. Exit status: `0` valid, `1` invalid
(with the first broken event), `2` usage or fetch error. `--json` prints a machine-readable
report.

**What a valid result means:** every recorded event was signed by the node's registered
key, nothing in the range was changed, removed or reordered, and the published uptime
follows from these events. **What it does not mean:** that every heartbeat sent was
recorded, or that the operator could not have run a modified system (see the threat model).

A range that does not start at the node's first event is verified from its *anchor* (the
event just before the range). To verify the whole history, request
`from=<monitoring_started_at>`; the proof then starts at the genesis hash.

## 2. Verify an agent binary

```bash
git clone https://github.com/LtAkmal/UpTime-Server.git && cd UpTime-Server
git checkout v<version>
make docker-release          # pinned Go toolchain image, see Makefile GO_IMAGE
cat dist/SHA256SUMS
```

The build is reproducible: same commit and same Go version give byte-identical
binaries (`-trimpath`, `-buildvcs=false`, empty build id, commit time as build time). Compare:

- your `dist/SHA256SUMS` with the `SHA256SUMS` of the GitHub release;
- the agent's "Binary SHA-256" on the node status page (taken from its signed events)
  with the checksum of the matching platform build;
- on a node you operate: `uptime-agent version` and `sha256sum /usr/local/bin/uptime-agent`.

## 3. Verify a public-key fingerprint

The node page lists the fingerprint of each signing key. The proof contains the public
key itself:

```bash
go run ./cmd/uptime-verify fingerprint '<base64 public key>'
# or
echo '<base64 public key>' | base64 -d | sha256sum
```

On the node: `sudo -u uptime-agent uptime-agent status --config /etc/uptime-agent/agent.conf`
prints the fingerprint of the local key.

## 4. Verify the collector version (administrators)

The status page shows the collector release, its Git commit and whether the installed
files match the release manifest. On the collector host:

```bash
cd /path/to/UpTime-Server && git checkout <commit>
scripts/collector-digest.sh collector/laravel/app/Uptime
cd /var/www/pterodactyl && php artisan tinker --execute='echo Pterodactyl\Uptime\Services\ReleaseInfo::digest(app_path("Uptime"));'
```

The two digests must be equal. This is a self-check by the operator; it does not prove
anything to a third party.
