# Vercel Deployment Notes

## How This Project Runs

Vercel builds `Dockerfile.vercel` as an OCI image for a Dockerfile-backed
container Service and routes traffic according to `vercel.json`.

The one-click `vercel.json` deploys only TYPO3. The Pro profile adds an
internal demo-only Solr service in `services/solr/`; Vercel injects its
binding URL into TYPO3 as `TYPO3_SOLR_SERVICE_URL`, and the Solr service
receives no public rewrite.

Both profiles pin the Services to `fra1`. That is a good default for this
European demo and a Frankfurt database. If your database lives elsewhere,
change `regions` before deploying: the application should be close to the
database first, then close to users.

The container starts nginx and PHP-FPM and serves `public/`. Real files in
`public/` can be called directly, which is why the secured Scheduler endpoint
lives at `/api/cron/typo3-scheduler.php`.

On Vercel, TLS terminates before the request reaches nginx. The runtime
config therefore trusts Vercel's proxy for scheme detection so TYPO3
generates HTTPS URLs and the backend referrer check works after login:

```dotenv
TYPO3_REVERSE_PROXY_IP=*
TYPO3_REVERSE_PROXY_HEADER_MULTI_VALUE=none
```

Do not use `TYPO3_REVERSE_PROXY_IP=*` for a container that is directly
exposed to the public internet; it is intended for Vercel's private container
runtime behind Vercel's proxy.

## Demo Mode

Without `DATABASE_URL`, the image defaults to:

```dotenv
TYPO3_DB_DRIVER=pdo_sqlite
TYPO3_DB_DBNAME=/tmp/typo3/camino.sqlite
```

On boot, `docker/entrypoint.sh` copies a pre-seeded Camino SQLite database
into `/tmp`. The frontend renders immediately, but nothing is durable. The
seed already contains the schema for installed packages, so keep
`TYPO3_EXTENSION_SETUP_ON_BOOT=0` for demo deployments.

The SQLite profile automatically gives eligible anonymous HTML a five-minute
Vercel CDN policy; TYPO3's own shared-cache decision runs first, and backend,
API, query-string, cookie, form, and personalized responses are never shared.
Set `TYPO3_VERCEL_EDGE_CACHE_TTL=0` to disable it.

The entrypoint treats mutable TYPO3 paths as runtime state: `var`,
`public/fileadmin`, and `public/typo3temp` point into `/tmp`. Committed demo
assets are copied there at startup; editor uploads are durable only with
[object storage](object-storage.md).

## Required Production Env Vars

```dotenv
TYPO3_CONTEXT=Production/Vercel
TYPO3_AUTO_SETUP=0
TYPO3_BOOTSTRAP_EMPTY_DATABASE=0
TYPO3_SETUP_DISTRIBUTION=theme_camino
TYPO3_SETUP_ADMIN_USERNAME=admin
# Optional explicit backend user UIDs. When omitted, startup resolves
# TYPO3_SETUP_ADMIN_USERNAME from be_users automatically.
# TYPO3_SYSTEM_MAINTAINERS=1,7
TYPO3_SETUP_ADMIN_PASSWORD=<long-random-password>
TYPO3_SETUP_ADMIN_EMAIL=admin@example.com
TYPO3_PROJECT_NAME=TYPO3 Camino
TYPO3_ENCRYPTION_KEY=<96-random-hex-chars>
TYPO3_TRUSTED_HOSTS_PATTERN=(?:(.+\.)?vercel\.app)
DATABASE_URL=<durable-postgres-or-mysql-url>
TYPO3_CACHE_BACKEND=file
TYPO3_ADMIN_PASSWORD_APPLY_ON_BOOT=0
TYPO3_EXTENSION_SETUP_ON_BOOT=0
```

Optional shared Redis cache (see [Redis](redis-cache.md)): set
`TYPO3_CACHE_BACKEND=redis`, `TYPO3_REDIS_REQUIRED=1`, and a real `redis://`
or `rediss://` endpoint; REST-only variables are not enough.

