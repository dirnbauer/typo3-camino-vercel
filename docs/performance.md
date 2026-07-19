# Performance And Cold Starts

## Performance Contract

This project optimizes four different paths. Do not combine them into one
average:

| Path | Expected behavior |
|---|---|
| Edge-cached public page | Served without activating PHP while the cached representation is valid. |
| Warm TYPO3 request | Uses an active nginx/PHP-FPM service and warmed TYPO3 release caches. |
| Cold TYPO3 request | Includes Vercel service activation and first application work. |
| Search after Solr scale-in | May activate both TYPO3 and the independent Solr demo service. |

Vercel documents that production Docker deployments can scale to zero after
five idle minutes. The platform does not currently document a minimum-instance
control for this deployment path. A cron request, smaller image, or warmed
framework cache reduces exposure but cannot guarantee that every user reaches
an already active instance.

Use an always-on TYPO3 origin when a contractual first-request latency cannot
tolerate activation. Use managed, always-on Solr for production search.

## Implemented Strategy

### Runtime Image

The application image uses Alpine, nginx, PHP 8.5 FPM, and a discarded build
stage for compiled PHP extensions. Composer development dependencies and build
tools do not enter the runtime image.

The Solr demonstration image separates nginx from the Solr process and exposes
a readiness gate. nginx returns `503 starting` until all five demo cores exist,
the documents are committed, and an exact query confirms the seeded content.
This avoids a fast but incorrect empty search response.

### TYPO3 Release Caches

The Docker build creates the seed database, warms TYPO3's dependency-injection
container and Fluid template cache, then copies those immutable artifacts to a
release-cache location. Startup restores them into the writable runtime tree.

Runtime startup does not run Composer and does not block on a full TYPO3 cache
warm-up. Database and object-storage setup remain guarded, explicit operations.

### Public Edge Cache

The evaluation profile automatically gives eligible anonymous SQLite demo
pages a short public TTL. Durable sites opt in with:

```dotenv
TYPO3_VERCEL_EDGE_CACHE_TTL=600
TYPO3_VERCEL_EDGE_CACHE_STALE_WHILE_REVALIDATE=3600
```

The middleware only publishes a response when all safety checks pass:

- request method is `GET` or `HEAD`
- URL has no query string
- no Cookie or Authorization header is present
- the route is outside `/typo3/` and `/api/`
- TYPO3 produced cacheable HTML without `Set-Cookie`
- forms, personalized plugins, and explicitly private content are excluded

Cookie and Authorization remain part of the cache variation policy. Static
File Cache is wrapped by the same rules so its fallback cannot make a private
response public.

After publishing durable content, invalidate and warm the eligible routes:

```bash
VERCEL_SCOPE=your-team scripts/invalidate-frontend-cache.sh
```

### Pro Warm-Up

`vercel.pro.json` calls `/api/cron/typo3-warmup.php` every three minutes. The
protected request checks frontend, backend, database, Redis, and Solr paths that
are enabled for the deployment. It is a best-effort latency mitigation, not an
instance reservation or service-affinity mechanism.

Hobby Cron cannot run this cadence. Deploy the Pro profile explicitly:

```bash
VERCEL_SCOPE=your-team scripts/deploy-pro.sh
vercel crons ls --scope your-team
```

The expected schedules are the three-minute warmer and the 15-minute TYPO3
Scheduler invocation. Pushes to `main` deploy the Pro profile through CI;
verify the schedules after every production release.

## Historical Measurements

Development measurements showed the stable shape of the problem:

- warm frontend and backend requests were commonly below half a second
- the original cold application path was roughly 10–12 seconds
- shrinking the image and warming TYPO3 caches reduced application work but did
  not remove the Vercel activation floor
- a cold internal Solr service could add roughly 15–20 seconds
- warm queries against the six-document demo index completed in milliseconds

These observations explain the architecture; they are not an SLA. The removed
dated audit reports tied exact values to individual deployment IDs and quickly
became stale. Current acceptance evidence should be recorded with the commit,
deployment, region, time, and request classification instead.

