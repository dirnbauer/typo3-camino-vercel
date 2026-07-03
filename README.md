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
- external object storage through a tested TYPO3 FAL adapter

For a stable backend login, the durable database is required. For durable
editor uploads, object storage is also required. Until both are configured, use
the free deploy only for checking that the container, TYPO3, and Camino boot.

## Durable Free Demo: Still Free, But Not One-Click Yet

Yes, a truly durable demo can still be free, but only if every part stays inside
its provider's free quota.

Best practical zero-cost shape:

- Vercel Hobby for the container, personal/non-commercial use only.
- Free database: TiDB Cloud MySQL-compatible, Neon Postgres, or Supabase Postgres.
- Free object storage: Cloudflare R2 or Vercel Blob.
- TYPO3 storage integration: a tested FAL driver/adapter for that object storage.

What this means today:

- The current one-click demo is free, but uploaded files are temporary.
- One-click free demo with durable uploaded files is not possible yet.
- A durable free demo needs setup steps for the database and object storage.
- TYPO3 needs Blob/R2 support wired through FAL before uploads can be durable.
- It stays free only while usage remains inside all free-tier limits.

## What Works

- Free one-click Vercel smoke deploy with a pre-seeded Camino SQLite demo
  database and no external storage requirement.
- Stable backend login when a durable SQL database is configured.
- TYPO3 14.3 Composer install with Camino and Scheduler included.
- Serverless-style runtime paths: TYPO3 writes to `/tmp`, not durable image paths.
- Durable external SQL database support through `DATABASE_URL` or TYPO3 DB env vars.
- Vercel Cron compatible endpoint for running TYPO3 Scheduler tasks.
- Vercel Firewall/WAF in front of the container.

## What Does Not Work

- No Linux daemon cron inside the container. Use Vercel Cron or an external cron service.
- No durable local filesystem. Runtime writes in `/tmp`, `var/`, or `fileadmin/` can disappear.
- SQLite is demo-only on Vercel. It is not reliable for TYPO3 backend sessions.
- Editor uploads need external object storage before production use.
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

For the free demo, do not add `DATABASE_URL` and do not create a Blob store.
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
```

The admin password must satisfy TYPO3's password policy: use uppercase,
lowercase, numbers, and a symbol.

After the first successful setup, set `TYPO3_AUTO_SETUP=0`. For stricter
production startup, also set `TYPO3_BOOTSTRAP_EMPTY_DATABASE=0` after the
database has been initialized.

## Costs For Testing

Vercel Hobby is free for personal/non-commercial testing within the plan limits.
The seeded SQLite demo can run without a paid database or Blob store, but it is
non-durable.

For a free or low-cost durable database test:

- **Postgres:** Neon or Supabase are the easiest Vercel Marketplace options.
- **MySQL-compatible:** TiDB Cloud has a Vercel integration and free starter quota.
- **PlanetScale:** MySQL-compatible and integrated with Vercel, but no free plan.

See [docs/costs.md](docs/costs.md) for the current caveats.

## Documentation

- [Quickstart](docs/quickstart.md)
- [Free demo mode](docs/free-demo.md)
- [Database setup](docs/database.md)
- [Backend login and sessions](docs/backend-login.md)
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
