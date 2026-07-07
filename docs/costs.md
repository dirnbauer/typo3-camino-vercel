# Costs For Testing

Pricing changes often. Check the linked provider pages before promising a cost
to a client.

## Vercel

As checked on 2026-07-03, Vercel Hobby is $0/month for personal,
non-commercial projects within plan limits. It includes enough usage for a
small TYPO3 smoke test, but it is not a production business plan.

Important Hobby constraints for this starter:

- Vercel Functions include 4 active CPU hours, 360 GB-hours provisioned memory,
  and 1,000,000 invocations per month
- Vercel Container Registry image storage includes 10 GB per month
- Fast Origin Transfer includes 10 GB per month
- Vercel Blob is free on Hobby within limits: 1 GB storage, 10,000 simple
  operations, 2,000 advanced operations, and 10 GB Blob data transfer
- cron jobs can run at most once per day on Hobby
- Hobby cron timing has hourly precision, not exact minute precision
- runtime logs are limited
- production deployment protection is not the same as Pro/Enterprise
- Vercel can pause a Hobby project until the next period if limits are exceeded

For commercial/client work, expect Vercel Pro or higher.

## Database Options

| Provider | Type | Free testing note |
| --- | --- | --- |
| Seeded SQLite | SQLite | Free, built into this image, not durable |
| Neon | Postgres | Vercel Marketplace option with free/start plans |
| Supabase | Postgres | Free project tier, outside/through marketplace depending setup |
| TiDB Cloud | MySQL-compatible | Vercel integration, free starter quota, good first MySQL-compatible test |
| PlanetScale | MySQL-compatible | Vercel integration, no free database plan |

MySQL preference:

- For a free Vercel-integrated MySQL-compatible test, try TiDB Cloud first.
- For true MySQL, confirm the provider's current free tier yourself before
  promising a zero-cost path. The Vercel Marketplace option checked here is
  MySQL-compatible, not classic MySQL.
- For commercial production, price the database separately from Vercel and
  confirm backups, region, TLS, and support level.

## Redis Cache Options

Redis is optional. TYPO3 needs it only when you want shared cache state across
Vercel runtime instances. It is not the primary content database and not file
storage.

The public demo uses the official Redis Cloud integration from the Vercel
Marketplace. The resource provisioned for this test was `Free - 30 MB` in
`fra1`, RAM-only, without high availability.

That can be free for testing while the cache data fits inside the provider's
free quota and the account stays within the provider's terms. It is not a
production sizing recommendation.

Important Redis cost notes:

- Vercel's current docs say new Redis projects should use Marketplace Redis
  integrations; Vercel KV is no longer available for new projects.
- The Vercel Marketplace Redis listing says Redis Cloud can start free and then
  scale to production plans.
- The same listing notes that paid plans are where persistence and high
  availability become relevant. For TYPO3 caches, persistence is less critical
  than for primary data because caches can be rebuilt.
- Upstash also has Vercel Marketplace options, but TYPO3's native Redis cache
  backend needs a real Redis TCP/TLS endpoint. REST-only variables are not
  enough.
- Redis does not remove the need for a durable SQL database or Blob/S3 object
  storage.

## Practical Recommendation

For the cheapest test:

1. use Vercel Hobby
2. use the seeded SQLite demo
3. do not add `DATABASE_URL`
4. keep the Deploy Button-created Blob store if you want uploaded files to
   survive runtime restarts
5. skip Blob only for the absolute smallest smoke test
6. do not use SQLite mode for backend editing or content you need to keep

For a more realistic free/low-cost test:

1. use Vercel Hobby or Pro trial
2. use TiDB/MySQL-compatible if MySQL-style SQL is preferred
3. set spend limits in the database provider
4. delete test resources when finished

For a durable free demo with persistent uploads:

1. use Vercel Hobby for personal/non-commercial testing
2. use a free database quota, for example TiDB Cloud, Neon, or Supabase
3. use free object storage quota, for example Vercel Blob or Cloudflare R2
4. optionally use a free Redis cache quota for shared TYPO3 caches
5. wire TYPO3 uploads through the included Blob or S3-compatible FAL driver
6. keep usage inside every provider's free limits

The file-storage part can be one-click now: the README Deploy Button asks
Vercel to create a public Blob store, and this starter auto-enables the Blob
FAL driver when Vercel provides the Blob token. The database part is still not
one-click. A fully durable TYPO3 demo needs a real database connection in
`DATABASE_URL`. The public demo deployment is already configured with Vercel
Blob, a durable database, and Redis cache as a working example.

## Sources

- Vercel pricing: https://vercel.com/pricing
- Vercel Hobby plan: https://vercel.com/docs/plans/hobby
- Vercel Blob pricing: https://vercel.com/docs/vercel-blob/usage-and-pricing
- Vercel Functions limits: https://vercel.com/docs/functions/limitations
- Vercel Cron pricing: https://vercel.com/docs/cron-jobs/usage-and-pricing
- Vercel Redis docs: https://vercel.com/docs/redis
- Vercel Redis Marketplace listing: https://vercel.com/marketplace/redis
- TiDB Cloud for Vercel: https://vercel.com/marketplace/tidb-cloud
- TiDB Cloud pricing: https://www.pingcap.com/pricing/
- Neon pricing: https://neon.com/pricing
- Supabase pricing: https://supabase.com/pricing
- Cloudflare R2 pricing: https://developers.cloudflare.com/r2/pricing/
- PlanetScale plans: https://planetscale.com/docs/planetscale-plans
