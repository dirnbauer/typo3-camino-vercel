# Costs For Testing

Pricing changes often. Check the linked provider pages before promising a cost
to a client.

## Vercel

As checked on 2026-07-03, Vercel Hobby is $0/month for personal,
non-commercial projects within plan limits. It includes enough usage for a
small TYPO3 smoke test, but it is not a production business plan.

Important Hobby constraints for this starter:

- included usage is limited, for example active CPU, provisioned memory, and
  function invocations
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
| Aiven | MySQL/Postgres | Free true MySQL plan outside Vercel Marketplace |
| PlanetScale | MySQL-compatible | Vercel integration, no free database plan |

MySQL preference:

- For a free Vercel-integrated MySQL-compatible test, try TiDB Cloud first.
- For true MySQL and still free, try Aiven and paste its connection URL into
  Vercel manually.
- For commercial production, price the database separately from Vercel and
  confirm backups, region, TLS, and support level.

## Practical Recommendation

For the cheapest test:

1. use Vercel Hobby
2. use the seeded SQLite demo
3. do not edit content you need to keep

For a more realistic free/low-cost test:

1. use Vercel Hobby or Pro trial
2. use TiDB/MySQL-compatible or Aiven/MySQL if MySQL is preferred
3. set spend limits in the database provider
4. delete test resources when finished

## Sources

- Vercel pricing: https://vercel.com/pricing
- Vercel Hobby plan: https://vercel.com/docs/plans/hobby
- Vercel Functions limits: https://vercel.com/docs/functions/limitations
- Vercel Cron pricing: https://vercel.com/docs/cron-jobs/usage-and-pricing
- TiDB Cloud pricing: https://www.pingcap.com/pricing/
- Aiven MySQL free plan: https://aiven.io/free-mysql-database
- PlanetScale plans: https://planetscale.com/docs/planetscale-plans
