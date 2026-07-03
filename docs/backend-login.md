# Backend Login And Sessions

## Symptom

The TYPO3 backend accepts the password, opens `/typo3/main`, and then logs out
again after a few seconds. Backend AJAX calls start returning `401`, or module
URLs redirect back to `/typo3/login`.

## Cause On Vercel

TYPO3 backend sessions are database-backed. TYPO3 stores the session id from the
`be_typo_user` cookie in the `be_sessions` table.

The free smoke demo uses SQLite copied to `/tmp/typo3/camino.sqlite` at runtime.
That file is local to one Vercel runtime instance. Vercel container services are
stateless and can start multiple instances for parallel backend requests. A
session created in one instance is missing in another instance, so TYPO3 treats
the request as logged out.

This is not fixed by changing the backend password, browser cookies, or TYPO3's
session timeout. The default TYPO3 backend timeout is much longer than a few
seconds. The missing shared session table is the problem.

## Required Fix

Use a durable shared database before relying on backend login:

```dotenv
DATABASE_URL=<durable-postgres-or-mysql-url>
TYPO3_AUTO_SETUP=1
```

Then deploy once so TYPO3 creates the schema and imports Camino. After the first
successful setup:

```dotenv
TYPO3_AUTO_SETUP=0
```

Redeploy with auto setup disabled.

## Vercel CLI Attempt

The Vercel CLI can discover Neon and shows a free plan:

```bash
vercel integration discover neon --scope webconsulting
vercel integration add neon --plan free_v3 -m region=iad1 -m auth=false -e production --name typo3-camino-vercel-db --scope webconsulting
```

In this account the install flow stops at provider setup and opens a browser.
Complete the browser step in Vercel/Neon, then confirm that `DATABASE_URL` is
present in the production environment:

```bash
vercel env ls production --scope webconsulting
```

After `DATABASE_URL` exists, deploy:

```bash
vercel env update TYPO3_AUTO_SETUP production --scope webconsulting
vercel deploy --prod --scope webconsulting --archive=tgz
```

Set `TYPO3_AUTO_SETUP=0` after the first successful setup and deploy again.

## MySQL-Compatible Option

If MySQL compatibility is preferred, use TiDB Cloud or another MySQL-compatible
provider and expose a standard URL:

```dotenv
DATABASE_URL=mysql://user:password@host:4000/database
TYPO3_DB_DRIVER=mysqli
TYPO3_AUTO_SETUP=1
```

The current Vercel CLI marketplace discovery did not expose TiDB Cloud for this
team, so that path may need provider/dashboard setup.

## What Still Needs Object Storage

A durable database fixes login, records, pages, and backend sessions. It does
not make uploaded files durable. TYPO3 editor uploads still need the included
S3-compatible FAL driver configured with Cloudflare R2, AWS S3, MinIO, Spaces,
or another S3-compatible provider. Vercel Blob needs a separate driver.

## Sources

- Vercel Docker deployments: https://vercel.com/kb/guide/does-vercel-support-docker-deployments
- Vercel SQLite note: https://vercel.com/kb/guide/is-sqlite-supported-in-vercel
- Vercel Storage overview: https://vercel.com/docs/storage
