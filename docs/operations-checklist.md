# Operations Checklist

## Before First Real Deploy

- [ ] Generate `TYPO3_ENCRYPTION_KEY`.
- [ ] Generate `TYPO3_SETUP_ADMIN_PASSWORD`.
- [ ] Set `TYPO3_TRUSTED_HOSTS_PATTERN` for the real domain.
- [ ] Add durable `DATABASE_URL`.
- [ ] Keep Vercel `regions` close to the database region.
- [ ] Confirm database backup/restore exists.
- [ ] Decide where `fileadmin` uploads will live.
- [ ] Configure `TYPO3_OBJECT_STORAGE_ENABLED=1` and `TYPO3_S3_*` before editors upload files.
- [ ] Keep Vercel runtime filesystem writes disposable.
- [ ] Keep `TYPO3_AUTO_SETUP=1` only for initial setup.
- [ ] Use `TYPO3_EXTENSION_SETUP_ON_BOOT=1` for one deploy after extension package changes.
- [ ] Use `TYPO3_ADMIN_PASSWORD_APPLY_ON_BOOT=1` for one deploy after password rotation only.

## After First Deploy

- [ ] Open the frontend.
- [ ] Log in at `/typo3`.
- [ ] Change shared/demo credentials if needed.
- [ ] Enable MFA for admin users.
- [ ] Set `TYPO3_AUTO_SETUP=0`.
- [ ] Set `TYPO3_BOOTSTRAP_EMPTY_DATABASE=0`.
- [ ] Set `TYPO3_EXTENSION_SETUP_ON_BOOT=0` after extension setup has run.
- [ ] Set `TYPO3_ADMIN_PASSWORD_APPLY_ON_BOOT=0` after password rotation has run.
- [ ] Redeploy.
- [ ] Check Vercel runtime logs.
- [ ] Check TYPO3 system reports.

## Basic TYPO3 Needs

- [ ] Database is durable and backed up.
- [ ] Encryption key is stable.
- [ ] Trusted hosts are restricted.
- [ ] Scheduler is installed.
- [ ] Cron trigger is configured only if `CRON_SECRET` exists.
- [ ] Backend admins use MFA.
- [ ] Error display is disabled.
- [ ] Install Tool is not publicly usable.
- [ ] File uploads are durable and scanned/limited by policy.
- [ ] S3-compatible object-storage integration is tested before editors upload files.
- [ ] Security updates are planned.
- [ ] Optional edge HTML caching is enabled only for anonymous cache-safe pages.

## Monthly

- [ ] Run `composer outdated typo3/*`.
- [ ] Review TYPO3 security advisories.
- [ ] Review Vercel runtime/build logs.
- [ ] Review backend users.
- [ ] Test database restore.
- [ ] Review Scheduler task failures.

## Before Production

- [ ] Add a custom domain.
- [ ] Set production `TYPO3_TRUSTED_HOSTS_PATTERN`.
- [ ] Add privacy policy and legal notice.
- [ ] Complete cookie/consent review.
- [ ] Configure Vercel Firewall rules.
- [ ] Add uptime monitoring.
- [ ] Decide on log retention.
- [ ] Document subprocessors.
