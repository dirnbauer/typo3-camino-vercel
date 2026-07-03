# Serverless Runtime Notes

## What The WordPress Template Does

The Vercel WordPress template I checked is ServerlessWP. Its runtime model is:

- committed WordPress core, plugins, and themes are copied to `/tmp/wp` so the
  PHP runtime can write temporary files
- real content lives in MySQL, or experimentally in SQLite synced to S3
- media uploads are handled with S3 through a WordPress plugin
- WordPress file editing and plugin/theme modification are disabled because
  runtime filesystem changes do not persist

That means it does not rely on a durable Vercel filesystem. It uses the repo as
the deployable code image and object/database storage for persistence.

## What This TYPO3 Starter Does

This project now follows the same safe baseline:

- `var` is symlinked to `/tmp/typo3/var`
- `public/fileadmin` is copied from the committed Camino seed assets to
  `/tmp/typo3/fileadmin`, then symlinked
- `public/typo3temp` is symlinked to `/tmp/typo3/typo3temp`
- the pre-seeded SQLite database is copied to `/tmp/typo3/camino.sqlite`

The image contains the code and Camino demo assets. Runtime writes are
disposable. Use a real database and object storage for anything that must
survive redeploys, cold starts, or scaling.

To disable this behavior for debugging:

```dotenv
TYPO3_SERVERLESS_FILESYSTEM=0
```

## Vercel Blob

Vercel Blob is the natural Vercel-native object storage candidate for editor
uploads. Vercel's SDK examples use `@vercel/blob` for TypeScript/JavaScript and
Python. TYPO3, however, writes uploads through FAL, so a production integration
needs a TYPO3 FAL driver for Vercel Blob or a bridge service that TYPO3 can use
as a storage backend.

Do not solve this by writing editor uploads to `public/fileadmin` on Vercel.
That only works until the container restarts or a second instance handles a
request.

Practical options:

- use an S3-compatible TYPO3 FAL driver with S3, Cloudflare R2, or another
  provider that supports the required TYPO3 version
- build a small TYPO3 FAL driver for Vercel Blob
- keep this as a read-only demo and do not accept editor uploads

## Database

ServerlessWP experiments with SQLite synced to S3. I do not recommend copying
that pattern to TYPO3 for this starter. TYPO3 has many write paths, backend
editor workflows, cache writes, scheduler writes, and extension-specific writes.
Use external MySQL/MariaDB or Postgres for real TYPO3 trials.

## Sources

- ServerlessWP template: https://vercel.com/templates/other/serverless-wordpress
- ServerlessWP repository: https://github.com/mitchmac/serverlesswp
- Vercel Blob docs: https://vercel.com/docs/vercel-blob
- Vercel file upload guide: https://vercel.com/kb/guide/how-to-upload-and-store-files-with-vercel
