.. include:: /Includes.rst.txt

.. _adr-005:

==========================================
ADR-005: Cache public HTML conservatively
==========================================

Status
======

**Accepted.** Retrospectively recorded on 2026-07-15.

Context
=======

Serving anonymous pages from the edge avoids PHP activation and gives the
largest latency improvement for a read-heavy site. TYPO3 pages may contain
sessions, forms, personalized plugins, preview state, or other responses that
must never be shared between users.

History evidence
================

- ``7f42ddc`` (2026-07-10) finalized the deployment profiles and cache policy.
- ``82a53fc`` (2026-07-10) protected cached pages from personalized requests.
- ``fbdddbb`` (2026-07-10) locked the policy before Static File Cache fallback.
- ``ed80e3c`` (2026-07-12) improved public-page delivery.

Decision
========

Publish a shared Vercel cache policy only for cookie-free ``GET`` or ``HEAD``
HTML requests without a query string, Authorization header, ``Set-Cookie``
response, backend path, API path, form, or known personalization signal.

TYPO3 must first mark the response cacheable. The same policy wraps Static File
Cache so a filesystem fallback cannot bypass private-response rules. Durable
sites opt in to a TTL explicitly; the disposable SQLite demo receives a short
automatic TTL for eligible public pages.

Tag every published response ``typo3-public``, invalidate that tag after
publishing, and warm only routes that pass the same eligibility rules.

Consequences
============

**Positive:**

- Eligible public traffic can bypass cold PHP entirely.
- Backend, authenticated, form, and personalized responses stay private.
- One policy governs both TYPO3 and Static File Cache output.

**Negative:**

- Many dynamic pages deliberately remain uncached.
- Editors must invalidate or choose TTLs that match publication
  expectations.
- The policy requires regression tests when request or response rules
  change.

Alternatives considered
=======================

1. **Cache every frontend HTML response:** Rejected because it could leak
   session or personalized content.
2. **Rely only on Static File Cache:** Rejected because local files are
   ephemeral and still require a shared-response safety decision.
3. **Disable edge caching entirely:** Safe but rejected for the evaluation and
   read-heavy use cases where edge delivery is the main performance advantage.
