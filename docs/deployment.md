# Deployment

Placeholders: `<panel>` is the panel directory (for example `/var/www/pterodactyl`),
`<collector-host>` the host name or address the agents use to reach the panel.

## Local / single-machine deployment (current setup)

Panel, collector and a node on one machine or one private network. **Not independent**;
the status page says so.

### Collector

```bash
git clone https://github.com/LtAkmal/UpTime-Server.git && cd UpTime-Server
sudo collector/laravel/install.sh <panel> --register        # copies app/Uptime, writes release.json
cd <panel>
sudo -u www-data php artisan migrate --force                 # uptime_* tables and append-only triggers
sudo -u www-data php artisan optimize:clear
sudo -u www-data php artisan schedule:list | grep uptime     # uptime:check (1 min), verify-chain (hourly)
```

The panel's cron must run `php artisan schedule:run` every minute (as the web user).
Optionally add the sidebar and footer links from
[../collector/laravel/INTEGRATION.md](../collector/laravel/INTEGRATION.md).

### Agent on the same machine

```bash
make build                                   # or make docker-release
sudo deploy/install-agent.sh dist/uptime-agent
sudoedit /etc/uptime-agent/agent.conf
#   collector_url = http://<collector-host>  (plain HTTP on a private network…)
#   allow_insecure_http = true               (…needs this, and logs a warning on every start)
#   check_tcp = wings 127.0.0.1:8080         (optional local check)
```

Create the node and a token in **Admin → Uptime Monitoring**, then:

```bash
sudo -u uptime-agent uptime-agent enroll --config /etc/uptime-agent/agent.conf --token-file -
sudo systemctl enable --now uptime-agent
journalctl -u uptime-agent -f
```

### Database backups

`uptime_events` is the evidence; back it up with the panel database (`mysqldump
--single-transaction`). A restored backup that is older than the agents' state makes
agents see a `prev_mismatch`; an agent follows the restored head only if it signed that
event, and the gap appears as downtime. Never "repair" the events table by hand: run
`php artisan uptime:verify-chain --all` after any restore.

## Public deployment (recommended)

1. **Separate collector host.** Run the panel/collector on a host that is not a
   monitored node, so a node failure cannot hide its own downtime.
2. **HTTPS.** Terminate TLS at a reverse proxy (nginx, Caddy) with a public certificate;
   remove `allow_insecure_http` from every agent.
3. **DNS.** A dedicated name such as `status.example.com`; protect the DNS account with
   2FA (DNS control could redirect agents and visitors).
4. **Reverse proxy.** Pass `X-Forwarded-For` and configure the panel's trusted proxies so
   rate limits apply per client. Limit request bodies (the agent API needs < 8 KiB).
5. **Firewall.** The collector needs 443 from agents and visitors only. Agents need no
   inbound ports. Optionally restrict the agent unit to the collector address with a
   systemd drop-in (`IPAddressDeny=any`, `IPAddressAllow=<collector-address>`).
6. **Separate status host (optional).** The status pages and public API are read-only
   and can be cached by a CDN for short periods (≤ 30 s); proofs are `no-store`.
7. **Independent monitors.** At least two, run by different parties on different
   networks (see [external-monitors.md](external-monitors.md)). Until they exist, keep the
   "not independently operated" notice.
8. **Key rotation.** Rotate agent keys when a node is rebuilt or access changes;
   revoke immediately on suspected compromise ([key-rotation.md](key-rotation.md)).
9. **Backups.** Daily database backups kept off-host; publish chain heads somewhere
   append-only if you want rewrites to be detectable by others.
10. **Incident response.** On a "Chain verification failed" or "Invalid signature"
    notification: do not modify data; run `uptime:verify-chain --node=<id>`, check
    access logs and the activity log, rotate the node key, and add an administrator note
    explaining what happened. The broken chain stays visible as "Not verified".

Nothing here changes network configuration automatically.
