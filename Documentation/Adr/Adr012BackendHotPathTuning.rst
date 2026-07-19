.. include:: /Includes.rst.txt

.. _adr-012:

===================================================
ADR-012: Backend and shared-service hot-path tuning
===================================================

Status
======

**Accepted.** Recorded on 2026-07-19 after a second audit focused on the
backend request path and every Redis and Blob setting. Two parallel audits
produced 27 findings; each actionable claim was adversarially re-verified
against the code and the vendor sources before implementation (13 confirmed,
2 refuted, 12 keep-as-is confirmations).

Context
=======

The frontend is fast because anonymous HTML is served from the Vercel edge
cache without waking the container. The backend never has that option: every
response is ``no-store``, so every editor click rides the container and pays
its fixed per-request costs in full. Those costs were larger than they
needed to be. Backend user sessions lived in the ``be_sessions`` SQL table,
so each click made session round trips to the remote database. Network
database connections were re-established per request against the pooler. The
three Redis cache backends each opened their own connection with a fresh
``AUTH`` and ``SELECT``, six handshake commands per request. The Blob client
opened a new TLS connection to the Blob API on the first call of every
request, with no connect timeout and a 100-continue stall on uploads. Two
correctness gaps compounded the latency: deploy-scoped page-cache prefixes
accumulated forever because nothing deleted a previous deployment's keys,
and :ref:`ADR-010 <adr-010>` derivatives under ``_processed_local_`` were
served with the one-hour cache policy instead of the intended one year.

History evidence
================

- ``b079751`` (2026-07-19) moved backend sessions to Redis, made network
  database connections persistent per FPM worker, and tightened the Pro
  warmup cron to every minute.
- ``a10588f`` (2026-07-19) shared one hardened Redis connection across the
  three caches, hardened the backend session backend with bounded timeouts
  and a TTL backstop, added deploy-prefix pruning and a connection-count
  gauge to the warmup probe, disabled the duplicate warning file writer,
  fixed the processed-image cache header, and gave the Blob client
  cross-request TLS reuse, connect timeouts, and no 100-continue stall.
- 2026-07-19: eviction was disabled on the production Upstash resource so
  the store refuses writes at quota rather than silently evicting cache tag
  sets and session keys.

Decision
========

Backend requests can never be cached, so their fixed costs move to fast
paths or persistent state:

1. Backend user sessions live in Redis through a hardened backend that
   pconnects with bounded connect and read timeouts, reconnect retries, and
   TCP keepalive, writes keys in their own namespace, and applies a TTL
   backstop so keys expire even where the provider runs without eviction.
   ``TYPO3_REDIS_SESSIONS=0`` reverts to the database.
2. Network database drivers keep one persistent connection per FPM worker.
   ``TYPO3_DB_PERSISTENT_CONNECTION=0`` reverts to per-request connections.
3. The cache backends share one connection per host, port, and database, so
   a warm request pays two handshake commands rather than six.
4. The Blob client reuses TLS sessions and connections across requests via
   the PHP 8.5 persistent curl share handle, sends upload bodies without a
   100-continue round trip, bounds connects, and speaks HTTP/2 where
   available. Processed derivatives in every processing folder receive the
   long-lived cache policy.
5. The warmup cron prunes the previous deployment's page-cache prefix and
   reports the connection count. Production Redis runs with eviction
   disabled and quota monitoring, because the cache and session data now
   depend on keys not vanishing underneath a live deployment.

Consequences
============

**Positive:**

- The backend hot path loses its per-request session, connection, and
  handshake round trips; the Blob path loses a TLS handshake per request and
  an upload stall.
- Repeat visitors keep processed images for a year instead of an hour.
- Page-cache prefixes no longer grow without bound across deployments.

**Negative:**

- With eviction disabled, the store refuses writes at quota instead of
  evicting; quota headroom must be monitored (the warmup probe reports it).
- Persistent sockets depend on phpredis retry semantics and the PHP 8.5
  curl share; both carry documented kill switches and env guards.
- More runtime state lives in Redis, so a Redis outage now also affects
  backend sessions, not only caches.

Alternatives considered
=======================

1. **Keep sessions and connections per-request:** Rejected; the audit
   measured the round trips on every uncached backend request, and the
   hardened backends remove the stale-socket failure that once justified
   per-request connects.
2. **Leave eviction enabled to avoid quota errors:** Rejected; Upstash's
   optimistic-volatile eviction can silently drop cache tag sets and session
   keys, which surfaces as stale content and random logouts — a worse
   failure than an explicit write refusal.
3. **Expire cache tag keys instead of pruning by deployment:** Rejected;
   expiring shared tag sets can break invalidation-by-tag for entries still
   live within a deployment.
4. **Refuted audit claims** are recorded so they are not re-litigated: a
   read-timeout floor does not multiply into a multi-second stall under the
   configured retries, and the backend session wiring needed no option-name
   changes beyond the hardened subclass. Twelve settings were audited and
   deliberately left unchanged (serializer choice, compression codec,
   per-cache option sizing, the API endpoints, and the stat-cache sizing
   among them).
