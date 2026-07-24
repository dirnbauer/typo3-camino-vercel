# Vercel Billing Review

## Executive Summary

The July 2026 Vercel bill was driven by compute residency and build-machine
selection, not website traffic.

Between July 7 and July 23, the infrastructure subtotal was $120.03. The Pro
plan's $20 usage credit reduced the infrastructure charge to $100.04. A
separate $30 platform invoice covered the $20 Pro subscription and $10 Web
Analytics Plus.

The two material cost drivers were:

1. A one-minute deep warm-up in `typo3-camino-vercel` repeatedly exercised
   TYPO3 and its separate Solr service.
2. `webconsulting-website` used the 30-vCPU Turbo build machine for 79
   deployments.

Traffic contributed only $0.29 to the infrastructure invoice.

## Old Version Compared With New Version

The old actual charge and the new forecast cover different lengths of time.
The reviewed old usage window was 16.33 days; the new figure is a complete
30-day forecast. Normalizing the old usage to 30 days gives a fair comparison:

| Cost or setting | Old version | New version |
| --- | ---: | ---: |
| Camino deep warm-up | Every minute | Removed |
| TYPO3 Scheduler | Every 15 minutes | Every 15 minutes |
| Dominant build machine | Turbo, 30 vCPU | Standard, 4 vCPU |
| Infrastructure before credit | Approximately $220.50/month | Approximately $5–20/month |
| Pro infrastructure credit | −$20/month | Up to −$20/month |
| Pro and Analytics Plus | $30/month | $30/month |
| **Expected monthly invoice** | **Approximately $230.50** | **Approximately $30; allow $30–40** |

The old configuration actually incurred $130.04 during the reviewed 16.33-day
window. If that compute and deployment pattern had continued for 30 days, the
normalized invoice would have been approximately $230.50 before tax. The new
configuration is expected to reduce the monthly invoice by approximately
$190–200, or 83–87%.

This comparison does not assign the saving to traffic. Visitor transfer was
already within the included allowance, and origin transfer contributed only
$0.29 to the reviewed invoice.

## The Biggest Wins

1. **Approximately $190–200 less per month.** The normalized monthly invoice
   falls from approximately $230.50 to about $30, with a conservative upper
   forecast of $40. This is an expected reduction of 83–87%.
2. **Approximately $141/month of unnecessary runtime compute is eliminated.**
   The old one-minute warm-up generated $76.91 of provisioned-memory and CPU
   charges in only 16.33 days. It did not guarantee that an instance remained
   available.
3. **Build-machine spending is reduced by approximately $60–70/month.** The
   old build charge normalizes to about $70/month. Standard builds should cost
   $0 with ordinary Pro concurrency; even the conservative on-demand allowance
   is only $5–10/month.
4. **The savings do not depend on reducing visitor traffic.** Fast Data
   Transfer was already $0, and origin transfer cost only $0.29. Public pages
   remain edge-cached; the production verification returned cached pages in
   approximately 0.1–0.3 seconds.

The main win is therefore structural: stop manufacturing continuous compute
load and stop paying for an oversized build machine. Normal visitor traffic
was not the billing problem.

## Speed: Old Version Compared With New Version

The new version in this table is the selected live, cost-optimized Vercel
deployment. Hetzner is included elsewhere for price comparison only.
Measurements use different dates and request classes, so they should be read
as operational evidence, not as a controlled before-and-after laboratory
benchmark.

| Request class | Old version | New live Vercel version |
| --- | ---: | ---: |
| Public frontend after deployment | 12.57s for `/` | 0.714–10.847s across 13 cache-fill requests |
| Public frontend repeat | 0.046s median for 10 warm `/` requests | 0.103s median across 13 edge-cached routes |
| Cached public-page mean | Not recorded across the route set | 0.149s |
| Cached public-page p95 | Not recorded across the route set | 0.323s |
| First measured search | 14.6–17.0s for confirmed cold activation despite the old warmer | 5.611s during the post-deploy cache-fill pass |
| Search repeat | 0.35–0.96s | 0.293s |
| Backend warm | 0.125s median | Not re-benchmarked |
| Backend cold | 10.151s, then 0.206–0.238s | Still exposed to the 10–12s Vercel cold-start class |
| Post-deploy public checks | Not recorded as one route set | 26/26 HTTP 200 responses |

The new cache-fill pass covered 13 routes. Its first pass had a 1.436s median,
3.843s mean, and 10.847s maximum while TYPO3, Solr, and the edge cache were
being populated. The immediate cached pass had a 0.103s median, 0.149s mean,
0.323s p95, and 0.323s maximum.

**The speed win is that the large cost reduction did not make normal cached
page delivery slow:** every measured cached route stayed below 0.33 seconds.
The old one-minute warmer was not providing an equivalent reliability win;
historical probes still observed 14.6–17.0-second Solr activation while it was
enabled.

