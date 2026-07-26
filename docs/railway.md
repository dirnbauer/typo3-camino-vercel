# Railway Deployment

This profile runs the same root `Dockerfile` as Vercel. Railway supplies the
public proxy and dynamic `PORT`; `railway.json` supplies the build, health
check, and restart policy.

## Architecture

Use three Railway services:

1. `app` — this GitHub repository and the root `railway.json`
2. `MySQL` — Railway's MySQL template on the private network
3. `scheduler` — optional cron service using `Dockerfile.cron`

For the comparison deployment, attach one application volume at
`/tmp/typo3/fileadmin`. This is simple and durable, but Railway volumes allow
only one replica and cause brief deployment downtime. A scalable production
deployment should use the included S3-compatible FAL driver instead.

Railway's database templates are convenient but officially unmanaged. Enable
backups and rehearse a restore before storing production content.

## Create The Project

1. Sign in to Railway and create an empty project in EU West.
2. Add a **MySQL** database service.
3. Add a service from the GitHub repository
   `dirnbauer/typo3-camino-vercel`.
4. Name that service `app`. Railway detects the root `Dockerfile` and
   `railway.json`.
5. Add a volume to `app` with mount path `/tmp/typo3/fileadmin`.
6. Generate a Railway domain for `app`.

Add these variables to `app`. Railway's raw editor accepts one `KEY=value`
entry per line:

```dotenv
DATABASE_URL=${{MySQL.MYSQL_URL}}
TYPO3_CONTEXT=Production/Railway
TYPO3_SERVERLESS_FILESYSTEM=1
TYPO3_PROJECT_NAME=TYPO3 Camino on Railway
TYPO3_SETUP_DISTRIBUTION=theme_camino
TYPO3_SETUP_ADMIN_USERNAME=admin
TYPO3_SETUP_ADMIN_EMAIL=admin@example.com
TYPO3_SETUP_ADMIN_PASSWORD=<long-random-password>
TYPO3_ENCRYPTION_KEY=<96-random-hex-characters>
TYPO3_TRUSTED_HOSTS_PATTERN=(?:your-app\.up\.railway\.app|www\.example\.com)
TYPO3_CACHE_BACKEND=file
TYPO3_SOLR_ENABLED=0
TYPO3_VERCEL_EDGE_CACHE_TTL=0
TYPO3_AUTO_SETUP=1
TYPO3_BOOTSTRAP_EMPTY_DATABASE=1
```

Generate the stable encryption key locally:

```bash
openssl rand -hex 48
```

Deploy the staged changes. The first start initializes only an empty database.
After `/api/health.php` and `/typo3/` work, change both setup flags to `0` and
deploy once more:

```dotenv
TYPO3_AUTO_SETUP=0
TYPO3_BOOTSTRAP_EMPTY_DATABASE=0
```

Never leave automatic setup enabled on an established database.

## Scheduler

Create another GitHub-backed service from the same repository and name it
`scheduler`.

Configure its Railway config-file path as:

```text
/platforms/railway/cron.json
```

Add only these variables:

```dotenv
TYPO3_PUBLIC_URL=https://your-app.up.railway.app
CRON_SECRET=<same-random-secret-as-app>
```

Add the same `CRON_SECRET` to `app`. The cron image calls the protected
Scheduler endpoint every 15 minutes and exits. Railway cron schedules use UTC,
have a five-minute minimum interval, and skip an invocation when the previous
one is still active.

## Object Storage Alternative

To remove the single-replica volume, delete it only after its files have been
migrated to S3-compatible storage. Configure the app with:

```dotenv
TYPO3_OBJECT_STORAGE_ENABLED=1
TYPO3_OBJECT_STORAGE_DRIVER=vercel_s3
TYPO3_S3_BUCKET=<bucket>
TYPO3_S3_REGION=<region>
TYPO3_S3_ENDPOINT=<endpoint>
TYPO3_S3_ACCESS_KEY_ID=<access-key>
TYPO3_S3_SECRET_ACCESS_KEY=<secret-key>
TYPO3_S3_PUBLIC_BASE_URL=<public-bucket-or-CDN-url>
```

Railway Buckets are S3-compatible but currently private-only. They require
signed URLs or an application proxy for public media. A public R2/S3 endpoint
is simpler for ordinary TYPO3 frontend images.

## Verify

```bash
curl --fail --show-error --silent \
  https://your-app.up.railway.app/api/health.php
curl --fail --show-error --silent \
  https://your-app.up.railway.app/ >/dev/null
```

Check the MySQL and application volume backup schedules, application restart
count, and Scheduler deployment history.

Sources: [Railway Dockerfiles](https://docs.railway.com/builds/dockerfiles),
[config as code](https://docs.railway.com/config-as-code/reference),
[volumes](https://docs.railway.com/volumes/reference),
[MySQL](https://docs.railway.com/databases/mysql), and
[cron jobs](https://docs.railway.com/cron-jobs).
