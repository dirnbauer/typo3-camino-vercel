# Free Demo Mode

This starter is intentionally usable without paid infrastructure for the first
test deploy.

## What It Uses

The free demo path uses:

- Vercel Hobby for personal, non-commercial testing.
- The container image built by this repository.
- A pre-seeded Camino SQLite database copied into `/tmp`.
- The Camino files committed in `public/fileadmin`.
- No external database.
- No Blob store.
- No S3 bucket.

The Deploy Button only asks for:

```dotenv
TYPO3_SETUP_ADMIN_USERNAME=admin
TYPO3_SETUP_ADMIN_PASSWORD=<long-random-password>
TYPO3_ENCRYPTION_KEY=<96-random-hex-chars>
```

Leave `DATABASE_URL` unset for this mode.

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

The demo database and runtime uploads are not durable:

- SQLite is copied to `/tmp` at container start.
- Editor uploads under `fileadmin` are also runtime files.
- Vercel may replace the runtime container at any time.
- Changes can disappear.

This is fine for checking whether TYPO3 boots, Camino works, and the backend is
usable. It is not fine for client work, production, or content you need to keep.

## Free Durable Upgrade Path

If users want a more realistic free test:

1. Keep Vercel Hobby, if the project is personal/non-commercial.
2. Add a free/start database provider before first deploy.
3. Set `DATABASE_URL`.
4. Set `TYPO3_AUTO_SETUP=1` for the first deploy.
5. After setup succeeds, set `TYPO3_AUTO_SETUP=0`.

For MySQL-compatible free testing, TiDB Cloud is the most Vercel-integrated
option checked for this starter. It exposes database variables to Vercel and
advertises free starter quota. For Postgres, Neon and Supabase are usually the
smoother Vercel Marketplace path.

Durable editor uploads still need object storage. Vercel Blob has a free Hobby
allowance, but this starter does not yet include a TYPO3 14 FAL driver for
Blob. Do not promise durable uploads until that adapter is implemented and
tested.

## Sources

- Vercel Hobby plan: https://vercel.com/docs/plans/hobby
- Vercel pricing: https://vercel.com/pricing
- Vercel Blob pricing: https://vercel.com/docs/vercel-blob/usage-and-pricing
- TiDB Cloud for Vercel: https://vercel.com/marketplace/tidb-cloud
