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
   sessions, and GraphicsMagick temp paths stay in `/tmp`.
5. **Caches:** runtime-local TYPO3 file caches for small demos, optional Redis
   only when shared cache state matters more than avoiding a network hop.
6. **Frontend cache:** optional Vercel CDN cache for anonymous public HTML only.
7. **Cold-start mitigation:** Vercel Cron or an external uptime monitor calls a
   lightweight endpoint and, when needed, the TYPO3 backend login route.
8. **Scheduler:** TYPO3 Scheduler runs through the protected HTTP cron endpoint,
   not a Linux daemon inside the container.

This is the shape already used by the public demo, except that keepalive is not
enabled by default because the public template must also work for free Hobby
deployments.

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

Production environment:

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

Optional anonymous frontend cache:

```dotenv
TYPO3_VERCEL_EDGE_CACHE_TTL=600
TYPO3_VERCEL_EDGE_CACHE_STALE_WHILE_REVALIDATE=3600
```

Do not enable the edge HTML cache for pages with frontend login, forms,
personalization, previews, carts, or uncached plugins.

## Cold-Start Mitigation

The public demo measured warm backend responses around 0.35-0.40 seconds, but
cold backend requests can still be around 10-12 seconds. The practical
mitigation is to keep the relevant runtime path warm.

For Pro projects, add a keepalive cron only in real projects, not in the public
template:

```json
{
  "crons": [
    {
      "path": "/_vercel_keepalive.php",
      "schedule": "*/5 * * * *"
    }
  ]
}
```

For backend editor comfort, also warm the actual TYPO3 backend route with an
external uptime monitor:

```text
GET https://<project>.vercel.app/typo3/
every 5 minutes
```

Why both can be useful:

- `/_vercel_keepalive.php` is cheap and keeps the PHP/Apache container path
  active.
- `/typo3/` exercises TYPO3 backend bootstrap and database/session plumbing.

Do not rely on this for hard realtime guarantees. It is a mitigation, not an
SLA feature. If strict first-hit latency is required, use Architecture B until
Vercel offers a minimum-instances or always-warm option for Container Images.

## What To Improve Next In This Repo

The next useful engineering improvements are:

1. **Build-speed base image:** publish a base image with the PHP extensions,
   GraphicsMagick, Ghostscript, Apache modules, and Composer already installed.
   This should reduce multi-minute clean builds.
2. **Warmup profile doc/command:** provide an optional `vercel` cron snippet for
   Pro users without enabling it for Hobby clones.
3. **Health endpoint:** add a protected health endpoint that can optionally
   verify database and object storage. Keep the current public keepalive cheap.
4. **Backend measurement script:** commit a small script that measures cold and
   warm `/`, `/typo3/`, and preflight timings separately.
5. **Blob backup:** document or add a cron-safe Blob backup path for production
   users who need export/restore guarantees.

## Decision Rule

Use Vercel-native TYPO3 when:

- public pages matter more than backend first-hit latency
- editors can tolerate the occasional cold backend hit
- content lives in a real DB
- uploads live in Blob/S3
- cache and keepalive are configured deliberately

Use hybrid/always-on TYPO3 when:

- the backend must feel instant after inactivity
- image processing is heavy
- traffic/concurrency is high
- regulatory or operational requirements demand predictable process behavior
- the team expects classic VM/container hosting semantics
