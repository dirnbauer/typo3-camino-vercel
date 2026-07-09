# Vercel Product Manager Summary

## Executive Verdict

TYPO3 14.3 and the Camino distribution run correctly as a Vercel Container
Image. A normal warm application request is fast. The hard part is not PHP
compatibility; it is adapting a stateful CMS and a JVM search engine to a
stateless, scale-to-zero Functions model.

The current repository is viable for:

- demos, evaluation environments, previews, and prototypes
- read-heavy low/medium traffic sites with an external database and Blob storage
- editorial sites whose team accepts a Pro warm-up strategy and monitors it

It is not a blanket recommendation for high-criticality TYPO3 production.
Those projects need an explicit decision about occasional container activation,
external SQL, durable files, background work, and external managed Solr.

The most visible problem was a roughly 10 to 12 second response after a period
of inactivity. Warm responses were normally below half a second. The overhaul
therefore focuses on both sides of the problem:

1. make activation cheaper by shrinking and simplifying both images
2. prevent normal production idling with a protected three-minute Pro cron
3. bypass the origin with CDN caching where anonymous page semantics allow it

Vercel currently has no minimum-instance control for this Container Image path,
so this is mitigation rather than an absolute zero-cold-start guarantee.

## Current Architecture

| Concern | Implementation |
|---|---|
| Web runtime | PHP 8.4 FPM + nginx on Alpine Linux |
| CMS | TYPO3 14.3.4 lock, official Camino distribution |
| Compute | Vercel Container Images, Fluid Compute, performance CPU, `fra1` |
| Database | External durable SQL via `DATABASE_URL`; SQLite only for smoke tests |
| Shared cache | Optional Redis over `redis://` or `rediss://` |
| Files | Custom Vercel Blob FAL driver or retained S3-compatible FAL driver |
| File auth | Vercel OIDC first; read/write token compatibility fallback |
| Search | EXT:solr 14 beta + Solr 10 service for demo; external Solr for production |
| Jobs | Protected Vercel Cron endpoints; no daemon inside the image |
| Security | Stable encryption key, trusted-host validation, protected deep probes |
| Health | Shallow public health and authenticated DB/Redis/Blob/Solr write probes |

This is not an official TYPO3 package. It is community integration code using
the official TYPO3 Camino distribution.

## Numbers

### Before The Overhaul

The representative live measurements that triggered this work were:

| Path | Cold or first request | Warm behavior |
|---|---:|---:|
| `/` | about 12.1s | about 0.09-0.22s |
| `/typo3/` | about 11.5s | about 0.19-0.49s |
| backend login preflight | cold outliers possible | about 0.16-0.25s |

A later Redis-enabled sample showed warm medians around 0.05s for `/`, 0.13s
for `/typo3/`, and 0.10s for login preflight. These are not laboratory-perfect
comparisons because they came from separate production windows, but the shape
is unambiguous: the warm application was already fast; activation was not.

### Image And Startup Work

| Component | Before | Current candidate | Change |
|---|---:|---:|---:|
| TYPO3 local image size | about 950 MB | about 446 MB | about 53% smaller |
| Solr local image size | about 843 MB | about 589 MB | about 30% smaller |
| Solr local readiness | 4.13-4.62s | 1.94-3.21s; 2.48s median | roughly 40%+ faster at the median |
| Final local first TYPO3 page | n/a | 4.27s | activation/bootstrap check |
| Final local warm TYPO3 page | n/a | 0.10s | healthy warm path |

Docker reports uncompressed local image sizes. Vercel transfers compressed
layers, so these figures are directional rather than a prediction of the exact
production delay. They nevertheless remove hundreds of megabytes and fewer
services/processes must start.

### Live Production Result

The production deployment provided the most important counter-result:

| Request | Result |
|---|---:|
| First `/typo3/` after deploy | 11.87s total; 11.84s TTFB |
| First full warmer with TYPO3 and Solr cold | 14.91s external; 9.84s internal |
| Immediate second full warmer | 0.93s external; 0.51s internal |

