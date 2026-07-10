# Choose One Of Two Setups

This repository supports two intentionally different deployment profiles. Pick
one before configuring services; do not treat the one-click test as a smaller
production architecture.

| | Solution 1: one-click test | Solution 2: professional hosting |
|---|---|---|
| Goal | Evaluate TYPO3 and Camino quickly | Run a durable editorial site |
| Vercel plan | Hobby is enough for personal testing | Pro or Enterprise |
| Configuration | `vercel.json` | `vercel.pro.json` through `scripts/deploy-pro.sh` |
| Database | Temporary pre-seeded SQLite | External PostgreSQL or MySQL-compatible SQL |
| Files | Bundled demo media; optional durable Blob uploads | Vercel Blob or S3/R2 through TYPO3 FAL |
| Search | No Solr service | Managed external Solr 10; internal Solr only for demos |
| Scheduler | None | TYPO3 Scheduler every 15 minutes, plus external workers for long jobs |
| Cold-start mitigation | Automatic five-minute CDN cache for eligible public pages | Three-minute Pro warmer plus optional CDN cache |
| Editing | Safe only for disposable experiments | Durable backend sessions and content |

## Solution 1: One-Click Test

Use the **Deploy with Vercel** button in the README. Enter a backend username,
a strong password, and a stable encryption key. No database or command line is
required.

The default `vercel.json` deliberately deploys only the TYPO3 application:

- one PHP 8.4/nginx container service
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

1. Use Vercel Pro or Enterprise with Fluid Compute, performance CPU, and one
   region close to the database.
2. Add a pooled external PostgreSQL or MySQL-compatible `DATABASE_URL`.
3. Connect Vercel Blob or S3/R2 and keep all editor uploads in that FAL storage.
4. Configure stable TYPO3 secrets, trusted hosts, SMTP, and production logging.
5. Run automatic database/bootstrap setup once, then disable all setup-on-boot
   flags.
6. Add Redis when shared multi-instance caches justify another dependency.
7. Use managed external Solr 10 for durable production search. The internal
   Vercel Solr service is a self-seeded demonstration, not production storage.
8. Set `CRON_SECRET` and deploy with `scripts/deploy-pro.sh`.
9. Confirm `vercel crons ls` shows the three-minute warmer and 15-minute
   Scheduler job.
10. Add database/object-storage backups, restore tests, monitoring, firewall
    rules, and the GDPR controls required for the project.

For read-heavy public sites, Vercel's CDN can absorb substantial traffic while
SQL, files, Redis, and Solr remain durable external services. Capacity must be
proved with a load test using the site's real extensions, templates, cache
policy, database, and traffic mix; this starter cannot assign a universal page
view limit.

Vercel currently does not expose a minimum always-warm instance for this
Container Image path. The three-minute warmer makes normal use much faster but
cannot guarantee zero cold starts during deployments, scale-out, eviction, or
cron delays. If a large site's backend or first request has a hard latency SLA,
run the TYPO3 origin on always-on infrastructure and use Vercel for CDN, public
delivery, assets, and preview deployments.

## Decision Rule

- Choose **Solution 1** when losing every edit is acceptable.
- Choose **Solution 2** before editors create content that matters.
- Use an always-on TYPO3 origin within Solution 2 when predictable first-hit
  latency is a contractual requirement.

Continue with [Quickstart](quickstart.md) for the test or
[Production hardening](production-hardening.md) for the professional setup.
