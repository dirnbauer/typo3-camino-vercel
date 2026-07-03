# Vercel Deployment Notes

## How This Project Runs

Vercel builds `Dockerfile.vercel` as a Container Image service and routes all
traffic to that service through `vercel.json`.

The container starts Apache, serves `public/`, and lets TYPO3 handle normal
frontend/backend routes. Real files in `public/` can still be called directly,
which is why the secured scheduler endpoint lives at
`/api/cron/typo3-scheduler.php`.

## Demo Mode

Without `DATABASE_URL`, the image defaults to:

```dotenv
TYPO3_DB_DRIVER=pdo_sqlite
TYPO3_DB_DBNAME=/tmp/typo3/camino.sqlite
```

On boot, `docker/entrypoint.sh` copies a pre-seeded Camino SQLite database into
`/tmp`. This makes the frontend render immediately for Vercel smoke tests. It is
not durable and should not be used for content you care about.

If `TYPO3_SETUP_ADMIN_PASSWORD` is set, the entrypoint updates the seeded
`admin` backend user on every boot. This avoids a known-password seed image.

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
```

Generate secrets locally:

```bash
openssl rand -hex 48
openssl rand -base64 32
```

Do not put generated secret values in the Deploy Button URL. Vercel documents
that Deploy Button env values must be entered by the user because URLs land in
browser history.

## First Deploy

1. Create the Vercel project from the Deploy Button or import this repository.
2. Add the production environment variables above.
3. Add a durable database if this is not a disposable test.
4. Deploy.
5. Confirm the frontend loads.
6. Open `/typo3` and sign in with the configured admin credentials.
7. Set `TYPO3_AUTO_SETUP=0` after successful database initialization.
8. Redeploy so the new env value is applied.

## Useful Commands

```bash
vercel env ls --scope webconsulting
vercel env add TYPO3_ENCRYPTION_KEY production --scope webconsulting
vercel env add TYPO3_SETUP_ADMIN_PASSWORD production --scope webconsulting
vercel deploy --prod --scope webconsulting
```

## Sources

- Vercel Deploy Button env vars: https://vercel.com/docs/deploy-button/environment-variables
- Vercel Deploy Button demo card: https://vercel.com/docs/deploy-button/demo
- Vercel project configuration: https://vercel.com/docs/project-configuration
