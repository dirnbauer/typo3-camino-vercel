# Always-On Hetzner Deployment

Use this profile when backend, personalized, or search requests must not wait
for a scale-to-zero activation. It runs TYPO3, MariaDB, Redis, and Solr on one
always-on host; only Caddy publishes ports to the internet.

## Recommended Baseline

For the measured Camino workload, start with a Hetzner Cloud `CX43` in Germany:

- 8 shared vCPU, 16 GB RAM, and 160 GB SSD
- €15.99/month excluding VAT
- €3.20/month for Hetzner's seven-slot backup option (20% of server price)
- €0.50/month for one primary IPv4 address
- €19.69/month excluding VAT in total
- 20 TB/month outbound traffic included at EU locations

Solr runs on the same host and has no separate license or hosting line item.
This is a predictable single-host setup, not high availability. Enable the
provider backup option, monitor it externally, and keep a database/file export
outside the server. Use a managed TYPO3 provider or a redundant design when a
formal availability SLA is required.

Current prices must be rechecked before ordering:

- [Hetzner June 2026 cloud pricing](https://docs.hetzner.com/general/infrastructure-and-availability/price-adjustment/)
- [Hetzner backup billing](https://docs.hetzner.com/cloud/billing/faq/)
- [Hetzner traffic allowance](https://docs.hetzner.com/robot/general/traffic/)
- [Hetzner primary IP pricing](https://docs.hetzner.com/cloud/servers/overview/)

## Prepare The Host

Install a supported Linux distribution, Docker Engine, and the Docker Compose
plugin. Restrict SSH to keys, enable automatic security updates, and allow
inbound TCP 80/443, UDP 443, and the administrative SSH source only. Database,
Redis, and Solr ports are private inside the Compose network.

Point the production domain's A/AAAA records to the server before starting the
proxy. Caddy obtains and renews the TLS certificate automatically.

## Configure

```bash
cp .env.hetzner.example .env.hetzner
chmod 600 .env.hetzner
openssl rand -hex 48
openssl rand -base64 36
```

Replace every `change-me` value. The encryption key must be the stable
96-character hex value. Use independently generated database, Redis, admin,
and cron secrets.

For the first start against an empty database only:

```dotenv
TYPO3_AUTO_SETUP=1
TYPO3_BOOTSTRAP_EMPTY_DATABASE=1
```

Start the stack:

```bash
docker compose \
  --env-file .env.hetzner \
  -f compose.hetzner.yaml \
  up --build -d
```

Wait until every service is healthy, log in to TYPO3, then set both setup flags
back to `0` and recreate the app and scheduler:

```bash
docker compose \
  --env-file .env.hetzner \
  -f compose.hetzner.yaml \
  up -d --force-recreate app scheduler
```

## Verify

```bash
docker compose --env-file .env.hetzner -f compose.hetzner.yaml ps
curl --fail --show-error --silent https://www.example.com/api/health.php
curl --fail --show-error --silent https://www.example.com/ >/dev/null
docker compose --env-file .env.hetzner -f compose.hetzner.yaml \
  exec app curl --fail --silent \
  http://solr:8983/solr/core_en/admin/ping
docker compose --env-file .env.hetzner -f compose.hetzner.yaml \
  logs --tail=100 scheduler
```

The repository acceptance test also restarts MariaDB and Solr and verifies that
the page rows and a committed Solr document survive.

## Operations

- Monitor the public URL from outside Hetzner at least once per minute.
- Alert on container restarts, disk use, backup failures, and Solr heap.
- Test restore procedures, not only backup creation.
- Keep SMTP external; no mail transfer agent runs in this stack.
- Build a tagged application image in CI and deploy that immutable tag for
  production changes.
- Schedule a maintenance window for Docker, MariaDB, Solr, and TYPO3 updates.

The scheduler container runs TYPO3 Scheduler once per minute. Solr's Index
Queue Worker remains bounded by the task configuration in TYPO3.

## Managed Alternative

If server administration and recovery should belong to the provider,
[jweiland Cloud PREMIUM](https://jweiland.net/typo3-hosting.html) is the
stronger operational fit: €36/month including German VAT, 4 vCPU, 8 GB RAM,
100 GB SSD, MariaDB, Solr, monitoring, backups, and a published 99.9%
availability target. Their €24 BASIC plan does **not** include Solr.
