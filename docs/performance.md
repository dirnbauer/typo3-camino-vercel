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

**Verdict:** reducing the application image from roughly 950 MB to 446 MB did
not materially move the observed Vercel end-to-end cold floor: it remained near
12 seconds. The image change still reduces artifact weight and removes Apache,
but it is not the user-visible cold-start solution. The three-minute Pro warmer
is the effective mitigation. A platform minimum-instance feature, or an
always-on host, is required for a hard guarantee.

## Implemented Cold-Start Strategy

### 1. Smaller Application Runtime

The old runtime used Debian, Apache, and mod_php. The current runtime uses:

- Alpine Linux
- nginx
- PHP 8.4 FPM
- `tini` for signal forwarding and clean shutdown
- a multi-stage extension build so compilers do not enter the runtime image
- Composer authoritative classmaps

Local Docker image size changed from about 950 MB to 446 MB, a reduction of
about 53%. Required runtime support remains present:

- MySQL and PostgreSQL drivers
- Redis extension
- ImageMagick with AVIF and WebP read/write support
- Ghostscript
- Vercel Blob and S3-compatible FAL packages
- all installed TYPO3 CMS system packages

The final local image returned its first TYPO3 page in 4.27s and the next page
in 0.10s. Local Docker has cached image layers, so this measures process and
framework bootstrap rather than remote image transfer.

### 2. Smaller Solr Runtime

The Solr service now uses `solr:10.0-slim` and copies only modules required by
the official EXT:solr 14 configset. It enables one English demo core.

| Metric | Before | After |
|---|---:|---:|
| Local image size | about 843 MB | about 589 MB |
| Ready for requests | 4.13-4.62s | 1.94-3.21s; 2.48s median over 5 starts |

The entrypoint has separate liveness and readiness endpoints and emits
structured startup logs. The service can still cold-start independently from
TYPO3.

### 3. Pro Warm-Up Cron

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
vercel deploy --prod -A vercel.pro.json --scope webconsulting --yes
```

Vercel Cron sends `Authorization: Bearer $CRON_SECRET`. Requests without the
correct token receive an error and cannot trigger expensive internal checks.

Hobby cron is limited to once per day, so this mitigation cannot be enabled in
the free one-click config. `vercel.json` intentionally remains Hobby-safe.

### 4. Optional Edge HTML Cache

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

This path can completely avoid PHP and its cold start for eligible cached
requests. It is off by default because TYPO3 editors may expect a publication
to appear immediately and many sites contain forms or personalization.

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
platform limit.

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
