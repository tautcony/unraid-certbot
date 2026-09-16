# Local development

The local preview runs the plugin in the `dev/run/` sandbox. All reads and writes stay inside the sandbox and do not affect the host system.

## Quick start

```bash
./dev.sh                # Initialize the sandbox and start the preview server
```

Open <http://127.0.0.1:8080>:

| URL | Contents |
|---|---|
| `/` | Debug home page with sandbox status and shortcuts |
| `/Settings/UnraidCertbot` | Certificate Status, Settings, Renewal History, and Run Log |

Useful commands:

```bash
./dev.sh status          # Show current status
./dev.sh renew           # Run one renewal check
./dev.sh renew --force   # Force renewal
./dev.sh reset           # Rebuild the sandbox
./dev.sh doctor          # Check local dependencies
./dev.sh --port 9000    # Use a different port
```

## Sandbox

`dev/run/` is ignored by Git and mirrors the Unraid filesystem. The plugin directory is a symlink to `source/`, so source changes take effect immediately. `dev/bin/docker` simulates certificate issuance with a self-signed OpenSSL certificate and does not access the network; `dev/bin/rc.nginx` simulates nginx restart.

Sample data uses hostname `tower` and domains `example.com,www.example.com`. Override them with environment variables:

```bash
CB_DEV_CERT_DAYS=10 ./dev.sh reset    # Generate an almost-expired certificate
```

| Variable | Purpose | Default |
|---|---|---|
| `CB_DEV_ROOT` | Sandbox root | `dev/run` |
| `CB_DOCKER` | Docker executable | `$CB_DEV_ROOT/bin/docker` |
| `CB_DEV_PORT` | Preview port | `8080` |
| `CB_DEV_TZ` | Time zone | System time zone |
| `CB_DEV_HOST` | Sample hostname | `tower` |
| `CB_DEV_EMAIL` | Sample email | `admin@example.com` |
| `CB_DEV_DOMAINS` | Sample domains | `example.com,www.example.com` |
| `CB_DEV_CERT_DAYS` | Sample certificate lifetime | `90` |

These variables are for local development only.
