# Free Demo Mode

This starter is intentionally usable without paid infrastructure for the first
frontend/container smoke test.

## What It Uses

The free demo path uses:

- Vercel Hobby for personal, non-commercial testing.
- The container image built by this repository.
- A pre-seeded Camino SQLite database copied into `/tmp`.
- The Camino files committed in `public/fileadmin`.
- A Vercel Blob store if you keep the storage step enabled in the Deploy
  Button flow.
- No external database.
- No Solr service and no scheduled jobs.
- Automatic five-minute Vercel CDN caching for eligible anonymous demo pages.

The Deploy Button asks you to enter only the secret/sensitive setup values:

```dotenv
TYPO3_SETUP_ADMIN_USERNAME=admin
TYPO3_SETUP_ADMIN_PASSWORD=<long-random-password>
TYPO3_ENCRYPTION_KEY=<96-random-hex-chars>
```

Leave `DATABASE_URL` unset for this mode.
Choose your personal Hobby account during Vercel import. Do not choose a paid
team unless you want the project billed under that team.

The Deploy Button does not ask you for Blob settings. Vercel creates the Blob
token for the project, and this starter automatically enables the Blob FAL
driver when that token exists. Keep the Blob store unless you want the absolute
smallest smoke test and do not care about uploaded files.

## What It Costs

For a small personal smoke test, the Vercel charge can be `0 EUR/USD` while the
project is eligible for Hobby and remains inside every current allowance. Free
means quota-limited, not unlimited or permanently price-guaranteed.

Do not copy fixed Function, transfer, registry, or Blob allowances from this
document into a client quote. Vercel changes plans and regional pricing. Check
the live Hobby, Functions, Blob, and pricing pages before deployment and monitor
usage afterward. Hobby is intended for personal, non-commercial use.

## What Is Not Free-Durable

The demo database and backend sessions are not durable:

- SQLite is copied to `/tmp` at container start.
- TYPO3 backend sessions live in the database table `be_sessions`.
- Vercel may replace the runtime container at any time.
- Changes can disappear.

Uploaded files are durable only when the Vercel Blob store is created and
`TYPO3_OBJECT_STORAGE_DRIVER=vercel_blob` stays enabled. If you skip Blob, then
editor uploads under `fileadmin` are runtime files too.

This is fine for checking whether TYPO3 boots and Camino renders. It is not fine
for backend editing, client work, production, or content you need to keep. If
the backend logs out after a few seconds, add a durable database.

The edge cache keeps repeat public page views responsive, but it does not cache
the backend, query-string requests, forms, cookies, or personalized responses.
The first uncached request can still experience a Vercel container cold start.

## Free Durable Upgrade Path

A more realistic test can still be zero-cost while every service stays inside
its provider's free quota:

- Vercel Hobby for the container Service (personal, non-commercial use only).
- Neon or Supabase for Postgres, or TiDB Cloud for a MySQL-compatible free
  quota — a fully durable demo still needs `DATABASE_URL`.
- The Deploy Button-created Vercel Blob store for uploads, or Cloudflare R2
  through the included `vercel_s3` driver.

Recommended first-boot flow:

1. Keep the Blob store enabled in the Deploy Button flow.
2. Add a free database provider and set `DATABASE_URL` before first deploy.
3. Set `TYPO3_AUTO_SETUP=1` for the first deploy; after setup succeeds, set it
   back to `0`.

The public demo at https://typo3-camino-vercel.vercel.app is a configured
example with a durable database plus Vercel Blob. A clone does not inherit
those resources, but the Deploy Button can create a new Blob store for it.

## Sources

- [Vercel Hobby plan](https://vercel.com/docs/plans/hobby)
- [Vercel pricing](https://vercel.com/pricing)
- [Vercel Function limits](https://vercel.com/docs/functions/limitations)
- [Vercel Blob pricing](https://vercel.com/docs/vercel-blob/usage-and-pricing)
- [TiDB Cloud for Vercel](https://vercel.com/marketplace/tidb-cloud)
