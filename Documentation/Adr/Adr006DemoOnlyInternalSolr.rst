.. include:: /Includes.rst.txt

.. _adr-006:

=============================================
ADR-006: Keep the internal Solr demo-only
=============================================

Status
======

**Accepted.** Retrospectively recorded on 2026-07-15.

Context
=======

EXT:solr integration needs a real Solr 10 service for meaningful acceptance.
Vercel Services can run that process, but the repository has no persistent
volume for Solr's live Lucene index. TYPO3 and Solr also scale independently,
so a ready TYPO3 instance does not imply a ready search service.

History evidence
================

- ``85a7b51`` (2026-07-08) added EXT:solr and the DDEV workflow.
- ``ab8bd40`` (2026-07-08) added the private Vercel Solr Service.
- ``b7c93d2`` (2026-07-09) placed nginx in front of Solr startup.
- ``d171769`` (2026-07-11) gated readiness on seeded data.
- ``8c3e33d`` (2026-07-12) routed localized search to language-specific cores.

Decision
========

Use the internal Pro-profile Service only as a reproducible demonstration. It
self-seeds six documents into each of five language cores after every start.
nginx returns ``503 starting`` until the cores are available, the update is
committed, and an exact query confirms the expected documents.

TYPO3 uses bounded retries and language-specific core routing. Suggestions for
the fixed demo catalog remain request-free so typing does not activate Solr.

Production search must use managed Solr 10 or always-on infrastructure with a
durable volume, backups, monitoring, and access control.

Consequences
============

**Positive:**

- The repository proves real multilingual EXT:solr integration.
- Readiness cannot produce a false successful empty search.
- The Service remains private behind the application binding.

**Negative:**

- Every new internal instance rebuilds an ephemeral demonstration index.
- A cold search may activate both services.
- Demo indexing behavior is intentionally different from production
  indexing.

Alternatives considered
=======================

1. **Store Lucene files in Vercel Blob:** Rejected because object storage is not
   a mounted low-latency filesystem for a live index.
2. **Call the internal Service production-ready:** Rejected because the index is
   ephemeral and no minimum residency is guaranteed.
3. **Mock Solr:** Rejected because it would not validate EXT:solr,
   configuration, core routing, readiness, or actual query behavior.
