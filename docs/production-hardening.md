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

1. **Compute:** a Pro/Enterprise Dockerfile-backed Vercel container Service in
   a fixed region near the database.
2. **Database:** external Postgres or MySQL/MariaDB through `DATABASE_URL`.
   Do not use SQLite for real backend sessions or content.
3. **Files:** Vercel Blob through the included `vercel_blob` FAL driver, or
   S3-compatible storage through `vercel_s3`.
4. **Temporary runtime state:** TYPO3 `var`, the local `fileadmin` fallback,
   `typo3temp`, PHP temp, and ImageMagick temp paths stay in `/tmp`. TYPO3
   sessions remain in the external database.
5. **Caches:** runtime-local TYPO3 file caches for small demos, or Redis
   through a Vercel Marketplace Redis integration when shared cache state
   matters across runtime instances.
6. **Frontend cache:** optional Vercel CDN cache for anonymous public HTML only.
7. **Search:** external managed Apache Solr 10 when EXT:solr search is needed.
   Do not keep production Solr index state inside the TYPO3 Vercel container.
8. **Cold starts:** accept occasional activation or move the production origin
   to the always-on profile. Do not schedule residency probes.
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

1. Deploy `compose.hetzner.yaml` on an always-on host.
2. Keep MariaDB, Redis, and Solr private and persistent.
3. Publish only Caddy on ports 80/443 and use automatic TLS.
4. Enable provider backups plus an independent database/file export.
5. Keep Vercel for evaluation, previews, or a separately justified CDN layer.

This is not a failure of TYPO3. It is the honest boundary while the current
Dockerfile deployment documentation exposes no minimum-instances or always-warm
control for this use case.

## Concrete Settings For Architecture A

Production Vercel project settings:

```text
Region: fra1, or the region closest to the database
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

## Cold-Start Decision

The public demo measured Redis-enabled warm backend login responses around
0.11-0.17 seconds, but cold requests can still be around 10-13 seconds.

The image build also runs TYPO3's official release warm-up for the DI container
and Fluid templates. Those environment-independent artifacts are copied into
`/tmp` at startup; no Composer or TYPO3 command blocks the runtime port. Do not
expand this to system, page, database, Redis, or Solr caches, because those must
be generated against the active production environment.

The previous one-minute warm-up consumed sustained memory and CPU while still
allowing platform restarts. It is removed from `vercel.pro.json`. The protected
endpoint remains available for manual diagnostics only.

If strict first-hit latency is required, use Architecture B. Fluid Compute
reduces cold-start frequency; it does not expose a minimum-instance guarantee
for this container Service.

## Remaining Engineering Work

The runtime image, always-on profile, protected deep health endpoint, Blob
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
- occasional scale-to-zero activation is acceptable

Use hybrid/always-on TYPO3 when:

- the backend must feel instant after inactivity
- image processing is heavy
- traffic/concurrency is high
- regulatory or operational requirements demand predictable process behavior
- the team expects classic VM/container hosting semantics
