# Performance And Cold Starts

## Performance Model

There are four different latency classes in this project. Do not combine them
into one average:

1. **Edge hit:** Vercel returns cached HTML or a static file without PHP.
2. **Warm application:** nginx, PHP-FPM, OPcache, DB connections, and caches are
   already active.
3. **Warm container, first TYPO3 route:** the process exists but a frontend or
   backend code path has not yet populated OPcache and TYPO3 runtime caches.
4. **Cold container:** Vercel activates image layers, starts processes, and then
   TYPO3 performs its first bootstrap. Solr may cold-start independently.

The reported 10 to 12 second problem was class 4. It should not be diagnosed as
slow SQL or disabled TYPO3 page cache because repeated class-2 requests were
already below half a second.

## Baseline

Representative production measurements before the runtime overhaul:

| Route | Cold or first hit | Warm behavior |
|---|---:|---:|
| `/` | 12.14s | 0.09-0.22s |
| `/typo3/` | 11.50s | 0.19-0.49s |
| `/typo3/ajax/login/preflight` | cold outliers | 0.16-0.25s |

A later Redis-enabled production window showed warmer medians around 0.046s,
0.125s, and 0.100s respectively. That sample is useful directionally, but it is
not an A/B test because deployment state and external services differed.

## Production Validation After The Overhaul

Measurements against `https://typo3-camino-vercel.vercel.app` on 2026-07-09,
using the Pro performance CPU in `fra1`:

| Request | External time | Application details |
|---|---:|---|
| First `/typo3/` after deploy | 11.87s | 11.84s time to first byte |
| First authenticated full warm-up | 14.91s | 9.84s internal; TYPO3 and Solr were both cold |
| Immediate second full warm-up | 0.93s | 0.51s internal |

The second warm-up reported a 45 ms database check, 178 ms frontend loopback,
88 ms backend loopback, and 200 ms Solr check. The first warm-up originally saw
a temporary Solr gateway `502`; the health implementation now retries temporary
`500/502/503/504` startup responses for up to the existing 25-second budget.

**Verdict:** reducing the application image from roughly 950 MB to the 446 MB
pre-warmup candidate did not materially move the observed Vercel end-to-end
cold floor: it remained near 12 seconds. The image change still reduces artifact
weight and removes Apache, but it is not the user-visible cold-start solution.
The three-minute Pro warmer is the effective mitigation. A platform
minimum-instance feature, or an always-on host, is required for a hard
guarantee.

### Final Production Pass

After adding Solr readiness retries and switching the cache to an Upstash TLS
endpoint in `fra1`, the final deployment produced:

| Check | Cold/full activation | Immediate repeat |
|---|---:|---:|
| Full protected warmer, external | 26.82s | 0.70s |
| Full protected warmer, internal | 21.73s | 0.43s |
| Database | 44 ms | 46 ms |
| Redis | 27 ms | 25 ms |
| TYPO3 frontend loopback | 5.93s | 187 ms |
| TYPO3 backend loopback | 399 ms | 111 ms |
| Solr | 15.34s, 6 attempts | 57 ms, 1 attempt |

The cold warmer deliberately absorbs both independent container starts before a
user does. The authenticated deep check then passed database, Redis TLS, Blob
OIDC put/read/delete, Solr, and temporary filesystem checks in 2.06 seconds.

Thirty sequential external requests after warming:

| Route | Median | p95 | Max | Verdict |
|---|---:|---:|---:|---|
| `/` edge-cache hit | 0.113s | 0.207s | 0.245s | fast |
| `/typo3/` | 0.208s | 0.251s | 0.664s | fast when warm |
| Solr search | 0.351s | 2.890s | 3.622s | good median; demo-service outliers |

The search outliers correlated with a new Solr Service instance returning a
temporary `502` and taking about 7.1 seconds to become ready. The page remained
HTTP 200 through graceful handling. This is acceptable for the transient demo,
not a production Solr latency guarantee.

