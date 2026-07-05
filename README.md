# TYPO3 Camino on Vercel

[![Deploy with Vercel](https://vercel.com/button)](https://vercel.com/new/clone?repository-url=https%3A%2F%2Fgithub.com%2Fdirnbauer%2Ftypo3-camino-vercel&project-name=typo3-camino-vercel&repository-name=typo3-camino-vercel&demo-title=TYPO3+Camino+on+Vercel&demo-description=Community+Vercel+container+starter+for+TYPO3+14.3+using+the+TYPO3+Camino+distribution.+Not+an+official+TYPO3+package.&demo-url=https%3A%2F%2Ftypo3-camino-vercel.vercel.app&demo-image=https%3A%2F%2Ftypo3-camino-vercel.vercel.app%2Ftemplate-preview.png&from=templates&env=TYPO3_SETUP_ADMIN_USERNAME,TYPO3_SETUP_ADMIN_PASSWORD,TYPO3_ENCRYPTION_KEY&envDescription=Set+a+backend+admin+username%2C+a+long+random+backend+password%2C+and+a+stable+96-character+hex+TYPO3+encryption+key.+Do+not+put+secrets+in+the+URL.&envLink=https%3A%2F%2Fgithub.com%2Fdirnbauer%2Ftypo3-camino-vercel%2Fblob%2Fmain%2Fdocs%2Fquickstart.md)

This is not an official TYPO3 package. It is a community Vercel container
starter for TYPO3 14.3 that uses the TYPO3 Camino distribution, packaged as a
PHP 8.4 Apache container for Vercel Container Images.

This is a lab/template starter, not a production recommendation for every
TYPO3 project. It is useful for testing Vercel's container support with TYPO3
and for learning what works well on a stateless platform.

## Important: Free Demo Data And Login Are Temporary

The one-click free demo is usable as a frontend/container smoke test, but it is
not durable and the TYPO3 backend login is not stable:

- You can upload files in TYPO3.
- You can edit pages and records.
- Those uploaded files and content changes can disappear.
- The backend can log you out after a few seconds.

Why: the free demo uses SQLite and runtime `fileadmin` storage inside the
Vercel container. TYPO3 stores backend sessions in the database table
`be_sessions`. Vercel can start more than one runtime instance, and those
instances do not share the SQLite file in `/tmp`.

For non-temporary files and content, add both:

- a durable database through `DATABASE_URL`
- external object storage through Vercel Blob or the S3-compatible TYPO3 FAL driver

For a stable backend login, the durable database is required. For durable
editor uploads, object storage is also required. Until both are configured, use
the free deploy only for checking that the container, TYPO3, and Camino boot.

**Loud and clear:** uploads are durable only after
`TYPO3_OBJECT_STORAGE_ENABLED=1` and an object-storage driver is configured.
For an all-Vercel setup, use `TYPO3_OBJECT_STORAGE_DRIVER=vercel_blob` with a
connected Vercel Blob store. For S3-compatible storage, use
`TYPO3_OBJECT_STORAGE_DRIVER=vercel_s3` and the `TYPO3_S3_*` bucket variables.
Without one of those setups, uploaded files still live in Vercel runtime storage
and can disappear. When object storage is enabled, the container verifies the
storage on boot and creates the TYPO3 upload, processing, and temp folders
there. If credentials are wrong, startup fails loudly instead of pretending
uploads are safe.

## Durable Free Demo: Still Free, But Not One-Click Yet

Yes, a truly durable demo can still be free, but only if every part stays inside
its provider's free quota.

Best practical zero-cost shape:

- Vercel Hobby for the container, personal/non-commercial use only.
- Free database: TiDB Cloud MySQL-compatible, Neon Postgres, or Supabase Postgres.
- Free object storage: Vercel Blob on Hobby within limits, or Cloudflare R2.
- TYPO3 storage integration: `vercel_blob` for Vercel Blob, `vercel_s3` for R2/S3.

What this means today:

- The current one-click demo is free, but uploaded files are temporary.
- One-click free demo with durable uploaded files still needs setup steps.
- A durable free demo needs setup steps for the database and object storage.
- Vercel Blob can now be wired through the included Blob FAL driver.
- Cloudflare R2 can still be wired through the included S3-compatible FAL driver.
- It stays free only while usage remains inside all free-tier limits.

## What Works

- Free one-click Vercel smoke deploy with a pre-seeded Camino SQLite demo
  database and no external storage requirement.
- Stable backend login when a durable SQL database is configured.
- TYPO3 14.3 Composer install with Camino and the current TYPO3 CMS system
  package set included.
- Serverless-style runtime paths: TYPO3 writes to `/tmp`, not durable image paths.
- Durable external SQL database support through `DATABASE_URL` or TYPO3 DB env vars.
- Durable editor uploads through Vercel Blob or the S3-compatible TYPO3 FAL driver.
- Vercel Cron compatible endpoint for running TYPO3 Scheduler tasks.
- Vercel Firewall/WAF in front of the container.
- Vercel region pinning and runtime-local TYPO3 caches for faster warm requests.
- Optional Vercel CDN caching for anonymous public frontend HTML.

## What Does Not Work

- No Linux daemon cron inside the container. Use Vercel Cron or an external cron service.
- No durable local filesystem. Runtime writes in `/tmp`, `var/`, or `fileadmin/` can disappear.
- SQLite is demo-only on Vercel. It is not reliable for TYPO3 backend sessions.
- Editor uploads are not durable unless Vercel Blob or S3-compatible object storage is configured.
- This starter is not a GDPR/legal compliance guarantee.

## Quick Demo

1. Click **Deploy with Vercel** and choose your personal Hobby account for the
   free demo.
2. Enter these required values in the Vercel form:

```dotenv
TYPO3_SETUP_ADMIN_USERNAME=admin
TYPO3_SETUP_ADMIN_PASSWORD=<strong-random-password>
TYPO3_ENCRYPTION_KEY=<96-random-hex-chars>
```

Generate the encryption key locally:

```bash
openssl rand -hex 48
```

3. Deploy and open the frontend.

The first deploy uses the seeded SQLite demo database unless you add a real
database. For a real database, read [docs/database.md](docs/database.md) before
deploying.

For the free demo, do not add `DATABASE_URL` and do not create an object
storage bucket.
The demo will reset when Vercel replaces the runtime container, so use it only
for testing the package and Camino frontend. Backend login needs a durable
database.

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

For durable uploads with Vercel Blob, create/connect a public Blob store and add:

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

For shared TYPO3 caches, Redis is supported when you provide a real Redis TCP
or TLS URL close to the Vercel region:

```dotenv
TYPO3_CACHE_BACKEND=redis
TYPO3_REDIS_URL=rediss://default:<password>@<host>:6379/0
```

For small demos, `TYPO3_CACHE_BACKEND=file` plus Vercel edge caching is usually
faster than a remote Redis hop.

## Costs For Testing

Vercel Hobby is free for personal/non-commercial testing within the plan limits.
The seeded SQLite demo can run without a paid database or object storage, but
it is non-durable.

For a free or low-cost durable database test:

- **Postgres:** Neon or Supabase are the easiest Vercel Marketplace options.
- **MySQL-compatible:** TiDB Cloud has a Vercel integration and free starter quota.
- **PlanetScale:** MySQL-compatible and integrated with Vercel, but no free plan.

See [docs/costs.md](docs/costs.md) for the current caveats.

## Documentation

- [Quickstart](docs/quickstart.md)
- [Free demo mode](docs/free-demo.md)
- [Database setup](docs/database.md)
- [Object storage and durable uploads](docs/object-storage.md)
- [Included TYPO3 packages](docs/typo3-packages.md)
- [Backend login and sessions](docs/backend-login.md)
- [Performance notes](docs/performance.md)
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
