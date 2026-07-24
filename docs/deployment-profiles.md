# Deployment Profiles

Vercel is the selected production platform. The repository contains two Vercel
profiles and one non-selected Hetzner comparison profile.

| | One-click evaluation | Selected production | Price comparison only |
|---|---|---|---|
| Hosting | Vercel Hobby | Vercel Pro | Hetzner Cloud |
| Configuration | `vercel.json` | `vercel.pro.json` | `compose.hetzner.yaml` |
| Goal | Disposable evaluation | Operate the Camino deployment | Compare price and capabilities |
| Search | No Solr service | Private self-seeding Solr demo service | Private persistent Solr 10 |
| Scheduler | None | Protected call every 15 minutes | Dedicated worker |
| Residency | Scale-to-zero | Scale-to-zero with safe edge caching | Resident processes |
| Status | Supported | **Selected** | Not selected; no cutover planned |

## One-Click Evaluation

Use the **Deploy with Vercel** button in the README. Enter a backend username,
a strong password, and a stable encryption key. No database or command line is
required.

The default `vercel.json` deliberately deploys only the TYPO3 application:

- one PHP 8.5/nginx Dockerfile-backed container Service
- no Solr container
- no scheduled jobs
- a pre-seeded Camino SQLite copy in `/tmp`
- bundled demo images and video
- a Vercel Blob store when accepted in the Deploy Button flow

Anonymous, cookie-free demo pages receive a short Vercel CDN cache policy after
TYPO3 confirms that they are safe to share. Query strings, forms, backend
routes, personalized requests, and private responses are never shared.

This profile is disposable. Database records and file metadata can disappear
when Vercel replaces the instance unless durable external services are
configured.

## Selected Vercel Production Profile

Deploy `vercel.pro.json` with:

```bash
VERCEL_SCOPE=webconsulting scripts/deploy-pro.sh
```

The selected profile adds the private multilingual Solr demonstration service
and retains only the bounded 15-minute Scheduler cron. The former one-minute
deep warmer is deliberately absent because it created sustained compute cost
without reserving an instance.

Production project settings use the Standard build machine. Public pages use
safe edge caching, while uncached TYPO3 and Solr requests may still experience
Vercel activation. This tradeoff is accepted by ADR-014.

Use a durable external SQL database and Blob/S3 object storage for editorial
content that must survive replacement. Redis may be used for shared TYPO3
caches. The private self-seeding Solr service proves search integration but its
local index is not a durable editor-managed production index.

## Hetzner Comparison Profile

`compose.hetzner.yaml` exists to make the cost and capability comparison
reproducible. It includes TYPO3, MariaDB, Redis, persistent Solr, Scheduler,
and Caddy TLS on one always-on host.

The measured price is €19.69/month excluding VAT for a CX43, provider backups,
and one IPv4 address. This is lower than the expected $30/month Vercel invoice,
but it transfers operating-system, database, search, monitoring, and recovery
work to the operator.

This profile is **not** a migration plan. No Hetzner provisioning, production
data transfer, DNS change, or cutover is authorized or planned.

Continue with [Quickstart](quickstart.md), the
[Vercel production hardening guide](production-hardening.md), or the
[billing comparison](vercel-billing-review.md).