One public `/typo3/` request still took 8.85 seconds after a successful warmer,
then ten repeats were 0.19-0.42 seconds. That is direct evidence that a cron
invocation warms one active instance but cannot reserve every instance Vercel
may select or create. No documented minimum-instance field currently exists for
this Container Image path.

The first `/camino-route-comparison` edge miss took 11.42 seconds; subsequent
Vercel edge hits were about 0.10 seconds. Public edge caching is therefore the
strongest frontend protection, while the uncached backend remains subject to
occasional activation.

### Signed Release Acceptance

The final Pro deployment on 2026-07-10 reported revision `fbdddbbf8e65` in
`fra1`. A fresh anonymous edge miss took 8.779 seconds. After warming, 20
frontend requests had a 0.143-second median and 0.198-second p95; 20 backend
login requests had a 0.255-second median and 0.364-second p95. A 30-request Solr
search sample immediately after the registered warmer had a 0.372-second
median, 0.496-second p95, and 0.589-second maximum, with no request over one
second.

The authenticated deep write probe showed the independent service lifecycle:
the first run took 16.82 seconds because Solr used 14.77 seconds and six
readiness attempts. The immediate repeat took 1.64 seconds, with Solr at 78 ms.
The registered warmer itself later completed all checks in 0.498 seconds. All
requests returned HTTP 200.

## Implemented Cold-Start Strategy

### 1. Smaller Application Runtime

The old runtime used Debian, Apache, and mod_php. The current runtime uses:

- Alpine Linux
- nginx
- PHP 8.4 FPM
- `tini` for signal forwarding and clean shutdown
- a multi-stage extension build so compilers do not enter the runtime image
- Composer authoritative classmaps

The final release image is about 465 MB, still about 51% smaller than the
original 950 MB image after adding the targeted TYPO3 release cache described
below. Required runtime support remains present:

- MySQL and PostgreSQL drivers
- Redis extension
- ImageMagick with AVIF and WebP read/write support
- Ghostscript
- Vercel Blob and S3-compatible FAL packages
- all installed TYPO3 CMS system packages

Before release-cache seeding, one clean local image returned its first TYPO3
page in 4.27s and the next page in 0.10s. Local Docker has cached image layers,
so this measures process and framework bootstrap rather than remote image
transfer.

### 2. Targeted TYPO3 Release Cache

TYPO3 14 provides `cache:warmup` for release preparation. The image build now
warms only the dependency-injection container and Fluid templates, then copies
those compiled files into `/tmp` when a container starts. It does not run a
TYPO3 command on the startup critical path and it does not preserve database-,
Redis-, site-, Solr-, or page-specific caches.

Composer is deliberately not used for this. Composer installation can run
without the final PHP/runtime/database context, while TYPO3 explicitly requires
the warm-up PHP version to match the web runtime. The container build provides
that stable PHP 8.4 context.

Three alternating fresh-container A/B runs on local OrbStack produced:

| Metric | Unwarmed image | Targeted warm-up | Change |
|---|---:|---:|---:|
| Image size | 451.5 MB | 464.5 MB | +13.0 MB / +2.9% |
| TCP-ready median | 20.754s | 20.561s | no material change |
| First frontend after ready | 9.665s median | 7.274s median | about 25% faster |
| First backend after ready | 1.871s median | 0.383s median | about 80% faster |

The release cache contains one DI artifact and 597 compiled Fluid templates,
12,566,528 bytes total. A build check confirmed it contains neither the build
encryption key nor the seed database path. Absolute local launch values were
noisy; the paired median direction is useful, not a Vercel latency prediction.
Vercel image activation remains a separate cost.

### 3. Smaller Solr Runtime

The Solr service now uses `solr:10.0-slim` and copies only modules required by
the official EXT:solr 14 configset. It enables one English demo core.

