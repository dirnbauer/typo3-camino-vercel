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

## Practical Recommendation

For the cheapest test:

1. use Vercel Hobby
2. use the seeded SQLite demo
3. do not add `DATABASE_URL`
4. do not create an object storage bucket
5. do not use it for backend editing or content you need to keep

For a more realistic free/low-cost test:

1. use Vercel Hobby or Pro trial
2. use TiDB/MySQL-compatible if MySQL-style SQL is preferred
3. set spend limits in the database provider
4. delete test resources when finished

For a durable free demo with persistent uploads:

1. use Vercel Hobby for personal/non-commercial testing
2. use a free database quota, for example TiDB Cloud, Neon, or Supabase
3. use free object storage quota, for example Cloudflare R2
4. wire TYPO3 uploads through the included S3-compatible FAL driver
5. keep usage inside every provider's free limits

This is not one-click yet. The current one-click demo does not include durable
uploaded files. Vercel Blob is not supported by the included driver because it
does not expose an S3-compatible API.

## Sources

- Vercel pricing: https://vercel.com/pricing
- Vercel Hobby plan: https://vercel.com/docs/plans/hobby
- Vercel Blob pricing: https://vercel.com/docs/vercel-blob/usage-and-pricing
- Vercel Functions limits: https://vercel.com/docs/functions/limitations
- Vercel Cron pricing: https://vercel.com/docs/cron-jobs/usage-and-pricing
- TiDB Cloud for Vercel: https://vercel.com/marketplace/tidb-cloud
- TiDB Cloud pricing: https://www.pingcap.com/pricing/
- Neon pricing: https://neon.com/pricing
- Supabase pricing: https://supabase.com/pricing
- Cloudflare R2 pricing: https://developers.cloudflare.com/r2/pricing/
- PlanetScale plans: https://planetscale.com/docs/planetscale-plans
