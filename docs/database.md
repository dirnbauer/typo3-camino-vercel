# Database Setup

## Demo Database

The default Vercel smoke deployment uses a seeded SQLite file copied to `/tmp`.
That is enough to test the container and Camino frontend, but it is not durable.
It is the intended free first deploy path.

Use a real external database for any backend trial. TYPO3 backend sessions are
stored in the database, so a real database is required for stable backend login
on Vercel.

If `/typo3/main` loads and then logs out after a few seconds, the project is
still using the SQLite smoke database. Add `DATABASE_URL` before debugging
passwords or browser cookies.

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
7. Optionally set `TYPO3_BOOTSTRAP_EMPTY_DATABASE=0`.
8. Redeploy.

The bootstrap checks for TYPO3's `be_users` table and skips setup when it
already exists, but disabling auto setup after first boot avoids unnecessary
startup work. `TYPO3_BOOTSTRAP_EMPTY_DATABASE` defaults to `1` and allows a
fresh durable database plus `TYPO3_SETUP_ADMIN_PASSWORD` to initialize even when
the provider stores `TYPO3_AUTO_SETUP` as a protected value.

## Backups

Vercel does not back up your external database. Use the database provider's
backup/restore feature and test restore before production.

## Uploaded Files

TYPO3 stores editor uploads under `public/fileadmin` by default. On Vercel this
is not durable runtime storage. This starter copies `fileadmin` into `/tmp` at
container start so Camino demo assets are available, but new uploads remain
ephemeral.

Before production, enable the included S3-compatible TYPO3 FAL driver with
`TYPO3_OBJECT_STORAGE_ENABLED=1` and the `TYPO3_S3_*` variables. See
[object storage and durable uploads](object-storage.md).

## Sources

- Vercel Storage overview: https://vercel.com/docs/storage
- Vercel Marketplace database category: https://vercel.com/marketplace/category/database
- Neon for Vercel: https://vercel.com/marketplace/neon
- TiDB Cloud for Vercel: https://vercel.com/marketplace/tidb-cloud
- PlanetScale for Vercel: https://vercel.com/marketplace/planetscale
- Vercel SQLite note: https://vercel.com/kb/guide/is-sqlite-supported-in-vercel
