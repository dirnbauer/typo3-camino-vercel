.. include:: /Includes.rst.txt

.. _adr-013:

=================================================
ADR-013: Use an always-on origin for production
=================================================

Status
======

**Accepted.** Recorded on 2026-07-24 after billing analysis and a full
container-stack acceptance test. This record supersedes the scheduled warmer
part of :ref:`ADR-002 <adr-002>` and :ref:`ADR-012 <adr-012>`.

Context
=======

The Vercel Pro platform fee did not cap infrastructure usage. Between July 7
and July 23, 2026, the one-minute deep warm-up generated nearly all Camino
compute while the project delivered only about 0.62 GB between origin and
users. The infrastructure invoice included 4,000 GB-hours of provisioned
memory and almost 88 hours of active CPU. The warmer also touched the
independently scaling Solr service and sometimes waited ten seconds for it.

The platform still restarted instances while the warmer was active. Vercel
Fluid Compute reduces cold-start frequency, but its documentation explicitly
states that cold starts can still happen. No minimum-instance control exists
for this container Service. A frequent probe was therefore both expensive and
unable to meet the required predictable first-request latency.

Decision
========

Run latency-sensitive production on an always-on origin. The reference
``compose.hetzner.yaml`` profile:

1. runs nginx/PHP-FPM, MariaDB, Redis, and durable Solr on one always-on host;
2. exposes only Caddy on HTTP/HTTPS and keeps stateful ports private;
3. persists the database, uploads, Redis data, Solr index, TYPO3 runtime data,
   and Caddy state in named volumes;
4. runs TYPO3 Scheduler in a separate restartable container; and
5. uses health-gated startup and automatic TLS.

A Hetzner CX43 is the starting size for this combined workload. Enable the
provider's seven-slot backup option and retain an independent application-level
export. This single-server baseline provides predictable residency, but does
not claim high availability.

Keep Vercel configurations as evaluation/demo profiles. Remove the periodic
deep warmer from ``vercel.pro.json``; retain only the bounded 15-minute
Scheduler invocation. The protected warmer endpoint remains available for
manual diagnostics. It is not an availability mechanism.

Consequences
============

**Positive:**

- The first uncached visitor no longer pays a scale-to-zero activation.
- Solr index and TYPO3 uploads survive process and container replacement.
- The infrastructure ceiling is predictable: €19.69 per month excluding VAT
  for the June 2026 CX43 price, backups, and one IPv4 address.
- EU CX servers include 20 TB outbound traffic, far above the measured usage.

**Negative:**

- Operating-system, Docker, database, Solr, monitoring, and restore work moves
  to the operator.
- One host is a failure domain; provider backups reduce recovery loss but do
  not provide failover.
- Vercel edge delivery and instant deployment rollback are no longer the
  production origin. A CDN may still be placed in front when justified.
- Migration requires a final database/files sync and DNS cutover.

Alternatives considered
=======================

1. **Keep the one-minute Vercel warmer:** Rejected because it generated the
   dominant compute charge and still did not reserve an instance.
2. **Remove the warmer but keep Vercel as the latency-sensitive origin:**
   Rejected because it lowers cost by reintroducing an unbounded cold path.
3. **Use Vercel Fluid Compute cold-start prevention:** Rejected as an SLA
   mechanism. It reduces frequency and impact; Vercel documents that cold
   starts can still occur and exposes no minimum-instance setting here.
4. **Managed TYPO3 hosting:** Recommended when operational ownership is more
   important than the lowest infrastructure price. jweiland Cloud PREMIUM
   includes MariaDB and Solr for €36 per month including VAT and publishes a
   99.9% availability target.
5. **Redundant Hetzner deployment:** Deferred until traffic or an availability
   target justifies a load balancer, two application nodes, and separately
   managed database/search failure domains.

Verification
============

On 2026-07-24 the production profile was built from scratch and all six
services became healthy. Frontend, backend, search, and shallow health routes
returned HTTP 200 through Caddy. The internal Solr ping returned ``OK`` and
the Scheduler completed repeated runs. MariaDB retained 45 page rows and Solr
retained a committed smoke document across service restarts. The focused
profile tests contain 37 assertions covering private ports, persistent
volumes, health checks, and the removed Vercel warmer.
