# Free Demo Mode

This starter is intentionally usable without paid infrastructure for the first
frontend/container smoke test.

## What It Uses

The free demo path uses:

- Vercel Hobby for personal, non-commercial testing.
- The container image built by this repository.
- A pre-seeded Camino SQLite database copied into `/tmp`.
- The Camino files committed in `public/fileadmin`.
- No external database.
- No S3 bucket.

The Deploy Button only asks for:

```dotenv
TYPO3_SETUP_ADMIN_USERNAME=admin
TYPO3_SETUP_ADMIN_PASSWORD=<long-random-password>
TYPO3_ENCRYPTION_KEY=<96-random-hex-chars>
```

Leave `DATABASE_URL` unset for this mode.
Choose your personal Hobby account during Vercel import. Do not choose a paid
team unless you want the project billed under that team.

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

The demo database, backend sessions, and runtime uploads are not durable:

- SQLite is copied to `/tmp` at container start.
- TYPO3 backend sessions live in the database table `be_sessions`.
- Editor uploads under `fileadmin` are also runtime files.
- Vercel may replace the runtime container at any time.
- Changes can disappear.

This is fine for checking whether TYPO3 boots and Camino renders. It is not fine
for backend editing, client work, production, or content you need to keep. If
the backend logs out after a few seconds, add a durable database.

## Free Durable Upgrade Path

If users want a more realistic free test, it can still be zero-cost, but only
while every service stays inside its provider's free quota.

Best practical stack:

- Vercel Hobby for the container, personal/non-commercial use only.
- TiDB Cloud for MySQL-compatible free database testing, or Neon/Supabase for
  Postgres.
- Cloudflare R2 for free S3-compatible object storage testing.
- The included `vercel_s3` TYPO3 FAL driver.

What this means today:

- The current one-click demo is free, but uploaded files are temporary.
- One-click free demo with durable uploaded files is not possible yet.
- A durable free demo needs setup steps for database and object storage.
- Cloudflare R2 can be wired through the included FAL driver.
- Vercel Blob still needs a separate TYPO3 FAL driver because Blob is not S3-compatible.
- It stays free only while usage remains inside all free-tier limits.

Recommended first-boot flow:

1. Keep Vercel Hobby, if the project is personal/non-commercial.
2. Add a free/start database provider before first deploy.
3. Set `DATABASE_URL`.
4. Set `TYPO3_AUTO_SETUP=1` for the first deploy.
5. After setup succeeds, set `TYPO3_AUTO_SETUP=0`.

For MySQL-compatible free testing, TiDB Cloud is the most Vercel-integrated
option checked for this starter. It exposes database variables to Vercel and
advertises free starter quota. For Postgres, Neon and Supabase are usually the
smoother Vercel Marketplace path.

Durable editor uploads still need object storage. This starter includes a
TYPO3 14 S3-compatible FAL driver, so Cloudflare R2 or another S3-compatible
provider can be used. Vercel Blob has a free Hobby allowance, but Blob is not
S3-compatible and is not supported by the included driver.

## Sources

- Vercel Hobby plan: https://vercel.com/docs/plans/hobby
- Vercel pricing: https://vercel.com/pricing
- Vercel Blob pricing: https://vercel.com/docs/vercel-blob/usage-and-pricing
- TiDB Cloud for Vercel: https://vercel.com/marketplace/tidb-cloud
