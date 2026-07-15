.. include:: /Includes.rst.txt

.. _adr-001:

================================================
ADR-001: Run TYPO3 as disposable Vercel compute
================================================

Status
======

**Accepted.** Retrospectively recorded on 2026-07-15.

Context
=======

A traditional TYPO3 host assumes a persistent web root, writable application
directories, and long-lived processes. Vercel Services starts and replaces
container instances on demand. Local writes cannot be assumed to survive or to
be visible to another instance.

History evidence
================

- ``dbd584b`` (2026-07-02) added the first TYPO3 container starter.
- ``5500dcc`` (2026-07-02) routed Vercel traffic to a container Service.
- ``1347594`` (2026-07-02) introduced a seeded database for smoke deployments.
- ``c4f7a6c`` (2026-07-03) moved TYPO3 runtime writes to ``/tmp``.

Decision
========

Run nginx and PHP-FPM as replaceable Vercel compute. Keep the application image
immutable and place runtime cache, locks, logs, sessions, temporary files, and
demo SQLite under ``/tmp``. Treat every local runtime path as disposable.

The container may serve a self-contained evaluation, but durable deployments
must externalize all state that editors or operators expect to retain.

Consequences
============

**Positive:**

- The application fits the Vercel Service lifecycle.
- Instances can be replaced without deployment-time filesystem migration.
- Writable-path behavior is explicit and testable.

**Negative:**

- Local database records and sessions are unsuitable for real editorial use.
- Local uploads, generated files, and search indexes are not durable.
- Cold activation remains part of uncached request latency.

Alternatives considered
=======================

1. **Treat the container as a persistent VM:** Rejected because the platform
   lifecycle does not guarantee local persistence or one resident instance.
2. **Write into the image tree:** Rejected because deployments are immutable and
   concurrent instances would diverge.
3. **Run TYPO3 on always-on infrastructure:** Still recommended when predictable
   first-request latency or a persistent local filesystem is required.
