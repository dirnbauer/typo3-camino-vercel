# Vercel Template Submission

Status: prepared locally, not submitted.

When ready to publish, submit this as a Vercel community template through:

https://vercel.com/templates/submit

## Template Details

- Name: TYPO3 Camino on Vercel
- Short description: TYPO3 14.3 with the official Camino distribution running on Vercel Container Images.
- GitHub repository: https://github.com/dirnbauer/typo3-camino-vercel
- Live demo: https://typo3-camino-vercel.vercel.app
- Demo image: https://typo3-camino-vercel.vercel.app/template-preview.png
- Deploy button URL: https://vercel.com/new/clone?repository-url=https%3A%2F%2Fgithub.com%2Fdirnbauer%2Ftypo3-camino-vercel&project-name=typo3-camino-vercel&repository-name=typo3-camino-vercel&demo-title=TYPO3+Camino+on+Vercel&demo-description=TYPO3+14.3+with+the+official+Camino+distribution+running+on+Vercel+Container+Images.&demo-url=https%3A%2F%2Ftypo3-camino-vercel.vercel.app&demo-image=https%3A%2F%2Ftypo3-camino-vercel.vercel.app%2Ftemplate-preview.png&from=templates&teamSlug=webconsulting&env=TYPO3_SETUP_ADMIN_USERNAME,TYPO3_SETUP_ADMIN_PASSWORD,TYPO3_ENCRYPTION_KEY&envDescription=Set+a+backend+admin+username%2C+a+long+random+backend+password%2C+and+a+stable+96-character+hex+TYPO3+encryption+key.+Do+not+put+secrets+in+the+URL.&envLink=https%3A%2F%2Fgithub.com%2Fdirnbauer%2Ftypo3-camino-vercel%2Fblob%2Fmain%2Fdocs%2Fquickstart.md
- Framework: Container Images
- Language/runtime: PHP 8.4, Apache
- Use cases: CMS, Starter, Backend
- Database: SQLite for demo, PostgreSQL/MySQL for production
- License: GPL-2.0-or-later

## Reviewer Notes

The template deploys without required environment variables for a first visual smoke test. The container uses a pre-seeded Camino SQLite database and generates an ephemeral TYPO3 encryption key when no key is configured.

For production TYPO3 usage, users should set a stable `TYPO3_ENCRYPTION_KEY`, connect a durable SQL database via `DATABASE_URL`, and add persistent object storage for editor uploads.

## Publish Checklist

- Push the prepared commits to GitHub.
- Mark the GitHub repository as a template repository.
- Add GitHub topics such as `typo3`, `vercel`, `container-images`, `cms`, and `starter`.
- Redeploy Vercel so `public/template-preview.png` is available at the demo URL.
- Submit the Vercel template form and wait for review.