Production error logging is on by default on Vercel
(`TYPO3_LOG_PRODUCTION_EXCEPTIONS=1`): visitors see the normal error page
while exception details go to Vercel runtime logs. This is not `TYPO3_DEBUG`;
never enable public debug output in production.

## Solr

Solr configuration, the internal demo service, and indexing live in the
[Solr guide](solr.md). The short version: the one-click profile has no Solr;
the Pro profile binds a private self-seeding demo service. Do not create the
Solr demo page during container boot — boot-time TYPO3 CLI setup pushed cold
starts into `INTERNAL_FUNCTION_INVOCATION_FAILED` in live tests. Run one-shot
setup through the protected `/api/cron/typo3-solr-demo.php` endpoint after
deploy, and use external managed Solr for production search.

## Pro Cold-Start Profile

`vercel.pro.json` adds the internal Solr demonstration service and:

- `/api/cron/typo3-warmup.php` every three minutes
- `/api/cron/typo3-scheduler.php` every 15 minutes

The warm-up performs local loopback requests to `/` and `/typo3/`, then
checks database, Redis, and Solr. Configure `CRON_SECRET` and deploy with:

```bash
VERCEL_SCOPE=webconsulting scripts/deploy-pro.sh
```

Git-based Vercel deployments read `vercel.json`. Run the Pro command after a
push when the deployment must keep its frequent warm-up schedule.

Generate secrets locally:

```bash
openssl rand -hex 48    # TYPO3_ENCRYPTION_KEY
openssl rand -base64 32 # CRON_SECRET, passwords
```

Never put secret values in a Deploy Button URL; the button may pre-fill only
non-secret defaults. Secrets belong in encrypted Vercel environment
variables.

## First Deploy

1. Create the project from the Deploy Button or import this repository.
2. Keep the Deploy Button-created Blob store for durable uploads.
3. Add the production environment variables above.
4. Add a durable database unless this is a disposable test
   (see [database setup](database.md)).
5. Confirm the frontend loads, then sign in at `/typo3`.
6. Set `TYPO3_AUTO_SETUP=0` and `TYPO3_BOOTSTRAP_EMPTY_DATABASE=0` after the
   database is initialized, and redeploy.
7. Run `scripts/deploy-pro.sh` when this is a Pro latency-sensitive site.

## One-Shot Lifecycle Flags

Some operations run once on boot and must be switched off again. The pattern
is always: set the flag, deploy, verify, reset the flag, redeploy.

```bash
# Fresh database setup
vercel env add DATABASE_URL production --sensitive --force
vercel env add TYPO3_AUTO_SETUP production --value 1 --force --yes
vercel deploy --prod --regions fra1
vercel env add TYPO3_AUTO_SETUP production --value 0 --force --yes
vercel env add TYPO3_BOOTSTRAP_EMPTY_DATABASE production --value 0 --force --yes
vercel deploy --prod --regions fra1

# After adding TYPO3 packages to an existing database
# ... TYPO3_EXTENSION_SETUP_ON_BOOT=1, deploy, then =0, deploy

# After rotating the admin password
# ... TYPO3_SETUP_ADMIN_PASSWORD (sensitive), TYPO3_ADMIN_PASSWORD_APPLY_ON_BOOT=1,
#     deploy, then =0, deploy
```

If the Vercel Marketplace Neon flow fails during provisioning, create or pick
a Neon project in the `fra1`-matching region yourself, copy the pooled
connection string, and add it as production `DATABASE_URL` — see
[database setup](database.md). Never paste database URLs into docs, chats, or
screenshots.

## Useful Commands

```bash
vercel env ls --scope webconsulting
vercel env add TYPO3_ENCRYPTION_KEY production --scope webconsulting
vercel deploy --prod --scope webconsulting --regions fra1
vercel crons ls --scope webconsulting
```

## Sources

- Vercel Deploy Button source and `stores`: https://vercel.com/docs/deploy-button/source
- Vercel project configuration: https://vercel.com/docs/project-configuration
- Vercel Services: https://vercel.com/docs/services
- Dockerfile deployments: https://vercel.com/kb/guide/docker
- Vercel Container Registry: https://vercel.com/docs/container-registry
