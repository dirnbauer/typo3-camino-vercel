.. include:: /Includes.rst.txt

.. _adr-002:

==========================================
ADR-002: Maintain two deployment profiles
==========================================

Status
======

**Superseded by ADR-013.** Retrospectively recorded on 2026-07-15 and
superseded on 2026-07-24 when production moved to an always-on origin.

Context
=======

The easiest evaluation should work without paid cron frequency or additional
services. A professional deployment needs explicit schedules, durable state,
and optional search. One configuration cannot honestly represent both without
making the Deploy Button expensive or making production behavior incomplete.

History evidence
================

- ``1347594`` (2026-07-02) added the seeded SQLite smoke deployment.
- ``135f1b6`` (2026-07-03) documented the free demonstration mode.
- ``9b27d48`` (2026-07-09) made Pro cron deployment deterministic.
- ``7f42ddc`` (2026-07-10) finalized the two deployment profiles.

Decision
========

Keep two explicit Vercel configurations:

1. ``vercel.json`` is the Hobby-compatible evaluation profile. It contains one
   TYPO3 Service, no cron jobs, and no Solr Service.
2. ``vercel.pro.json`` adds the private demonstration Solr Service, a
   three-minute warmer, and a 15-minute Scheduler invocation.

Git-based deployment continues to read ``vercel.json``. The
``scripts/deploy-pro.sh`` command deploys a clean committed archive with the Pro
configuration as its canonical Vercel file.

Consequences
============

**Positive:**

- One-click evaluation stays simple and Hobby-compatible.
- Paid operational behavior is explicit and reviewable.
- The Pro deployment script does not modify the working tree.

**Negative:**

- Operators must deliberately redeploy the Pro profile after Git releases.
- The profiles can drift unless both are schema-checked and documented.
- The Pro warmer reduces exposure but does not reserve an instance.

Alternatives considered
=======================

1. **One Pro-only configuration:** Rejected because frequent cron is not
   Hobby-compatible and would break the one-click promise.
2. **One minimal configuration for every environment:** Rejected because it
   silently omits required production operations.
3. **Generate configuration in-place:** Rejected because deployment must not
   leave uncommitted changes in the checkout.
