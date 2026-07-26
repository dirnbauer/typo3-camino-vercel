# Four-Platform Deployment Comparison

Rechecked on 2026-07-26. The comparison uses the same root `Dockerfile`, Git
revision, Camino content, shallow health route, and PHP/nginx runtime on all
four platforms.

## Selected Platforms

| Platform | Operating model | Persistent SQL | Persistent media | Scheduler |
|---|---|---|---|---|
| Vercel | Serverless container service | External database | Blob/S3 | Vercel Cron |
| Railway | Managed container PaaS | Railway MySQL template | Volume or S3 | Railway Cron |
| Sliplane | Managed European app server | MariaDB container or managed PostgreSQL | Volume or S3 | Resident worker |
| Coolify/Hetzner | Self-hosted PaaS | MariaDB container | Docker volume or S3 | Resident worker |

Zeabur was evaluated but not selected. It stopped accepting new shared-cluster
projects in March 2026 and now requires a dedicated or bring-your-own server,
which overlaps substantially with the Coolify comparison.

## Installation Status

| Platform | Status | Evidence |
|---|---|---|
| Vercel | Live | `https://typo3-camino-vercel.vercel.app` |
| Railway | Profile ready; live account authorization required | `railway.json`, `platforms/railway/cron.json` |
| Sliplane | Installation runbook ready; live account authorization required | `docs/sliplane.md` |
| Coolify/Hetzner | Compose profile and local test ready; VPS authorization required | `compose.coolify.yaml` |

## Current Price Shape

Prices exclude tax unless explicitly noted. Platform pricing changes; recheck
the linked provider pages before ordering.

| Platform | Reproducible comparison configuration | Expected monthly platform cost |
|---|---|---:|
| Vercel | Existing Pro account plus Analytics Plus; external SQL/Redis/Blob currently within their separate quotas | approximately $30–40 |
| Railway | One app averaging 1 GB/0.10 vCPU, MySQL averaging 1 GB/0.05 vCPU, 20 GB volume storage, and 20 GB egress | approximately $27; usage-based |
| Sliplane | Medium Germany app server with TYPO3, MariaDB, and Scheduler | €28.80 |
| Coolify/Hetzner | CX33, IPv4, provider backups, self-hosted Coolify | €10.69 |

Railway bills actual RAM, CPU, volume storage, and egress. The $27 model is
`$20 RAM + $3 CPU + $3 volumes + $1 egress`; it is an explicit low-traffic
assumption, not a quote. A reliable monthly number must be taken from a live
deployment after at least several days. The $5 Hobby or $20 Pro subscription
minimum includes that amount of usage rather than being added twice. Railway's
current rates are $10/GB-month of RAM, $20/vCPU-month, $0.15/GB-month of
volume storage, and $0.05/GB egress.

Coolify Cloud is an optional additional $5/month; self-hosted Coolify has no
license fee. Operator time and high availability are not included in the
Coolify/Hetzner number. Sliplane includes server operations, TLS, metrics, and
daily volume backups but the selected single server is still not highly
available.

## Benchmark Contract

Run all targets from the same client and at the same time:

```bash
php scripts/benchmark-platforms.php \
  --runs=10 \
  --output=docs/benchmarks/2026-07-26.json \
  Vercel=https://typo3-camino-vercel.vercel.app \
  Railway=https://replace-me.up.railway.app \
  Sliplane=https://replace-me.sliplane.app \
  Coolify=https://replace-me.example.com
```

The script records four distinct request classes:

1. first observed request — useful, but not proof of a cold start
2. warm public `/` requests — includes CDN or proxy cache benefits
3. uncached origin requests — `/` with a cookie and `Cache-Control: no-cache`
4. shallow `/api/health.php` requests — proxy and minimal PHP overhead

Median and p95 are reported separately. A deployment is not accepted merely
because it is fast: the frontend, backend login, database persistence, media
persistence, Scheduler, and backup configuration must also work.

## Baseline Measured On 2026-07-26

Twenty successful requests per public/origin class were measured from Vienna
against the live Vercel production URL:

| Platform | Warm public median / p95 | Uncached origin median / p95 | Health median / p95 |
|---|---:|---:|---:|
| Vercel | 133 / 216 ms | 223 / 291 ms | 183 / 203 ms |

The initial benchmark invocation also observed a 6,023 ms first request after
the service had been idle. A directly repeated run started with a cached
181 ms response. This is useful cold-start evidence but not a controlled
provider cold-start test. The complete successful 20-run sample is stored in
`docs/benchmarks/2026-07-26-vercel.json`.

Railway, Sliplane, and public Coolify measurements remain intentionally blank
until account authorization creates real external deployments. Localhost
numbers would make the self-hosted option look artificially fast and are not
mixed into this table.

## Decision Boundary

- Choose **Vercel** when cacheable public delivery and its existing ecosystem
  outweigh occasional cold starts and external-state complexity.
- Choose **Railway** for the least operational work with conventional
  containers, accepting usage-based cost and unmanaged database templates.
- Choose **Sliplane** for a managed European server with predictable pricing.
- Choose **Coolify/Hetzner** for the lowest infrastructure price and greatest
  control, accepting full server and database responsibility.

Sources: [Vercel pricing](https://vercel.com/pricing),
[Railway pricing](https://docs.railway.com/pricing),
[Sliplane pricing](https://sliplane.io/pricing),
[Coolify pricing](https://coolify.io/pricing), and
[Hetzner pricing](https://docs.hetzner.com/general/infrastructure-and-availability/price-adjustment/).
