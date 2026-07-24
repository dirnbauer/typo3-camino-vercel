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

The project has been changed from Turbo to Standard:

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
- Removed the periodic Camino deep warm-up from `vercel.pro.json`.
- Added regression tests that prevent reintroducing the scheduled warmer.
- Added an always-on deployment profile with TYPO3, MariaDB, Redis, persistent
  Solr, Scheduler, and automatic TLS.
- Recorded the hosting decision in ADR-013.

Pending production cutover:

- Provision the always-on host or managed TYPO3 package.
- Restore/synchronize the production database and files.
- Validate TYPO3, backend login, Scheduler, and Solr.
- Change DNS.
- Confirm external monitoring and backups.
- Remove the live Vercel warm-up only after the new origin is serving traffic.

## Forecast

If Vercel remains for previews and low-traffic demonstrations:

- Platform baseline: $30/month while Pro and Analytics Plus remain enabled.
- Camino compute after removing the warmer: expected low single digits per
  month at the observed visitor traffic.
- Standard build usage: approximately $5–10/month at the current deployment
  frequency.
- Expected Vercel total: approximately $35–45/month, excluding marketplace
  databases or other external services.

The tested self-managed Hetzner baseline costs €19.69/month excluding VAT,
including one CX43 server, provider backups, IPv4, and Solr on the same host.
The managed jweiland Cloud PREMIUM alternative costs €36/month including VAT
and includes Solr.

See [hosting costs](costs.md), the
[always-on deployment guide](hetzner.md), and
[ADR-013](../Documentation/Adr/Adr013AlwaysOnProductionOrigin.rst).
