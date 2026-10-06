#!/usr/bin/env bash
# Prints the digest the collector computes over its own files (ReleaseInfo::digest):
# SHA-256 of "sha256  relative/path" lines, sorted by path, excluding release.json.
set -euo pipefail
dir="${1:-collector/laravel/app/Uptime}"
cd "$dir"
find . -type f ! -name release.json | sed 's|^\./||' | LC_ALL=C sort | while IFS= read -r f; do
    printf '%s  %s\n' "$(sha256sum "$f" | cut -d' ' -f1)" "$f"
done | sha256sum | cut -d' ' -f1
