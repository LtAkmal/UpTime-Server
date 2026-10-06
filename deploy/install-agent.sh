#!/usr/bin/env bash
# Installs the UpTime-Server agent on a Linux node with systemd.
#
#   sudo deploy/install-agent.sh <path to uptime-agent binary>
#
# Creates the unprivileged system user "uptime-agent", installs the binary, an example
# configuration (if none exists) and the systemd unit. It does not enroll or start the
# agent: edit /etc/uptime-agent/agent.conf, enroll with a one-time token, then start.
set -euo pipefail
[ "$(id -u)" = 0 ] || { echo "run as root" >&2; exit 1; }
BIN="${1:?usage: install-agent.sh <uptime-agent binary>}"
HERE="$(cd "$(dirname "$0")" && pwd)"

"$BIN" version >/dev/null
id uptime-agent >/dev/null 2>&1 || useradd --system --no-create-home --home-dir /var/lib/uptime-agent --shell /usr/sbin/nologin uptime-agent

install -o root -g root -m 0755 "$BIN" /usr/local/bin/uptime-agent
install -d -o root -g uptime-agent -m 0750 /etc/uptime-agent
[ -f /etc/uptime-agent/agent.conf ] || install -o root -g uptime-agent -m 0640 "$HERE/config/agent.conf.example" /etc/uptime-agent/agent.conf
install -d -o uptime-agent -g uptime-agent -m 0700 /var/lib/uptime-agent
install -o root -g root -m 0644 "$HERE/systemd/uptime-agent.service" /etc/systemd/system/uptime-agent.service
systemctl daemon-reload

/usr/local/bin/uptime-agent version
cat <<TXT

Installed. Next:
  1. Set collector_url (and interval) in /etc/uptime-agent/agent.conf.
  2. In the collector admin (Admin → Uptime Monitoring), create the node and generate a token.
  3. sudo -u uptime-agent uptime-agent enroll --config /etc/uptime-agent/agent.conf --token-file -
     (paste the token, press Enter; it is read from standard input, not the command line)
  4. sudo systemctl enable --now uptime-agent && journalctl -u uptime-agent -f
TXT
