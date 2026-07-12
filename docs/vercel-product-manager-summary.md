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
| Editing | Visual Editor plus complete strict translations of 9 pages, 52 content elements, 18 nested items, and image relations in five languages |
| Jobs | Protected Vercel Cron endpoints; no daemon inside the image |
| Security | Stable encryption key, trusted-host validation, protected deep probes |
| Health | Shallow public health and authenticated DB/Redis/Blob/Solr write probes |

This is not an official TYPO3 package. It is community integration code using
the official TYPO3 Camino distribution.

## Two Shippable Solutions

The final repository no longer asks one configuration to serve incompatible
goals.

| Product | What ships | Intended user | Performance approach |
|---|---|---|---|
| One-click test | TYPO3 app only, seeded temporary SQLite, optional Blob, no Solr service, no cron | A non-technical evaluator on Hobby | Eligible anonymous demo pages receive a five-minute CDN policy automatically; the first uncached/backend hit can still be cold |
| Professional hosting | Pro/Enterprise app, external SQL, Blob/S3, optional Redis, managed external Solr, protected Scheduler and warmer | An operated editorial site, including larger read-heavy sites after load testing | Three-minute warmer, regional stateful services, optional explicit CDN policy, monitoring and backups |

This split removes two avoidable problems from the one-click path: it no longer
builds a JVM search service that a disposable evaluator does not need, and it no
longer registers protected jobs without a `CRON_SECRET`. It also avoids claiming
that temporary SQLite is a small production database.

The professional path can serve large public traffic when anonymous delivery is
edge-cached and all state is external. It is not a universal replacement for an
always-on origin: a hard backend/first-hit latency SLA still calls for always-on
TYPO3 compute with Vercel used for CDN, assets, previews, and public delivery.

## Release Acceptance Audit (2026-07-10)

This final pass checked the deployed product, not only the repository. Results
below are direct observations from the production alias and Vercel CLI.

| Check | Observed result |
|---|---|
| One-click profile | Fresh preview was Ready with only the 172.56 MB application artifact in `fra1`; no Solr service or cron configuration was built |
| One-click edge cache | Anonymous `/` changed from `MISS` to `HIT`; the same URL with a cookie was a separate `MISS`, Authorization was `BYPASS`, and the anonymous variant remained a `HIT` |
| Deployment | `dpl_6wBiCWwTyweuHrMMZUXGsc2am9bq` was Ready in `fra1`; application artifact 172.55 MB and Solr service artifact 277.65 MB in `vercel inspect` |
| Runtime health | HTTP 200; PHP 8.4.23; Git revision `fbdddbbf8e65` and `fra1` reported by `/api/health.php` |
| Public routes | `/`, `/visual-editor`, `/search`, all four language roots, and all four localized route-comparison slugs returned HTTP 200 |
| Backend | `/typo3/` returned HTTP 200 and rendered the complete login form; 20 warm requests had a 0.255s median, 0.364s p95, and 0.366s max |
| Edge isolation | After priming anonymous HTML, a cookie request was `MISS` with `private, no-store`, Authorization was `BYPASS` with `private, no-store`, and the anonymous variant remained a `HIT` |
| Images | All 21 processed route-comparison image variants returned HTTP 200; a browser response trace found no broken resources |
| Video | The 19.72s H.264 Visual Editor video reached ready state 4 and advanced to 1.89s in Chrome through valid `206` byte-range responses |
| Search | Browser rendering returned all six indexed pages with no warming message; 30 post-warm requests had a 0.372s median, 0.496s p95, and 0.589s max |
| Languages | All four strict language roots rendered with the expected `lang`; corrected translated markup and the Hungarian heading were present in the durable database |
| Durable services | Authenticated write probe passed PostgreSQL, Redis TLS, Blob OIDC put/read/delete, Solr, and temporary filesystem checks |
| Access control | Unauthenticated warm-up, Scheduler, deep-health, and large-upload administration requests were rejected or redirected to login |
| Pro cron | `vercel crons ls` showed warm-up at `*/3 * * * *` and Scheduler at `*/15 * * * *`; manual invocations of both returned HTTP 200 |

