# TYPO3 Camino on Vercel

[![Deploy with Vercel](https://vercel.com/button)](https://vercel.com/new/clone?repository-url=https%3A%2F%2Fgithub.com%2Fdirnbauer%2Ftypo3-camino-vercel&project-name=typo3-camino-vercel&repository-name=typo3-camino-vercel&demo-title=TYPO3+Camino+on+Vercel&demo-description=Community+Vercel+container+starter+for+TYPO3+14.3+using+the+TYPO3+Camino+distribution.+Not+an+official+TYPO3+package.&demo-url=https%3A%2F%2Ftypo3-camino-vercel.vercel.app&demo-image=https%3A%2F%2Ftypo3-camino-vercel.vercel.app%2Ftemplate-preview.png&from=templates&teamSlug=webconsulting&env=TYPO3_SETUP_ADMIN_USERNAME,TYPO3_SETUP_ADMIN_PASSWORD,TYPO3_ENCRYPTION_KEY&envDescription=Set+a+backend+admin+username%2C+a+long+random+backend+password%2C+and+a+stable+96-character+hex+TYPO3+encryption+key.+Do+not+put+secrets+in+the+URL.&envLink=https%3A%2F%2Fgithub.com%2Fdirnbauer%2Ftypo3-camino-vercel%2Fblob%2Fmain%2Fdocs%2Fquickstart.md)

This is not an official TYPO3 package. It is a community Vercel container
starter for TYPO3 14.3 that uses the TYPO3 Camino distribution, packaged as a
PHP 8.4 Apache container for Vercel Container Images.

This is a lab/template starter, not a production recommendation for every
TYPO3 project. It is useful for testing Vercel's container support with TYPO3
and for learning what works well on a stateless platform.

## What Works

- One-click Vercel smoke deploy with a pre-seeded Camino SQLite demo database.
- Backend login for the seeded demo when `TYPO3_SETUP_ADMIN_PASSWORD` is set.
- TYPO3 14.3 Composer install with Camino and Scheduler included.
- Durable external SQL database support through `DATABASE_URL` or TYPO3 DB env vars.
- Vercel Cron compatible endpoint for running TYPO3 Scheduler tasks.
- Vercel Firewall/WAF in front of the container.

## What Does Not Work

- No Linux daemon cron inside the container. Use Vercel Cron or an external cron service.
- No durable local filesystem. Runtime writes in `/tmp`, `var/`, or `fileadmin/` can disappear.
- SQLite is demo-only on Vercel. Use a real database for anything you want to keep.
- Editor uploads need external object storage before production use.
- This starter is not a GDPR/legal compliance guarantee.

## Quick Demo

1. Click **Deploy with Vercel**.
2. Enter these required values in the Vercel form:

```dotenv
TYPO3_SETUP_ADMIN_USERNAME=admin
TYPO3_SETUP_ADMIN_PASSWORD=<long-random-password>
TYPO3_ENCRYPTION_KEY=<96-random-hex-chars>
```

Generate the encryption key locally:

```bash
openssl rand -hex 48
```

3. Deploy and open `/typo3`.

The first deploy uses the seeded SQLite demo database unless you add a real
database. For a real database, read [docs/database.md](docs/database.md) before
deploying.

## Production Shape

For anything beyond a short test, use:

```dotenv
TYPO3_CONTEXT=Production/Vercel
TYPO3_AUTO_SETUP=1
TYPO3_SETUP_DISTRIBUTION=theme_camino
TYPO3_SETUP_ADMIN_USERNAME=admin
TYPO3_SETUP_ADMIN_PASSWORD=<long-random-password>
TYPO3_SETUP_ADMIN_EMAIL=admin@example.com
TYPO3_PROJECT_NAME=TYPO3 Camino
TYPO3_ENCRYPTION_KEY=<96-random-hex-chars>
TYPO3_TRUSTED_HOSTS_PATTERN=(.+\.)?vercel\.app
DATABASE_URL=<durable-postgres-or-mysql-url>
```

After the first successful setup, set `TYPO3_AUTO_SETUP=0`.

## Costs For Testing

Vercel Hobby is free for personal/non-commercial testing within the plan limits.
The seeded SQLite demo can run without a paid database, but it is non-durable.

For a free durable database test:

- **Postgres:** Neon or Supabase are the easiest Vercel Marketplace options.
- **MySQL-compatible:** TiDB Cloud has a Vercel integration and free starter quota.
- **MySQL:** Aiven offers a free MySQL plan outside Vercel Marketplace.
- **PlanetScale:** MySQL-compatible and integrated with Vercel, but no free plan.

See [docs/costs.md](docs/costs.md) for the current caveats.

## Documentation

- [Quickstart](docs/quickstart.md)
- [Database setup](docs/database.md)
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
