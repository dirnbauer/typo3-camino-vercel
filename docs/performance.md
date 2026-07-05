# Performance Notes

## What Was Slow

TYPO3 was not globally running with debug cache disabled. OPcache is enabled in
the container and TYPO3 page caching is active.

The slow live requests were mostly cold starts and waiting, not burning CPU:

- cold frontend/backend requests were around 10-13 seconds
- warm frontend requests were around 0.12-0.35 seconds
- public anonymous HTML can be cached at Vercel's edge
- backend requests must stay uncached and will always be slower than frontend
  edge hits

The largest avoidable startup costs were self-inflicted:

- the entrypoint re-applied the TYPO3 admin password on every cold start
- the entrypoint ran the object-storage setup script even when object storage
  was not enabled
- Apache parsed `.htaccess` on every request
- PHP OPcache used conservative defaults instead of immutable-container
  settings

Those are now optimized.

## Runtime Region

`vercel.json` pins the deployment to `fra1`:

```json
"regions": ["fra1"]
```

Keep this close to the database. If the database is in another region, move the
function region to the database region first.

## TYPO3 Cache Backend

On Vercel, this starter defaults page, hash, and rootline caches to TYPO3's file
backend:

```dotenv
TYPO3_CACHE_BACKEND=file
```

Those cache files live under the runtime `/tmp` state and are disposable. This
is intentional for Vercel: content and sessions belong in the real database,
but generated caches can be rebuilt per instance.

Use this if warm frontend speed matters. Use this instead if you prefer shared
cache rows in the database:

```dotenv
TYPO3_CACHE_BACKEND=database
```

## Optional Redis Cache

The image includes the PHP Redis extension, and TYPO3 can use Redis for
`hash`, `pages`, and `rootline` caches:

```dotenv
TYPO3_CACHE_BACKEND=redis
TYPO3_REDIS_URL=rediss://default:<password>@<host>:6379/0
TYPO3_REDIS_PREFIX=typo3-camino-vercel:
```

Use Redis only with a real `redis://` or `rediss://` TCP/TLS endpoint close to
the Vercel region. Upstash/Vercel REST variables such as `KV_REST_API_URL` are
not enough for TYPO3's native Redis backend.

Redis can help when many Vercel containers need shared warm caches. For a small
demo, local file cache plus Vercel edge cache is usually faster because it
avoids another network hop.

## Optional Edge HTML Cache

The included middleware can opt anonymous public HTML into Vercel shared-cache
headers:

```dotenv
TYPO3_VERCEL_EDGE_CACHE_TTL=600
TYPO3_VERCEL_EDGE_CACHE_STALE_WHILE_REVALIDATE=3600
```

It only changes responses when all of these are true:

- request method is `GET` or `HEAD`
- path is not `/typo3` and not `/api`
- request has no query string
- request has no `Cookie` header
- response status is `200`
- response is HTML
- response has no `Set-Cookie`

Do not enable this blindly for pages with personalization, frontend login,
forms, carts, previews, or uncached plugins. Keep the TTL short until the site
is tested.

The middleware sends `Cache-Control: public, max-age=0` to browsers and uses
`CDN-Cache-Control` plus `Vercel-CDN-Cache-Control` for the shared cache. Check
`x-vercel-cache` in the response headers to confirm `HIT` after the first
request.

## Cold Starts

Normal production startup should not run setup work. After the first successful
database setup:

```dotenv
TYPO3_AUTO_SETUP=0
TYPO3_BOOTSTRAP_EMPTY_DATABASE=0
TYPO3_EXTENSION_SETUP_ON_BOOT=0
TYPO3_ADMIN_PASSWORD_APPLY_ON_BOOT=0
```

Set `TYPO3_ADMIN_PASSWORD_APPLY_ON_BOOT=1` only for one deploy after rotating
`TYPO3_SETUP_ADMIN_PASSWORD`, then set it back to `0`.

Set `TYPO3_EXTENSION_SETUP_ON_BOOT=1` only for one deploy after adding/removing
TYPO3 packages against an existing durable database, then set it back to `0`.

The template includes `/_vercel_keepalive.php`, a lightweight endpoint for a
Pro Vercel Cron or external scheduler. Vercel Hobby cron jobs can run only once
per day, so this endpoint is not scheduled by default in `vercel.json`.

## Temporary Files And Image Processing

The container includes GraphicsMagick and Ghostscript. TYPO3 is configured for:

```dotenv
TYPO3_GFX_PROCESSOR=GraphicsMagick
TYPO3_GFX_PROCESSOR_PATH=/usr/bin/
```

The entrypoint points PHP upload temp files, normal temp files, and
GraphicsMagick temp files into writable runtime storage:

```text
/tmp/typo3/tmp
/tmp/typo3/gm
/tmp/typo3/php-sessions
```

`public/typo3temp`, `public/fileadmin`, and `var` are symlinked to `/tmp` on
Vercel. That supports TYPO3's normal temporary files and image processing, but
runtime files are disposable. Durable editor uploads still need object storage.

## Vercel Package

A larger Vercel memory/CPU setting is not the first fix for this demo. The
measured CPU time was much smaller than total request time. Start with:

1. durable database in the same region as the function
2. `TYPO3_CACHE_BACKEND=file`
3. optional edge HTML cache for anonymous pages
4. startup flags set to `0` after one-shot setup work is complete
5. object storage for durable uploads

Consider a higher Vercel memory/CPU tier only after metrics show CPU-bound PHP
work or memory pressure.
