# Choose One Of Two Setups

This repository supports two intentionally different deployment profiles. Pick
one before configuring services; do not treat the one-click test as a smaller
production architecture.

| | Solution 1: one-click test | Solution 2: professional hosting |
|---|---|---|
| Goal | Evaluate TYPO3 and Camino quickly | Run a durable editorial site |
| Hosting | Vercel Hobby is enough for personal testing | Always-on host or managed TYPO3 provider |
| Configuration | `vercel.json` | `compose.hetzner.yaml` |
| Database | Temporary pre-seeded SQLite | Private persistent MariaDB |
| Files | Bundled demo media; optional durable Blob uploads | Persistent `fileadmin` volume plus external backups |
| Search | No Solr service | Private persistent Solr 10 |
| Scheduler | None | Dedicated always-on Scheduler container |
| Residency | Scale-to-zero; edge cache for eligible pages | Resident processes; no scale-to-zero |
| Editing | Safe only for disposable experiments | Durable backend sessions and content |

## Solution 1: One-Click Test

Use the **Deploy with Vercel** button in the README. Enter a backend username,
a strong password, and a stable encryption key. No database or command line is
required.

The default `vercel.json` deliberately deploys only the TYPO3 application:

- one PHP 8.5/nginx Dockerfile-backed container Service
- no Solr container
- no scheduled jobs
- a pre-seeded Camino SQLite copy in `/tmp`
- bundled demo images and video
- a Vercel Blob store when it is accepted in the Deploy Button flow

Both profiles also receive the same build-time DI and Fluid template cache.
The compiled files are restored at startup without executing Composer or a
TYPO3 warm-up command on the critical path.

To keep the public test responsive without paid cron, anonymous cookie-free
SQLite demo pages receive a five-minute Vercel CDN cache policy automatically.
TYPO3 first confirms that each page is shared-cacheable. After the first
uncached render, matching page requests can be served without starting PHP.
Query strings, forms, the TYPO3 backend, API routes, personalized requests, and
responses with cookies or private/no-store headers are never shared.

This profile still has an honest limit: the first uncached page or backend
request after scale-to-zero can take roughly 10 to 12 seconds. Backend sessions,
page edits, extension state, and database records can disappear when Vercel
replaces the instance. Blob uploads can be durable, but their TYPO3 metadata is
not durable without SQL.

Use this profile to inspect the frontend, sign in briefly, try the Visual
Editor, view the translated pages, and evaluate the container. Do not use it
for client content or anything that must be retained.

## Solution 2: Professional Hosting

Use this profile for durable content and real editorial work:

1. Provision an always-on host; the tested baseline is a Hetzner CX43.
2. Copy `.env.hetzner.example` to `.env.hetzner` and replace every secret.
3. Point DNS to the host and deploy `compose.hetzner.yaml`.
4. Run database/bootstrap setup once, then disable all setup-on-boot flags.
5. Confirm app, database, Redis, Solr, Scheduler, and Caddy are healthy.
6. Enable provider backups and keep an independent database/file export.
7. Add external uptime monitoring, firewall rules, restore tests, SMTP, and
   the GDPR controls required for the project.

Capacity must be proved with a load test using the site's real extensions,
templates, cache policy, database, and traffic mix. The single-host profile is
resident and predictable, but it is not high availability. Use a managed TYPO3
provider or redundant design for a formal availability SLA.

## Decision Rule

- Choose **Solution 1** when losing every edit is acceptable.
- Choose **Solution 2** before editors create content that matters.
- Choose managed hosting instead of self-managed Hetzner when the provider
  should own patching, monitoring, and recovery.

Continue with [Quickstart](quickstart.md) for the test or
[Production hardening](production-hardening.md) for the professional setup.
