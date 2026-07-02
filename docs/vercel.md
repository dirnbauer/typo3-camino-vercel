# Vercel Deployment Notes

## Why This Uses an External Database

Vercel container functions are stateless and can scale to zero. The container image is durable, but runtime writes are not a database volume. TYPO3 therefore needs an external SQL database. PostgreSQL via Neon, Supabase, Prisma Postgres, or AWS Aurora works with this image because the PHP container includes `pdo_pgsql`. MySQL/MariaDB also works when provided by an external service because the image includes `mysqli` and `pdo_mysql`.

Uploaded files in `public/fileadmin/user_upload` are still runtime filesystem writes. For production, add a TYPO3 FAL driver backed by S3-compatible object storage or Vercel Blob before accepting editor uploads. The Camino starter assets are committed into the image, so the demo frontend survives cold starts.

## First Deploy

1. Create/link the Vercel project with the slug `webconsulting-typo3-lab`.
2. Provision a database. For Vercel Marketplace Postgres:

   ```bash
   vercel integration add neon --plan free_v3 --name webconsulting-typo3-lab-db -m region=fra1
   ```

3. Set the required TYPO3 secrets:

   ```bash
   openssl rand -hex 48
   vercel env add TYPO3_ENCRYPTION_KEY production
   vercel env add TYPO3_SETUP_ADMIN_PASSWORD production
   ```

4. Set non-secret setup values:

   ```bash
   vercel env add TYPO3_AUTO_SETUP production
   vercel env add TYPO3_SETUP_DISTRIBUTION production
   vercel env add TYPO3_SETUP_ADMIN_USERNAME production
   vercel env add TYPO3_SETUP_ADMIN_EMAIL production
   vercel env add TYPO3_PROJECT_NAME production
   vercel env add TYPO3_TRUSTED_HOSTS_PATTERN production
   ```

5. Deploy:

   ```bash
   vercel deploy --prod
   ```

On first start, `scripts/bootstrap-typo3.php` checks the database for `be_users`. If the table is missing, it runs `vendor/bin/typo3 setup` with `theme_camino`. If the table exists, setup is skipped.

## Introduction Package Status

The official Introduction Package is `typo3/cms-introduction`, but its current release only allows TYPO3 12/13. This starter keeps TYPO3 on 14.3 and uses `typo3/theme-camino`, the TYPO3 14 default distribution. If the Introduction Package adds TYPO3 14 support later, add it with:

```bash
composer require typo3/cms-introduction
```

Then replace `TYPO3_SETUP_DISTRIBUTION=theme_camino` with the distribution key provided by that package.
