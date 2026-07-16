# Quickstart

## Fastest Safe Test

1. Open the Deploy Button from the README.
2. Use a generated admin password with upper/lowercase letters, a number, and
   a symbol.
3. Use a stable generated `TYPO3_ENCRYPTION_KEY`.
4. Keep the public Vercel Blob store enabled if you want uploaded files to be
   durable.
5. Do not add `DATABASE_URL` for the free smoke test.
6. Deploy and wait until Vercel reports **Ready**; a push is not immediately live.
7. Open the frontend and confirm Camino renders.

```dotenv
TYPO3_SETUP_ADMIN_USERNAME=admin
TYPO3_SETUP_ADMIN_PASSWORD=<strong-random-password>
TYPO3_ENCRYPTION_KEY=<96-random-hex-chars>
```

Generate the encryption key:

```bash
openssl rand -hex 48
```

Generate a password with a password manager, or use a command such as:

```bash
openssl rand -base64 32
```

TYPO3 validates the initial admin password. If setup rejects it, generate a new
one that includes uppercase, lowercase, numbers, and a symbol.

This mode is free-demo mode: seeded SQLite, no external database, no stable
backend login, and no durable database edits. Uploaded files are durable only
when the Deploy Button-created Blob store is enabled. See
[Free demo mode](free-demo.md).

The one-click profile does not deploy Solr or register cron jobs. Eligible
anonymous pages are cached at the Vercel edge for five minutes automatically,
so repeat frontend views do not need a warm PHP container. The first uncached
page and the backend can still be cold.

## Secure Enough For A Real Trial

Add a real database before first deploy:

```dotenv
DATABASE_URL=<provider-connection-url>
TYPO3_AUTO_SETUP=1
TYPO3_CACHE_BACKEND=file
```

After TYPO3 creates the schema and admin user, set:

```dotenv
TYPO3_AUTO_SETUP=0
TYPO3_BOOTSTRAP_EMPTY_DATABASE=0
TYPO3_EXTENSION_SETUP_ON_BOOT=0
TYPO3_ADMIN_PASSWORD_APPLY_ON_BOOT=0
```

Then redeploy.

If Vercel Marketplace database provisioning fails, create the database in the
provider console and add the connection URL manually with `vercel env add
DATABASE_URL production --sensitive --force`.

## Durable Uploads

The README Deploy Button can create a public Vercel Blob store. Keep it
enabled for the easiest all-Vercel path: there are no Blob fields to fill in,
and this starter automatically enables the `vercel_blob` FAL driver when the
store credentials exist. The container verifies the storage at startup and
fails loudly on bad credentials.

For manual Blob setup, or for Cloudflare R2, AWS S3, MinIO, and other
S3-compatible providers through the `vercel_s3` driver, see
[object storage and durable uploads](object-storage.md).

Normal TYPO3 backend uploads are limited to 4 MB because Vercel rejects
request bodies above 4.5 MB. For larger files, open **Media > Large upload**:
the browser sends the file directly to Blob (default limit 5 GiB) while TYPO3
still checks the editor, destination, filename, type, and size.

## Optional Redis Cache

For most first tests, leave this alone:

```dotenv
TYPO3_CACHE_BACKEND=file
```

For a shared TYPO3 cache on Vercel, add **Upstash for Redis** from the
Marketplace on the free plan. Choose `fra1`, disable auto-upgrade, and connect
it with prefix `TYPO3_` so Vercel injects `TYPO3_REDIS_URL`. Then add:

```dotenv
TYPO3_CACHE_BACKEND=redis
TYPO3_REDIS_REQUIRED=1
TYPO3_REDIS_PREFIX=typo3-camino-vercel:
```

Redis can improve warm backend cache behavior, but it does not make SQLite
durable, does not store uploads, and does not remove Vercel container cold
starts. See [Redis cache on Vercel](redis-cache.md).

## Pro Cold-Start Mitigation

The Hobby-safe config cannot run frequent cron. On Pro, configure
`CRON_SECRET` and deploy the three-minute frontend/backend/Solr warmer:

```bash
scripts/deploy-pro.sh
```

This normally prevents the five-minute idle scale-down path. It is not a
minimum-instance guarantee; see [Performance](performance.md).

## Backend Login

Backend URL:

```text
https://<your-project>.vercel.app/typo3
```

Username:

```text
admin
```

Password:

```text
the value of TYPO3_SETUP_ADMIN_PASSWORD
```

Backend login on Vercel requires a durable database. If the project still uses
seeded SQLite, the backend can log out after a few seconds because the
`be_sessions` table is not shared across runtime instances. See
[Backend login and sessions](backend-login.md).

## Do

- Use a generated password for every clone; include uppercase, lowercase,
  numbers, and a symbol.
- Use a stable `TYPO3_ENCRYPTION_KEY`.
- Use `TYPO3_TRUSTED_HOSTS_PATTERN` for the exact domain before production.
- Add a real database before backend editing or content you want to keep.
- Add object storage before accepting editor uploads. Use Vercel Blob for the
  all-Vercel path, or the S3 driver for R2/S3-compatible providers.
- Keep Vercel Functions close to the database region.
- Keep setup/password rotation flags disabled after their one deploy.
- Enable MFA for backend admin users after first login.

## Do Not

- Do not use the seeded SQLite database for backend sessions or production.
- Do not commit passwords or `.env` files.
- Do not put secret values into a Deploy Button URL.
- Do not rely on Linux cron inside the Vercel container.
- Do not enable edge HTML caching for personalized pages, forms, or frontend
  user login without testing.
- Do not assume GDPR compliance because TYPO3 and Vercel have privacy features.
