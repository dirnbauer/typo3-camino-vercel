# TYPO3 Camino on Vercel

[![Deploy with Vercel](https://vercel.com/button)](https://vercel.com/new/clone?repository-url=https%3A%2F%2Fgithub.com%2Fdirnbauer%2Ftypo3-camino-vercel&project-name=typo3-camino-vercel&repository-name=typo3-camino-vercel&demo-title=TYPO3+Camino+on+Vercel&demo-description=Community+Vercel+container+starter+for+TYPO3+14.3+using+the+TYPO3+Camino+distribution.+Not+an+official+TYPO3+package.&demo-url=https%3A%2F%2Ftypo3-camino-vercel.vercel.app&demo-image=https%3A%2F%2Ftypo3-camino-vercel.vercel.app%2Ftemplate-preview.png&from=templates&env=TYPO3_SETUP_ADMIN_USERNAME%2CTYPO3_SETUP_ADMIN_PASSWORD%2CTYPO3_ENCRYPTION_KEY&envDefaults=%7B%22TYPO3_SETUP_ADMIN_USERNAME%22%3A%22admin%22%7D&envDescription=Choose+a+backend+username%2C+set+a+strong+random+backend+password%2C+and+paste+a+stable+96-character+hex+TYPO3+encryption+key.+The+Deploy+Button+creates+a+public+Vercel+Blob+store+for+durable+uploaded+files.+Add+a+real+database+later+for+stable+backend+login+and+durable+content.&envLink=https%3A%2F%2Fgithub.com%2Fdirnbauer%2Ftypo3-camino-vercel%2Fblob%2Fmain%2Fdocs%2Fquickstart.md&stores=%5B%7B%22type%22%3A%22blob%22%2C%22access%22%3A%22public%22%7D%5D)

This is not an official TYPO3 package. It is a community Vercel container
starter for TYPO3 14.3 that uses the TYPO3 Camino distribution, packaged as a
PHP 8.4 Apache container for Vercel Container Images.

This is a lab/template starter, not a production recommendation for every
TYPO3 project. It is useful for testing Vercel's container support with TYPO3
and for learning what works well on a stateless platform.

## Install TYPO3 On Vercel

This is the shortest safe path for non-technical testing.

1. Click **Deploy with Vercel**.
2. Sign in to Vercel or create a free Hobby account.
3. Choose your personal account or team.
4. Keep the Vercel Blob store enabled when Vercel asks for storage. The button
   creates a public Blob store for uploaded TYPO3 images and files.
5. Enter the TYPO3 setup values:

```dotenv
TYPO3_SETUP_ADMIN_USERNAME=admin
TYPO3_SETUP_ADMIN_PASSWORD=<your-own-strong-password>
TYPO3_ENCRYPTION_KEY=<96-random-hex-characters>
```

There are no Blob fields to fill in. Vercel creates the Blob token for the
project, and this starter automatically uses the `vercel_blob` FAL driver when
that token exists.

6. Click **Deploy** and wait until Vercel shows the project URL.
7. Open the site. The TYPO3 backend is available at `/typo3/`.

To create the encryption key on macOS, open **Terminal**, paste this command,
and copy the output into `TYPO3_ENCRYPTION_KEY`:

```bash
openssl rand -hex 48
```

Keep the encryption key and backend password in your own password manager.
Never put the password, encryption key, database URL, or Blob token into a
public GitHub file.

## What You Get After One Click

A fresh free clone gives you:

- a working TYPO3 14.3 Camino frontend
- a TYPO3 backend login for short tests
- durable uploaded files when the Vercel Blob store is accepted
- a pre-seeded SQLite demo database so the site starts without database setup

The important limitation is the database. Without a real database, TYPO3
content changes and backend sessions are still temporary. The backend can log
you out after a few seconds because the free demo database lives in Vercel
runtime storage, not in a shared durable database.

For a real test site, add:

- a durable database through `DATABASE_URL`
- Vercel Blob storage from the Deploy Button, or S3-compatible object storage
- optional external Apache Solr for TYPO3 search

For a stable backend login, the durable database is required. For durable
editor uploads, Blob or S3-compatible object storage is required. If you accept
the Blob store during the Deploy Button flow, the file part is already wired.

## Important: Database Data And Login Are Temporary Until You Add A DB

Say this loudly: the one-click deploy can make files durable through Vercel
Blob, but it does not make TYPO3's database durable.

- You can upload files in TYPO3, and they are durable if Blob is enabled.
- You can edit pages and records.
- Those page/content/database changes can disappear without a real database.
- The backend can log you out after a few seconds without a real database.

Why: the free clone uses SQLite inside the Vercel runtime. TYPO3 stores backend
sessions in the database table `be_sessions`. Vercel can start more than one
runtime instance, and those instances do not share the SQLite file in `/tmp`.

For all-Vercel file storage, this starter uses
the `vercel_blob` FAL driver automatically when a Vercel Blob store is
connected. For S3-compatible storage, use `TYPO3_OBJECT_STORAGE_DRIVER=vercel_s3`
and the `TYPO3_S3_*` bucket variables. When object storage is enabled, the
container verifies storage on boot and creates the TYPO3 upload, processing,
and temp folders there. If credentials are wrong, startup fails loudly instead
of pretending uploads are safe. To disable automatic Blob storage in a test
project, set `TYPO3_OBJECT_STORAGE_ENABLED=0`.

## Current Public Demo State

The public demo at https://typo3-camino-vercel.vercel.app is no longer the
bare one-click state. It is configured with a durable database, Vercel Blob
object storage, and Redis Cloud cache through the Vercel Marketplace. New
clones do not inherit those resources; they still need the database and Redis
setup described below. File storage is easier: the Deploy Button can create a
new Vercel Blob store for the clone.

Measured on 2026-07-07 against the live Vercel deployment after enabling Redis,
all tested routes returned `200`:

- frontend `/`: first hit after deploy 12.57s, warm median 0.046s
- backend login `/typo3/`: warm median 0.125s, range 0.110-0.168s
- backend login preflight Ajax: warm median 0.100s, range 0.083-0.157s
- later backend cold check: `/typo3/` once at 10.15s, then 0.21-0.24s

Before Redis, the latest warm backend sample was about 0.23-0.41s for
`/typo3/` and 0.16-0.25s for login preflight. Redis helped the measured warm
backend path, but it did not remove Vercel container cold starts. The public
demo uses Vercel Pro/performance CPU in `fra1` Frankfurt, which helps warm PHP
work but does not make every first request instant.

For a product-manager level summary of what worked, what was coded, what got
faster, and what Vercel could improve, see
[docs/vercel-product-manager-summary.md](docs/vercel-product-manager-summary.md).

## Durable Free Demo: Still Free, But The Database Needs Setup

Yes, a truly durable demo can still be free, but only if every part stays inside
its provider's free quota.

Best practical zero-cost shape:

- Vercel Hobby for the container, personal/non-commercial use only.
- Free database: TiDB Cloud MySQL-compatible, Neon Postgres, or Supabase Postgres.
- Free object storage: Vercel Blob on Hobby within limits, or Cloudflare R2.
- TYPO3 storage integration: `vercel_blob` for Vercel Blob, `vercel_s3` for R2/S3.

What this means today:

- A fresh one-click clone is free and can have durable uploaded files through
  the Blob store created by the Deploy Button.
- A fully durable TYPO3 demo still needs a real database, so content and backend
  sessions survive runtime restarts.
- Vercel Blob is wired through the included Blob FAL driver.
- Cloudflare R2 can still be wired through the included S3-compatible FAL driver.
- It stays free only while usage remains inside all free-tier limits.

## What Works

- Free one-click Vercel smoke deploy with a pre-seeded Camino SQLite demo
  database.
- Deploy Button-created Vercel Blob store for durable editor uploads.
- Stable backend login when a durable SQL database is configured.
- TYPO3 14.3 Composer install with Camino and the current TYPO3 CMS system
  package set included.
- Serverless-style runtime paths: TYPO3 writes to `/tmp`, not durable image paths.
- Durable external SQL database support through `DATABASE_URL` or TYPO3 DB env vars.
- Durable editor uploads through Vercel Blob or the S3-compatible TYPO3 FAL driver.
- Vercel Cron compatible endpoint for running TYPO3 Scheduler tasks.
- Vercel Firewall/WAF in front of the container.
- Vercel region pinning and runtime-local TYPO3 caches for faster warm requests.
- Optional Redis cache through Vercel Marketplace Redis/Redis Cloud for shared
  TYPO3 `hash`, `pages`, and `rootline` caches.
- Optional EXT:solr 14.0 beta integration for an external Apache Solr 10
  endpoint. Vercel does not provide managed Apache Solr; use managed Solr for
  production, or DDEV Solr locally.
- Optional Vercel CDN caching for anonymous public frontend HTML.
- Vercel memory/CPU can be raised on Pro/Enterprise in the dashboard or project
  API. The public demo project uses the performance CPU class and `fra1`.
  Hobby/free test deployments use Vercel's fixed size.

## What Does Not Work

- No Linux daemon cron inside the container. Use Vercel Cron or an external cron service.
- No durable local filesystem. Runtime writes in `/tmp`, `var/`, or `fileadmin/` can disappear.
- SQLite is demo-only on Vercel. It is not reliable for TYPO3 backend sessions.
- Editor uploads are not durable if the Blob store is skipped and no
  S3-compatible object storage is configured.
- Vercel's deployment File API is not a replacement for Vercel Blob. It uploads
  deployment/build files, not runtime TYPO3 editor uploads.
- A Solr Docker container on Vercel is not production storage. Solr needs
  durable index state, so production search should use external managed Solr.
- This starter is not a GDPR/legal compliance guarantee.

## Quick Demo

1. Click **Deploy with Vercel** and choose your personal Hobby account for the
   free demo.
2. Keep the public Vercel Blob store enabled if you want uploaded files to
   survive redeploys and runtime restarts.
3. Enter these required values in the Vercel form:

```dotenv
TYPO3_SETUP_ADMIN_USERNAME=admin
TYPO3_SETUP_ADMIN_PASSWORD=<strong-random-password>
TYPO3_ENCRYPTION_KEY=<96-random-hex-chars>
```

Generate the encryption key locally:

```bash
openssl rand -hex 48
```

4. Deploy and open the frontend.

The first deploy uses the seeded SQLite demo database unless you add a real
database. For a real database, read [docs/database.md](docs/database.md) before
deploying.

For a fresh free clone, you can leave `DATABASE_URL` empty. The demo database
can reset when Vercel replaces the runtime container, so use that mode only for
testing the package and Camino frontend. Backend login needs a durable database
before you rely on it.

## Deployment Timing And Deploy Now

A Git push is not immediately online: Vercel must finish a production
deployment and show `Ready` before the live `.vercel.app` alias changes.

To deploy now from the Vercel dashboard, open the project, go to
**Deployments**, open the newest deployment, and click **Redeploy**.

To deploy now from this repository with the Vercel CLI:

```bash
vercel deploy --prod --scope webconsulting --regions fra1 --yes
```

For a fork under your own Vercel account, omit `--scope webconsulting` or
replace it with your own team scope.

## Local Development With DDEV

This repo includes a DDEV setup for local TYPO3 work:

```bash
ddev start
ddev composer install
```

DDEV is configured for PHP 8.4, matching the Vercel container. It also includes
a local Solr service pinned to the TYPO3 14 compatible EXT:solr 14 configset.
See [docs/solr.md](docs/solr.md) for the local Solr commands.

## Production Shape

For anything beyond a short test, use:

```dotenv
TYPO3_CONTEXT=Production/Vercel
TYPO3_AUTO_SETUP=1
TYPO3_BOOTSTRAP_EMPTY_DATABASE=1
TYPO3_SETUP_DISTRIBUTION=theme_camino
TYPO3_SETUP_ADMIN_USERNAME=admin
TYPO3_SETUP_ADMIN_PASSWORD=<strong-random-password>
TYPO3_SETUP_ADMIN_EMAIL=admin@example.com
TYPO3_PROJECT_NAME=TYPO3 Camino
TYPO3_ENCRYPTION_KEY=<96-random-hex-chars>
TYPO3_TRUSTED_HOSTS_PATTERN=(.+\.)?vercel\.app
DATABASE_URL=<durable-postgres-or-mysql-url>
TYPO3_CACHE_BACKEND=file
TYPO3_ADMIN_PASSWORD_APPLY_ON_BOOT=0
TYPO3_EXTENSION_SETUP_ON_BOOT=0
```

For durable uploads with Vercel Blob, the Deploy Button path needs no extra
Blob env fields. Vercel creates `BLOB_READ_WRITE_TOKEN`, and the starter
auto-enables Blob storage when that token exists.

For manual or advanced Vercel Blob setup, create/connect a public Blob store
and add:

```dotenv
TYPO3_OBJECT_STORAGE_ENABLED=1
TYPO3_OBJECT_STORAGE_DRIVER=vercel_blob
TYPO3_OBJECT_STORAGE_VERIFY_ON_BOOT=1
TYPO3_BLOB_ACCESS=public
TYPO3_BLOB_PREFIX=typo3/
```

Vercel supplies `BLOB_READ_WRITE_TOKEN` for connected Blob stores. For
S3-compatible object storage instead, add:

```dotenv
TYPO3_OBJECT_STORAGE_ENABLED=1
TYPO3_OBJECT_STORAGE_DRIVER=vercel_s3
TYPO3_S3_BUCKET=<bucket>
TYPO3_S3_REGION=auto
TYPO3_S3_ENDPOINT=<s3-compatible-endpoint>
TYPO3_S3_ACCESS_KEY_ID=<access-key>
TYPO3_S3_SECRET_ACCESS_KEY=<secret-key>
TYPO3_S3_PUBLIC_BASE_URL=<public-bucket-or-cdn-url>
TYPO3_OBJECT_STORAGE_VERIFY_ON_BOOT=1
```

The admin password must satisfy TYPO3's password policy: use uppercase,
lowercase, numbers, and a symbol.

After the first successful setup, set `TYPO3_AUTO_SETUP=0`. For stricter and
faster production startup, also set `TYPO3_BOOTSTRAP_EMPTY_DATABASE=0`,
`TYPO3_EXTENSION_SETUP_ON_BOOT=0`, and `TYPO3_ADMIN_PASSWORD_APPLY_ON_BOOT=0`
after the database has been initialized.

For faster anonymous frontend pages on Vercel, you may enable the opt-in CDN
HTML cache:

```dotenv
TYPO3_VERCEL_EDGE_CACHE_TTL=600
TYPO3_VERCEL_EDGE_CACHE_STALE_WHILE_REVALIDATE=3600
```

This only targets anonymous `GET`/`HEAD` HTML requests without cookies, query
strings, `Set-Cookie`, `/typo3`, or `/api`. Keep it disabled while testing
forms, frontend user login, personalization, or uncached plugins.

For shared TYPO3 caches, Redis is supported. The easiest Vercel path is the
official Redis Marketplace integration, which injects `REDIS_URL`. Then set:

```dotenv
TYPO3_CACHE_BACKEND=redis
TYPO3_REDIS_REQUIRED=1
TYPO3_REDIS_PREFIX=typo3-camino-vercel:
```

Use only real `redis://` or `rediss://` TCP/TLS URLs close to the Vercel
region. REST-only Redis variables are not enough for TYPO3's native Redis cache
backend. For small one-container demos, `TYPO3_CACHE_BACKEND=file` can still be
enough; Redis is mainly useful when shared cache state matters.

## Costs For Testing

Vercel Hobby is free for personal/non-commercial testing within the plan limits.
The seeded SQLite demo can run without a paid database or object storage, but
it is non-durable.

For a free or low-cost durable database test:

- **Postgres:** Neon or Supabase are the easiest Vercel Marketplace options.
- **MySQL-compatible:** TiDB Cloud has a Vercel integration and free starter quota.
- **PlanetScale:** MySQL-compatible and integrated with Vercel, but no free plan.
- **Redis cache:** the official Redis Marketplace integration can start on a
  free Redis Cloud plan. The public demo currently uses a free 30 MB Redis
  cache. This is fine for cache testing, not a substitute for the SQL database.

See [docs/costs.md](docs/costs.md) for the current caveats.

## Documentation

- [Vercel Blob FAL driver](docs/vercel-blob-fal-driver.md)
- [Quickstart](docs/quickstart.md)
- [Free demo mode](docs/free-demo.md)
- [Database setup](docs/database.md)
- [Object storage and durable uploads](docs/object-storage.md)
- [Redis cache on Vercel](docs/redis-cache.md)
- [Solr search](docs/solr.md)
- [Included TYPO3 packages](docs/typo3-packages.md)
- [Backend login and sessions](docs/backend-login.md)
- [Performance notes](docs/performance.md)
- [Production hardening](docs/production-hardening.md)
- [Vercel product manager summary](docs/vercel-product-manager-summary.md)
- [Serverless runtime notes](docs/serverless-runtime.md)
- [Scheduler and cron](docs/scheduler.md)
- [Security and firewall](docs/security.md)
- [GDPR and privacy checklist](docs/gdpr.md)
- [Operations checklist](docs/operations-checklist.md)
- [Limitations](docs/limitations.md)
- [Vercel deployment notes](docs/vercel.md)

## Local Smoke Test

```bash
docker compose up --build
open http://localhost:8080
```

The local Compose setup uses MariaDB and initializes TYPO3 automatically.
