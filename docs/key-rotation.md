# Key enrollment, rotation and revocation

## Enrollment

1. **Admin → Uptime Monitoring → Add monitored node** (public display name, optional
   private link to a Pterodactyl node, interval/timeout/tolerance/clock window).
2. **Generate enrollment token.** A random 256-bit token is shown once on the next page
   view, valid for 30 minutes. Only its SHA-256 is stored. Generating a new token cancels
   any unused earlier one.
3. On the node:
   ```bash
   sudo -u uptime-agent uptime-agent enroll --config /etc/uptime-agent/agent.conf --token-file -
   ```
   Paste the token and press Enter. The agent generates its key locally (if none exists),
   sends the public key and the token, and stores the returned node id and chain head.
4. `sudo systemctl enable --now uptime-agent`.

The enrollment endpoint is rate-limited (10/minute per address). Invalid, expired or
reused tokens are rejected and recorded as an anomaly; administrators are notified.

### Manual registration (no token)

```bash
sudo -u uptime-agent uptime-agent keygen --config /etc/uptime-agent/agent.conf
```

Copy the printed public key into **Register a public key manually** on the node's admin
page, then on the node:

```bash
sudo -u uptime-agent uptime-agent set-node --config /etc/uptime-agent/agent.conf --node <public-id>
sudo systemctl enable --now uptime-agent
```

## Rotation

1. On the node's admin page: **Generate rotation token**.
2. On the node:
   ```bash
   sudo -u uptime-agent uptime-agent enroll --config /etc/uptime-agent/agent.conf --token-file - --rotate-key
   sudo systemctl restart uptime-agent
   ```
   A new key is generated next to the old one, registered, and only then installed; the
   old private key file is replaced.

From the moment the new key is registered, the old key is marked `rotated` and events
signed with it are rejected. The chain continues: the first event of the new key links
to the node's current head. Proofs list every key with its validity window, so history
signed by the old key stays verifiable.

## Revocation

On the node's admin page, **Revoke** a key with a reason (audited). Events signed with
it after that instant are rejected; earlier events stay valid. Revoke immediately if a
node's key may be compromised, then enroll a new key.

## Archiving a node

**Archive node** stops accepting events, revokes the active key and hides the node from
the public page. Events, outages and proofs are kept; nothing is deleted.
