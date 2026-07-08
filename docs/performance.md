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

Those are now optimized. Preseeding TYPO3's generated code cache in the image
was tested and reverted because TYPO3's runtime cache can include environment-
specific state that is not safe to reuse across Vercel runtime starts.

## Container Optimizations

The immutable image is tuned for the serverless model:

- **Primed OPcache file cache.** `docker/php.ini` sets `opcache.file_cache` to a
  build-time-warmed directory (`scripts/warm-opcache.php`). Because the Vercel
  filesystem is read-only except `/tmp`, the cache is populated once at build and
  read by every new instance, so the first request loads compiled opcodes from
  disk instead of recompiling the TYPO3 codebase.
- **Tracing JIT.** `opcache.jit=tracing` (64 MB buffer) trims CPU on warm
  requests on PHP 8.4.
- **Authoritative autoloader.** `composer install --classmap-authoritative`
  removes the per-`class_exists()` filesystem fallback; safe because no classes
  are generated at runtime.
- **Warm Apache workers.** `mpm_prefork` starts with enough workers to answer a
  cold instance's first page plus its parallel asset requests immediately,
  instead of ramping up one worker at a time.
- **Edge-cached static assets.** `public/.htaccess` sends `s-maxage` on static
  files so Vercel's CDN serves CSS/JS/fonts/images without invoking the
  container — and without paying a cold start for stray asset requests after
  scale-to-zero.
- **Brotli compression.** Enabled alongside gzip for smaller text payloads.
- **Object storage verified only on change.** The boot script skips the storage
  write and its network folder verification when the stored record is already
  correct, keeping cold starts cheap.

## Current Live Measurements

Measured on 2026-07-07 against
`https://typo3-camino-vercel.vercel.app` after adding the Vercel Blob FAL
driver, enabling production object storage, moving the public demo project to
Vercel's performance CPU class in `fra1`, and enabling Redis through the
official Redis Vercel Marketplace integration.

All tested requests returned HTTP `200`.

| Route | Result |
| --- | --- |
| Frontend home page, `/`, first request after deploy | 12.57 seconds |
| Frontend home page, `/`, warm 10-request pass | median 0.046 seconds, range 0.031-0.173 seconds |
| Backend login page, `/typo3/`, first Redis-enabled pass | first hit 0.431 seconds, then 0.129-0.155 seconds |
| Backend login page, `/typo3/`, warm 10-request pass | median 0.125 seconds, range 0.110-0.168 seconds |
| Backend login page, `/typo3/`, later cold check | first hit 10.151 seconds, then 0.206-0.238 seconds |
| Backend login preflight, `/typo3/ajax/login/preflight`, first Redis-enabled pass | 0.089-0.159 seconds |
| Backend login preflight, `/typo3/ajax/login/preflight`, warm 10-request pass | median 0.100 seconds, range 0.083-0.157 seconds |
| Backend login preflight, `/typo3/ajax/login/preflight`, later 5-request check | median 0.175 seconds, range 0.088-0.244 seconds |

Earlier baseline after the database/object-storage/performance-CPU work, but
before Redis:

| Route | Earlier result |
| --- | --- |
| Frontend home page, `/` | first hit 1.33 seconds, then 0.12-0.22 seconds |
| Backend login page, `/typo3/` | 0.23-0.41 seconds |
| Backend login preflight, `/typo3/ajax/login/preflight` | 0.16-0.25 seconds |
| Cold-start spike after deploy, `/` | 12.38 seconds |
| Cold-start spike after deploy, `/typo3/` | 10.61 seconds |

One earlier five-request backend probe also produced one transient Vercel
`500` after about 25 seconds. It did not repeat in the later warm samples, but
it is a reminder that cold starts and platform-level invocation outliers are
still possible.

The answer to "is the backend faster now?" is:

- warm backend responses are faster in the Redis-enabled sample, around
  0.11-0.17 seconds for the login page and around 0.08-0.16 seconds for login
  preflight
- Redis is not the only possible variable in a live Vercel measurement, so read
  this as a real production sample, not a controlled lab benchmark
- cold starts are still not materially solved; the frontend showed a 12.57
  second first request after deploy, and a later backend login check still hit
  10.151 seconds once
- backend pages cannot use the optional Vercel edge HTML cache because they use
  cookies, sessions, and no-store headers

Authenticated backend navigation was not included in this measurement because
the pulled local Vercel env file does not expose the sensitive backend
password. The login page and login preflight still exercise the TYPO3 backend
container, database/session setup, PHP runtime, and uncached backend response
path.

## Runtime Region

`vercel.json` pins deployments to `fra1`:

