# Security And Firewall

## Baseline

TYPO3 is security-conscious, but a secure installation still requires ongoing
administration. Treat this repository as a starter, not a hardening guarantee.

## TYPO3 Settings

Configured by default:

- production debug mode off unless `TYPO3_DEBUG=1`
- strict trusted-host pattern support through `TYPO3_TRUSTED_HOSTS_PATTERN`
- Argon2i password hashing
- `displayErrors=0` in production
- system encryption key from `TYPO3_ENCRYPTION_KEY`

Set a host pattern before production:

```dotenv
TYPO3_TRUSTED_HOSTS_PATTERN=(?:www\.)?example\.com
```

## Backend Security

Do:

- Use a unique generated admin password.
- Enable MFA for admin accounts.
- Disable or delete unused backend users.
- Use groups and least privilege for editors.
- Rotate credentials after sharing a demo.

Do not:

- Share one admin user with multiple people.
- Keep demo credentials after the first login.
- Expose the Install Tool in production.

## Vercel Firewall

Vercel sits in front of the container and provides Firewall/WAF features. For a
TYPO3 backend, useful first rules are:

- log suspicious requests first, then block/challenge
- challenge obvious automated traffic against `/typo3`
- block unwanted countries only if the business can justify it
- IP allowlist backend access if the editor base is small
- enable Attack Mode only during targeted attacks

Vercel says DDoS mitigation, IP blocking, and custom rules are available on all
plans. Some managed rules and rate-limiting features vary by plan.

## Headers

For production, add or verify:

- `Strict-Transport-Security`
- `X-Content-Type-Options: nosniff`
- `Referrer-Policy`
- `Permissions-Policy`
- a tested Content Security Policy

TYPO3 has CSP support, but Camino and backend assets should be tested before
enforcing strict frontend CSP.

## Secrets

Never commit:

- `TYPO3_SETUP_ADMIN_PASSWORD`
- `TYPO3_ENCRYPTION_KEY`
- `DATABASE_URL`
- `CRON_SECRET`
- database provider CA/private key material

## Sources

- TYPO3 security guidelines: https://docs.typo3.org/m/typo3/reference-coreapi/main/en-us/Security/Index.html
- Vercel WAF: https://vercel.com/docs/vercel-firewall/vercel-waf
- Vercel WAF pricing: https://vercel.com/docs/vercel-firewall/vercel-waf/usage-and-pricing
- Vercel Attack Mode: https://vercel.com/docs/vercel-firewall/attack-mode
