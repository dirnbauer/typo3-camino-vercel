# Quickstart

## Fastest Safe Test

1. Open the Deploy Button from the README.
2. Use a generated admin password.
3. Use a stable generated `TYPO3_ENCRYPTION_KEY`.
4. Do not add a database for the first smoke test if you only want to see the
   Camino frontend.
5. Deploy.
6. Open `/typo3` and sign in.

```dotenv
TYPO3_SETUP_ADMIN_USERNAME=admin
TYPO3_SETUP_ADMIN_PASSWORD=<long-random-password>
TYPO3_ENCRYPTION_KEY=<96-random-hex-chars>
```

Generate the encryption key:

```bash
openssl rand -hex 48
```

Generate a password:

```bash
openssl rand -base64 32
```

## Secure Enough For A Real Trial

Add a real database before first deploy:

```dotenv
DATABASE_URL=<provider-connection-url>
TYPO3_AUTO_SETUP=1
```

After TYPO3 creates the schema and admin user, set:

```dotenv
TYPO3_AUTO_SETUP=0
```

Then redeploy.

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

## Do

- Use a generated password for every clone.
- Use a stable `TYPO3_ENCRYPTION_KEY`.
- Use `TYPO3_TRUSTED_HOSTS_PATTERN` for the exact domain before production.
- Add a real database before editing content you want to keep.
- Add object storage before accepting editor uploads.
- Enable MFA for backend admin users after first login.

## Do Not

- Do not use the seeded SQLite database for production.
- Do not commit passwords or `.env` files.
- Do not put secret values into a Deploy Button URL.
- Do not rely on Linux cron inside the Vercel container.
- Do not assume GDPR compliance because TYPO3 and Vercel have privacy features.
