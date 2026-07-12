# Limitations

## Disposable Runtime Filesystem

This project treats the Vercel container runtime filesystem as disposable, not
as a durable TYPO3 volume. Anything written there can disappear across instance
replacement, redeployment, or scaling.

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

- Cron invokes an HTTP Function and inherits the applicable invocation limits.
- Hobby Cron can run at most once per day and its delivery time can vary
  substantially; Pro/Enterprise schedules can run once per minute.
- Function duration and resource limits depend on the current runtime, plan,
  and configuration. Check the live Vercel limits before selecting a batch size.
- Do not assume that a runtime-specific extended-duration feature applies to
  this Dockerfile-backed PHP Function.

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

## Outgoing Mail

The container ships no local mail transfer agent, so TYPO3's default `sendmail`
transport cannot deliver mail on Vercel. Any site that sends mail (form
submissions, notifications, backend password resets) must be pointed at an
external SMTP relay:

```dotenv
TYPO3_MAIL_TRANSPORT=smtp
TYPO3_MAIL_SMTP_SERVER=smtp.example.com:587
TYPO3_MAIL_SMTP_ENCRYPT=tls
TYPO3_MAIL_SMTP_USERNAME=<smtp-user>
TYPO3_MAIL_SMTP_PASSWORD=<smtp-password>
```

Without an SMTP configuration, frontend code may appear to submit successfully
while mail is not delivered. Test delivery and monitor provider failures.

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

Normal TYPO3 uploads pass through the Function request body and are limited to
4 MB by this image, below Vercel's 4.5 MB total request limit. Blob can hold
larger objects through the included **Media > Large upload** flow. It uploads
browser-to-Blob with a short-lived scoped token, defaults to 5 GiB, and is
available only for the `vercel_blob` storage.

The direct path does not stream file contents through TYPO3, so it cannot do
server-side magic-byte or malware inspection before storage. Active web formats
are blocked. Image processing can still hit Vercel temporary-disk or invocation
limits when TYPO3 later downloads and transforms a very large original.

Blob and S3/R2 are object storage. Neither can be mounted as a Solr Lucene
volume or used as TYPO3's SQL database.

## Redis

Redis is supported for TYPO3 `hash`, `pages`, and `rootline` caches. It needs a
real Redis TCP/TLS endpoint and the PHP Redis extension. REST-only Redis
variables from provider SDKs are not enough for TYPO3's native Redis backend.

Redis does not remove Vercel container cold starts. It can improve warm shared
cache behavior, but it is not an always-on runtime control.

## Cold Starts

The Pro profile calls TYPO3 frontend/backend and Solr every three minutes, and
the smaller images reduce activation work. This often keeps a selected TYPO3
instance active, but it does not reserve a minimum instance and cannot guarantee
zero cold starts during deploys, scale-out, eviction, or delayed cron. Hobby
cannot run the frequent cron.

Production validation observed one `/typo3/` request at 8.85 seconds after the
protected warmer had already succeeded; immediate repeats were about 0.2
seconds. The warmer reduces the common idle case, but Vercel can still select or
create another instance. Public pages can use edge caching. The private backend
needs a platform minimum-instance guarantee or an always-on host for a hard
latency SLO.

The limitation is stronger for the separate Solr service. After roughly 13
hours with the three-minute schedule registered, three consecutive warm-ups
still spent 14.553-16.989 seconds in Solr startup. Cron is therefore not a
reliable Solr residency control in this deployment.

## Solr

EXT:solr is installed as a Composer package, but no Vercel-managed Apache Solr
product was documented in the sources reviewed for this starter. The repo
includes an internal Vercel Solr container Service for demos and experiments,
but production search
should use an external managed Solr 10 service. The Vercel Solr container still
needs durable index state and operational protection before it can be considered
production-safe. Large indexing jobs should run as chunked scheduler batches or
on an external worker, not as one long Vercel invocation.

The internal demo Solr service has an extra Vercel-specific cold-start problem:
the internal service gateway can return HTTP `500 Starting...` or a temporary
`502/503/504` before the Solr container has received the request. The repo works
around that for demos by routing TYPO3/EXT:solr through a loopback-only app
proxy, reusing one service connection, and waiting within a bounded 20-25
second window. This turns most cold failures into one slow successful request;
it does not make Solr always-on or durable and is not a replacement for managed
Solr.

## Marketplace Status

This repository is prepared as a Vercel template candidate, but it has not been
submitted to the Vercel Marketplace.

## TYPO3 Introduction Package

This starter uses `typo3/theme-camino`, the Camino distribution selected for
this TYPO3 14 integration. It does not claim to be the TYPO3 Introduction
Package or an official Vercel package.

## Sources

- [Dockerfile deployment behavior](https://vercel.com/kb/guide/docker)
- [Vercel Function limits](https://vercel.com/docs/functions/limitations)
- [Cron usage and plan limits](https://vercel.com/docs/cron-jobs/usage-and-pricing)
- [Vercel Blob limits and pricing](https://vercel.com/docs/vercel-blob/usage-and-pricing)
