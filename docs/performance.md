# Performance Notes

## What Was Slow

TYPO3 was not globally running with debug cache disabled. OPcache is enabled in
the container and TYPO3 page caching is active.

The slow live requests were mostly waiting, not burning CPU:

- Vercel provisioned memory: 2048 MB.
- Hot frontend CPU time: about 80 ms.
- Hot frontend request duration: about 1.5 seconds.
- Public HTML returned `Cache-Control: private, no-store`, so Vercel CDN could
  not cache it.
- Requests entered Europe but the function ran in `iad1`.

That points to database/cache/network latency and server-side TYPO3 work, not a
clear need for a bigger Vercel package.

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

## Optional Edge HTML Cache

The included middleware can opt anonymous public HTML into Vercel shared-cache
headers:

```dotenv
TYPO3_VERCEL_EDGE_CACHE_TTL=60
TYPO3_VERCEL_EDGE_CACHE_STALE_WHILE_REVALIDATE=300
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

## Vercel Package

A larger Vercel memory/CPU setting is not the first fix for this demo. The
measured CPU time was much smaller than total request time. Start with:

1. durable database in the same region as the function
2. `TYPO3_CACHE_BACKEND=file`
3. optional edge HTML cache for anonymous pages
4. object storage for durable uploads

Consider a higher Vercel memory/CPU tier only after metrics show CPU-bound PHP
work or memory pressure.