Uncached backend, personalized, and first search requests can still encounter
Vercel activation. This limitation is accepted for the current production
decision and will be monitored; it does not trigger a Hetzner migration.

## Cost Breakdown

| Invoice line | Usage | Cost |
| --- | ---: | ---: |
| Fluid provisioned memory | 4,000.073 GB-hours | $60.79 |
| Fluid active CPU | 87h 46m 30.5s | $16.12 |
| Build CPU | 224h 38m | $38.12 |
| Container Registry storage | 37.02 GB | $3.70 |
| Fast Origin Transfer | 4.69 GB | $0.29 |
| Fast Data Transfer | 8.99 GB | $0.00 |
| **Infrastructure subtotal** | | **$120.03** |
| Pro usage credit | | **−$20.00** |
| **Infrastructure charged** | | **$100.04** |

Including the $30 platform invoice, total Vercel charges for the reviewed
period were $130.04.

## TYPO3 Runtime Cost

`typo3-camino-vercel` accounted for:

- 99.8% of provisioned-memory usage
- 97.6% of active CPU usage
- 3,997 GB-hours of provisioned memory
- approximately 85h 45m of active CPU

The scheduled deep warm-up called the TYPO3 frontend, TYPO3 backend, database,
Redis, and Solr every minute. Some Solr probes retried for 10–13 seconds.

This produced an average of approximately 10.2 GB of continuously provisioned
memory. Vercel still restarted service instances periodically, so the warm-up
created sustained charges without providing a minimum-instance or always-on
guarantee.

The warm-up was therefore removed from the next Vercel configuration. The
protected endpoint remains available for manual diagnostics.

## Build Cost

`webconsulting-website` used approximately 178h 30m of Turbo build CPU across
79 deployments. This represented almost all of the $38.12 build charge.

Both `webconsulting-website` and `typo3-camino-vercel` are now explicitly
fixed to Standard builds:

| Build machine | Resources | Price |
| --- | --- | ---: |
| Turbo | 30 vCPU, 60 GB RAM | $0.105/build-minute |
| Standard | 4 vCPU, 8 GB RAM | $0.014/build-minute |

At comparable build duration, the build charge should fall from approximately
$37.50 to about $5. Builds may take longer on Standard, so a practical forecast
is $5–10 per month at the same deployment frequency.

## Traffic Cost

Traffic was not a material contributor:

- 8.99 GB of Fast Data Transfer cost $0.
- 4.69 GB of Fast Origin Transfer cost $0.29.
- The effective charged origin-transfer rate was approximately $0.062/GB.
- Camino itself transferred only about 328 MB to visitors and 288 MB between
  CDN and compute during the selected period.

Reducing normal visitor traffic would therefore have little effect on this
invoice. The meaningful savings come from compute residency, build-machine
selection, and deployment frequency.

## Corrective Actions

Completed:

- Changed `webconsulting-website` from Turbo to Standard builds.
- Fixed `typo3-camino-vercel` to the Standard build machine.
- Removed the periodic Camino deep warm-up from `vercel.pro.json` and deployed
  the change to production.
- Added regression tests that prevent reintroducing the scheduled warmer.
- Added a tested Hetzner reference profile so its price and capabilities can
  be compared with Vercel, including persistent Solr.
- Recorded the final decision to retain Vercel in ADR-014.

Live verification on July 24, 2026 confirmed the production deployment was
`READY`, the build machine was Standard (4 vCPU and 8 GB RAM), and the only
remaining Vercel cron was the bounded TYPO3 Scheduler call every 15 minutes.

No Hetzner provisioning, data migration, or DNS cutover is planned.

## Forecast

For the selected Vercel production deployment:

- Fixed platform fees: $20/month for Pro and $10/month for Web Analytics Plus.
- Camino compute after removing the warmer: expected $1–5/month at the
  observed visitor traffic.
- Standard builds: $0 when ordinary Pro build concurrency is used; budget up
  to $5–10/month if on-demand concurrent builds are enabled.
- Registry storage and origin transfer: approximately $4–5/month if current
  storage and traffic remain unchanged.
- Expected infrastructure usage: approximately $5–20/month. The Pro plan's
  included $20 monthly usage credit should normally cover this.
- **Expected Vercel invoice: approximately $30/month before tax**, with a
  conservative $30–40/month range for unusually high build or compute usage.

For price comparison only, the tested self-managed Hetzner baseline costs
€19.69/month excluding VAT, including one CX43 server, provider backups, IPv4,
and Solr on the same host. The managed jweiland Cloud PREMIUM comparison costs
€36/month including VAT and includes Solr. Neither option is the selected
hosting target.

See [hosting costs](costs.md), the
[Hetzner price comparison](hetzner.md), and
[ADR-014](../Documentation/Adr/Adr014RetainVercelProduction.rst).
