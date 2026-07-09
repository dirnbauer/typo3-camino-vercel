# Redis Cache On Vercel

This project supports TYPO3's native Redis cache backend for the `hash`,
`pages`, and `rootline` caches. The public demo uses **Upstash for Redis** from
the Vercel Marketplace through its standard TLS Redis endpoint.

Redis is optional for small clones. It is useful when more than one Vercel
runtime instance should share warm TYPO3 caches. It is not a replacement for
the SQL database, and it does not make uploaded files durable.

## Current Public Demo Setup

The production demo at `https://typo3-camino-vercel.vercel.app` is configured
with:

```dotenv
TYPO3_CACHE_BACKEND=redis
TYPO3_REDIS_REQUIRED=1
TYPO3_REDIS_PREFIX=typo3-camino-vercel:
TYPO3_REDIS_URL=<provided by Upstash with the TYPO3_ prefix>
```

`TYPO3_REDIS_REQUIRED=1` is intentional. If Redis is requested but the
container has no usable Redis TCP/TLS connection, startup fails instead of
quietly falling back to file cache.

The current resource is the Upstash Marketplace `Free` plan in `fra1`, with
eviction enabled and automatic paid-plan upgrades disabled. It is suitable for
this small disposable cache; production sizing and support requirements must be
chosen separately.

### Why The Public Demo Changed Provider

On 2026-07-09, the existing official Redis Cloud Marketplace endpoint accepted
the same credentials and `PING` from the local production Docker image, but
connections from the deployed Vercel Container repeatedly ended with a Redis
read error. The database and Vercel region were both Frankfurt. The replacement
Upstash TLS endpoint passed `PING` and a write/read/delete probe with the same
image before deployment.

This is a record of one resource/runtime incident, not a claim that Redis Cloud
is generally incompatible with Vercel. It demonstrates why the protected deep
health check must be run after provisioning instead of treating an injected
environment variable as proof of connectivity.

## What It Improved

Measured against the live public demo after enabling Redis on 2026-07-07:

| Route | Redis-enabled result |
| --- | --- |
| Frontend `/`, first request after deploy | 12.57s |
| Frontend `/`, warm 10-request pass | median 0.046s, range 0.031-0.173s |
| Backend login `/typo3/`, warm 10-request pass | median 0.125s, range 0.110-0.168s |
| Backend login `/typo3/`, later cold check | first hit 10.151s, then 0.206-0.238s |
| Backend login preflight Ajax, warm 10-request pass | median 0.100s, range 0.083-0.157s |

Before Redis, the latest documented warm backend sample was roughly
0.23-0.41s for `/typo3/` and 0.16-0.25s for login preflight. The
Redis-enabled sample is better for these backend endpoints, but this is not a
lab benchmark with every variable isolated.

The important conclusion is:

- Redis helped the measured warm backend path.
- Redis did not remove the 10-13s Vercel container cold-start class. A later
  backend check still saw `/typo3/` at 10.151s once.
- Redis does not fix SQLite demo-mode backend sessions. A durable database is
  still required for stable login.
- Redis does not make uploads durable. Vercel Blob or S3-compatible object
  storage is still required.

## Step By Step: Vercel Dashboard

1. Open the Vercel project.
2. Open **Storage** or **Marketplace**.
3. Choose **Upstash for Redis**.
4. Create a database on the **Free** plan.
5. Pick a region close to the Vercel function region. For this demo, both are
   in or near Frankfurt (`fra1`).
6. Enable eviction and disable automatic plan upgrades if the demo must remain
   free.
7. Connect the resource to the Vercel project and production environment with
   environment-variable prefix `TYPO3_`.
8. Confirm Vercel added `TYPO3_REDIS_URL` to the project environment.
9. Add these project environment variables:

```dotenv
TYPO3_CACHE_BACKEND=redis
TYPO3_REDIS_REQUIRED=1
TYPO3_REDIS_PREFIX=typo3-camino-vercel:
```

10. Redeploy production.

## Step By Step: Vercel CLI

The reproducible free setup with Vercel CLI 54.6.1 is:

```bash
vercel integration add upstash/upstash-kv \
  --scope <team-or-user-scope> \
  --name <upstash-resource-name> \
  --plan free \
  --prefix TYPO3_ \
  --environment production \
  --metadata primaryRegion=fra1 \
  --metadata eviction=true \
  --metadata prodPack=false \
  --metadata autoUpgrade=false \
  --format=json
```

`--prefix TYPO3_` maps the provider's `REDIS_URL` to
`TYPO3_REDIS_URL`. This also avoids collisions when a project already has a
different `REDIS_URL`.

Then set the TYPO3 cache variables:

```bash
vercel env add TYPO3_CACHE_BACKEND production --value redis --force --yes --scope <team-or-user-scope>
vercel env add TYPO3_REDIS_REQUIRED production --value 1 --force --yes --scope <team-or-user-scope>
vercel env add TYPO3_REDIS_PREFIX production --value typo3-camino-vercel: --force --yes --scope <team-or-user-scope>
vercel deploy --prod -A vercel.pro.json --scope <team-or-user-scope> --yes
```

Check the environment:

```bash
vercel env ls production --scope <team-or-user-scope>
```

You should see `TYPO3_REDIS_URL`, `TYPO3_CACHE_BACKEND`,
`TYPO3_REDIS_REQUIRED`, and `TYPO3_REDIS_PREFIX`.

## Supported Environment Variables

The easiest setup is one URL:

```dotenv
TYPO3_CACHE_BACKEND=redis
TYPO3_REDIS_REQUIRED=1
TYPO3_REDIS_URL=rediss://default:<password>@<host>:6379/0
TYPO3_REDIS_PREFIX=typo3-camino-vercel:
```

The code also accepts provider-injected aliases:

```dotenv
REDIS_URL=redis://...
UPSTASH_REDIS_URL=rediss://...
KV_URL=rediss://...
```

If the provider gives separate fields instead of one URL, these are supported:

```dotenv
TYPO3_REDIS_HOST=<host-or-rediss-url>
TYPO3_REDIS_PORT=6380
TYPO3_REDIS_TLS=1
TYPO3_REDIS_USERNAME=default
TYPO3_REDIS_PASSWORD=<password>
TYPO3_REDIS_DATABASE=0
```

`TYPO3_REDIS_HOST` may be only a hostname, or a scheme-bearing endpoint such as
`rediss://default:<password>@<host>:6380/0`. URL variables are preferred; remove
them before switching to separate component variables.

Provider aliases are supported for common Vercel/Upstash names:

```dotenv
REDIS_HOST=<host>
REDIS_ENDPOINT=<host>
REDIS_PORT=6380
REDIS_TLS=1
REDIS_USERNAME=default
REDIS_USER=default
REDIS_PASSWORD=<password>
REDIS_PASS=<password>
REDIS_DATABASE=0
REDIS_DB=0
UPSTASH_REDIS_HOST=<host>
UPSTASH_REDIS_ENDPOINT=<host>
UPSTASH_REDIS_PORT=6379
UPSTASH_REDIS_TLS=1
UPSTASH_REDIS_USERNAME=default
UPSTASH_REDIS_PASSWORD=<password>
UPSTASH_REDIS_TOKEN=<password>
```

## Important Upstash Note

TYPO3's native Redis backend needs the PHP Redis extension and a real Redis
TCP/TLS endpoint, such as `redis://` or `rediss://`.

REST-only variables are not enough:

```dotenv
KV_REST_API_URL=...
KV_REST_API_TOKEN=...
UPSTASH_REDIS_REST_URL=...
UPSTASH_REDIS_REST_TOKEN=...
```

Those variables work for JavaScript/REST clients, but not for TYPO3's native
`TYPO3\CMS\Core\Cache\Backend\RedisBackend`.

## Costs

For the public demo, the Upstash Marketplace resource uses the `Free` plan with
automatic upgrade disabled. It remains free while usage stays within the
provider's current free quota and terms; requests over the quota can be rejected
rather than silently moving this configured resource to a paid plan.

For production:

- cache memory must be sized for the TYPO3 site and traffic
- paid plans may be needed for persistence, high availability, larger memory,
  support, or production SLAs
- caches are disposable by design, so persistence is less important than it
  would be for primary data
- the durable SQL database and Blob/S3 file storage still need their own free
  or paid plans

## Troubleshooting

If production returns `500` immediately after enabling Redis:

1. Check that `REDIS_URL` or `TYPO3_REDIS_URL` starts with `redis://` or
   `rediss://`.
2. Check that the Redis resource is fully provisioned and connected to the same
   Vercel project/environment as the deployment.
3. Keep the Redis region close to the Vercel function region.
4. Inspect runtime logs.
5. To restore the site quickly, set:

```dotenv
TYPO3_CACHE_BACKEND=file
TYPO3_REDIS_REQUIRED=0
```

Then redeploy.

If backend login still logs out quickly, Redis is not the missing piece. Add a
durable database through `DATABASE_URL`; TYPO3 backend sessions use the SQL
database in this starter.

## Sources

- Vercel Redis docs: https://vercel.com/docs/redis
- Vercel storage docs: https://vercel.com/docs/storage
- Vercel Redis Marketplace listing: https://vercel.com/marketplace/redis
- Vercel Upstash Marketplace listing: https://vercel.com/marketplace/upstash
- Upstash Redis documentation: https://upstash.com/docs/redis