The 53% application image reduction did **not** materially change the roughly
12-second Vercel activation floor. This was the clearest experimental result:
container slimming is useful hygiene, but periodic warming is the effective
user-facing mitigation. The first full warmer also exposed a temporary Solr
`502`; bounded readiness retries were added so one invocation can wait for the
service instead of failing and relying on the next cron run.

The final pass, with those retries and working Upstash Redis, was more
diagnostic:

| Request/check | Final result |
|---|---:|
| Cold full warmer, TYPO3 + Solr | 26.82s external; 21.73s internal; HTTP 200 |
| Immediate full-warmer repeat | 0.70s external; 0.43s internal |
| Warm frontend edge hit, 30 runs | 0.113s median; 0.207s p95 |
| Warm backend, 30 runs | 0.208s median; 0.251s p95; 0.664s max |
| Warm search, 30 runs | 0.351s median; 2.890s p95; 3.622s max |
| Deep DB/Redis/Blob/Solr/filesystem check | 2.06s; all passed |

One backend request still took 8.85 seconds after the warmer had succeeded.
This is the key limitation of the workaround: a scheduled invocation cannot
reserve the instance pool or stop Fluid Compute from selecting/creating a fresh
instance. Public HTML can avoid this with the edge cache; `/typo3/` cannot.

### Search Work

Warm local benchmarks against the same six-page Camino index:

| Operation | Runs | Median | p95 / max |
|---|---:|---:|---:|
| Direct Solr query | 50 | 3.1 ms | 7.0 ms p95 |
| Update plus hard commit | 20 | 34.3 ms | 38.1 ms p95, 60.5 ms max |
| TYPO3 six-page index rebuild | 10 | 1.11s | 1.55s max |
| Full TYPO3 search page | 30 | 27.2 ms | 28.8 ms p95, 106 ms max |

Verdict: query, update, and small-batch indexing speed is sufficient. Solr
activation and index durability are the constraints, not normal query latency.

### File Storage Check

The Blob driver completed a production-authenticated put, read, and delete
probe in roughly two seconds and removed its test object. Public file reads use
the Blob URL rather than streaming the payload through PHP.

## What Helped Most

### 1. A Durable SQL Database

This was the largest reliability improvement. It fixed backend sessions,
content persistence, extension schema state, and cross-instance consistency.
Redis cannot substitute for this database.

### 2. Vercel Blob Through TYPO3 FAL

Blob solved the user-visible file durability problem in an all-Vercel setup.
Because Blob is not S3-compatible, this required a real TYPO3 FAL driver rather
than a configuration switch. The repository retains the S3/R2 driver as an
independent option.

### 3. Removing Work From Container Boot

Database installation, extension setup, password hashing, and Solr page setup
now run only when explicitly requested. Repeating them on every activation was
slow and could produce competing writes across instances.

### 4. Smaller Purpose-Built Images

Replacing Debian Apache/mod_php with Alpine nginx/PHP-FPM cut the app image by
more than half while retaining PostgreSQL/MySQL, Redis, ImageMagick, AVIF,
WebP, Ghostscript, Blob, S3, and all installed TYPO3 system packages.

The Solr image now uses the slim base and copies only modules required by the
official EXT:solr configset. It still runs actual Apache Solr 10, not a mock.

### 5. The Pro Warm-Up Cron

Vercel documents that production Functions can scale down after five idle
minutes. The Pro config calls a protected endpoint every three minutes, leaving
margin for scheduler jitter. The endpoint warms:

- database connectivity
- Redis connectivity
- the full TYPO3 frontend bootstrap through a local loopback request
- the full `/typo3/` backend login bootstrap
- the Solr core ping through the private service binding

This is the most direct product-level response to the observed 12-second delay.
It costs 20 invocations per hour, about 14,400 per 30-day month. Warm invocations
are short, so the expected incremental usage is usually cents to low single
digits, but the actual invoice depends on duration, CPU class, concurrency, and
plan credits. The Pro subscription is the larger fixed cost.

### 6. Edge Caching For Eligible Frontend Pages

Opt-in CDN headers let Vercel answer anonymous, cookie-free HTML without
invoking TYPO3. Backend, API, query-string, cookie, personalized, and
`Set-Cookie` responses are excluded. This can remove origin cold starts from a
brochure frontend, but it introduces a publication delay equal to the cache TTL.

