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
- Added an always-on deployment profile with TYPO3, MariaDB, Redis, persistent
  Solr, Scheduler, and automatic TLS.
- Recorded the hosting decision in ADR-013.

Live verification on July 24, 2026 confirmed the production deployment was
`READY`, the build machine was Standard (4 vCPU and 8 GB RAM), and the only
remaining Vercel cron was the bounded TYPO3 Scheduler call every 15 minutes.

Pending production cutover:

- Provision the always-on host or managed TYPO3 package.
- Restore/synchronize the production database and files.
- Validate TYPO3, backend login, Scheduler, and Solr.
- Change DNS.
- Confirm external monitoring and backups.

## Forecast

If Vercel remains for previews and low-traffic demonstrations:

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

The tested self-managed Hetzner baseline costs €19.69/month excluding VAT,
including one CX43 server, provider backups, IPv4, and Solr on the same host.
The managed jweiland Cloud PREMIUM alternative costs €36/month including VAT
and includes Solr.

See [hosting costs](costs.md), the
[always-on deployment guide](hetzner.md), and
[ADR-013](../Documentation/Adr/Adr013AlwaysOnProductionOrigin.rst).
