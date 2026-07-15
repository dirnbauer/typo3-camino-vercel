.. include:: /Includes.rst.txt

.. _adr-003:

=======================================
ADR-003: Externalize all durable state
=======================================

Status
======

**Accepted.** Retrospectively recorded on 2026-07-15.

Context
=======

TYPO3 needs a consistent relational database for records and backend sessions,
and durable storage for uploads and derivatives. Those guarantees cannot come
from a scaled-to-zero container filesystem. Large uploads also exceed Vercel's
request-body limit when proxied through PHP.

History evidence
================

- ``7d12a93`` (2026-07-03) documented SQL as a backend-login requirement.
- ``8fcf603`` (2026-07-03) added the S3-compatible TYPO3 FAL driver.
- ``b6df071`` (2026-07-05) added the Vercel Blob FAL driver.
- ``95d113e`` (2026-07-07) added optional Redis cache support.
- ``ccf08c2`` (2026-07-10) added direct large uploads to Vercel Blob.

Decision
========

Use a durable PostgreSQL or MySQL-compatible service for TYPO3 data and backend
sessions. Use Vercel Blob or S3-compatible object storage through TYPO3 FAL for
uploads and processed derivatives.

Prefer Vercel request OIDC for Blob authentication, with a read/write token as
a compatibility fallback. Send large uploads directly from an authenticated
browser to Blob with short-lived, path-, type-, and size-scoped authorization.

Redis remains optional. It may share selected TYPO3 caches but is not a
database, file store, instance-residency control, or correctness requirement.

Consequences
============

**Positive:**

- Editorial data, sessions, and files survive instance replacement.
- Direct uploads avoid routing large bodies through PHP.
- Storage providers remain selectable through TYPO3 FAL.
- Redis can improve cross-instance cache reuse without becoming mandatory.

**Negative:**

- A durable deployment requires external services and secret management.
- Network latency and provider availability enter the request path.
- Storage migration and backup policies remain operator responsibilities.

Alternatives considered
=======================

1. **Persist files in the container:** Rejected because instances are ephemeral
   and do not share one filesystem.
2. **Use Blob as a SQL or mounted-disk replacement:** Rejected because object
   storage does not provide relational or POSIX filesystem semantics.
3. **Require Redis for all deployments:** Rejected because it adds a dependency
   without solving database, file, or cold-activation concerns.
