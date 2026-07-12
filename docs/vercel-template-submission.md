# Vercel Template Submission

Status: prepared locally, not submitted.

When ready to publish, submit this as a Vercel community template through:

https://vercel.com/templates/submit

## Template Details

- Name: TYPO3 Camino on Vercel
- Short description: Community Vercel container starter for TYPO3 14.3 using the TYPO3 Camino distribution. Not an official TYPO3 package.
- GitHub repository: https://github.com/dirnbauer/typo3-camino-vercel
- Live demo: https://typo3-camino-vercel.vercel.app
- Demo image: https://typo3-camino-vercel.vercel.app/template-preview.png
- Deploy button URL: https://vercel.com/new/clone?repository-url=https%3A%2F%2Fgithub.com%2Fdirnbauer%2Ftypo3-camino-vercel&project-name=typo3-camino-vercel&repository-name=typo3-camino-vercel&demo-title=TYPO3+Camino+on+Vercel&demo-description=Community+Vercel+container+starter+for+TYPO3+14.3+using+the+TYPO3+Camino+distribution.+Not+an+official+TYPO3+package.&demo-url=https%3A%2F%2Ftypo3-camino-vercel.vercel.app&demo-image=https%3A%2F%2Ftypo3-camino-vercel.vercel.app%2Ftemplate-preview.png&from=templates&env=TYPO3_SETUP_ADMIN_USERNAME%2CTYPO3_SETUP_ADMIN_PASSWORD%2CTYPO3_ENCRYPTION_KEY&envDefaults=%7B%22TYPO3_SETUP_ADMIN_USERNAME%22%3A%22admin%22%7D&envDescription=Choose+a+backend+username%2C+set+a+strong+random+backend+password%2C+and+paste+a+stable+96-character+hex+TYPO3+encryption+key.+The+Deploy+Button+creates+a+public+Vercel+Blob+store+for+durable+uploaded+files.+Add+a+real+database+later+for+stable+backend+login+and+durable+content.&envLink=https%3A%2F%2Fgithub.com%2Fdirnbauer%2Ftypo3-camino-vercel%2Fblob%2Fmain%2Fdocs%2Fquickstart.md&stores=%5B%7B%22type%22%3A%22blob%22%2C%22access%22%3A%22public%22%7D%5D
- Framework/runtime: Vercel Services with the container runtime (currently Beta)
- Language/runtime: PHP 8.4 FPM, nginx, Alpine Linux
- Use cases: CMS, Starter, Backend
- Database: SQLite for frontend smoke demo, PostgreSQL/MySQL for stable backend use
- License: GPL-2.0-or-later

## Reviewer Notes

The template asks users for a backend username, backend password, and stable
TYPO3 encryption key. It also asks Vercel to create a public Blob store and
uses its OIDC/store credentials or compatibility `BLOB_READ_WRITE_TOKEN` to
auto-enable the `vercel_blob` FAL driver, so editor uploads can be durable from
the first deploy when the user accepts the storage step.

The container still uses a pre-seeded Camino SQLite database when no durable
database is configured. Backend login is not stable in this SQLite mode because
TYPO3 sessions are stored in the database.

The one-click profile deploys only TYPO3: no Solr service and no cron jobs. It
automatically applies a five-minute Vercel CDN policy to eligible anonymous
SQLite demo pages, while backend, API, cookie, query-string, and personalized
requests remain uncached. The first uncached request can still be cold.

For production TYPO3 usage, users should set a stable `TYPO3_ENCRYPTION_KEY`,
connect a durable SQL database via `DATABASE_URL`, and keep persistent object
storage for editor uploads. Durable uploads are supported through the included
Vercel Blob FAL driver and the S3-compatible FAL driver.

The separate professional profile requires Pro/Enterprise, deploys through
`scripts/deploy-pro.sh`, and expects external durable SQL, object storage, and
managed Solr for production search. It is documented separately so template
reviewers are not asked to infer production safety from the one-click demo.

## Publish Checklist

- Push the prepared commits to GitHub.
- Mark the GitHub repository as a template repository.
- Add GitHub topics such as `typo3`, `vercel`, `dockerfile`, `cms`, and `starter`.
- Redeploy Vercel so `public/template-preview.png` is available at the demo URL.
- Submit the Vercel template form and wait for review.
