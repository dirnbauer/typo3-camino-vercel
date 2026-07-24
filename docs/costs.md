# Hosting Costs

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

### July 2026 Billing Analysis

The July 7–23 infrastructure invoice was $120.03 before the Pro plan's $20
usage credit and $100.04 after it. The separate platform invoice was $30:
$20 Pro plus $10 Web Analytics Plus.

| Invoice line | Usage | Cost |
| --- | ---: | ---: |
| Fluid provisioned memory | 4,000.073 GB-hours | $60.79 |
| Fluid active CPU | 87h 46m 30.5s | $16.12 |
| Build CPU | 224h 38m | $38.12 |
| Vercel Container Registry storage | 37.02 GB | $3.70 |
| Fast origin transfer | 4.69 GB | $0.29 |
| Fast data transfer | 8.99 GB | $0.00 |

The `typo3-camino-vercel` project produced 99.8% of provisioned memory and
97.6% of active CPU. Its scheduled one-minute deep probe called TYPO3
frontend, backend, database, Redis, and the independently scaling Solr service.
It tried to keep roughly 10.2 GB resident on average and sometimes waited
10–13 seconds for Solr. Platform restarts still occurred, so the probe was
neither a cost-efficient monitor nor an availability guarantee.

Traffic did not cause this bill. Camino transferred about 328 MB from CDN to
visitors and 288 MB from CDN to compute in the selected period. Across the
whole team, the invoice's only traffic charge was $0.29 for 4.69 GB of origin
transfer, or about $0.062/GB. User-facing transfer was within the plan
allowance and cost $0.

Build cost was independent of Camino runtime cost. The
`webconsulting-website` project used 178h 30m of Turbo build CPU across 79
deployments, accounting for nearly all of the $38.12 build line.

ADR-013 removes the scheduled warmer. If Vercel is retained for low-traffic
demos, forecast low-single-digit monthly Camino compute rather than the
observed roughly $77 per 16 days. The remaining Vercel baseline is still $30
per month while Pro and Analytics Plus remain enabled, and Turbo builds remain
a separate cost until that project uses a smaller build machine or deploys
less frequently.

## Predictable Always-On Cost

The tested Hetzner baseline runs TYPO3, MariaDB, Redis, and durable Solr on one
CX43:

| Component | Monthly cost excluding VAT |
| --- | ---: |
| CX43 (8 vCPU, 16 GB RAM, 160 GB SSD) | €15.99 |
| Seven-slot provider backup option (20%) | €3.20 |
| Primary IPv4 | €0.50 |
| **Total** | **€19.69** |

EU CX servers include 20 TB outbound traffic. At the measured team-wide
13.68 GB, traffic would use about 0.07% of that allowance and add €0. Solr has
no separate line item because it runs privately on the same host.

This is infrastructure pricing, not managed operations or high availability.
For provider-owned TYPO3 operations, jweiland Cloud PREMIUM currently costs
€36/month including German VAT and includes MariaDB, Solr, backups, monitoring,
and a published 99.9% availability target. Cloud BASIC is €24 but does not
include Solr.

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
- the included self-managed persistent Solr 10 service on the Hetzner profile
- a managed TYPO3 plan that explicitly includes Solr

Self-managed Solr does not add a provider line item, but it consumes the host's
RAM, storage, backup capacity, monitoring, and operator time.

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
- [Vercel Functions usage and pricing](https://vercel.com/docs/functions/usage-and-pricing)
- [Hetzner June 2026 prices](https://docs.hetzner.com/general/infrastructure-and-availability/price-adjustment/)
- [Hetzner backup billing](https://docs.hetzner.com/cloud/billing/faq/)
- [Hetzner included traffic](https://docs.hetzner.com/robot/general/traffic/)
- [jweiland TYPO3 hosting](https://jweiland.net/typo3-hosting.html)
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
