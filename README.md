# Webconsulting TYPO3 on Vercel

TYPO3 14.3 container starter for Vercel's Dockerfile-backed Functions.

This repository builds a PHP/Apache container from `Dockerfile.vercel`, installs TYPO3 with Composer, and can initialize an empty external database with TYPO3's v14 Camino starter distribution.

## Current Compatibility

- TYPO3 is pinned through Composer to the latest 14.3 LTS patch in `composer.lock`.
- Vercel containers are stateless. Keep persistent content in an external database and object storage; do not put a database inside the Vercel container.
- The official `typo3/cms-introduction` package is not installable with TYPO3 14 at the moment. Its current Composer constraints allow TYPO3 12/13 only. For TYPO3 14 this starter uses the official `typo3/theme-camino` distribution.

## Required Vercel Env Vars

Set these in the Vercel project before the first production deploy:

```bash
TYPO3_CONTEXT=Production/Vercel
TYPO3_AUTO_SETUP=1
TYPO3_SETUP_DISTRIBUTION=theme_camino
TYPO3_SETUP_ADMIN_USERNAME=admin
TYPO3_SETUP_ADMIN_PASSWORD=<generate-a-long-password>
TYPO3_SETUP_ADMIN_EMAIL=admin@example.com
TYPO3_PROJECT_NAME=Webconsulting TYPO3 Lab
TYPO3_ENCRYPTION_KEY=<96-random-hex-chars>
TYPO3_TRUSTED_HOSTS_PATTERN=(.+\.)?vercel\.app
DATABASE_URL=<postgres-or-mysql-url>
```

After the first successful setup you can set `TYPO3_AUTO_SETUP=0`. The bootstrap script also checks the database for existing TYPO3 tables, so leaving it enabled is idempotent for an already initialized database.

## Local Smoke Test

```bash
docker compose up --build
open http://localhost:8080
```

The local Compose setup uses MariaDB and initializes TYPO3 automatically.

## Deploy

```bash
vercel link --project webconsulting-typo3-lab
vercel integration add neon --plan free_v3 --name webconsulting-typo3-lab-db -m region=fra1
vercel env add TYPO3_ENCRYPTION_KEY production
vercel env add TYPO3_SETUP_ADMIN_PASSWORD production
vercel deploy --prod
```

Vercel will assign a `*.vercel.app` domain to the project. Use `webconsulting-typo3-lab.vercel.app` as the intended project slug if it is available.

More detail: [docs/vercel.md](docs/vercel.md).
