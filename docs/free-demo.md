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

For a small personal smoke test, the expected Vercel bill is `0 EUR/USD` as
long as the project stays inside Hobby limits.

The important included Vercel Hobby limits to watch are:

- 4 active CPU hours per month for functions.
- 360 GB-hours provisioned memory per month.
- 1,000,000 function invocations per month.
- 10 GB Vercel Container Registry image storage.
- 10 GB Fast Origin Transfer.

If a Hobby limit is exceeded, Vercel usually pauses the affected feature until
the usage window resets instead of charging overages. Hobby is restricted to
personal/non-commercial use.

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

If users want a more realistic free test, it can still be zero-cost, but only
while every service stays inside its provider's free quota.

Best practical stack:

- Vercel Hobby for the container, personal/non-commercial use only.
- TiDB Cloud for MySQL-compatible free database testing, or Neon/Supabase for
  Postgres.
- Vercel Blob on Hobby within limits, or Cloudflare R2 for free object storage
  testing.
- The included `vercel_blob` and `vercel_s3` TYPO3 FAL drivers.

What this means today:

- A fresh one-click clone is free and can have durable uploaded files through
  the Vercel Blob store created by the Deploy Button.
- A fully durable TYPO3 demo still needs database setup.
- Cloudflare R2 can be wired through the included FAL driver.
- Vercel Blob is wired through the included Blob FAL driver.
- It stays free only while usage remains inside all free-tier limits.

The public demo deployment at https://typo3-camino-vercel.vercel.app is a
configured example: it uses a durable database plus Vercel Blob. A clone made
from the Deploy Button does not inherit those resources, but the button can
create a new Blob store for the clone.

Recommended first-boot flow:

1. Keep Vercel Hobby, if the project is personal/non-commercial.
2. Keep the Vercel Blob store enabled in the Deploy Button flow.
3. Add a free/start database provider before first deploy.
4. Set `DATABASE_URL`.
5. Set `TYPO3_AUTO_SETUP=1` for the first deploy.
6. After setup succeeds, set `TYPO3_AUTO_SETUP=0`.

For MySQL-compatible free testing, TiDB Cloud is the most Vercel-integrated
option checked for this starter. It exposes database variables to Vercel and
advertises free starter quota. For Postgres, Neon and Supabase are usually the
smoother Vercel Marketplace path.

Durable editor uploads need object storage. The simplest path is the Vercel
Blob store created during the Deploy Button flow. This starter also includes
`vercel_s3` for Cloudflare R2 or another S3-compatible provider.

## Sources

- Vercel Hobby plan: https://vercel.com/docs/plans/hobby
- Vercel pricing: https://vercel.com/pricing
- Vercel Blob pricing: https://vercel.com/docs/vercel-blob/usage-and-pricing
- TiDB Cloud for Vercel: https://vercel.com/marketplace/tidb-cloud
