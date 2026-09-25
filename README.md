# unraid-certbot

Automatically issue and renew Let's Encrypt certificates for the Unraid webGUI using Cloudflare DNS-01 validation.

## Installation

Requires Unraid 6.9 or later:

```bash
plugin install https://raw.githubusercontent.com/tautcony/unraid-certbot/master/unraid-certbot.plg
```

## Configuration

Open **Settings -> Network Services -> Unraid Certbot**. The page contains Certificate Status, Settings, Renewal History, and Run Log tabs.

### Cloudflare API token

Create a token in **My Profile -> API Tokens -> Create Token** with:

- **Permissions**: `Zone` -> `DNS` -> `Edit`
- **Zone Resources**: `Include` -> `Specific zone`, selecting the zones containing the requested domains

### Settings

| Setting | Description |
|---|---|
| Cloudflare API Token | Used for DNS-01 validation; stored in `cloudflare.ini` with mode 600 |
| Email | Receives Let's Encrypt expiry notices |
| Unraid Hostname | Must match the Unraid server name or the webGUI will not load the new certificate |
| Domains | Comma- or newline-separated; the first domain is primary; wildcards such as `*.example.com` are supported |
| DNS propagation wait | Time to wait after creating DNS records; default 60 seconds |
| Certificate storage directory | Default `/mnt/user/appdata/letsencrypt`; must support symbolic links; FAT/exFAT will fail |
| Automatic renewal frequency | Check frequency; default daily; renewal occurs only within 30 days of expiry |
| Restart nginx after update | Restarts the webGUI service when the certificate changes; enabled by default |
| Staging environment | Uses Let's Encrypt Staging; issued certificates are not trusted by browsers |

For the first setup, enable **Staging environment** to validate the token and domain configuration. After validation succeeds, clear it and run **Force renewal** to obtain a production certificate.

## File locations

| Path | Contents |
|---|---|
| `/boot/config/plugins/unraid-certbot/` | Configuration, token, renewal history, log, and cron entry |
| `/mnt/user/appdata/letsencrypt/` (default) | certbot accounts, certificates, and renewal configuration |
| `/boot/config/ssl/certs/<hostname>_unraid_bundle.pem` | Certificate used by the webGUI |

## Troubleshooting

```bash
# Show current status (read-only)
/usr/local/emhttp/plugins/unraid-certbot/scripts/renew.sh --status

# Run a renewal check manually
/usr/local/emhttp/plugins/unraid-certbot/scripts/renew.sh --trigger=manual

# Force renewal
/usr/local/emhttp/plugins/unraid-certbot/scripts/renew.sh --force --trigger=manual

# View the log
tail -50 /boot/config/plugins/unraid-certbot/certbot.log
```

Exit codes: `1` configuration error, `2` certbot failure, `3` another instance is running, `4` Docker unavailable.

Common issues:

- **Invalid zone ID**: the token lacks permission or the domain is outside an authorized zone.
- **Docker unavailable**: the array is stopped or Docker is not available.
- **Old certificate still used by the webGUI**: the hostname does not match the Unraid server name, or restart nginx is disabled.
- **Browser still reports an expired certificate**: refresh after clearing or bypassing the browser cache.

## Development

Source is under `source/unraid-certbot/` and maps to `/usr/local/emhttp/` on Unraid.

```bash
./lint.sh               # Run the same static checks as CI
./build.sh              # Build the package using VERSION
./dev.sh                # Start the local preview at http://127.0.0.1:8080
```

See [dev/README.md](dev/README.md) for the local sandbox.

Push a `YYYY.MM.DD` tag to let GitHub Actions build and publish a release. Version tags must use this format.

## License

GPL-3.0-or-later, see [LICENSE](LICENSE).

Copyright (C) 2026 tautcony
