# Releasing

1. Update `VERSION` and `CHANGELOG.md`; commit.
2. Tag: `git tag -s v$(cat VERSION) -m "UpTime-Server $(cat VERSION)"` and push the tag.
3. Build in the pinned toolchain image:
   ```bash
   make docker-release
   ```
   `dist/` then holds `uptime-agent-<version>-linux-{amd64,arm64}`,
   `uptime-verify-<version>-linux-{amd64,arm64}` and `SHA256SUMS`.
4. Rebuild once more (or on another machine) and compare `SHA256SUMS`; they must match.
5. Publish a GitHub release for the tag with the binaries and `SHA256SUMS`.
6. Add `<version> <sha256>` lines for each agent binary to **Admin → Uptime Monitoring →
   Settings → Published agent releases**.

Build metadata embedded with `-ldflags -X`: version (`VERSION`), Git commit, and the
commit time as build time. Reproducibility comes from `-trimpath`, `-buildvcs=false`,
an empty build id, `CGO_ENABLED=0` and the pinned Go version (`GO_IMAGE` in the
Makefile). A different Go version produces different bytes; the Go version is printed by
`uptime-agent version`.

`make release` refuses to build from a dirty working tree.

## Collector

`collector/laravel/install.sh` records the repository version, the Git commit (only if
the collector files are unmodified) and the file digest in `app/Uptime/release.json`.
The status page shows them and whether the installed files still match the digest.