The audit caught one release-process defect before sign-off: a normal direct
deployment had silently restored an old mixed-purpose cron file, leaving a
five-minute warm-up and a daily Scheduler run. The final one-click profile now
contains no cron jobs at all, while `scripts/deploy-pro.sh` stages the intended
Pro schedules. This is why `vercel crons ls` is an acceptance check, not
optional documentation.

The production `CRON_SECRET` was rotated without displaying it, then the
protected maintenance endpoint reconciled the existing durable database in
10.55 seconds. It applied one Camino source correction and confirmed 9 pages,
52 content elements, and 18 nested list items for each of four languages. This
matters because rebuilding a seed image cannot update records already stored in
PostgreSQL.

The first authenticated deep write probe after deployment took 16.82 seconds
because Solr needed 14.77 seconds and six readiness attempts. Its immediate
repeat took 1.64 seconds, including a 1.33-second Blob OIDC write probe and a
78 ms Solr check. Both returned HTTP 200 and removed their probe objects.

### 13-Hour Solr Follow-Up

The release sample above showed a fast post-warm search, but it did not prove
service residency. After the three-minute schedule had been registered for
about 13 hours, three consecutive scheduled warm-ups still cold-started the
private Solr service:

| Scheduled run | Full endpoint | Solr only | Attempts |
|---|---:|---:|---:|
| 08:00 | 17.385s | 16.989s | 6 |
| 08:03 | 18.649s | 16.080s | 7 |
| 08:06 | 14.864s | 14.553s | 6 |

All returned HTTP 200, but the logs also showed repeated Solr startup records
during one retry sequence. The engineering correction was to stop sending
`Connection: close`, reuse one cURL handle in the frontend, proxy, and health
client, and give the demo renderer one bounded 25-second startup budget. This is
a reliability improvement: a cold search waits for results instead of giving up
after roughly eight seconds. It is not a startup-speed improvement and it is not
equivalent to a minimum warm instance.

The first acceptance request then exposed a separate application-readiness
race: the cold search returned HTTP 200 in 20.583 seconds but had zero results
because the startup seed had not committed; the immediate repeat returned all
six in 0.796 seconds. The service now gates every bound Solr path with `503
starting` until the seed has committed and an exact six-document count succeeds.
Readiness therefore means usable demo search, not only a running JVM and open
core.

### Final Solr Readiness Acceptance (2026-07-11)

The follow-up deployment `d1717692ebf4` was verified from the production alias
after warming only the TYPO3 application. The first search then had to activate
Solr and returned HTTP 200 in 16.36 seconds with all six results, no warming
message, and no empty-result state. The immediate repeat took 0.96 seconds.

The most useful surprise was in the structured telemetry: the renderer reused
one cURL handle but Vercel recorded nine attempts and nine actual connections.
Handle reuse therefore did not provide connection or instance affinity across
temporary `503 starting` responses. It was worthwhile transport hygiene, but it
did not solve correctness by itself. The effective fix was to keep every Solr
path at `503 starting` until that instance had committed and counted all six
seed documents, then let the bounded client retry succeed.

### Final Cleanup Follow-Up

The final audit found several smaller issues that materially improved the
quality of the result:

| Finding | What changed | Product implication |
|---|---|---|
| Missing Solr Log4j file | The service now points at Solr's bundled production configuration | Custom entrypoints can bypass image initialization assumptions; Services guidance should call this out |
| Excessive INFO logging | A custom WARN-only file was tested but rejected after a 41.9s start and a separate pre-bind exit | Quieter logs were not worth an unproven startup regression; per-service log controls would help |
| Missing favicon | A tracked multi-size Camino favicon removed the only unexpected HTTP 404 | Runtime-log review found a user-visible polish defect that route-only checks missed |
| Host PHP mismatch | Validation moved from macOS PHP 8.3 to DDEV PHP 8.4 | Reproducible project runtimes are essential for template maintainers |
| Repeated cache misses | About 159KB, 22KB, and 2KB uploads each caused a full three-minute image rebuild with `Previous build caches not available` | Build-cache reuse was the least predictable and least repository-controllable part of finalization |

