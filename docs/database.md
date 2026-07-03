# Database Setup

## Demo Database

The default Vercel smoke deployment uses a seeded SQLite file copied to `/tmp`.
That is enough to test the container and Camino frontend, but it is not durable.
It is the intended free first deploy path.

Use a real external database for any real trial.

## Recommended Test Options

### Postgres

Neon or Supabase are the simplest Vercel Marketplace paths because they provide
a standard `DATABASE_URL` and integrate with Vercel projects.

Example:

```dotenv
DATABASE_URL=postgres://user:password@host:5432/dbname?sslmode=require
TYPO3_AUTO_SETUP=1
```

### MySQL-Compatible

TiDB Cloud is MySQL-compatible and has a Vercel Marketplace integration. It is
the most interesting free MySQL-compatible path for testing.

Example shape:

```dotenv
DATABASE_URL=mysql://user:password@host:4000/dbname
TYPO3_DB_DRIVER=mysqli
TYPO3_AUTO_SETUP=1
```

Check the provider's TLS requirements. The current bootstrap uses TYPO3's setup
CLI, which supports standard host/user/password setup but not every
provider-specific TLS flag. If the provider requires a CA file or strict TLS
verification at setup time, test the first deploy carefully.

### MySQL

For classic MySQL, add a provider connection URL manually in Vercel Project
Settings. Confirm the provider's current free tier, TLS requirements, backups,
and connection limits before calling it a zero-cost option.

Example shape:

```dotenv
DATABASE_URL=mysql://user:password@host:3306/dbname
TYPO3_DB_DRIVER=mysqli
TYPO3_AUTO_SETUP=1
```

## First-Boot Flow

1. Add `DATABASE_URL`.
2. Add `TYPO3_AUTO_SETUP=1`.
3. Deploy.
4. Wait for TYPO3 setup to create tables and import Camino.
5. Log in at `/typo3`.
6. Change `TYPO3_AUTO_SETUP=0`.
7. Redeploy.

The bootstrap checks for TYPO3's `be_users` table and skips setup when it
already exists, but disabling auto setup after first boot avoids unnecessary
startup work.

## Backups

Vercel does not back up your external database. Use the database provider's
backup/restore feature and test restore before production.

## Uploaded Files

TYPO3 stores editor uploads under `public/fileadmin` by default. On Vercel this
is not durable runtime storage. This starter copies `fileadmin` into `/tmp` at
container start so Camino demo assets are available, but new uploads remain
ephemeral.

Before production, add a TYPO3 FAL driver backed by S3-compatible object
storage, or build a Vercel Blob FAL driver. See
[serverless runtime notes](serverless-runtime.md).

## Sources

- Vercel Storage overview: https://vercel.com/docs/storage
- Vercel Marketplace database category: https://vercel.com/marketplace/category/database
- Neon for Vercel: https://vercel.com/marketplace/neon
- TiDB Cloud for Vercel: https://vercel.com/marketplace/tidb-cloud
- PlanetScale for Vercel: https://vercel.com/marketplace/planetscale
