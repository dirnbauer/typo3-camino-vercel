.. include:: /Includes.rst.txt

.. _adr-014:

========================================
ADR-014: Retain Vercel for production
========================================

Status
======

**Accepted.** Recorded on 2026-07-24. This decision supersedes
:ref:`ADR-013 <adr-013>` and the warmer-bearing profile in
:ref:`ADR-002 <adr-002>`.

Context
=======

The July billing review found that normal visitor traffic was not the source
of the unexpectedly high invoice. The material causes were a one-minute deep
warm-up that repeatedly activated TYPO3 and Solr, and Turbo build-machine use
in another team project.

The warm-up did not reserve a service instance. Historical checks still found
Solr cold after hours of scheduled probes. It therefore produced sustained
memory and CPU charges without guaranteeing predictable first-request
latency.

The evaluated Hetzner stack provides an always-on comparison including
MariaDB, Redis, and persistent Solr for €19.69/month excluding VAT. Its lower
infrastructure price comes with responsibility for the operating system,
Docker, databases, search, monitoring, backups, and recovery.

Decision
========

Retain Vercel as the production platform:

1. deploy the committed ``vercel.pro.json`` profile;
2. use the fixed Standard build machine instead of Turbo;
3. schedule only the bounded 15-minute TYPO3 Scheduler invocation;
4. keep the protected deep warm-up as a manual diagnostic, never as a
   residency mechanism;
5. serve eligible anonymous public pages through the safe Vercel edge-cache
   policy; and
6. monitor uncached TYPO3 and Solr activation without manufacturing traffic
   to keep instances resident.

Keep ``compose.hetzner.yaml`` and its documentation as a reproducible price
and capability comparison. Do not provision Hetzner, migrate data, change DNS,
or perform a cutover without a new decision and explicit authorization.

Consequences
============

**Positive:**

- The expected invoice falls from an approximately $230.50 normalized monthly
  run rate to about $30, with a conservative $30--40 range.
- The Vercel deployment workflow, edge network, rollback model, and managed
  platform remain unchanged.
- Cached production acceptance returned all 13 routes in 0.095--0.323 seconds.
- The team avoids taking on VM, database, Solr, and backup operations.

**Negative:**

- An uncached TYPO3 request can still encounter the historical 10--12 second
  Vercel activation class.
- A genuinely cold private Solr service can take roughly 15--20 seconds.
- Vercel exposes no minimum-instance guarantee for this Service path.
- The forecast must be validated against at least one complete billing cycle.

Alternatives considered
=======================

1. **Retain the one-minute warmer:** Rejected because it caused most Camino
   compute cost and did not guarantee residency.
2. **Move production to Hetzner:** Not selected. The price comparison remains
   useful, but the lower infrastructure fee does not justify changing the
   hosting platform and assuming additional operations.
3. **Use managed TYPO3 hosting:** Not selected. It remains a comparison when a
   future requirement values provider-owned operations over Vercel delivery.

Verification
============

On 2026-07-24 the Vercel project reported the Standard build machine and a
``READY`` production deployment. Its only registered cron was TYPO3 Scheduler
every 15 minutes. The post-deploy check returned 26 of 26 HTTP 200 responses
across two passes over 13 routes. The cached pass had a 0.103-second median,
0.149-second mean, and 0.323-second p95.

The repository regression test rejects a scheduled deep warmer. PHP, assets,
documentation, and both container builds passed in CI.