The latest accepted runtime returned six search results on the first cold
request in 16.69s and on the immediate repeat in 0.93s. The Log4j
reconfiguration error was gone, the favicon returned HTTP 200, and no
unexpected runtime HTTP errors remained. The complete evidence and rejected
experiment are in [Final cleanup audit](final-cleanup.md).

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

| Component | Before | Final image | Change |
|---|---:|---:|---:|
| TYPO3 local image size | about 950 MB | about 465 MB | about 51% smaller, including targeted warm cache |
| Solr local image size | about 843 MB | about 589 MB | about 30% smaller |
| Solr local readiness | 4.13-4.62s | 1.94-3.21s; 2.48s median | roughly 40%+ faster at the median |
| First backend after container ready | 1.871s median unwarmed | 0.383s median warmed | about 80% faster in three-run local A/B |
| First frontend after container ready | 9.665s median unwarmed | 7.274s median warmed | about 25% faster in three-run local A/B |

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

The earlier 53% application image reduction did **not** materially change the roughly
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

The signed release acceptance sample on 2026-07-10 produced:

| Request/check | Release result |
|---|---:|
| Fresh anonymous edge miss | 8.779s |
| Warm frontend edge hit, 20 runs | 0.143s median; 0.198s p95; 0.257s max |
| Warm backend, 20 runs | 0.255s median; 0.364s p95; 0.366s max |
| Post-warm search, 30 runs | 0.372s median; 0.496s p95; 0.589s max |
| Cold deep write probe | 16.82s; Solr used 14.77s and six attempts |
| Immediate deep write repeat | 1.64s; Solr 78 ms and one attempt |
| Registered warm-up invocation | 0.498s internal; all five checks passed |

A separate cookie-isolated request landed on a fresh instance and took 6.21
seconds even though the anonymous representation was cached. This is further
evidence that cache and cron reduce exposure but do not reserve the instance
pool.

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

### 5. Targeted TYPO3 Release Cache

TYPO3's official release warm-up now compiles only the DI container and Fluid
templates during the image build. Startup copies 12.6 MB of safe compiled files
into `/tmp`; it does not run Composer or TYPO3 CLI before opening the port. In
the local three-run A/B this reduced first backend framework work by about 80%
and first frontend work by about 25%, while adding 2.9% to the image. The build
also verifies that the preserved cache contains neither the build encryption
key nor the seed database path.

Full system/page warm-up was deliberately rejected because those caches can
depend on the selected database, Redis, site configuration, Solr endpoint, and
editor state. This is a targeted release artifact, not a snapshot of runtime
content.

### 6. The Pro Warm-Up Cron

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

### 7. Edge Caching For Eligible Frontend Pages

CDN headers let Vercel answer anonymous, cookie-free HTML without invoking
TYPO3. The temporary SQLite one-click profile now defaults to five minutes;
durable sites remain opt-in. Backend, API, query-string, cookie, personalized,
private/no-store, `Vary: *`, and `Set-Cookie` responses are excluded. Cacheable
responses vary on `Cookie` and `Authorization`; requests carrying either value
are forced to `private, no-store` if they reach TYPO3. A live preview test found
this necessary because Vercel can serve a cached response before PHP inspects a
new request's cookies. A conditional TYPO3 site set enables native shared-cache
classification only while the Vercel policy has a positive TTL; the middleware
then applies the shorter Vercel TTL. This also fixed a final review finding
where TYPO3's safe default `private, no-store` header would otherwise have
prevented the feature from working at all.

The production audit found a second ordering issue: Static File Cache could
return its fallback response before the policy middleware inspected the current
request. The middleware now executes before and wraps that fallback. The final
live sequence proved anonymous `HIT`, cookie `MISS` with `private, no-store`,
Authorization `BYPASS` with `private, no-store`, and an unchanged anonymous
`HIT`. The cache can remove origin cold starts from a brochure frontend, but it
introduces a publication delay equal to the TTL.

A 2026-07-12 hard-reload reproduction made the boundary especially clear. The
first German route request was a serverless cache miss at 6.13 seconds; the next
11 edge hits were 0.12-0.26 seconds. Database and Redis checks were only tens of
milliseconds. The useful change was therefore not more PHP tuning: it was
activating the previously empty production edge-cache variables, tagging all
eligible pages as `typo3-public`, invalidating that tag after publication, and
warming known localized routes after deployment. The operational sequence is
short: publish, invalidate one tag, warm twice, verify HTTP 200.

