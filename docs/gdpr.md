# GDPR And Privacy Notes

This is engineering guidance, not legal advice. Have the final setup reviewed by
the responsible data protection/legal team before processing personal data.

## TYPO3

TYPO3's own privacy notes describe privacy-friendly defaults: no cookies for
anonymous visitors by default, permission-based backend access, password hashing,
HTTPS/TLS support, and the Scheduler for purging old data.

For a GDPR-aware TYPO3 setup:

- document what personal data the site stores
- configure retention periods
- add Scheduler tasks for log cleanup/anonymization
- avoid tracking until consent is granted
- use a consent solution for analytics, embeds, maps, marketing pixels, and
  other third-party services
- avoid loading third-party assets before consent if they are not strictly
  necessary
- keep a data processing inventory
- test data export/deletion workflows if users submit personal data

## Vercel

Vercel provides a DPA and states GDPR-related transfer mechanisms in its legal
and compliance documentation. That does not automatically make an individual
TYPO3 site compliant. The site owner still controls:

- what data is collected
- which database/storage provider is used
- which region/provider is selected
- which third-party scripts are embedded
- retention, deletion, and subject-right workflows
- privacy policy and cookie consent wording

## Cookies

EU guidance distinguishes strictly necessary cookies from cookies that require
consent. Authentication cookies can be necessary. Analytics, advertising, social
plugins, and many third-party embeds usually need prior consent.

## Do

- Choose EU regions where available if the site targets EU users.
- Sign/check DPAs for Vercel and the database/storage providers.
- Keep backend logs only as long as needed.
- Prefer privacy-friendly analytics or run analytics only after consent.
- Document subprocessors in the privacy policy.

## Do Not

- Do not enable Vercel Web Analytics or Speed Insights without checking consent
  and privacy-policy requirements for the target market.
- Do not embed YouTube, maps, fonts, analytics, or marketing tags casually.
- Do not store editor/user IP addresses forever without a retention reason.
- Do not claim GDPR compliance from this repository alone.

## Sources

- TYPO3 data privacy: https://typo3.org/governance-values/data-privacy
- TYPO3 GDPR article: https://news.typo3.com/article/how-to-make-your-typo3-application-gdpr-compliant
- Vercel DPA: https://vercel.com/legal/dpa
- Vercel security/compliance: https://vercel.com/docs/security/compliance
- EU cookie guidance: https://europa.eu/youreurope/business/dealing-with-customers/data-protection/online-privacy/index_en.htm
