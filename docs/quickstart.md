# Quickstart

## Fastest Safe Test

1. Open the Deploy Button from the README.
2. Use a generated admin password with upper/lowercase letters, a number, and
   a symbol.
3. Use a stable generated `TYPO3_ENCRYPTION_KEY`.
4. Do not add `DATABASE_URL` for the free smoke test.
5. Deploy.
6. Open the frontend and confirm Camino renders.

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

This mode is free-demo mode: seeded SQLite, no external database, no object
storage, no stable backend login, and no durable edits. See
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
```

Then redeploy.

If Vercel Marketplace database provisioning fails, create the database in the
provider console and add the connection URL manually with `vercel env add
DATABASE_URL production --sensitive --force`.

## Durable Uploads

Add S3-compatible object storage before editors upload files:

```dotenv
TYPO3_OBJECT_STORAGE_ENABLED=1
TYPO3_S3_BUCKET=<bucket>
TYPO3_S3_REGION=auto
TYPO3_S3_ENDPOINT=<s3-compatible-endpoint>
TYPO3_S3_ACCESS_KEY_ID=<access-key>
TYPO3_S3_SECRET_ACCESS_KEY=<secret-key>
TYPO3_S3_PUBLIC_BASE_URL=<public-bucket-or-cdn-url>
```

Cloudflare R2 works well for a free durable trial because it exposes an
S3-compatible API. Vercel Blob is not supported by this driver. See
[Object storage and durable uploads](object-storage.md).

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
- Add S3-compatible object storage before accepting editor uploads.
- Keep Vercel Functions close to the database region.
- Enable MFA for backend admin users after first login.

## Do Not

- Do not use the seeded SQLite database for backend sessions or production.
- Do not commit passwords or `.env` files.
- Do not put secret values into a Deploy Button URL.
- Do not rely on Linux cron inside the Vercel container.
- Do not enable edge HTML caching for personalized pages, forms, or frontend
  user login without testing.
- Do not assume GDPR compliance because TYPO3 and Vercel have privacy features.
