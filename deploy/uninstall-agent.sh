#!/usr/bin/env bash
# Removes the agent service and binary. The key and state in /var/lib/uptime-agent are
# kept unless --purge is given (revoke the key in the collector first).
set -euo pipefail
[ "$(id -u)" = 0 ] || { echo "run as root" >&2; exit 1; }
systemctl disable --now uptime-agent 2>/dev/null || true
rm -f /etc/systemd/system/uptime-agent.service /usr/local/bin/uptime-agent
systemctl daemon-reload
if [ "${1:-}" = "--purge" ]; then
    rm -rf /var/lib/uptime-agent /etc/uptime-agent
    userdel uptime-agent 2>/dev/null || true
    echo "Removed agent, configuration, key and state."
else
    echo "Removed agent service and binary; /etc/uptime-agent and /var/lib/uptime-agent kept (use --purge to delete)."
fi
