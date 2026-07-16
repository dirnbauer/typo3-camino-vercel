# Costs For Testing

Pricing changes often. Check the linked provider pages before promising a cost
to a client.

## Vercel

As rechecked on 2026-07-12, Vercel Hobby has no monthly subscription charge for
eligible personal, non-commercial projects within plan limits. It can cover a
small TYPO3 smoke test, but it is not a production business plan.

Important Hobby constraints for this starter:

- every Function, transfer, registry, and Blob allowance is finite
- cron jobs can run at most once per day on Hobby
- Hobby cron timing is not exact; current documentation allows broad delivery
  variation
- runtime logs and production controls differ from paid plans
- exceeding a free allowance can pause or restrict the affected resource

Use the live plan and pricing pages for exact allowances. They are deliberately
not duplicated here because values, regions, and billing units can change.

For commercial/client work, expect Vercel Pro or higher.

### Pro Warm-Up Cost

The three-minute Pro warm-up runs 20 times per hour, 480 times per day, or
about 14,400 times in a 30-day month. A warm invocation checks DB/Redis, primes
frontend and backend through local loopback, and pings Solr.

Those invocations consume normal Function resources. Duration, memory/CPU
class, Solr activation, concurrency, transfer, regional pricing, and plan
credits determine the bill. Use Vercel Observability and the current `fra1`
rates for a forecast instead of treating the invocation count as a cost
estimate. The Pro subscription is a prerequisite for this schedule.

Hobby cannot use this schedule because its cron limit is once per day. A free
demo can be durable, but it cannot use the built-in frequent cold-start warmer.

## Database Options

| Provider | Type | Free testing note |
| --- | --- | --- |
| Seeded SQLite | SQLite | Free, built into this image, not durable |
| Neon | Postgres | Vercel Marketplace, free plan, Frankfurt region |
| Supabase | Postgres | Free tier; free projects pause after a week of inactivity |
| TiDB Cloud | MySQL-compatible | Vercel integration with free starter quota |
| PlanetScale | MySQL-compatible | Vercel integration, no free database plan |

For a free MySQL-compatible test, try TiDB Cloud first; classic managed MySQL
usually starts at a paid tier. For commercial production, price the database
separately from Vercel and confirm backups, region, TLS, and support level.

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

No Vercel-managed Apache Solr product was documented in the sources reviewed
for this starter. This repo includes an internal
Vercel Solr container service for demos, and DDEV includes local Solr for
development, but production TYPO3 search needs durable Solr index storage.

Practical production choices:

- hosted Solr provider with TYPO3 support, for example hosted-solr.com,
  OpenSolr, SearchStax, or a TYPO3 host that offers Solr
- self-managed Solr 10 on always-on infrastructure with backups and monitoring

Expect production Solr to add a separate cost. Provider plans, storage,
retention, replicas, traffic, backups, support, and region change the price;
check a current provider quote before committing to a budget.

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

- [Vercel pricing](https://vercel.com/pricing)
- [Vercel Hobby plan](https://vercel.com/docs/plans/hobby)
- [Vercel Blob pricing](https://vercel.com/docs/vercel-blob/usage-and-pricing)
- [Vercel Functions limits](https://vercel.com/docs/functions/limitations)
- [Vercel Cron usage and pricing](https://vercel.com/docs/cron-jobs/usage-and-pricing)
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