| Metric | Before | After |
|---|---:|---:|
| Local image size | about 843 MB | about 589 MB |
| Ready for requests | 4.13-4.62s | 1.94-3.21s; 2.48s median over 5 starts |

The entrypoint has separate liveness and readiness endpoints and emits
structured startup logs. The service can still cold-start independently from
TYPO3.

### 4. Pro Warm-Up Cron

Vercel documents that production Functions can scale down after five minutes
without requests. `vercel.pro.json` schedules `/api/cron/typo3-warmup.php`
every three minutes. The margin matters because cron delivery is not guaranteed
to occur at an exact second.

The protected endpoint performs:

1. direct database connection and query
2. direct Redis ping when Redis is configured
3. local loopback request to `/` to populate the frontend path
4. local loopback request to `/typo3/` to populate the backend path
5. Solr core ping through the private service binding or external endpoint

The Solr check uses bounded retries because a newly activated Vercel Service
can temporarily return `500`, `502`, `503`, or `504` before Solr is ready. One
warm-up request therefore primes both containers instead of requiring a second
cron interval.

The local loopback requests target the exact active application container and
bypass the Vercel CDN. This is intentional: a CDN hit would not compile and
prime TYPO3 inside PHP-FPM.

Configure a strong secret:

```bash
openssl rand -hex 32
vercel env add CRON_SECRET production
```

Deploy the Pro config:

```bash
VERCEL_SCOPE=webconsulting scripts/deploy-pro.sh
```

Git-based deployments always read the default `vercel.json` in this repository.
That file must remain Hobby-compatible for one-click clones, so a Git deployment
of the public Pro demo removes the frequent jobs and restores the no-cron test
profile.
In the tested Container Services deployment, the CLI `-A vercel.pro.json`
override was also replaced by the root config during the remote build. The
deployment script avoids that ambiguity by staging the committed tree with the
Pro file named `vercel.json`. Run it after each production push and verify with:

```bash
vercel crons ls --scope webconsulting
```

The expected Pro result contains `/api/cron/typo3-warmup.php` at `*/3 * * * *`
and `/api/cron/typo3-scheduler.php` at `*/15 * * * *`. A successful endpoint
test does not prove that the recurring schedule is registered.

Vercel Cron sends `Authorization: Bearer $CRON_SECRET`. Requests without the
correct token receive an error and cannot trigger expensive internal checks.

Hobby cron is limited to once per day, so this mitigation cannot be enabled in
the free one-click config. `vercel.json` intentionally remains Hobby-safe.

### 5. Edge HTML Cache

For public brochure pages:

```dotenv
TYPO3_VERCEL_EDGE_CACHE_TTL=300
TYPO3_VERCEL_EDGE_CACHE_STALE_WHILE_REVALIDATE=600
```

The TYPO3 middleware sets Vercel CDN headers only when all of these are true:

- method is `GET` or `HEAD`
- no Cookie header or parsed cookies
- no query string
- path is outside `/typo3` and `/api`
- response is HTTP 200 HTML
- response has no `Set-Cookie`
- TYPO3 has classified the page as shared-cacheable
- response does not contain `private`, `no-store`, `no-cache`, `Pragma:
  no-cache`, or `Vary: *`

