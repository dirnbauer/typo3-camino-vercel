.. include:: /Includes.rst.txt

.. _adr-010:

=========================================
ADR-010: Store processed images durably
=========================================

Status
======

**Accepted.** Recorded on 2026-07-19.

Context
=======

Storage uid 1 keeps the committed Camino seed assets on the local driver. Its
processed derivatives were written to ``fileadmin/_processed_``, which
:ref:`ADR-001 <adr-001>` routes into per-instance ``/tmp``. The
``sys_file_processedfile`` rows and the cached HTML referencing those files
live in durable stores: the SQL database, the shared Redis page cache, and
the Vercel edge cache. After instance replacement the references outlived the
files: hero image variants returned 404, and each miss fell through nginx
``try_files`` into a full TYPO3 render. Only the derivatives baked into the
image survived boot; the responsive variants generated at runtime did not.

History evidence
================

- ``d0c844d`` (2026-07-05) seeded baked processed Camino images.
- ``5ff0007`` (2026-07-09) routed runtime writes into per-instance ``/tmp``.
- ``7f42ddc`` (2026-07-10) enabled tag-based edge caching of public HTML.
- 2026-07-18: production runtime logs recorded repeated 404 responses for
  ``/fileadmin/_processed_/*.webp`` hero variants after instance
  replacement, each triggering a full TYPO3 render.
- ``616fb44`` (2026-07-19) pointed local processing folders at object
  storage and purged stale processed-file records.

Decision
========

When object storage is enabled, processed derivatives of every storage live
on the durable object storage. The boot script points the
``processingfolder`` of each local-driver storage at a combined identifier on
the object storage (default ``2:/_processed_local_/``), purges that storage's
stale ``sys_file_processedfile`` rows exactly once when the folder changes,
pre-creates the folder during boot verification, and remains a cheap no-op on
unchanged boots. ``TYPO3_LOCAL_STORAGE_PROCESSING_FOLDER`` overrides the
target; ``local`` reverts to TYPO3's default local folder and ``unmanaged``
leaves the rows untouched. The baked seed derivatives remain in the image for
first renders and previously cached HTML.

Consequences
============

**Positive:**

- Derivative URLs stay valid across instance replacement, redeploys, and
  scale-out; cached HTML can no longer reference vanished files.
- The 404-to-full-render penalty disappears for processed images.
- One boot script owns all durable storage rows, including the one-time
  cleanup of records that would otherwise fail regeneration.

**Negative:**

- Image processing performs remote writes; the first render of a new variant
  is slower than a local write.
- Edge-cached HTML that predates the switch must be invalidated once.
- Reverting to local processing needs the documented keyword and another
  cache purge.

Alternatives considered
=======================

1. **Bake every derivative into the image:** Rejected because sizes, crops,
   and editorial changes make the set unbounded and build-coupled.
2. **Shorten edge TTLs only:** Rejected because durable database rows and the
   shared page cache still outlive instances between purges.
3. **Check file existence at render time:** Rejected because a remote probe
   per image adds latency and cannot heal already-cached HTML.
4. **Accept the 404s:** Rejected because every miss triggers a full TYPO3
   render and breaks hero images until caches expire.
