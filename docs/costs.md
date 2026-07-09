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

### Pro Warm-Up Cost

The three-minute Pro warm-up runs 20 times per hour, 480 times per day, or
about 14,400 times in a 30-day month. A warm invocation checks DB/Redis, primes
frontend and backend through local loopback, and pings Solr.

At current active-CPU and provisioned-memory pricing, the expected incremental
usage for this small demo is usually cents to low single-digit dollars per
month before plan credits. This is an estimate because duration, CPU class,
Solr activation, and concurrency affect the bill. Use Vercel Observability and
the current `fra1` rates for an actual forecast. The fixed Pro subscription is
the larger prerequisite.

Hobby cannot use this schedule because its cron limit is once per day. A free
demo can be durable, but it cannot use the built-in frequent cold-start warmer.

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

The public demo uses Upstash for Redis from the Vercel Marketplace. The resource
uses the `Free` plan in `fra1`; eviction is enabled and automatic paid-plan
upgrades are disabled.

That can be free for testing while the cache data fits inside the provider's
free quota and the account stays within the provider's terms. It is not a
production sizing recommendation.

Important Redis cost notes:

- Vercel offers Redis providers through Marketplace integrations; the free
  Upstash resource used here exposes both REST variables and a real TLS
  `TYPO3_REDIS_URL`.
- TYPO3's native Redis backend uses that TCP/TLS URL. REST-only variables are
  not enough.
- A free plan is a quota, not unlimited production capacity. With auto-upgrade
  disabled, budget safety takes priority over uninterrupted cache availability.
- Paid persistence is less important for TYPO3 caches than for primary data
  because caches can be rebuilt; capacity, SLA, and support still matter.
- Redis does not remove the need for a durable SQL database or Blob/S3 object
  storage.

## Solr Search Cost Notes

Vercel does not currently provide managed Apache Solr as a first-party or
Marketplace storage service for this starter. This repo includes an internal
Vercel Solr container service for demos, and DDEV includes local Solr for
development, but production TYPO3 search needs durable Solr index storage.

Practical production choices:

- hosted Solr provider with TYPO3 support, for example hosted-solr.com,
  OpenSolr, SearchStax, or a TYPO3 host that offers Solr
- self-managed Solr 10 on always-on infrastructure with backups and monitoring

Expect Solr to add a separate monthly cost. Publicly listed entry plans checked
during this work were roughly 10-15 EUR/month for small hosted Solr services,
but provider plans change often and must be checked before a client quote.

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
FAL driver when Vercel provides Blob OIDC/store credentials. The database part is still not
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
- Vercel Upstash Marketplace listing: https://vercel.com/marketplace/upstash
- Upstash pricing: https://upstash.com/pricing/redis
- TiDB Cloud for Vercel: https://vercel.com/marketplace/tidb-cloud
- TiDB Cloud pricing: https://www.pingcap.com/pricing/
- Neon pricing: https://neon.com/pricing
- Supabase pricing: https://supabase.com/pricing
- Cloudflare R2 pricing: https://developers.cloudflare.com/r2/pricing/
- PlanetScale plans: https://planetscale.com/docs/planetscale-plans
