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
- system encryption key from `TYPO3_ENCRYPTION_KEY` (required on Vercel — the
  container refuses to start without it, because a per-instance random fallback
  would break cHash validation and cache integrity across ephemeral instances)

Set a host pattern before production:

```dotenv
TYPO3_TRUSTED_HOSTS_PATTERN=(?:www\.)?example\.com
```

TYPO3 evaluates the pattern anchored as `/^<pattern>$/i`. If you use an
alternation, wrap the whole thing in a single non-capturing group so both
anchors apply to every branch — otherwise the middle branches are left
unanchored and accept spoofed `Host` headers:

```dotenv
# Correct — every branch is anchored:
TYPO3_TRUSTED_HOSTS_PATTERN=(?:example\.com|staging\.example\.com)
# Wrong — "example.com.attacker.com" would be trusted:
TYPO3_TRUSTED_HOSTS_PATTERN=example\.com|staging\.example\.com
```

## Install Tool

Standalone Install Tool access (reached via `?__typo3_install`) is disabled by
default. TYPO3's **System** backend modules remain available to authenticated
system maintainers: Core creates a short-lived authorized Install session and
redirects to `install[context]=backend`, which this starter permits. Enable the
standalone Install Tool deliberately only when you need it:

```dotenv
TYPO3_INSTALL_TOOL_ENABLED=1
TYPO3_INSTALL_TOOL_PASSWORD_HASH=<argon2i-hash>
```

The password hash does not enable the public entry point by itself; it is also
used by authenticated System modules. Disable `TYPO3_INSTALL_TOOL_ENABLED` again
after standalone maintenance, while keeping the hash configured for backend
verification.

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