```json
"regions": ["fra1"]
```

The `webconsulting` production project default region is also set to `fra1` in
the Vercel Project API, so future CLI/dashboard deploys should keep the same
runtime region.

Keep this close to the database. If the database is in another region, move the
function region to the database region first.

`fra1` means Vercel runs the TYPO3 container in Frankfurt. That is a good fit
for users in Austria and nearby EU countries because the network round trip is
shorter than a US region. It is only fully useful when the database and object
storage are also close to the same region; otherwise TYPO3 may still wait on
cross-region database or file-storage calls.

## Memory And CPU

Vercel Container Images use the Vercel Functions resource model. Memory/CPU is
not configured in `vercel.json`; attempting to set function memory there is
ignored or warned about at build time.

For Pro or Enterprise projects, increase memory/CPU in the dashboard:

1. Open the Vercel project.
2. Go to **Settings**.
3. Open **Functions**.
4. Open **Advanced Settings**.
5. Change **Function CPU** from the default tier to the performance tier.
6. Redeploy production.

The same setting is also available through the Vercel Project API as
`resourceConfig.functionDefaultMemoryType`. The `webconsulting` production
project has been switched from `standard` to `performance`.

On Hobby, the memory/CPU size is fixed by Vercel. You cannot increase it for a
free test deployment.

For this public demo, "Pro/performance CPU" means the project is using Vercel's
higher Function CPU class available on Pro, not the default standard CPU tier.
That should make PHP bootstrap, TYPO3 backend rendering, image processing, and
Composer-autoload-heavy work faster once the container is already running.

More memory can help CPU-heavy PHP work, image processing, or memory pressure.
It will not remove all TYPO3 backend latency because uncached backend requests
still need PHP bootstrap, database/session work, and sometimes a fresh Vercel
runtime start.

The practical result:

- performance CPU improves warm request time
- `fra1` improves European latency when the database is nearby
- neither setting guarantees instant first requests after inactivity
- occasional 10+ second cold starts are still possible with Vercel Container
  Images

## TYPO3 Cache Backend

On Vercel, this starter defaults page, hash, and rootline caches to TYPO3's file
backend:

```dotenv
TYPO3_CACHE_BACKEND=file
```

Those cache files live under the runtime `/tmp` state and are disposable. This
is intentional for Vercel: content and sessions belong in the real database,
but generated caches can be rebuilt per instance.

The default free-demo database is explicitly:

```dotenv
TYPO3_DB_DRIVER=pdo_sqlite
TYPO3_DB_DBNAME=/tmp/typo3/camino.sqlite
```

If these are present in Vercel as empty env vars, set them to the values above
or remove them. Empty overrides can make the runtime fall back to the wrong DB
driver and make backend behavior inconsistent.

Use this if warm frontend speed matters. Use this instead if you prefer shared
cache rows in the database:

```dotenv
TYPO3_CACHE_BACKEND=database
```

## Redis Cache

The image includes the PHP Redis extension, and TYPO3 can use Redis for
`hash`, `pages`, and `rootline` caches. The public demo now uses Redis Cloud
through the Vercel Marketplace:

```dotenv
TYPO3_CACHE_BACKEND=redis
TYPO3_REDIS_REQUIRED=1
TYPO3_REDIS_PREFIX=typo3-camino-vercel:
REDIS_URL=<provided-by-vercel-marketplace>
```

Use Redis only with a real `redis://` or `rediss://` TCP/TLS endpoint close to
the Vercel region. Vercel KV is no longer available for new projects; Vercel's
current Redis path is a Marketplace Redis integration. Upstash/Vercel REST
variables such as `KV_REST_API_URL` are not enough for TYPO3's native Redis
backend.

Redis can help when many Vercel containers need shared warm caches. In this
demo it improved the measured warm backend path. It still does not solve cold
starts, SQL session durability, or file durability.

See [redis-cache.md](redis-cache.md) for setup, supported env variables,
costs, and troubleshooting.

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

For the public `webconsulting` demo, the Vercel project is on the performance
CPU class and pinned to `fra1`. That improves warm PHP work, but it does not
remove Vercel cold starts.

For new clones, start with:

1. durable database in the same region as the function
2. `TYPO3_CACHE_BACKEND=file`
3. optional Redis only when shared TYPO3 cache state is needed
4. optional edge HTML cache for anonymous pages
5. startup flags set to `0` after one-shot setup work is complete
6. object storage for durable uploads

On Pro/Enterprise, raise the Vercel memory/CPU tier when backend PHP work,
image processing, or logs show CPU or memory pressure. On Hobby, that tier is
fixed.