## What Helped Less Than Expected

### Redis Did Not Solve Cold Starts

Redis improved the warm backend sample and shares selected caches across
instances. It cannot avoid image activation, PHP process startup, or initial
opcode compilation. It also adds a network dependency. Redis is valuable for
cache consistency, not as the primary cold-start fix.

The cache provider also produced an operational surprise. One official Redis
Cloud Marketplace resource accepted the same credentials from local Docker but
reset connections from the deployed Vercel Container on 2026-07-09. The demo
was moved to a free Upstash Marketplace TLS endpoint in `fra1`, with automatic
paid upgrades disabled. This is not evidence of general Redis Cloud
incompatibility; it is evidence that Marketplace provisioning and environment
injection need an automatic runtime connection test.

### More CPU Did Not Remove Activation

The public project uses Vercel's performance CPU class and runs in Frankfurt.
That improves PHP execution after startup and reduces network latency to nearby
services. It does not keep an idle image resident.

### Region Pinning Was Necessary But Not Transformative

Putting compute, SQL, Redis, and Solr in Europe avoids repeated transatlantic
round trips. It does not materially change image pull and process startup time.

### PHP JIT Did Not Improve The Measured Workload

TYPO3 spends substantial time in framework dispatch, database, cache, and file
I/O. PHP JIT reserved memory but did not improve the tested path, so it is off.

### Compile-All OPcache Was A Bad Trade

A full build-time opcode cache added about 306 MB to the image. A narrower
attempt saved roughly one second of local first-render work but still added
about 88 MB. Larger deployment layers can worsen Vercel activation, so both
approaches were rejected. Normal in-memory OPcache remains enabled.

### Image Reduction Did Not Change Cached Local Docker Bootstrap Much

Local Docker already had every layer on disk. The first TYPO3 render remained
around four seconds because PHP still compiled and bootstrapped TYPO3. This was
a useful surprise: image size mainly targets remote activation, while the
three-minute warmer targets the remaining framework bootstrap.

The same surprise was stronger in production: the first backend request was
still 11.87 seconds after the image fell from about 950 MB to 446 MB. The likely
dominant time is Vercel image activation/orchestration plus first framework
bootstrap, not raw PHP request execution. Vercel observability currently does
not expose those phases separately enough to attribute the 11.84-second TTFB.

### Rebuilding Unchanged Images Was Expensive

An environment-only redeploy reported no previous build cache and rebuilt the
unchanged application image in about 244 seconds. Inspection then found that
the PHP Alpine base already supplied cURL, mbstring, and OPcache; recompiling
them was redundant. Removing those duplicate builds retained all required
modules and produced a 446 MB image. A clean local build completed in 97.6
seconds, but that is not presented as a direct Vercel A/B comparison because
the builders and caches differ.

The next clean Vercel build still reported no previous cache, but the simplified
extension stage completed the application image in 124.7 seconds. That is about
half the earlier build time and confirms the duplicate compilation removal was
useful for deployment throughput, even though it did not solve request-time
cold activation.

### A Solr Container Is Not The Same As Managed Solr

Running Java and answering queries was straightforward. Making Lucene state
durable was not. The engineering effort improved demo startup and error
handling, but it could not invent a Vercel persistent service volume.

## Solr And Durable Storage

Vercel Blob is durable object storage, but Solr needs a low-latency,
filesystem-like, mutable Lucene index at `/var/solr`. Blob cannot be mounted as
that filesystem and object-by-object synchronization is not a safe Lucene
storage layer.

The internal Vercel Solr service therefore has two legitimate uses:

1. a self-seeded search demonstration
2. bounded experiments with small transient indexes

Production Solr should use a managed Solr 10 provider or an always-on container
platform with a durable volume, backups, monitoring, private networking, and a
tested restore procedure. A future Vercel durable volume for Services could
change this conclusion. Container Registry alone does not; it stores immutable
images, not live index data.

## Product Surprises

- A traditional PHP CMS works technically well once warm.
- The database, cache, files, and search each require a different durability
  product; there is no generic "durable storage" switch.
- Blob onboarding is good, but using it from non-Node applications required
  direct REST/OIDC integration and TYPO3-specific FAL code.