A small site set enables TYPO3's
`config.sendCacheHeadersForSharedCaches = force` only when the same edge-cache
policy resolves to a positive TTL. TYPO3 therefore performs its native checks
for uncached plugins, frontend/backend users, and workspaces before the custom
middleware applies Vercel's shorter TTL. This is required because TYPO3 14
otherwise emits `private, no-store` even for normal cacheable frontend pages.
The entrypoint calculates the flag through the same PHP policy class used by
the request middleware, avoiding separate startup and request-time rules.
Cacheable responses add `Vary: Cookie, Authorization`, which makes those
request headers part of Vercel's cache key; Vercel documents
[full `Vary` support](https://vercel.com/changelog/serve-personalized-content-faster-with-vary-support).
If a cookie, authorization header, or query string reaches TYPO3, the middleware
removes TYPO3's temporary shared headers and returns `private, no-store`. This
second step is essential because the CDN can otherwise answer from its cache
before PHP sees the request.

The policy middleware must also execute before
`staticfilecache/fallback`. That fallback can return a generated HTML file
without calling later middleware. The final production audit initially found a
cookie request that was a Vercel `MISS` but still carried a public browser
header for this reason. After moving the policy wrapper ahead of the fallback,
the live sequence was: anonymous `HIT`, cookie `MISS` with `private, no-store`,
Authorization `BYPASS` with `private, no-store`, then anonymous `HIT` again.

This path can completely avoid PHP and its cold start for eligible cached
requests. The temporary SQLite one-click profile defaults to a 300-second TTL.
Durable database-backed sites remain opt-in because TYPO3 editors may expect a
publication to appear immediately and many sites contain forms or
personalization. Set the TTL explicitly to `0` to disable demo caching.

## What Not To Expect

The warm-up is not a formal minimum-instance reservation. Cold activation can
still happen:

- immediately after a deployment
- during scale-out when Vercel creates another instance
- after a failed or delayed cron invocation
- after infrastructure maintenance or runtime eviction
- when the project is deployed with `vercel.json` instead of `vercel.pro.json`

For a strict latency SLO that forbids any cold request, use a platform with an
always-on/minimum-instance guarantee or wait for Vercel to expose one for this
runtime.

## Cost Of Warming

A three-minute schedule produces:

```text
20 invocations/hour
480 invocations/day
14,400 invocations/30-day month
```

Warm executions should be short. At current Functions active-CPU and
provisioned-memory pricing, the incremental compute is expected to be in the
cents to low single-digit dollars per month for this small demo, before plan
credits. That is an estimate, not a promise. Measure the endpoint duration in
Vercel Observability and apply current regional rates. The Pro subscription is
the larger fixed prerequisite.

## TYPO3 Runtime Settings

### OPcache

Enabled settings include:

- 256 MB shared memory
- 40,000 accelerated files
- timestamp validation disabled for immutable deployments
- 32 MB interned-string buffer
- CLI OPcache enabled for Scheduler jobs

JIT is disabled. It did not improve the measured framework and I/O-heavy path.

Two build-time file-cache experiments were rejected:

- compiling the whole codebase added about 306 MB
- a targeted cache saved roughly one second locally but added about 88 MB

The extra image weight conflicts with the main Vercel activation goal.

### PHP-FPM

The pool starts two workers and can grow to eight. Two initial workers allow a
cron request to issue its local frontend/backend warm-up requests without
deadlocking the only PHP worker.

### Boot Work

Keep these off after first database initialization:

```dotenv
TYPO3_AUTO_SETUP=0
TYPO3_BOOTSTRAP_EMPTY_DATABASE=0
TYPO3_EXTENSION_SETUP_ON_BOOT=0
TYPO3_ADMIN_PASSWORD_APPLY_ON_BOOT=0
```

Running schema setup, extension setup, and password hashing on every activation
adds latency and risks competing writes from multiple instances.

### Temporary Files And Images

TYPO3, PHP sessions, locks, ImageMagick, and Ghostscript use writable paths
below `/tmp/typo3`. This is correct for temporary work but not durable storage.
Final uploads and processed FAL derivatives must use Blob or S3/R2.

Normal backend upload size is 4 MB because Vercel Functions reject total request
bodies above 4.5 MB. Increasing PHP's `upload_max_filesize` cannot bypass the
platform limit. The included **Media > Large upload** flow bypasses PHP and sends
the file directly to Blob, using multipart above 100 MB. It avoids Function body
and PHP memory pressure, but subsequent image processing can still consume
temporary disk and request time when TYPO3 downloads a large original.

## Database Performance

The database should be in or near `fra1` for the public demo. Every TYPO3 request
performs multiple queries, so network round-trip time compounds.

Use the provider's pooled connection endpoint when available. Avoid performing
DDL or setup work on normal starts. A database that suspends on its own free tier
can add a second independent cold start; measure it separately from Vercel.

SQLite is fast locally but invalid for production Vercel behavior because
instances do not share `/tmp`.

## Redis Performance

Redis shares TYPO3 `pages`, `hash`, and `rootline` caches across instances. It
can improve warm backend/frontend work and reduce duplicate cache builds.
Rendered `pages` cache keys include the first 12 characters of
`VERCEL_GIT_COMMIT_SHA`. A new code deployment therefore cannot serve HTML
rendered by an older template, while `hash` and `rootline` remain reusable.
Old rendered-page entries expire according to their normal TYPO3 lifetime.

It cannot:

- keep the Container Image active
- replace the SQL database
- make sessions reliable when the database is temporary
- make files durable
- make Solr durable

Use a nearby TCP/TLS endpoint and short connection timeout. Persistent sockets
are off by default because a reused Vercel process can retain a socket that the
cloud Redis service has already closed.

## Solr Benchmarks

Local benchmark set with six Camino documents:

| Operation | Runs | Min | Median | Mean | p95 / max |
|---|---:|---:|---:|---:|---:|
| Direct query | 50 | 2.0 ms | 3.1 ms | 3.7 ms | 7.0 / 9.3 ms |
| Update + commit | 20 | 27.7 ms | 34.3 ms | 35.3 ms | 38.1 / 60.5 ms |
| TYPO3 full rebuild | 10 | 1.043s | 1.108s | 1.151s | 1.550s max |
| Full TYPO3 search page | 30 | 26.3 ms | 27.2 ms | 29.9 ms | 28.8 / 106 ms |

The internal service is fast when active. Its index is not durable, so these
figures do not change the production recommendation to use external Solr.

## Benchmark Procedure

Record cold and warm samples separately. A useful curl probe is:

```bash
curl -sS -o /dev/null \
  -w 'status=%{http_code} connect=%{time_connect} ttfb=%{time_starttransfer} total=%{time_total}\n' \
  https://typo3-camino-vercel.vercel.app/
```

Test at least:

- `/`
- `/typo3/`
- `/typo3/ajax/login/preflight`
- `/search?tx_solr%5Bq%5D=camino`
- `/api/health.php`

For a genuine scale-to-zero sample, disable the Pro warmer or use an isolated
preview and wait beyond its documented idle window. Do not call a request
"cold" merely because it was the first curl in a local loop.

For warm percentiles, make 30 or more requests, preserve individual samples,
and report median, p95, maximum, HTTP status, deployment SHA, region, and date.

## Health And Observability

Public shallow check:

```text
GET /api/health.php
```

Authenticated deep read/write check:

```bash
curl -H "Authorization: Bearer $CRON_SECRET" \
  'https://example.vercel.app/api/health.php?deep=1&write=1'
```

The write check validates database, Redis, Blob put/read/delete, Solr, and
writable temporary paths. It deletes its Blob probe object.

Alert on:

- warm-up cron non-200 responses
- cold p95 separately from warm p95
- DB and Redis connection time
- Solr readiness/startup time
- Blob write probe failures
- deployment SHA mismatches

## Verdict

Warm TYPO3 and Solr are fast enough for this demo and ordinary editorial work.
The runtime overhaul reduces activation weight substantially, and the Pro cron
addresses the five-minute idle window directly. Edge caching can remove the
origin from eligible anonymous requests.

The remaining risk is platform activation during deploys, scale-out, eviction,
or missed warm-ups. More PHP tuning cannot eliminate that lifecycle. The
long-term product solution is a Vercel minimum-instance control; the alternative
today is always-on infrastructure.
