.. include:: /Includes.rst.txt

.. _adr-011:

=====================================
ADR-011: Audited request-path tuning
=====================================

Status
======

**Accepted.** Recorded on 2026-07-19 after a full performance audit: five
parallel audits over runtime settings, TYPO3 configuration, the Visual
Editor render path, boot scripts, and the storage packages produced 57
findings; every high-impact claim was adversarially re-verified against the
code before implementation (8 confirmed, 4 refuted).

Context
=======

Warm requests paid recurring costs that no cache carried: every request
opened up to three fresh TCP+TLS connections to the managed Redis (hash,
pages, and rootline backends connect separately, with no read timeout and
no retry), and every image on an uncached render triggered up to three
synchronous HTTPS existence probes against the Blob API — the Visual
Editor renders uncached by design, so editors paid this per image, per
language, per reload. Cold starts repeated work the image could carry:
only two of eleven warmable cache families were baked, and boot walked
``/tmp/typo3`` recursively up to three times to fix permissions. The two
FAL drivers had grown ~48 byte-identical methods, so every storage fix
landed twice, and boot/API scripts re-implemented shared helpers (auth,
PDO, Solr URLs, truthy parsing) up to seven times.

History evidence
================

- ``b7085a8`` (2026-07-19) tuned nginx/FPM/PHP settings, switched to a
  Unix FPM socket, baked all warmable caches and file modes into the
  image, hardened persistent Redis connections, and removed
  ``cms-adminpanel`` (SQL logging on backend-authenticated renders) and
  ``cms-indexed-search`` (redundant next to Solr).
- ``8aac5fc`` (2026-07-19) merged the shared object-storage driver trait
  with a request-scoped plus 15-minute positive-only existence cache
  (net −422 lines).
- ``440061e`` and ``da6d3c8`` (2026-07-19) merged the package and script
  consolidations (net −331 lines, one implementation each for auth
  gating, PDO setup, Solr URL resolution, purges, and CLI runners).
- A 2026-07-19 benchmark rejected OPcache file-cache baking (no gain,
  +400 MB image); it stays rejected.

Decision
========

Recurring platform round trips must be carried by a cache or a persistent
connection, and shared behavior must exist once:

1. Redis cache backends connect persistently through a hardened backend
   (reconnect retries, bounded read timeout, TCP keepalive);
   ``TYPO3_REDIS_PERSISTENT_CONNECTION=0`` remains the kill switch.
2. Object-storage existence and metadata checks flow through the shared
   driver core's two-layer cache: a per-request map plus a 15-minute
   positive-only entry in the ``hash`` cache, invalidated by every
   mutating driver operation.
3. Behavior shared by the Blob and S3 drivers lives in one trait in
   ``packages/typo3-object-storage-core``; boot and API scripts consume
   the shared helpers in ``scripts/typo3-env.php`` instead of local
   copies.
4. The image bakes every cache family the build can warm and final file
   modes; boot restores them and fixes only top-level directories.
5. The default package set contains only extensions the demo exercises;
   removed extensions return via ``composer require``, not by default.

Consequences
============

**Positive:**

- Warm requests no longer pay per-request TLS handshakes or remote
  existence probes; editor renders collapse many Blob round trips into
  at most one per image per 15 minutes.
- Driver and script fixes land once; ~800 net lines and one diagnostic
  endpoint removed.
- Cold boots skip recursive permission walks and start from eleven baked
  cache families; wrong-connection schema-cache entries cannot match
  production because TYPO3 hashes connection parameters into the
  identifier.

**Negative:**

- A blob deleted outside TYPO3 can be reported as existing for up to 15
  minutes.
- Persistent sockets depend on phpredis retry semantics; the deep health
  check must confirm recovery after idle-socket closes.
- Demos that expect the admin panel or indexed search must re-add them.

Alternatives considered
=======================

1. **Keep connections per-request:** Rejected; the audit measured the
   handshake tax on every warm request, and retries plus read timeout
   remove the stale-socket failure that once justified it.
2. **Move more caches to Redis:** Rejected; the audit confirmed local
   disk is correct for hot read-heavy caches on a dedicated instance.
3. **OPcache file cache / FrankenPHP:** Re-rejected on prior benchmarks.
4. **Serve StaticFileCache files from nginx or remove the extension:**
   Deferred; the operator chose to keep it, and the edge cache remains
   the anonymous delivery path.
5. **Consolidate boot into one PHP process:** Verified worthwhile
   (150-500 ms per cold start) but boot-critical; deferred to its own
   change with its own deploy.
6. **Refuted audit claims** (gzip dead behind the proxy; uncached editor
   renders as a defect) are recorded here so they are not re-litigated:
   gzip works as configured, and editMode bypasses caches by design.