- New Blob OIDC auth is safer than a long-lived token, but request-bound OIDC
  needs an explicit fallback story for Scheduler and CLI execution.
- Vercel Service bindings made private TYPO3-to-Solr connectivity possible, but
  a newly activated Solr service can temporarily answer `Starting...` through
  the gateway. Bounded retries and graceful frontend handling were necessary.
- The most expensive-looking optimization, compile-all OPcache, was one of the
  least useful after its image-size cost was measured.
- A simple periodic request is currently more effective for CMS latency than
  Redis, more CPU, or JIT because it addresses scale-to-zero directly.
- The warmer can succeed and a later backend request can still land on a fresh
  instance; minimum-instance control would be materially stronger than cron.
- Cutting the application image by 53% did not move the measured production
  cold request away from roughly 12 seconds; image size was not the dominant
  end-to-end variable in this case.
- An environment-only deployment rebuilt unchanged container sources for about
  four minutes because prior build caches were unavailable.
- A provisioned Redis resource and valid injected URL did not guarantee that a
  connection from the deployed Container would survive authentication and
  `PING`; the health probe caught this before the setup was called complete.
- A 4.5 MB Function request limit is surprisingly restrictive for a CMS media
  backend. Durable Blob storage does not remove the request limit when PHP
  remains in the upload path.

## Product Opportunities For Vercel

### High Impact

- Add minimum warm instances or a configurable idle timeout for paid Container
  Images and Services.
- Expose cold-start count, image activation time, process-ready time, and first
  application response as separate observability metrics.
- Offer a durable mounted volume for stateful Services, including documented
  backup and restore behavior.
- Make CPU/memory and Fluid Compute controls easier to discover for Container
  Image projects.

### CMS Onboarding

- Let Deploy Buttons provision Blob and a Marketplace database in one guided
  flow, with post-provision environment validation.
- Document Blob OIDC REST usage for PHP, Python, Go, and generic HTTP clients.
- Provide a supported direct-to-Blob browser upload pattern for non-Next.js
  backends so CMS users can avoid the Function request-body limit.
- Clearly distinguish deployment File API, Blob, database storage, cache, and
  persistent mounted volumes in product guidance.

### Stateful Search Services

- Publish whether Services can reserve a minimum instance and attach a durable
  volume.
- Document activation gateway responses and recommended health/readiness
  behavior for JVM services.
- Consider a Marketplace path for managed OpenSearch/Solr, or make external
  private service connectivity easier to configure.

## Operator Checklist

- [ ] Use a durable database near the Vercel region.
- [ ] Keep SQLite restricted to disposable smoke tests.
- [ ] Connect Vercel Blob or S3/R2 before editors upload files.
- [ ] Verify a Blob put/read/delete health probe after every credential change.
- [ ] Use a stable 96-character hexadecimal TYPO3 encryption key.
- [ ] Disable auto setup, extension setup, and password apply after bootstrap.
- [ ] Configure SMTP; the image has no local mail transfer agent.
- [ ] Use Redis only when shared cache behavior justifies the dependency.
- [ ] On Pro, set `CRON_SECRET` and deploy with `-A vercel.pro.json`.
- [ ] Monitor cron results and cold-start outliers separately from warm latency.
- [ ] Use managed external Solr for production search.
- [ ] Move multi-hour indexing to an external worker and process bounded queue
      batches from Vercel Cron.
- [ ] Review GDPR roles, regions, retention, subprocessors, and data deletion.

## Final Product Conclusion

The integration is now technically coherent: durable SQL, durable FAL files,
shared cache, bounded jobs, protected health checks, a demonstrable Solr path,
and a concrete cold-start mitigation all exist.

The current warm application is fast enough for ordinary CMS use. The deciding
production question is whether a site can accept Vercel's scale-to-zero model
and externalize its durable services. With Pro warming and optional edge cache,
the demo should normally feel immediate. Without a platform minimum-instance
feature, occasional activation during deploys, scaling, failures, or missed cron
runs remains an architectural limitation rather than a TYPO3 tuning problem.

See [Performance](performance.md), [Solr](solr.md),
[Object storage](object-storage.md), and [Limitations](limitations.md) for the
implementation-level detail.