For backend media, an existing processed thumbnail is emitted as a direct
public Vercel Blob URL and does not pass through PHP. A production sample on
2026-07-19 returned the uncached Camino thumbnail in about 0.15s; a browser-
cached repeat was effectively immediate. TYPO3 backend initialization and
uncached language-domain modules took up to about 0.7s in the same sample. A new
derivative can take longer because TYPO3 must fetch the original, run
ImageMagick in `/tmp`, and upload the durable processed file to Blob.

## Benchmark Procedure

### Repository Baseline

Before measuring a deployment, verify the exact code and image inputs:

```bash
npm ci
npm run build:blob-upload
git diff --exit-code -- packages/typo3-vercel-blob-storage/Resources/Public/JavaScript/large-upload.js
Build/Scripts/runTests.sh -s all
Build/Scripts/runTests.sh -s containers
```

Record the Git SHA, Composer lock hash, Vercel profile, region, and whether the
database, Redis, Blob, and Solr are external or local to the profile.

### Request Classes

Measure these independently:

1. first frontend request after a confirmed scale-in window
2. immediate frontend repeat
3. edge-cache miss and hit, confirmed through response headers
4. first and repeated `/typo3/` requests
5. first and repeated search with all expected results present
6. protected shallow and deep health probes

Do not call a response warm merely because it followed another URL. Vercel may
route separate requests to different instances, and the Solr service scales
independently from TYPO3.

Example timing loop:

```bash
for run in $(seq 1 20); do
  curl --silent --show-error --output /dev/null \
    --write-out '%{http_code} %{time_starttransfer} %{time_total}\n' \
    https://example.vercel.app/
done
```

For a true cold sample, use an isolated deployment or wait beyond the documented
scale-in window without a warmer. Label any inferred cold request as inferred.

### Search Correctness

Latency is not enough. A successful cold-search sample must contain all expected
demo results for the selected language. A quick HTTP 200 with zero results is a
failed readiness result.

The internal demo contains six documents in each of `core_en`, `core_de`,
`core_es`, `core_zh`, and `core_hu`. Production external Solr has a site-specific
index size and needs its own correctness criteria.

## Runtime Settings

### PHP And FPM

`docker/php.ini` enables production OPcache and places sessions and temporary
files under `/tmp`. `docker/php-fpm.conf` uses a small dynamic worker pool so a
single container can reuse active workers without allocating a traditional
large VM pool.

Do not disable OPcache for production measurements. Do not infer image
activation time from the duration of PHP application code alone.

### Database

Put durable SQL near the Vercel region and use a provider's pooled URL when
available. A shared database makes sessions and editorial state correct; it can
still dominate warm request time when it is remote or establishes a fresh TLS
connection.

### Redis

Redis can share selected TYPO3 caches and reduce repeated database work. It does
not keep a Vercel container alive, replace SQL, or make files durable. Measure
with and without Redis using the same deployment conditions before making it a
required dependency.

### Media

The normal upload path stays below 4 MB because of the 4.5 MB platform request
body limit. Direct browser-to-Blob uploads avoid routing large bodies through
PHP. ImageMagick processing still uses local temporary space and should be
bounded by source dimensions and memory limits.

## Observability

`/api/health.php` is intentionally shallow. Protected deep probes can check the
database, Redis, Blob, Solr, and temporary filesystem. Set `CRON_SECRET`, do not
expose deep provider diagnostics publicly, and keep probes shorter than the
request timeout.

Track at least:

- cold and warm TTFB separately
- edge hit/miss status
- activation and timeout failures
- database and Redis connection failures
- Solr readiness attempts and result count
- deployment SHA and region
- image build duration and cache reuse

## Decision Rule

Use Vercel-native TYPO3 when public traffic is mostly cacheable, state is
external, and occasional uncached activation is acceptable. Use an always-on
origin when editorial, personalized, or search traffic requires predictable
first-request latency.

Sources: [Vercel Docker deployments](https://vercel.com/kb/guide/does-vercel-support-docker-deployments),
[Vercel Services](https://vercel.com/docs/services),
[Cron usage and pricing](https://vercel.com/docs/cron-jobs/usage-and-pricing), and
[Function limits](https://vercel.com/docs/functions/limitations).
