#!/usr/bin/env bash
# Installs (or updates) the UpTime-Server collector into a Pterodactyl panel.
#
#   collector/laravel/install.sh /var/www/pterodactyl [--register] [--with-tests]
#
#   --register     add the service provider to the panel's config/app.php if missing
#   --with-tests   also copy the collector tests to tests/Integration/Uptime
#
# Writes app/Uptime/release.json (version, Git commit, file digest) so the status page
# can show which release is installed and whether the files still match it.
set -euo pipefail

usage() { sed -n '2,10p' "$0"; exit 2; }
[ $# -ge 1 ] || usage
PANEL="$(cd "$1" && pwd)"; shift
REGISTER=0; TESTS=0
for arg in "$@"; do
    case "$arg" in
        --register) REGISTER=1 ;;
        --with-tests) TESTS=1 ;;
        *) usage ;;
    esac
done

SRC="$(cd "$(dirname "$0")" && pwd)"
REPO="$(cd "$SRC/../.." && pwd)"
[ -f "$PANEL/artisan" ] && [ -f "$PANEL/config/app.php" ] || { echo "error: $PANEL is not a Pterodactyl panel" >&2; exit 1; }

VERSION="$(cat "$REPO/VERSION")"
COMMIT="unknown"
if git -C "$REPO" rev-parse HEAD >/dev/null 2>&1; then
    if [ -z "$(git -C "$REPO" status --porcelain -- collector/laravel/app)" ]; then
        COMMIT="$(git -C "$REPO" rev-parse HEAD)"
    else
        echo "warning: collector files have uncommitted changes; the commit is recorded as unknown" >&2
    fi
fi
DIGEST="$("$REPO/scripts/collector-digest.sh" "$SRC/app/Uptime")"
OWNER="$(stat -c '%U:%G' "$PANEL/artisan")"

rsync -a --delete --exclude release.json "$SRC/app/Uptime/" "$PANEL/app/Uptime/"
cat > "$PANEL/app/Uptime/release.json" <<JSON
{
    "software": "UpTime-Server collector",
    "version": "$VERSION",
    "commit": "$COMMIT",
    "digest": "$DIGEST",
    "installed_at": "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
}
JSON
chown -R "$OWNER" "$PANEL/app/Uptime"
find "$PANEL/app/Uptime" -type d -exec chmod 755 {} + && find "$PANEL/app/Uptime" -type f -exec chmod 644 {} +

if [ "$TESTS" = 1 ]; then
    rsync -a --delete "$SRC/tests/Uptime/" "$PANEL/tests/Integration/Uptime/"
    chown -R "$OWNER" "$PANEL/tests/Integration/Uptime"
fi

PROVIDER='Pterodactyl\Uptime\UptimeServiceProvider::class'
if grep -qF "$PROVIDER" "$PANEL/config/app.php"; then
    echo "provider already registered"
elif [ "$REGISTER" = 1 ]; then
    cp "$PANEL/config/app.php" "$PANEL/config/app.php.uptime-backup"
    python3 - "$PANEL/config/app.php" <<'PY'
import sys
path = sys.argv[1]
s = open(path).read()
anchor = "        Pterodactyl\\Providers\\RouteServiceProvider::class,\n"
if anchor not in s:
    sys.exit("could not find the provider list; register the provider manually (see INTEGRATION.md)")
s = s.replace(anchor, anchor + "        // UpTime-Server collector (app/Uptime).\n        Pterodactyl\\Uptime\\UptimeServiceProvider::class,\n", 1)
open(path, "w").write(s)
PY
    echo "provider registered (backup: config/app.php.uptime-backup)"
else
    echo "next: register $PROVIDER in config/app.php (or rerun with --register)"
fi

cat <<TXT
Installed UpTime-Server collector $VERSION (commit $COMMIT, digest $DIGEST).
Then, in $PANEL:
    sudo -u ${OWNER%%:*} php artisan migrate --force
    sudo -u ${OWNER%%:*} php artisan optimize:clear
and make sure the panel's scheduler (php artisan schedule:run) runs every minute.
TXT