This gives the demo a target of <=1 second TTFB and <=2 seconds browser load for
warmed public pages. It does not create a hard first/cold guarantee for backend,
query-string, personalized, evicted, or stale entries. A product-level solution
would be minimum resident Container Image instances or a platform-supported
pre-render/invalidation integration for conventional CMS output.

Autocomplete showed the same boundary at smaller scale: a PHP JSON endpoint
had a 0.35-second median but intermittent 4-6 second service-routing outliers.
For the immutable six-record demo index, the final implementation embeds the
seed catalog and filters it in the browser with no jQuery or follow-up request.
External production Solr still uses live server suggestions. This is an
effective demo workaround, not a general replacement for low-latency dynamic
Functions or managed search.

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

The final one-line middleware-order follow-up, deployed minutes after another
successful build, again reported no previous build cache. The application image
took 193.7 seconds and the complete app-plus-Solr build about four minutes.
Container layer reuse therefore remained unreliable in this acceptance window.

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
  A three-minute cron still found Solr cold after 13 hours, so cron registration
  must not be presented as proof that a separate service remains resident.
- The most expensive-looking optimization, compile-all OPcache, was one of the
  least useful after its image-size cost was measured.
- A simple periodic request helped TYPO3 more than Redis, more CPU, or JIT, but
  it did not reliably keep the separate JVM Solr service warm. The distinction
  between calling a service and reserving an instance matters.
- An origin middleware cannot use the current request's cookies to protect a
  response that the CDN already cached. The live preview initially returned an
  anonymous `HIT` to a cookie-bearing request; adding `Vary: Cookie,
  Authorization` plus origin-side `private, no-store` handling changed that
  cookie request to `MISS` and an authorized request to `BYPASS` without
  sacrificing the anonymous `HIT`.
- Static File Cache can return before later TYPO3 middleware executes. The live
  production audit caught a cookie response that was not shared at Vercel but
  still had a public browser header. Moving the policy wrapper ahead of the
  static fallback made both cookie and authorized responses `private, no-store`.
- The warmer can succeed and a later backend request can still land on a fresh
  instance; minimum-instance control would be materially stronger than cron.
- Supporting Hobby one-click clones and a Pro production warmer requires two
  configuration files. A normal Git deployment restores the no-cron one-click
  profile, and the tested CLI custom-config override was also
  replaced during the remote Container build. The repository now stages the Pro
  file under the canonical name before deploying, but a project-level production
  config selection would remove this operational trap.
- A newly built seed cannot update an already connected durable database. The
  project now exposes a protected, idempotent maintenance POST so existing
  Vercel installations can apply demo records without an interactive shell or
  repeated cold-start migrations.
- Cutting the application image by 53% did not move the measured production
  cold request away from roughly 12 seconds; image size was not the dominant
  end-to-end variable in this case.
- An environment-only deployment rebuilt unchanged container sources for about
  four minutes because prior build caches were unavailable.
- A repository MP4 was valid and played locally, but a cached Vercel byte-range
  response returned partial bytes with status `200` instead of `206`. Chromium
  stopped decoding after roughly half a second. The demo now streams through a
  small range-aware PHP endpoint with Vercel edge caching disabled.
- A provisioned Redis resource and valid injected URL did not guarantee that a
  connection from the deployed Container would survive authentication and
  `PING`; the health probe caught this before the setup was called complete.
- A 4.5 MB Function request limit is surprisingly restrictive for a CMS media
  backend. Durable Blob storage alone does not remove the request limit when PHP
  remains in the upload path.
- An environment-variable name can appear in `vercel env ls` while its value is
  empty. Blob then looked connected but TYPO3 could not treat it as an enabled
  FAL destination. Explicit non-empty driver settings plus a runtime write probe
  are more reliable than checking variable names alone.
- Opening the large-upload route with the bundled Camino storage identifier made
  a valid Blob connection look broken. The module now resolves that request to
  the writable Blob storage and displays the changed destination.
