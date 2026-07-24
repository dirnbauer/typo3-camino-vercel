# Redis Cache On Vercel

This project supports TYPO3's native Redis cache backend for the `hash`,
`pages`, and `rootline` caches. The public demo uses **Upstash for Redis**
from the Vercel Marketplace through its standard TLS Redis endpoint.

Redis is optional. It is useful when more than one Vercel runtime instance
should share warm TYPO3 caches. It does not replace the SQL database, does
not store uploads, and does not remove container cold starts.

## Current Public Demo Setup

```dotenv
TYPO3_CACHE_BACKEND=redis
TYPO3_REDIS_REQUIRED=1
TYPO3_REDIS_PREFIX=typo3-camino-vercel:
TYPO3_REDIS_URL=<provided by Upstash with the TYPO3_ prefix>
```

`TYPO3_REDIS_REQUIRED=1` is intentional: if Redis is requested but no usable
TCP/TLS connection exists, startup fails instead of quietly falling back to
file cache.

The demo resource is the Upstash Marketplace `Free` plan in `fra1` with
eviction disabled and automatic paid upgrades disabled — suitable for a
small disposable cache, not a production sizing recommendation.

Provider note: the previous Redis Cloud Marketplace endpoint passed local
`PING` tests but repeatedly failed with read errors from the deployed Vercel
container (2026-07-09, both sides in Frankfurt). The Upstash replacement
passed the same probes and the deployed deep health check (Redis over TLS in
28 ms). Lesson: always run the protected deep health check after
provisioning; an injected environment variable is not proof of connectivity.

## What It Improved

Measured on the live public demo after enabling Redis on 2026-07-07:

| Route | Redis-enabled result |
| --- | --- |
| Frontend `/`, first request after deploy | 12.57s |
| Frontend `/`, warm 10-request pass | median 0.046s |
| Backend login `/typo3/`, warm 10-request pass | median 0.125s |
| Backend login `/typo3/`, later cold check | first hit 10.151s, then 0.206-0.238s |

Redis improved the measured warm backend path (previously roughly
0.23-0.41s). It did not remove the 10-13s cold-start class, and a durable
database is still required for stable login.

## Setup: Vercel Dashboard

1. Project > **Storage**/**Marketplace** > **Upstash for Redis**.
2. Create a database on the **Free** plan near the compute region (`fra1`
   here); keep eviction disabled and disable auto-upgrade for a free demo.
3. Connect it to the project and production environment with
   environment-variable prefix `TYPO3_`, so Vercel injects `TYPO3_REDIS_URL`.
4. Add `TYPO3_CACHE_BACKEND=redis`, `TYPO3_REDIS_REQUIRED=1`, and
   `TYPO3_REDIS_PREFIX=typo3-camino-vercel:`.
5. Redeploy production.

## Setup: Vercel CLI

```bash
vercel integration add upstash/upstash-kv \
  --scope <team-or-user-scope> \
  --name <upstash-resource-name> \
  --plan free \
  --prefix TYPO3_ \
  --environment production \
  --metadata primaryRegion=fra1 \
  --metadata eviction=false \
  --metadata prodPack=false \
  --metadata autoUpgrade=false \
  --format=json

vercel env add TYPO3_CACHE_BACKEND production --value redis --force --yes --scope <team-or-user-scope>
vercel env add TYPO3_REDIS_REQUIRED production --value 1 --force --yes --scope <team-or-user-scope>
vercel env add TYPO3_REDIS_PREFIX production --value typo3-camino-vercel: --force --yes --scope <team-or-user-scope>
VERCEL_SCOPE=<team-or-user-scope> scripts/deploy-pro.sh
```

`--prefix TYPO3_` maps the provider's `REDIS_URL` to `TYPO3_REDIS_URL` and
avoids collisions with an existing `REDIS_URL`. Verify with
`vercel env ls production`.

## Supported Environment Variables

The easiest setup is one URL:

```dotenv
TYPO3_CACHE_BACKEND=redis
TYPO3_REDIS_REQUIRED=1
TYPO3_REDIS_URL=rediss://default:<password>@<host>:6379/0
TYPO3_REDIS_PREFIX=typo3-camino-vercel:
```

Provider-injected `REDIS_URL`, `UPSTASH_REDIS_URL`, and `KV_URL` are accepted
aliases. If the provider gives separate fields instead of one URL, use
`TYPO3_REDIS_HOST` (hostname or scheme-bearing endpoint), `TYPO3_REDIS_PORT`,
`TYPO3_REDIS_TLS`, `TYPO3_REDIS_USERNAME`, `TYPO3_REDIS_PASSWORD`, and
`TYPO3_REDIS_DATABASE`; the common `REDIS_*` and `UPSTASH_REDIS_*` provider
names are accepted as aliases too. URL variables win; remove them before
switching to component variables.

`TYPO3_REDIS_PERSISTENT_CONNECTION` defaults to `1`: without it, every
request pays a fresh TCP+TLS handshake per cache (hash, pages, rootline are
three connections). The hardened backend makes this safe where core's is
not — it retries once over a reconnect when a provider closed an idle
socket, enables TCP keepalive, and bounds each command with a read timeout
(`TYPO3_REDIS_READ_TIMEOUT`, default 2 s) so a wedged server degrades fast
instead of pinning a worker. Set the variable to `0` as a kill switch if a
provider still misbehaves.

With the Redis cache backend active, backend user sessions also move from
the `be_sessions` table to Redis (`TYPO3_REDIS_SESSIONS=0` reverts): the
backend can never use the page or edge caches, so every click otherwise
pays session round trips to the remote SQL database. Sessions stay durable
across instance replacement either way.

Run production Redis with eviction disabled (the Upstash default) plus
quota monitoring. Upstash eviction is "optimistic-volatile": its no-TTL
fallback stage may evict cache tag sets (silently breaking
invalidation-by-tag until TTLs expire) and session keys (random backend
logouts). At quota with eviction off, cache writes degrade to misses and
new logins fail loudly — prefer that failure mode, or pair eviction with
`TYPO3_REDIS_SESSIONS=0`. The manual warmup probe can prune the previous
deployment's page-cache keys and reports the connection count against the
provider's limit.

## Important Upstash Note

TYPO3's native Redis backend needs the PHP Redis extension and a real Redis
TCP/TLS endpoint (`redis://` or `rediss://`). REST-only variables such as
`KV_REST_API_URL`/`KV_REST_API_TOKEN` or `UPSTASH_REDIS_REST_*` work for
JavaScript clients, not for TYPO3's `RedisBackend`.

## Costs

The free Upstash plan is a quota, not production capacity. With auto-upgrade
disabled, over-quota requests can be rejected rather than silently billed.
For production, size the cache for the site, and price persistence, high
availability, and support separately. Caches are disposable by design, so
capacity and SLA usually matter more than persistence.

## Troubleshooting

If production returns `500` immediately after enabling Redis:

1. Check that the injected URL starts with `redis://` or `rediss://`.
2. Check that the resource is provisioned and connected to the same project
   and environment as the deployment.
3. Keep the Redis region close to the Vercel region, and inspect runtime logs.
4. To restore the site quickly: `TYPO3_CACHE_BACKEND=file`,
   `TYPO3_REDIS_REQUIRED=0`, redeploy.

If backend login still logs out quickly, Redis is not the missing piece: add
a durable database (see [backend login](backend-login.md)).

## Sources

- Vercel Redis docs: https://vercel.com/docs/redis
- Vercel Upstash Marketplace listing: https://vercel.com/marketplace/upstash
- Upstash Redis documentation: https://upstash.com/docs/redis
