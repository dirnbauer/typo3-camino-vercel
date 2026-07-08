# Limitations

## Stateless Runtime

Vercel container runtime storage is not a durable TYPO3 volume. Anything written
at runtime can disappear across cold starts, redeploys, or scaling events.

Impact:

- SQLite is demo-only.
- TYPO3 backend login is not stable with SQLite in `/tmp`, because backend
  sessions are database-backed and Vercel can run parallel requests on separate
  instances.
- `fileadmin` is copied to `/tmp` at container start. Uploads are durable only
  when Vercel Blob or S3-compatible object storage is configured.
- generated cache files should be treated as disposable.
- Redis can share TYPO3 cache entries across runtime instances, but it is still
  cache storage only. It does not make SQLite, backend sessions, or uploaded
  files durable.
- logs should go to Vercel logs or an external log drain for retention.

## Cron

No Linux cron daemon is expected to run inside the container. Use Vercel Cron or
an external cron caller.

## Long-Running Jobs

Vercel requests, including Cron-triggered requests to this container, are still
bounded function invocations. They are not suitable for one uninterrupted
multi-hour TYPO3 job.

Current practical limits:

- Hobby: maximum invocation duration is 300 seconds.
- Pro/Enterprise: default duration is 300 seconds and can be raised up to 800
  seconds for normal function workloads.
- Vercel's 1800 second extended duration is beta and documented for selected
  Node.js/Python runtimes. Do not assume it applies to this PHP Apache container
  service.
- Cron invokes an HTTP path and inherits the same invocation limits.
- Hobby Cron can run only once per day with per-hour precision. Pro/Enterprise
  Cron can run once per minute.

That means a Solr full-site index that needs hours is possible only when it is
split into many short, idempotent indexing batches, or when it runs on an
external worker. It is not safe to run it as one Vercel request.

For EXT:solr, use the Index Queue Worker scheduler task with a bounded
"Number of documents to Index" per run. Tune the batch size so one run finishes
comfortably below the Vercel limit, ideally below 60-120 seconds. For large
initial indexing, use a dedicated worker platform close to the database and Solr
endpoint.

See [long-running jobs](long-running-jobs.md) for the decision table and Solr
indexing patterns.

## Database Setup

The automatic first-boot setup is intentionally simple. It works best with
standard Postgres/MySQL connection strings. Some MySQL providers require custom
TLS CA handling during setup; test those providers before promising one-click
production deployment.

## Vercel Blob

Vercel Blob is wired into TYPO3 FAL through the `vercel_blob` driver. Use a
public Blob store for normal frontend images and downloads. Private Blob stores
need a custom delivery/proxy strategy and are not the default for TYPO3 public
assets.

## Redis

Redis is supported for TYPO3 `hash`, `pages`, and `rootline` caches. It needs a
real Redis TCP/TLS endpoint and the PHP Redis extension. REST-only Redis
variables from provider SDKs are not enough for TYPO3's native Redis backend.

Redis does not remove Vercel container cold starts. It can improve warm shared
cache behavior, but it is not an always-on runtime control.

## Solr

EXT:solr is installed as an optional Composer package, but Vercel does not
provide managed Apache Solr for this starter. The repo includes an internal
Vercel Solr container service for demos and experiments, but production search
should use an external managed Solr 10 service. The Vercel Solr container still
needs durable index state and operational protection before it can be considered
production-safe. Large indexing jobs should run as chunked scheduler batches or
on an external worker, not as one long Vercel invocation.

## Marketplace Status

This repository is prepared as a Vercel template candidate, but it has not been
submitted to the Vercel Marketplace.

## TYPO3 Introduction Package

The old `typo3/cms-introduction` package currently does not target TYPO3 14.
This starter uses `typo3/theme-camino`, which is the TYPO3 14 Camino
distribution.
