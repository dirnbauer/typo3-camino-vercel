.. include:: /Includes.rst.txt

.. _adr-007:

==============================================
ADR-007: Expose short protected jobs over HTTP
==============================================

Status
======

**Accepted.** Retrospectively recorded on 2026-07-15.

Context
=======

A scaled-to-zero container does not run a permanent cron daemon or worker.
Vercel Cron invokes HTTP routes and each invocation has a finite duration.
TYPO3 Scheduler, Solr queue work, setup, warm-up, and maintenance therefore need
an execution model that is authenticated, repeatable, and short-lived.

History evidence
================

- ``9f6c047`` (2026-07-08) documented long-running job constraints.
- ``e0a9bb6`` (2026-07-08) added Solr Scheduler cron support.
- ``093b507`` (2026-07-08) hardened the Scheduler endpoint.
- ``7fc55a1`` (2026-07-09) made Pro warming verifiable.
- ``9b27d48`` (2026-07-09) made Pro cron deployment deterministic.

Decision
========

Expose narrow HTTP endpoints for Scheduler, warm-up, and idempotent maintenance
operations that are expected to fit within one invocation. Require Vercel's
``CRON_SECRET`` Bearer token for protected operations. Execute child commands
through argument arrays, close their input, and merge output streams to avoid
pipe deadlocks. Treat the platform invocation duration as the outer deadline,
not as a general-purpose worker guarantee.

Skip Scheduler automatically for the self-seeded internal Solr demo unless an
operator explicitly forces a test. Process production indexing in bounded
batches with persistent queue state in durable SQL. Use an external worker or
job runner for work that cannot complete safely within one request.

Consequences
============

**Positive:**

- Short scheduled operations match the platform execution model.
- Authentication and bounded inputs reduce abuse and runaway work.
- Idempotent maintenance can be retried after deployment failures.

**Negative:**

- Multi-hour jobs require another worker platform.
- Cron delivery is not a precise residency or timing guarantee.
- The application endpoints do not impose a safe deadline on arbitrary
  third-party Scheduler tasks; those tasks must checkpoint and finish within
  the platform limit.
- Queue design must persist progress outside the container.

Alternatives considered
=======================

1. **Run cron inside the container:** Rejected because there may be no resident
   instance and the process lifecycle is not permanent.
2. **Process an entire index in one request:** Rejected because timeouts would
   lose progress or cause repeated unbounded work.
3. **Leave maintenance routes public:** Rejected because setup and diagnostics
   expose privileged and potentially expensive operations.
