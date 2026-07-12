# TYPO3 on Vercel: Product Manager Summary

## Review Status

This document was fully re-audited on 2026-07-12 against:

- repository revision `46ae0b9c96b6`
- the current production deployment and Vercel CLI output
- the repository test suite
- current Vercel documentation linked under [Sources](#sources)

Current production observations are separated from historical experiments.
Pricing, quotas, Beta status, and platform limits can change; the linked Vercel
pages remain authoritative.

## Executive Verdict

**TYPO3 works on Vercel, but not as an all-in-one traditional server.**

The successful architecture is:

- Vercel runs the disposable PHP/nginx compute layer.
- PostgreSQL or MySQL stores durable TYPO3 data and backend sessions.
- Vercel Blob or S3-compatible object storage stores durable files through
  TYPO3 FAL.
- Redis optionally shares selected TYPO3 caches across instances.
- External durable Solr is used when production search is required.
- Vercel Cron invokes short, protected maintenance endpoints; it does not run a
  permanent Linux daemon.

Public, anonymous pages are fast when served from Vercel's edge cache. Warm
TYPO3 backend and uncached requests are also fast enough for normal editorial
work. The unresolved platform constraint is predictable first-request latency:
Vercel's Dockerfile deployment guide says production instances scale in after
five idle minutes. The current platform documentation does not expose a
minimum-instance setting for this deployment path.

The repository declares both application and Solr runtimes as Vercel Services
with `runtime: "container"`. Vercel currently labels Services as Beta, which is
an additional production-adoption consideration.

Therefore:

| Use case | Verdict |
|---|---|
| Disposable one-click evaluation | Works, with temporary SQLite and reduced features |
| Durable demo or prototype | Works with external SQL and Blob/S3 |
| Read-heavy production site | Viable after load testing when public HTML is edge-cached and all state is external |
| Editorial backend with a strict first-request SLA | Not guaranteed by this architecture |
| High-criticality or highly dynamic site | Use always-on TYPO3 compute unless occasional cold activation is acceptable |
| Production Solr inside the current Vercel demo service | Not durable; use external managed or always-on Solr |

This is a community integration, not an official TYPO3 package. It uses the
official `typo3/theme-camino` distribution.

## What Was Built

| Concern | Current implementation |
|---|---|
| Application | TYPO3 14.3.4, PHP 8.4 FPM, nginx, Alpine Linux |
| Deployment | Dockerfile-backed Vercel container Services; private Solr Service only in the Pro/demo profile |
| Region | `fra1` for the public project |
| Database | External PostgreSQL/MySQL through `DATABASE_URL`; temporary SQLite only for one-click testing |
| Files | Custom Vercel Blob FAL driver plus an independent S3-compatible FAL driver |
| Blob authentication | Vercel OIDC when available; read/write token fallback |
| Large uploads | Authenticated direct browser-to-Blob upload, multipart above 100 MB, 5 GiB project default |
| Shared cache | Optional native TYPO3 Redis caches over TCP/TLS |
| Public cache | Cookie- and authorization-aware Vercel edge policy with tag invalidation and route warming |
| Search | EXT:solr 14.0.0-beta3 and Solr 10 demo service; external Solr recommended for production |
| Editing | Visual Editor and strict German, Spanish, Simplified Chinese, and Hungarian translations |
| Jobs | Protected warm-up and TYPO3 Scheduler endpoints invoked by Vercel Cron in the Pro profile |
| Health | Public shallow health plus protected DB, Redis, Blob, Solr, and temporary-filesystem probes |

The one-click and professional profiles are intentionally different.

| Profile | Included | Excluded or temporary |
|---|---|---|
| One-click test | TYPO3 app Service, Camino content, temporary SQLite, optional Deploy Button-created Blob store, five-minute eligible-page edge cache | No Solr service, no cron, no durable database |
| Professional | Durable SQL, Blob/S3, optional Redis, protected cron, explicit cache policy, external production Solr | No promise of a permanently resident TYPO3 instance |

The professional profile can support larger read-heavy sites because public
delivery can bypass PHP. It still requires site-specific load tests, database
sizing, cache safety review, monitoring, backups, and a recovery plan.

## Current Acceptance Result

### Repository And Deployment

Verified on 2026-07-12:

| Check | Result |
|---|---|
| Git revision | `46ae0b9c96b6` |
| Production deployment | `dpl_G6QFPrntxA3vjH4Mka9nPHvahYQ7`, Ready in `fra1` |
| Application artifact | 172.56 MB reported by `vercel inspect` |
| Solr service artifact | 277.65 MB reported by `vercel inspect` |
| Runtime | PHP 8.4.23; `/api/health.php` returned HTTP 200 |
| Pro cron registration | Warm-up every three minutes; Scheduler every 15 minutes |
| Automated tests | 55 tests, 2,315 assertions, all passing |

The image artifact sizes above are Vercel deployment observations. The larger
local Docker sizes in historical tests are uncompressed and are not directly
comparable.

### Live Route Check

All checked routes returned HTTP 200. A direct-request pass during the final
audit was:

| Route | TTFB | Total |
|---|---:|---:|
| `/` | 0.243s | 0.258s |
| `/typo3/`, first request in that pass | 6.251s | 6.269s |
| `/search`, without a query | 0.977s | 0.995s |
| `/visual-editor` | 0.124s | 0.141s |
| German route comparison | 0.119s | 0.144s |
| Spanish language root | 0.210s | 0.234s |
| Chinese language root | 0.165s | 0.183s |
| Hungarian language root | 0.167s | 0.185s |

A separate 12-request hard-reload sample of the German route had a 0.131s
median, 0.151s average, and 0.221s maximum. Earlier browser verification
measured 12 fresh mobile page loads between 0.34s and 0.71s.

The 6.251s backend result was a current cold-path observation, not omitted as an
outlier. Its next ten requests had 0.213-0.492s TTFB. This directly demonstrates
the remaining cold/warm split for an uncached backend path.

These are production samples, not a contractual SLA. They describe the warmed
deployment and cached public path at that time.

### Search Check

The production query `/search?tx_solr[q]=*` returned all six demo documents on
its first audited request, but the independently idled Solr service made that
request take 16.495s. Its immediate repeat returned the same six results in
0.459s. Autocomplete uses a request-free embedded catalog for the fixed
six-page demo. External production Solr uses the live EXT:solr suggest endpoint.

Warm local Solr benchmarks against the same small index were:

| Operation | Runs | Median | p95 or maximum |
|---|---:|---:|---:|
| Direct query | 50 | 3.1 ms | 7.0 ms p95 |
| Update plus hard commit | 20 | 34.3 ms | 38.1 ms p95; 60.5 ms max |
| TYPO3 six-page index rebuild | 10 | 1.11s | 1.55s max |
| Complete TYPO3 search page | 30 | 27.2 ms | 28.8 ms p95; 106 ms max |

The query and small-batch indexing implementation is fast enough. Service
activation and durable index storage, not normal query speed, are the limiting
factors.

## The Cold-Start Finding

### Historical Baseline

The original production deployment produced approximately:

| Request | Cold or first request | Warm behavior |
|---|---:|---:|
| Frontend `/` | about 12.1s | about 0.09-0.22s |
| Backend `/typo3/` | about 11.5s | about 0.19-0.49s |
| Login preflight | cold outliers occurred | about 0.16-0.25s |

This proved that normal PHP execution was not the main problem. The large gap
was container activation plus first framework bootstrap.

### Changes And Their Effect

| Change | Measured or observed effect | Verdict |
|---|---|---|
| Alpine nginx/PHP-FPM application image | Local uncompressed image fell from about 950 MB to about 465 MB | Important build/runtime hygiene, but did not remove the production activation floor |
| Slim Solr image | Local uncompressed image fell from about 843 MB to about 589 MB; local readiness median became 2.48s | Helpful locally; Vercel service activation remained much slower |
| Targeted TYPO3 release cache | Local first backend framework work fell from 1.871s to 0.383s median; frontend work fell from 9.665s to 7.274s | Useful after process start; not a platform cold-start solution |
| Redis | Improved shared warm-cache behavior | Does not start a container or reserve an instance |
| Pro performance CPU and `fra1` | Improves active PHP work and can reduce distance to European stateful services | Does not prevent scale-to-zero |
| Three-minute protected warmer | Reduces ordinary idle exposure for TYPO3 | Best effort only; cron does not reserve an instance |
| Anonymous edge caching | Repeat public requests bypass PHP entirely | Most effective public-frontend mitigation |
| JIT and compile-all OPcache experiments | No useful workload improvement; compile-all added excessive image weight | Rejected |

The most important negative result was that cutting the application image by
roughly half did not materially move an observed production backend cold request
away from approximately 12 seconds. Image reduction was worthwhile, but image
size was not the dominant end-to-end variable in that test.

### Current Performance Contract

The repository targets:

- warmed, eligible public page TTFB at or below 1 second
- warmed public browser load at or below 2 seconds
- warm backend interactions normally below 1 second
- graceful search completion instead of an empty result during demo Solr start

The current measurements meet those targets. The repository cannot guarantee
them for an uncached request after scale-in, a new instance created by scale-out,
a deployment, an eviction, or a delayed cron invocation. A hard all-request
latency guarantee requires minimum resident instances or an always-on origin.

## Why Edge Caching Matters

Eligible anonymous HTML receives a Vercel edge-cache policy. The middleware
excludes backend and API paths, query strings, cookies, authorization, private
or no-store responses, `Set-Cookie`, and unsafe `Vary` behavior. It also wraps
the Static File Cache fallback so private requests cannot bypass the policy.

Production verification demonstrated this isolation:

- anonymous request: edge `HIT` after priming
- cookie request: separate `MISS` with `private, no-store`
- authorization request: `BYPASS` with `private, no-store`
- later anonymous request: remained a `HIT`

Cache tags allow publication to invalidate `typo3-public`; the deployment and
invalidation scripts then warm known localized routes twice. This gives public
pages a practical fast-first-view path after a controlled deployment or publish
operation. It does not accelerate `/typo3/`, personalized pages, query-string
search, or a genuinely missing/evicted cache entry.

The one-click SQLite profile defaults to a five-minute TTL. Durable sites must
choose their own TTL because immediate publication, personalization, forms, and
legal content can make shared caching inappropriate.

## Durable State

### Database

A durable SQL database was the largest reliability improvement. It fixed
backend login persistence, content, extension schema state, and consistency
between instances. Redis cannot replace SQL.

Temporary SQLite makes the Deploy Button easy to evaluate, but records and
sessions may disappear or differ between instances. It must not be presented as
a small production database.

### Files And Large Uploads

Vercel Blob and S3-compatible storage are connected through TYPO3 FAL. Public
file delivery uses the object-storage URL instead of streaming file bodies
through PHP. A protected production probe has verified Blob put, read, and
delete behavior.

The normal TYPO3 upload path stays below 4 MB because Vercel Functions document
a 4.5 MB request or response body limit. The custom large-upload module avoids
that path:

1. TYPO3 authorizes the editor and validates storage, folder, name, type, and
   configured size.
2. The application obtains a short-lived Blob upload payload scoped to the
   selected pathname.
3. The browser uploads directly to Blob; multipart is used above 100 MB.
4. TYPO3 verifies remote size and type, then registers the object in FAL.
5. Active executable web formats remain blocked by default.

The project default is 5 GiB. Vercel Blob currently documents a 5 TB object
limit, but operators should set a lower limit appropriate to cost, processing,
and editorial policy. Direct upload avoids the Function body limit; later image
processing can still consume temporary disk, memory, and execution time.

Blob is object storage, not a POSIX volume, SQL database, or Solr Lucene volume.

### Solr

The private Solr 10 Vercel Service proves that a real JVM service, TYPO3
EXT:solr connectivity, startup readiness, demo indexing, and search can run on
Vercel. It deliberately stores its index in `/tmp` and recreates six demo
documents, so it is not durable production search.

The service has a separate cold-start boundary. Historical scheduled checks
after about 13 hours still measured 14.553-16.989 seconds of Solr startup even
with a three-minute warmer. The corrected service returns `503 starting` until
all six documents are committed and counted. A bounded client waits for that
readiness state, which fixes empty first results but does not make startup fast.

Production Solr should use a managed Solr 10 endpoint or always-on infrastructure
with a durable volume, backups, monitoring, access control, and a tested restore
procedure. The current Vercel documentation reviewed for this audit does not
describe a persistent mounted volume for Services. Vercel Blob cannot safely
replace Lucene's mutable filesystem, and Container Registry stores immutable
images rather than live indexes.

## Background Work

The Pro profile registers:

- a protected warm-up every three minutes
- TYPO3 Scheduler every 15 minutes

Vercel Cron invokes a Function. It is not a Linux cron daemon, worker
reservation, or timing guarantee. Current documentation allows frequent Pro and
Enterprise schedules; Hobby schedules run at most daily and can have broad
timing variation.

Long jobs should be split into idempotent, restartable batches. A complete
multi-hour Solr reindex should run on a dedicated worker or the external Solr
hosting environment, not as one web request. See
[Long-running jobs](long-running-jobs.md).

## What Helped Most

1. External SQL made TYPO3 data and sessions durable.
2. Vercel Blob/S3 FAL made originals and generated derivatives durable.
3. Edge caching removed PHP and cold activation from eligible public hits.
4. Removing installation and migrations from container start reduced both work
   and cross-instance write races.
5. Targeted release caches reduced TYPO3 work after PHP starts.
6. Protected health probes exposed database, Redis, Blob, and Solr failures
   before they became editorial failures.

## What Helped Less Than Expected

- Cutting image size by roughly half did not remove the observed Vercel
  activation floor.
- Redis improved shared cache behavior but did not solve cold starts.
- More CPU improved active execution but did not keep an instance resident.
- Region pinning reduced avoidable network distance but not startup orchestration.
- PHP JIT did not improve the measured TYPO3 workload.
- Compile-all OPcache increased image size too much for its small benefit.
- Reusing an HTTP client did not guarantee affinity to one newly starting Solr
  instance; an explicit data-readiness gate was still required.
- The three-minute cron reduced TYPO3 idle exposure but did not reliably keep
  the separate Solr service resident.
- Vercel build-cache reuse was inconsistent during the recorded deployment
  window, so small changes sometimes rebuilt both images.

These negative results are product findings, not unfinished implementation.
They identify limits that application code cannot remove reliably.

## Product Opportunities For Vercel

### Highest Impact

- Offer minimum warm instances or a configurable production idle timeout for
  paid Dockerfile-backed container Services.
- Report image activation, process-ready, framework bootstrap, and first-byte
  time as separate observability phases.
- Offer a durable mounted volume for stateful Services, with documented backup,
  restore, performance, and failover behavior.
- Offer or integrate managed search suitable for Solr-compatible CMS workloads.

### CMS Onboarding

- Provision Blob and a Marketplace SQL database in one guided Deploy Button
  flow, then validate that credentials are non-empty and reachable.
- Publish a generic direct-to-Blob recipe for PHP and other non-Node backends.
- Document OIDC-based Blob REST use outside the JavaScript SDK.
- Make the difference between Function compute, private Services, immutable
  Container Registry images, object storage, and persistent volumes explicit.
- Let a project select separate one-click and production deployment profiles
  without staging a different canonical `vercel.json`.

### Operations

- Document whether service bindings provide any instance affinity during
  startup retries; this experiment observed several connections despite client
  reuse.
- Add an explicit post-deployment hook that can invalidate tagged HTML and warm
  selected CMS routes after the deployment is ready.
- Show cron delay, selected instance, cold-start status, and service activation
  in one trace.
- Surface Function body limits in Blob onboarding and point server-rendered
  applications to direct client uploads.

## Operator Checklist

Before calling a deployment production-ready:

- [ ] Replace SQLite with durable regional PostgreSQL or MySQL.
- [ ] Keep a stable TYPO3 encryption key and strong backend credentials.
- [ ] Put all editor files in Vercel Blob or S3-compatible FAL storage.
- [ ] Run the protected DB/Redis/Blob/Solr write probe.
- [ ] Configure external SMTP; the image contains no local mail server.
- [ ] Decide whether Redis is justified and test the deployed TCP/TLS connection.
- [ ] Use external durable Solr for production search.
- [ ] Run large indexing as bounded batches or on a dedicated worker.
- [ ] Review edge-cache safety for forms, cookies, personalization, publication,
      and legal pages.
- [ ] Deploy the intended Pro profile and verify the actual cron registrations.
- [ ] Load-test public pages, uncached pages, backend workflows, uploads, image
      processing, search, and cold activation.
- [ ] Configure backups, restore tests, monitoring, alerts, retention, and
      incident ownership for every external state service.
- [ ] Complete a GDPR assessment covering regions, processors, contracts,
      retention, access, exports, deletion, logs, and future vendor changes.
- [ ] Recheck current Vercel limits and pricing before launch and periodically
      afterward.

## Final Product Conclusion

The concept is technically valid and the current demo works. The accurate
product positioning is **TYPO3 compute and public delivery on Vercel with
external durable state**, not **a complete traditional TYPO3 server inside one
Vercel container**.

For demos and prototypes, the one-click profile is useful and can remain within
free allowances, but SQLite content is disposable. For durable read-heavy
sites, external SQL, Blob/S3, protected operations, and edge caching form a
credible architecture. For strict backend/uncached first-request latency,
multi-hour workers, or durable in-platform Solr, the current Vercel product
still needs minimum instances, persistent service volumes, or an external
always-on component.

## Sources

Vercel platform claims in this document were checked on 2026-07-12:

- [Dockerfile deployments and five-minute production scale-in](https://vercel.com/kb/guide/docker)
- [Dockerfile support announcement](https://vercel.com/changelog/bring-your-dockerfile-to-vercel-functions)
- [Vercel Services](https://vercel.com/kb/guide/vercel-services)
- [Vercel Function limits](https://vercel.com/docs/functions/limitations)
- [Cron usage and plan limits](https://vercel.com/docs/cron-jobs/usage-and-pricing)
- [Fluid compute](https://vercel.com/docs/fluid-compute)
- [Vercel Blob usage and pricing](https://vercel.com/docs/vercel-blob/usage-and-pricing)
- [Vercel Blob OIDC authentication](https://vercel.com/changelog/vercel-blob-now-supports-oidc-authentication)
- [Direct uploads around the Function body limit](https://vercel.com/kb/guide/how-to-bypass-vercel-body-size-limit-serverless-functions)

Repository implementation and detailed measurements:

- [Deployment profiles](deployment-profiles.md)
- [Performance and cold starts](performance.md)
- [Vercel Blob FAL driver](vercel-blob-fal-driver.md)
- [Solr](solr.md)
- [Long-running jobs](long-running-jobs.md)
- [Limitations](limitations.md)
- [Final cleanup audit](final-cleanup.md)
