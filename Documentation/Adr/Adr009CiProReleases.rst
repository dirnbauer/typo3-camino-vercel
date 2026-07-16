.. include:: /Includes.rst.txt

.. _adr-009:

==========================================
ADR-009: Release the Pro profile from CI
==========================================

Status
======

**Accepted.** Recorded on 2026-07-16.

Context
=======

The Vercel project is deliberately not Git-connected: a native Git deployment
reads ``vercel.json`` and would replace production with the evaluation
profile from :ref:`ADR-002 <adr-002>`. Manual ``scripts/deploy-pro.sh``
releases kept the Pro profile intact but were easy to forget after a push,
letting the live demo drift behind ``main``.

History evidence
================

- ``a6b1ca3`` (2026-07-16) added the CI deploy job and token support.

Decision
========

After every successful CI run for a push to ``main``, a ``deploy`` job stages
the committed tree with ``vercel.pro.json`` and deploys production through
the Vercel CLI (``scripts/deploy-pro.sh``). The job authenticates with the
``VERCEL_TOKEN`` repository secret plus the Vercel organization and project
id secrets, runs in a serialized concurrency group, and never runs for pull
requests. The Deploy Button and manual imports keep reading ``vercel.json``.

Consequences
============

**Positive:**

- Pushing to ``main`` releases the Pro profile without losing cron schedules
  or the Solr service binding.
- Releases are gated on the complete lint, analysis, test, container, and
  documentation checks.

**Negative:**

- The deploy job fails until a valid ``VERCEL_TOKEN`` secret exists, and the
  token must be rotated like any other credential.
- A production release now depends on GitHub Actions availability; the
  manual script remains the fallback.

Alternatives considered
=======================

1. **Connect Vercel's native Git integration:** Rejected because it deploys
   the evaluation profile on every push and cannot read ``vercel.pro.json``.
2. **Make ``vercel.json`` the Pro profile:** Rejected because Hobby one-click
   deployments fail on its cron frequency, breaking the Deploy Button.
3. **Keep releases manual-only:** Rejected because the extra step after every
   push is easy to forget and leaves production behind ``main``.
