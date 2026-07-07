# Vercel Deployment Notes

## How This Project Runs

Vercel builds `Dockerfile.vercel` as a Container Image service and routes all
traffic to that service through `vercel.json`.

The template pins Functions/Container Images to `fra1` in `vercel.json`. That
is a good default for this European demo and for a Neon database created in
Frankfurt. If your database lives elsewhere, change `regions` to the database
region before deploying. The function should be close to the database first,
then close to users.

The container starts Apache, serves `public/`, and lets TYPO3 handle normal
frontend/backend routes. Real files in `public/` can still be called directly,
which is why the secured scheduler endpoint lives at
`/api/cron/typo3-scheduler.php`.

On Vercel, TLS terminates before the request reaches Apache. The runtime config
therefore trusts Vercel's proxy for scheme detection so TYPO3 generates HTTPS
backend URLs and the backend referrer check keeps working after login. The
default is:

```dotenv
TYPO3_REVERSE_PROXY_IP=*
TYPO3_REVERSE_PROXY_HEADER_MULTI_VALUE=none
```

Do not use `TYPO3_REVERSE_PROXY_IP=*` for a container that is directly exposed
to the public internet. It is intended for Vercel's private container runtime,
where the app is only reached through Vercel's proxy.

## Demo Mode

Without `DATABASE_URL`, the image defaults to:

```dotenv
TYPO3_DB_DRIVER=pdo_sqlite
TYPO3_DB_DBNAME=/tmp/typo3/camino.sqlite
```

On boot, `docker/entrypoint.sh` copies a pre-seeded Camino SQLite database into
`/tmp`. This makes the frontend render immediately for Vercel smoke tests. It is
not durable and should not be used for content you care about.

If `TYPO3_ADMIN_PASSWORD_APPLY_ON_BOOT=1` and
`TYPO3_SETUP_ADMIN_PASSWORD` is set, the entrypoint updates the `admin` backend
user during startup. Use this for one deploy after rotating the password, then
set it back to `0`. Keeping it enabled slows cold starts because TYPO3 hashes
and writes the password on every new container.

The entrypoint also treats mutable TYPO3 paths as serverless runtime state:
`var`, `public/fileadmin`, and `public/typo3temp` point into `/tmp`. Committed
Camino demo assets are copied there at startup, but editor uploads are not
durable unless `TYPO3_OBJECT_STORAGE_ENABLED=1` and either `vercel_blob` or
`vercel_s3` object storage is configured. See
[object storage and durable uploads](object-storage.md).

## Required Production Env Vars

```dotenv
TYPO3_CONTEXT=Production/Vercel
TYPO3_AUTO_SETUP=1
TYPO3_SETUP_DISTRIBUTION=theme_camino
TYPO3_SETUP_ADMIN_USERNAME=admin
TYPO3_SETUP_ADMIN_PASSWORD=<long-random-password>
TYPO3_SETUP_ADMIN_EMAIL=admin@example.com
TYPO3_PROJECT_NAME=TYPO3 Camino
TYPO3_ENCRYPTION_KEY=<96-random-hex-chars>
TYPO3_TRUSTED_HOSTS_PATTERN=(.+\.)?vercel\.app
DATABASE_URL=<durable-postgres-or-mysql-url>
TYPO3_CACHE_BACKEND=file
TYPO3_ADMIN_PASSWORD_APPLY_ON_BOOT=0
TYPO3_EXTENSION_SETUP_ON_BOOT=0
```

Optional shared Redis cache:

```dotenv
TYPO3_CACHE_BACKEND=redis
TYPO3_REDIS_REQUIRED=1
TYPO3_REDIS_PREFIX=typo3-camino-vercel:
REDIS_URL=<provided-by-vercel-marketplace-redis>
```

Use Redis only with a real `redis://` or `rediss://` TCP/TLS endpoint. REST-only
Redis variables are not enough for TYPO3's native Redis backend.

Generate secrets locally:

```bash
openssl rand -hex 48
openssl rand -base64 32
```

Do not put generated secret values in the Deploy Button URL. The Deploy Button
may pre-fill only non-secret defaults, such as the default admin username.
Passwords, tokens, database URLs, and encryption keys must be entered by the
user or added as encrypted Vercel environment variables.

## First Deploy

