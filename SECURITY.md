# Security policy

## Reporting a vulnerability

Please **do not** open a public issue for security problems. Use GitHub's private
vulnerability reporting for this repository ("Security" tab → "Report a vulnerability").
Include the affected version or commit, the impact and steps to reproduce.

You can expect an acknowledgement within 7 days. Fixes are released as a new version and
described in `CHANGELOG.md` after users had a reasonable chance to update.

## Scope

In scope: the agent, the protocol and verifier, the collector code in
`collector/laravel`, and the documentation's security claims (if something here
over-promises, that is a bug).

Out of scope: the limitations listed in [docs/threat-model.md](docs/threat-model.md)
(for example, an operator with root access changing their own deployment), and the
Pterodactyl panel itself (report those to the Pterodactyl project).

## Handling secrets

This repository must never contain private keys, enrollment tokens, API keys,
passwords, `.env` files, production configuration, database dumps, real IP addresses
or customer data. The `.gitignore` excludes the usual locations; please double-check
your diffs before opening a pull request.
