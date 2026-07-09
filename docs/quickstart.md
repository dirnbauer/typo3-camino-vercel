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

The README Deploy Button can create a public Vercel Blob store. Keep that store
enabled for the easiest all-Vercel path. There are no Blob fields to fill in:
Vercel connects the store through request OIDC on new connections or a
`BLOB_READ_WRITE_TOKEN` on older connections. This starter supports both and
automatically enables the `vercel_blob` FAL driver.

For manual setup, add object storage before editors upload files. For an
all-Vercel trial, use Vercel Blob:

```dotenv
TYPO3_OBJECT_STORAGE_ENABLED=1
TYPO3_OBJECT_STORAGE_DRIVER=vercel_blob
TYPO3_OBJECT_STORAGE_VERIFY_ON_BOOT=1
TYPO3_BLOB_ACCESS=public
TYPO3_BLOB_PREFIX=typo3/
```

Vercel supplies `BLOB_STORE_ID` plus request OIDC, or a compatibility
`BLOB_READ_WRITE_TOKEN`, when a Blob store is connected. For Cloudflare R2, AWS
S3, MinIO, or another S3-compatible provider, use the S3 driver:

```dotenv
TYPO3_OBJECT_STORAGE_ENABLED=1
TYPO3_OBJECT_STORAGE_DRIVER=vercel_s3
TYPO3_OBJECT_STORAGE_VERIFY_ON_BOOT=1
TYPO3_S3_BUCKET=<bucket>
TYPO3_S3_REGION=auto
TYPO3_S3_ENDPOINT=<s3-compatible-endpoint>
TYPO3_S3_ACCESS_KEY_ID=<access-key>
TYPO3_S3_SECRET_ACCESS_KEY=<secret-key>
TYPO3_S3_PUBLIC_BASE_URL=<public-bucket-or-cdn-url>
```

See [Object storage and durable uploads](object-storage.md).

When these variables are present, the Vercel container verifies the bucket at
startup and creates the TYPO3 upload and processed-file folders in object
storage. Bad credentials fail the deployment loudly.

Normal TYPO3 backend uploads are limited to 4 MB because Vercel Functions
reject total request bodies above 4.5 MB. Blob itself can store larger files,
but those need a separate direct-upload flow.

## Optional Redis Cache

For most first tests, leave this alone:

```dotenv
TYPO3_CACHE_BACKEND=file
```

For a shared TYPO3 cache on Vercel, add the official Redis Marketplace
integration. Vercel injects `REDIS_URL`; then add:

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
vercel deploy --prod -A vercel.pro.json
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
