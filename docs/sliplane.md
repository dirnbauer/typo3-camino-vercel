# Sliplane Deployment

Sliplane is the fully managed European comparison. It operates the server,
container routing, TLS, health checks, metrics, and volume backups. Services
can deploy from GitHub or a registry, but Docker Compose is currently imported
through a best-effort parser rather than run as the source of truth.

## Recommended Comparison Size

Select a **Medium** app server in Germany:

- 3 vCPU
- 4 GB RAM
- 20 GB storage
- €28.80/month excluding VAT on monthly billing

The €9 Starter has only 1 GB RAM and is too small for a comfortable combined
TYPO3, PHP-FPM, MariaDB, and Scheduler installation. A Base server can be used
for a short smoke test, but its 2 GB RAM leaves little headroom.

## Create The Services

1. Sign in to Sliplane with GitHub.
2. Create a project and a Medium server in Germany. A 48-hour demo server can
   be used for the benchmark before adding a payment method.
3. Deploy the MariaDB preset as a **private** service:
   - image: `mariadb:10.11`
   - TCP port: `3306`
   - volume: `/var/lib/mysql`
   - database: `typo3`
   - user: `typo3`
   - unique user and root passwords
4. Record the private MariaDB hostname shown by Sliplane.
5. Deploy this GitHub repository as a second, **public HTTP** service.
   Sliplane automatically detects the root `Dockerfile`.
6. Set the health route to `/api/health.php`.
7. Attach an application volume at `/tmp/typo3/fileadmin`.

Add these environment variables to the application:

```dotenv
TYPO3_CONTEXT=Production/Sliplane
TYPO3_SERVERLESS_FILESYSTEM=1
TYPO3_PROJECT_NAME=TYPO3 Camino on Sliplane
TYPO3_SETUP_DISTRIBUTION=theme_camino
TYPO3_SETUP_ADMIN_USERNAME=admin
TYPO3_SETUP_ADMIN_EMAIL=admin@example.com
TYPO3_SETUP_ADMIN_PASSWORD=<long-random-password>
TYPO3_ENCRYPTION_KEY=<96-random-hex-characters>
TYPO3_TRUSTED_HOSTS_PATTERN=(?:your-service\.sliplane\.app|www\.example\.com)
TYPO3_DB_DRIVER=mysqli
TYPO3_DB_HOST=<private-MariaDB-hostname>
TYPO3_DB_PORT=3306
TYPO3_DB_DBNAME=typo3
TYPO3_DB_USERNAME=typo3
TYPO3_DB_PASSWORD=<database-password>
TYPO3_CACHE_BACKEND=file
TYPO3_SOLR_ENABLED=0
TYPO3_VERCEL_EDGE_CACHE_TTL=0
TYPO3_AUTO_SETUP=1
TYPO3_BOOTSTRAP_EMPTY_DATABASE=1
```

Generate the encryption key with `openssl rand -hex 48`. Deploy, verify the
frontend and backend, then change both setup flags to `0` and redeploy.

## Scheduler

Deploy a third, private service from the same GitHub repository:

- override command: `docker/run-scheduler-loop.sh`
- use the same database and TYPO3 environment variables
- set `TYPO3_AUTO_SETUP=0`
- set `TYPO3_BOOTSTRAP_EMPTY_DATABASE=0`
- set `TYPO3_SCHEDULER_LOOP_INTERVAL=300`
- attach the same `fileadmin` volume at `/tmp/typo3/fileadmin`

Do not expose the Scheduler service publicly.

## Media Storage Alternative

Sliplane volumes are the simplest single-server option and receive automatic
daily backups. Sliplane also offers S3-compatible object storage. The first
1 GB is currently free; additional storage is sold in 250 GB blocks. Use the
S3 variables from the [object-storage guide](object-storage.md) if media must
survive independently of the app server or be shared by multiple instances.

## Verify

```bash
curl --fail --show-error --silent \
  https://your-service.sliplane.app/api/health.php
curl --fail --show-error --silent \
  https://your-service.sliplane.app/ >/dev/null
```

Verify daily backups for both volumes. Sliplane retains default volume backups
for seven days; test a restore rather than assuming snapshots are sufficient.

Sources: [Sliplane service deployment](https://docs.sliplane.io/services/deploying-a-service/),
[volumes](https://docs.sliplane.io/servers/volumes/), and
[pricing](https://sliplane.io/pricing).
