# Production Hardening

This document turns the product-summary caveat into an implementation plan.

The short version: yes, there is a technical solution for serious TYPO3 use on
Vercel, but it is not a single TYPO3 flag. It is a production architecture:
durable state, object storage, region alignment, startup cleanup, cache
strategy, and an explicit cold-start mitigation.

## Target Architecture A: Vercel-Native TYPO3

Use this when occasional first-hit latency is acceptable and the site is mostly
public content with a normal editor backend.

Required pieces:

1. **Compute:** Vercel Pro/Enterprise Container Images, Fluid Compute enabled,
   performance CPU, and a fixed region near the database.
2. **Database:** external Postgres or MySQL/MariaDB through `DATABASE_URL`.
   Do not use SQLite for real backend sessions or content.
3. **Files:** Vercel Blob through the included `vercel_blob` FAL driver, or
   S3-compatible storage through `vercel_s3`.
4. **Temporary runtime state:** TYPO3 `var`, `fileadmin`, `typo3temp`, PHP temp,
   sessions, and ImageMagick temp paths stay in `/tmp`.
5. **Caches:** runtime-local TYPO3 file caches for small demos, or Redis
   through a Vercel Marketplace Redis integration when shared cache state
   matters across runtime instances.
6. **Frontend cache:** optional Vercel CDN cache for anonymous public HTML only.
7. **Search:** external managed Apache Solr 10 when EXT:solr search is needed.
   Do not keep production Solr index state inside the TYPO3 Vercel container.
8. **Cold-start mitigation:** deploy `vercel.pro.json`; its protected warm-up
   primes frontend, backend, DB, Redis, and Solr every three minutes.
9. **Scheduler:** TYPO3 Scheduler runs through the protected HTTP cron endpoint,
   not a Linux daemon inside the container.

This is the shape used by the public demo. The default `vercel.json` remains a
Hobby-compatible no-cron test, so Pro projects must deploy with
`scripts/deploy-pro.sh`.

## Target Architecture B: Strict Production

Use this when the requirement is predictable first-hit latency, heavy backend
editing, heavy image processing, high concurrency, or classic always-on hosting
behavior.

Recommended shape:

1. Keep TYPO3 backend/origin on an always-on PHP host or container platform.
2. Put the public frontend, CDN, static assets, preview/demo deploys, and edge
   caching on Vercel.
3. Keep database and object storage external and shared.
4. Use Vercel as the fast public delivery layer, not the only runtime for the
   editor backend.

This is not a failure of TYPO3. It is the honest boundary while Vercel Container
Images do not expose a minimum-instances or always-warm control for this use
case.

## Concrete Settings For Architecture A

Production Vercel project settings:

```text
Function CPU: performance
Region: fra1, or the region closest to the database
Fluid Compute: enabled
```

Production environment, simple default:

```dotenv
TYPO3_CONTEXT=Production/Vercel
DATABASE_URL=<durable-db-url-in-same-region>
TYPO3_CACHE_BACKEND=file
TYPO3_OBJECT_STORAGE_ENABLED=1
TYPO3_OBJECT_STORAGE_DRIVER=vercel_blob
TYPO3_OBJECT_STORAGE_VERIFY_ON_BOOT=1
TYPO3_ADMIN_PASSWORD_APPLY_ON_BOOT=0
TYPO3_EXTENSION_SETUP_ON_BOOT=0
TYPO3_AUTO_SETUP=0
TYPO3_BOOTSTRAP_EMPTY_DATABASE=0
```

Production environment, shared Redis cache:

```dotenv
TYPO3_CONTEXT=Production/Vercel
DATABASE_URL=<durable-db-url-in-same-region>
TYPO3_CACHE_BACKEND=redis
TYPO3_REDIS_REQUIRED=1
TYPO3_REDIS_PREFIX=typo3-camino-vercel:
TYPO3_REDIS_URL=<provided-by-upstash-with-TYPO3-prefix>
TYPO3_OBJECT_STORAGE_ENABLED=1
TYPO3_OBJECT_STORAGE_DRIVER=vercel_blob
TYPO3_OBJECT_STORAGE_VERIFY_ON_BOOT=1
TYPO3_ADMIN_PASSWORD_APPLY_ON_BOOT=0
TYPO3_EXTENSION_SETUP_ON_BOOT=0
TYPO3_AUTO_SETUP=0
TYPO3_BOOTSTRAP_EMPTY_DATABASE=0
```

Use Redis only with a real Redis TCP/TLS endpoint close to the Vercel region.
It is for TYPO3 cache entries, not for SQL content, backend sessions, or file
storage in this starter.

Optional anonymous frontend cache:

```dotenv
TYPO3_VERCEL_EDGE_CACHE_TTL=600
TYPO3_VERCEL_EDGE_CACHE_STALE_WHILE_REVALIDATE=3600
```

Do not enable the edge HTML cache for pages with frontend login, forms,
personalization, previews, carts, or uncached plugins.

## Cold-Start Mitigation

The public demo measured Redis-enabled warm backend login responses around
0.11-0.17 seconds, but cold requests can still be around 10-13 seconds. The
practical mitigation is to keep the relevant runtime path warm.

The image build also runs TYPO3's official release warm-up for the DI container
and Fluid templates. Those environment-independent artifacts are copied into
`/tmp` at startup; no Composer or TYPO3 command blocks the runtime port. Do not
expand this to system, page, database, Redis, or Solr caches, because those must
be generated against the active production environment.

For Pro projects, use the included profile:

```json
{
  "crons": [
    {
      "path": "/api/cron/typo3-warmup.php",
      "schedule": "*/3 * * * *"
    }
  ]
}
```

Use the protected `/api/cron/typo3-warmup.php` endpoint from
`vercel.pro.json`. It performs local loopback requests to both `/` and
`/typo3/`, then checks database, Redis, and Solr. This primes the actual TYPO3
code paths rather than merely returning a cheap PHP response.

Do not rely on this for hard realtime guarantees. It is a mitigation, not an
SLA feature. If strict first-hit latency is required, use Architecture B until
Vercel offers a minimum-instances or always-warm option for Container Images.

## Remaining Engineering Work

The runtime image, Pro warm-up profile, protected deep health endpoint, Blob
write probe, direct large-upload flow, and benchmark documentation are
implemented. Remaining useful work is:

1. **Blob backup/export:** automate inventory and restore tests for projects
   that require independent file backups.
2. **External worker example:** provide a complete multi-hour Solr reindex job
   for an always-on runner.
3. **Live regression benchmark:** run a deployment-tagged warm/cold suite in CI
   without accidentally keeping the target warm before the cold sample.
4. **Stable EXT:solr 14:** remove beta compatibility fallbacks after the stable
   PostgreSQL-safe release is verified.

## Decision Rule

Use Vercel-native TYPO3 when:

- public pages matter more than backend first-hit latency
- editors can tolerate the occasional cold backend hit
- content lives in a real DB
- uploads live in Blob/S3
- cache and the protected Pro warm-up are configured deliberately

Use hybrid/always-on TYPO3 when:

- the backend must feel instant after inactivity
- image processing is heavy
- traffic/concurrency is high
- regulatory or operational requirements demand predictable process behavior
- the team expects classic VM/container hosting semantics