1. Create the Vercel project from the Deploy Button or import this repository.
2. Keep the Deploy Button-created Vercel Blob store enabled if you want durable
   uploaded files.
3. Add the production environment variables above.
4. Add a durable database if this is not a disposable test.
5. Deploy.
6. Confirm the frontend loads.
7. Open `/typo3` and sign in with the configured admin credentials only after
   `DATABASE_URL` points to a durable database.
8. Set `TYPO3_AUTO_SETUP=0` after successful database initialization.
9. Set `TYPO3_BOOTSTRAP_EMPTY_DATABASE=0` for stricter production startup.
10. If extensions were added after the database was created, set
   `TYPO3_EXTENSION_SETUP_ON_BOOT=1` for one deploy.
11. Redeploy so the new env values are applied.
12. After extension setup has run, set `TYPO3_EXTENSION_SETUP_ON_BOOT=0` and
    redeploy.
13. If the backend password is rotated later, set
    `TYPO3_ADMIN_PASSWORD_APPLY_ON_BOOT=1` for one deploy, then set it back to
    `0` and redeploy.

## If Neon Marketplace Provisioning Fails

The Vercel Marketplace Neon flow can fail during provider setup, for example
when the Neon organization, Vercel team, free-plan quota, or authorization state
does not match. Use an existing Neon project instead:

1. Open Neon and create or choose a project in the same region as Vercel
   `regions` in `vercel.json` (`fra1` by default here).
2. Copy the pooled connection string.
3. Add it to Vercel as production `DATABASE_URL`.
4. Set `TYPO3_AUTO_SETUP=1` for the first deploy.
5. Deploy once, confirm TYPO3 works, then set `TYPO3_AUTO_SETUP=0` and
   `TYPO3_BOOTSTRAP_EMPTY_DATABASE=0`.

CLI shape:

```bash
vercel env add DATABASE_URL production --sensitive --force
vercel env add TYPO3_AUTO_SETUP production --value 1 --force --yes
vercel deploy --prod --regions fra1
vercel env add TYPO3_AUTO_SETUP production --value 0 --force --yes
vercel env add TYPO3_BOOTSTRAP_EMPTY_DATABASE production --value 0 --force --yes
vercel deploy --prod --regions fra1
```

Do not paste the database URL into GitHub, docs, screenshots, or chat. Add it
through Vercel's encrypted environment variable flow.

If you later add TYPO3 packages to an existing database, run the extension setup
once:

```bash
vercel env add TYPO3_EXTENSION_SETUP_ON_BOOT production --value 1 --force --yes
vercel deploy --prod --regions fra1
vercel env add TYPO3_EXTENSION_SETUP_ON_BOOT production --value 0 --force --yes
vercel deploy --prod --regions fra1
```

If you rotate the admin password:

```bash
vercel env add TYPO3_SETUP_ADMIN_PASSWORD production --sensitive --force
vercel env add TYPO3_ADMIN_PASSWORD_APPLY_ON_BOOT production --value 1 --force --yes
vercel deploy --prod --regions fra1
vercel env add TYPO3_ADMIN_PASSWORD_APPLY_ON_BOOT production --value 0 --force --yes
vercel deploy --prod --regions fra1
```

## Useful Commands

```bash
vercel env ls --scope webconsulting
vercel env add TYPO3_ENCRYPTION_KEY production --scope webconsulting
vercel env add TYPO3_SETUP_ADMIN_PASSWORD production --scope webconsulting
vercel env add TYPO3_CACHE_BACKEND production --value redis --force --yes --scope webconsulting
vercel env add TYPO3_REDIS_REQUIRED production --value 1 --force --yes --scope webconsulting
vercel env add TYPO3_REDIS_PREFIX production --value typo3-camino-vercel: --force --yes --scope webconsulting
vercel deploy --prod --scope webconsulting --regions fra1
```

## Sources

- Vercel Deploy Button source and `stores`: https://vercel.com/docs/deploy-button/source
- Vercel Deploy Button env vars: https://vercel.com/docs/deploy-button/environment-variables
- Vercel Deploy Button demo card: https://vercel.com/docs/deploy-button/demo
- Vercel project configuration: https://vercel.com/docs/project-configuration
- Vercel Redis docs: https://vercel.com/docs/redis