- Translation record counts alone did not prove translation integrity. The final
  audit found translated HTML tag names and damaged rich-text list identifiers;
  the catalog was repaired and now has more than 2,000 structural assertions
  covering supported tags, balanced lists, and exactly one valid ID per item.
- The official Camino seed attached Portuguese distance facts to the French Way
  card. The integration now applies a tested source correction before creating
  translations instead of faithfully localizing contradictory content.

### Large Upload Solution

The repo now bypasses that limit without weakening TYPO3 permissions:

1. TYPO3 validates the editor, FAL folder, filename, MIME type, and size.
2. Vercel issues a short-lived token scoped to the exact Blob pathname.
3. The browser uploads directly to Blob; files above 100 MB use multipart data.
4. TYPO3 verifies the stored object and registers it in FAL without downloading it.
5. The normal Blob FAL driver serves the durable file.

The Files-module button and direct module URL now hand a selected local Camino
folder off to the first writable Blob folder. Editors no longer need to know a
FAL storage uid before using the durable path.

The standard TYPO3 uploader remains limited to about 4 MB. **Media > Large
upload** supports 5 GiB by default and can be configured up to Blob's platform
limit. Executable web formats are blocked by default.

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
- Turn the repo's working direct-to-Blob browser flow into a supported,
  framework-neutral recipe for non-Next.js backends.
- Clearly distinguish deployment File API, Blob, database storage, cache, and
  persistent mounted volumes in product guidance.

### Stateful Search Services

- Publish whether Services can reserve a minimum instance and attach a durable
  volume.
- Document activation gateway responses and recommended health/readiness
  behavior for JVM services.
- Document whether a service binding offers request or connection affinity;
  without that contract, retry clients cannot know whether a new attempt reaches
  the instance they just activated.
- Consider a Marketplace path for managed OpenSearch/Solr, or make external
  private service connectivity easier to configure.

## Operator Checklist

- [ ] Choose the one-click test or professional profile explicitly.
- [ ] Use a durable database near the Vercel region.
- [ ] Keep SQLite restricted to disposable smoke tests.
- [ ] Connect Vercel Blob or S3/R2 before editors upload files.
- [ ] Confirm object-storage environment values are non-empty and that **Media >
      Large upload** shows a Blob destination rather than only listing variables.
- [ ] Verify a Blob put/read/delete health probe after every credential change.
- [ ] Use a stable 96-character hexadecimal TYPO3 encryption key.
- [ ] Disable auto setup, extension setup, and password apply after bootstrap.
- [ ] Configure SMTP; the image has no local mail transfer agent.
- [ ] Use Redis only when shared cache behavior justifies the dependency.
- [ ] On Pro, set `CRON_SECRET` and deploy with `scripts/deploy-pro.sh`.
- [ ] Run `vercel crons ls` after every production deployment and confirm both
      Pro schedules are registered.
- [ ] Monitor cron results and cold-start outliers separately from warm latency.
- [ ] Use managed external Solr for production search.
- [ ] Move multi-hour indexing to an external worker and process bounded queue
      batches from Vercel Cron.
- [ ] Review GDPR roles, regions, retention, subprocessors, and data deletion.

## Final Product Conclusion

The integration is now technically coherent: durable SQL, durable FAL files,
shared cache, bounded jobs, protected health checks, a demonstrable Solr path,
and a concrete cold-start mitigation all exist.

The one-click product is now intentionally small, edge-cached, and disposable.
The professional product externalizes durable state and is fast enough when
warm for ordinary CMS use and larger read-heavy delivery after project-specific
load testing. With Pro warming and optional edge cache, normal requests should
feel immediate. Without a platform minimum-instance feature, occasional
activation during deploys, scaling, failures, or missed cron runs remains an
architectural limitation rather than a TYPO3 tuning problem; strict latency
sites should keep an always-on origin.

For search specifically, the internal Solr container is a demonstrator, not the
professional solution. A production TYPO3 site should use managed or always-on
Solr with durable index storage; the bounded Vercel retry path exists to make
the transient demo honest and usable.

See [Performance](performance.md), [Solr](solr.md),
[Object storage](object-storage.md), and [Limitations](limitations.md) for the
implementation-level detail.
