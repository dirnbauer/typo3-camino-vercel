# Vercel Blob FAL Driver

This project includes a TYPO3 FAL driver for Vercel Blob. It is the preferred
all-Vercel path for durable editor uploads in this starter.

The driver is not part of TYPO3 core and it is not an official TYPO3 package.
It is a project-local extension in this repository.

## Why This Exists

Vercel's runtime filesystem is disposable. TYPO3 can write to `fileadmin/`,
`typo3temp/`, and `var/` during a request, but those files are runtime state.
They can disappear after a cold start, redeploy, or scale-out.

TYPO3's normal answer for durable files is FAL: configure a storage driver and
let TYPO3 write files through that driver. Vercel Blob is not S3-compatible, so
the S3 driver cannot be reused for Blob. This repository therefore contains two
separate drivers:

- `vercel_blob` for Vercel Blob
- `vercel_s3` for S3-compatible storage such as Cloudflare R2, AWS S3, MinIO,
  or DigitalOcean Spaces

The existing S3-compatible driver remains unchanged. The Blob driver is an
additional extension.

## Extension Location

Package:

```text
packages/typo3-vercel-blob-storage
```

Composer package:

```text
webconsulting/typo3-vercel-blob-storage
```

TYPO3 extension key:

```text
typo3_vercel_blob_storage
```

PHP namespace:

```text
Webconsulting\Typo3VercelBlobStorage
```

TYPO3 FAL driver id:

```text
vercel_blob
```

Important files:

```text
packages/typo3-vercel-blob-storage/ext_localconf.php
packages/typo3-vercel-blob-storage/Classes/Client/VercelBlobClient.php
packages/typo3-vercel-blob-storage/Classes/Authentication/BlobCredentials.php
packages/typo3-vercel-blob-storage/Classes/DirectUpload/DirectUploadService.php
packages/typo3-vercel-blob-storage/Classes/Resource/Driver/BlobDriver.php
packages/typo3-vercel-blob-storage/Configuration/Resource/Driver/BlobDriverFlexForm.xml
scripts/apply-object-storage.php
docker/entrypoint.sh
```

## Runtime Model

When `TYPO3_OBJECT_STORAGE_ENABLED=1`, `TYPO3_OBJECT_STORAGE_DRIVER` is set, or
a Vercel Blob token exists, the container entrypoint runs
`scripts/apply-object-storage.php`. Explicit
`TYPO3_OBJECT_STORAGE_ENABLED=0` disables this.

For `TYPO3_OBJECT_STORAGE_DRIVER=vercel_blob`, the script:

1. connects to the TYPO3 database
2. creates or updates `sys_file_storage` uid `2`
3. sets the storage driver to `vercel_blob`
4. stores non-secret driver options in the TYPO3 FlexForm XML
5. keeps the Blob token name as an env-var reference, not as a secret in the DB
6. makes uid `2` the default writable storage
7. creates `user_upload/`, `_processed_/`, and `_temp_/` in Vercel Blob
8. fails startup if verification is enabled and Blob access does not work

The committed Camino seed files remain on local storage uid `1`. That avoids
breaking demo database records that already point at local seed assets.

New editor uploads should go to storage uid `2` after the driver is enabled.

## Easiest Setup: Deploy Button

The README Deploy Button is the easiest setup. It asks Vercel to create a
public Blob store during deployment. Vercel then adds `BLOB_READ_WRITE_TOKEN`
to the project environment. This starter sees that token and automatically
uses the `vercel_blob` FAL driver.

No Blob settings are required for the normal Deploy Button path. These are the
defaults used by the starter:

```dotenv
TYPO3_OBJECT_STORAGE_ENABLED=1
TYPO3_OBJECT_STORAGE_DRIVER=vercel_blob
TYPO3_OBJECT_STORAGE_VERIFY_ON_BOOT=1
TYPO3_BLOB_ACCESS=public
TYPO3_BLOB_PREFIX=typo3/
```

This works because Vercel's Deploy Button supports a `stores` parameter for
creating a Blob store during project creation. The Deploy Button must never
contain passwords, Blob tokens, database URLs, or other secrets.

You still need to enter:

```dotenv
TYPO3_SETUP_ADMIN_USERNAME=admin
TYPO3_SETUP_ADMIN_PASSWORD=<your-own-strong-password>
TYPO3_ENCRYPTION_KEY=<96-random-hex-characters>
```

Vercel creates the Blob token for the project. This starter reads
`BLOB_READ_WRITE_TOKEN` automatically when the Blob store is connected.

To turn automatic Blob storage off in a disposable test project, set:

```dotenv
TYPO3_OBJECT_STORAGE_ENABLED=0
```

Blob fixes uploaded files. It does not fix the demo SQLite database. Add a real
database through `DATABASE_URL` before relying on backend login or edited
content.

## Manual Vercel Blob Setup

Create a public Blob store connected to the Vercel project:

```bash
vercel blob create-store typo3-camino-uploads --access public --environment production --yes
```

Then add the TYPO3 object-storage settings:

```bash
vercel env add TYPO3_OBJECT_STORAGE_ENABLED production --value 1 --yes
vercel env add TYPO3_OBJECT_STORAGE_DRIVER production --value vercel_blob --yes
vercel env add TYPO3_OBJECT_STORAGE_VERIFY_ON_BOOT production --value 1 --yes
vercel env add TYPO3_BLOB_ACCESS production --value public --yes
vercel env add TYPO3_BLOB_PREFIX production --value typo3/ --yes
vercel deploy --prod
```

Vercel normally supplies `BLOB_READ_WRITE_TOKEN` automatically when the Blob
store is connected to the project. Do not commit this token.

When you configure Blob manually, connecting the Blob store alone is not
enough. Keep the explicit `TYPO3_OBJECT_STORAGE_ENABLED=1` and
`TYPO3_OBJECT_STORAGE_DRIVER=vercel_blob` settings so the template does not
silently switch storage drivers just because a Blob token exists.

## Optional Blob Environment Variables

Use these only when the defaults are not enough:

```dotenv
TYPO3_BLOB_STORE_ID=<store-id-if-not-provided-by-vercel>
TYPO3_BLOB_TOKEN_ENV_NAME=BLOB_READ_WRITE_TOKEN
TYPO3_BLOB_ACCESS=public
TYPO3_BLOB_PREFIX=typo3/
TYPO3_BLOB_PUBLIC_BASE_URL=
TYPO3_BLOB_API_URL=https://vercel.com/api/blob
TYPO3_BLOB_DEFAULT_FOLDER=user_upload
TYPO3_BLOB_CACHE_CONTROL_MAX_AGE=31536000
TYPO3_BLOB_PROCESSING_FOLDER=_processed_
TYPO3_BLOB_STORAGE_UID=2
TYPO3_BLOB_STORAGE_NAME=Vercel Blob uploads
```

Shared object-storage overrides:

```dotenv
TYPO3_OBJECT_STORAGE_STORAGE_UID=2
TYPO3_OBJECT_STORAGE_STORAGE_NAME=Vercel Blob uploads
TYPO3_OBJECT_STORAGE_MAKE_DEFAULT=1
TYPO3_OBJECT_STORAGE_PROCESSING_FOLDER=_processed_
```

## Token Handling

The driver supports two credential modes:

- read/write token mode through `BLOB_READ_WRITE_TOKEN`
- OIDC/store-id mode through `VERCEL_OIDC_TOKEN` and `BLOB_STORE_ID`

For normal connected Vercel requests, the request OIDC header plus
`BLOB_STORE_ID` is preferred because the credential is short-lived and rotated
by Vercel. The driver exports the request token for protected child CLI calls.
It falls back to `BLOB_READ_WRITE_TOKEN` for local development, manual CLI/API
use, older connected-store setups, and jobs where request OIDC is unavailable.

The FlexForm configuration stores `tokenEnvName`, for example
`BLOB_READ_WRITE_TOKEN`. It does not store the token value. The token value must
come from Vercel environment variables at runtime.

## Why Not The Vercel File API?

The Vercel deployment File API is not a better storage backend for TYPO3 FAL.
It uploads build/deployment files before creating a Vercel deployment. TYPO3
uploads happen at runtime after editors log in, so they need object storage.

Vercel Sandbox filesystem APIs are also not a replacement for Blob in this
project. They belong to Sandbox sessions, not the production Function/container
runtime used by this TYPO3 site.

Use Vercel Blob for the all-Vercel path. Use S3-compatible storage only when
you need an S3 ecosystem provider such as Cloudflare R2, AWS S3, or MinIO.

## Upload Size Limit

Vercel Functions impose a 4.5 MB total request-body limit. The PHP container
uses a 4 MB upload limit to leave room for multipart metadata. Blob itself can
store much larger objects, but the normal TYPO3 backend upload travels through
PHP first. Increasing `upload_max_filesize` does not bypass Vercel's limit.

The extension therefore includes a separate **Media > Large upload** module and
a **Large upload** button in writable Blob folders. Its sequence is:

1. validate the authenticated backend user, FAL file mount and permissions,
   filename, declared MIME type, and size
2. issue a short-lived Vercel token restricted to one exact path/type/size
3. upload browser-to-Blob, using multipart for files above 100 MB
4. verify the remote size and MIME type, then create the `sys_file` record

The payload never passes through PHP and FAL hashing uses remote object
fingerprint data rather than downloading a multi-gigabyte object. The default
limit is 5 GiB; `TYPO3_BLOB_DIRECT_UPLOAD_MAX_BYTES` can raise it up to Vercel
Blob's 5 TB hard limit. `TYPO3_BLOB_DIRECT_UPLOAD_TOKEN_TTL` defaults to four
hours and accepts 300 to 86,400 seconds.

Security trade-off: the browser-declared MIME type is constrained by the file
extension and rechecked against Blob metadata, but the server does not download
the full file for magic-byte or malware inspection. HTML, JavaScript, SVG, XML,
and related active formats are therefore blocked by default. Add an asynchronous
scanner/quarantine workflow before using this path for untrusted public uploads.

Very large images can upload successfully but later fail when TYPO3/ImageMagick
must download and transform the original within Vercel's temporary disk and
request-duration limits. Store large videos and archives as downloads; process
large media asynchronously outside the request path.

## Public Versus Private Blob Stores

Use a public Blob store for this starter.

TYPO3 frontend images, downloads, and processed files need public URLs. A
private Blob store can be useful for special application files, but it is not a
good default for normal TYPO3 public assets. Private Blob mode does not produce
the same simple public FAL URLs.

## What Gets Written To Blob

After setup, Blob should contain objects below the configured prefix, usually:

```text
typo3/user_upload/
typo3/_processed_/
typo3/_temp_/
```

Typical editor uploads go below:

```text
typo3/user_upload/
```

TYPO3 image derivatives go below:

```text
typo3/_processed_/
```

The local runtime filesystem is still used for temporary processing. The final
uploaded files and processed derivatives are written through FAL to Blob.

## Verification After Deploy

Check the Vercel logs:

```bash
vercel logs typo3-camino-vercel.vercel.app --since 30m --expand
```

Expected boot output:

```text
TYPO3 object storage verified and required folders exist.
TYPO3 object storage uid 2 uses driver vercel_blob (Vercel Blob). It is the default upload storage.
```

Check the live site:

```bash
curl -I https://typo3-camino-vercel.vercel.app/
curl -I https://typo3-camino-vercel.vercel.app/typo3/
```

Check the database:

```sql
SELECT uid, name, driver, is_default, processingfolder
FROM sys_file_storage
WHERE driver = 'vercel_blob';
```

Expected driver:

```text
vercel_blob
```

Check through TYPO3:

1. log in to `/typo3`
2. open **Filelist**
3. confirm the `Vercel Blob uploads` storage exists
4. upload a small image
5. insert it on a page
6. reload the frontend
7. confirm the image URL is served from Vercel Blob
8. redeploy
9. confirm the image still exists

## CLI Smoke Probe

You can test the Blob store outside TYPO3 with the Vercel CLI:

```bash
printf 'TYPO3 Vercel Blob probe\n' > /tmp/typo3-vercel-blob-probe.txt
vercel blob put /tmp/typo3-vercel-blob-probe.txt \
  --pathname typo3/_probe.txt \
  --access public \
  --allow-overwrite true \
  --cache-control-max-age 60
vercel blob list --prefix typo3/_probe.txt
vercel blob del typo3/_probe.txt
```

If the CLI reports an OIDC/store-id mismatch, pass a read/write token explicitly
from a local ignored env file. Do not print the token and do not commit it.

## Backend Login Requirement

Blob fixes durable files. It does not fix TYPO3 backend sessions.

TYPO3 backend sessions live in the database. A stable backend login still needs
a durable shared database through `DATABASE_URL` or explicit TYPO3 DB env vars.
The seeded SQLite demo is for frontend/container smoke testing only.

## Performance Notes

Blob does not remove Vercel cold starts. It only removes the temporary-upload
problem.

Latest public-demo measurement after enabling Redis on 2026-07-07:

- frontend first request after deploy: 12.57 seconds
- warm backend login page: median 0.125 seconds over 10 requests
- later backend login cold check: first hit 10.151 seconds, then 0.206-0.238 seconds
- warm backend login preflight Ajax: median 0.100 seconds over 10 requests
- warm frontend home page: median 0.046 seconds over 10 requests

Blob did not cause those backend timing improvements; Redis and the broader
runtime tuning affect warm cache behavior. Blob's job is durable uploaded files
and processed derivatives. Cold starts remain slow. Backend pages are uncached
because they use cookies, sessions, and `no-store` headers.

See [performance notes](performance.md) for the full measurement context.

## Troubleshooting

### Storage Is Not Created

Check these env vars:

```dotenv
BLOB_READ_WRITE_TOKEN=<set by connected Vercel Blob store>
```

Then redeploy. The entrypoint only applies object storage during container boot.
For manual setup without a connected Vercel Blob store, set
`TYPO3_OBJECT_STORAGE_ENABLED=1` and
`TYPO3_OBJECT_STORAGE_DRIVER=vercel_blob`.

### Boot Fails During Object-Storage Verification

Keep `TYPO3_OBJECT_STORAGE_VERIFY_ON_BOOT=1` for production. If boot fails,
the Blob credentials or store configuration are wrong.

Check:

- the Blob store is connected to the correct Vercel project
- `BLOB_READ_WRITE_TOKEN` exists in the target environment
- `TYPO3_BLOB_ACCESS=public`
- `TYPO3_BLOB_PREFIX` does not contain a leading slash
- the deployment target is the same environment where the Blob store is attached

### Uploads Still Go To Local Storage

Check the `sys_file_storage` record:

```sql
SELECT uid, name, driver, is_default
FROM sys_file_storage
ORDER BY uid;
```

The Blob storage should have `driver = 'vercel_blob'` and `is_default = 1`.

If the record is missing, run one deploy with:

```dotenv
TYPO3_OBJECT_STORAGE_ENABLED=1
TYPO3_OBJECT_STORAGE_DRIVER=vercel_blob
TYPO3_OBJECT_STORAGE_VERIFY_ON_BOOT=1
```

### Public URLs Are Wrong

Use a public Blob store. For normal public TYPO3 assets, do not use private
Blob mode.

If a custom public base URL is required, set:

```dotenv
TYPO3_BLOB_PUBLIC_BASE_URL=<public-base-url>
```

### Existing Camino Images Still Use Local URLs

That is expected. The demo records reference committed seed files on local
storage uid `1`. New editor uploads use the Blob storage uid `2`.

Migrating existing files from uid `1` to uid `2` is intentionally not automated
by this starter.

### Login Is Still Unstable

Add a real database. Blob storage does not persist `be_sessions`.

### The Backend Is Still Slow On First Hit

That is a cold start. Keep one-shot setup flags disabled after setup:

```dotenv
TYPO3_AUTO_SETUP=0
TYPO3_BOOTSTRAP_EMPTY_DATABASE=0
TYPO3_EXTENSION_SETUP_ON_BOOT=0
TYPO3_ADMIN_PASSWORD_APPLY_ON_BOOT=0
```

For a warmer backend on Pro, deploy `vercel.pro.json`. Its protected warm-up
primes `/` and `/typo3/` every three minutes. Hobby cron is too limited for
this schedule.

## Security Notes

- Do not commit `.env` files.
- Do not put Blob tokens in a Deploy Button URL.
- Use Vercel encrypted environment variables for secrets.
- Use a dedicated Blob store or prefix per project.
- Public Blob stores are for public site assets; do not upload private files
  unless you have a separate access model.
- Keep TYPO3 upload file-extension restrictions enabled.
- Add malware scanning if untrusted users can upload files.

## Limitations

- No one-click clone can inherit the public demo's Blob store. The Deploy
  Button can create a new Blob store for that clone instead.
- The driver does not make SQLite durable.
- The driver does not persist TYPO3 cache files in `var/`.
- The driver does not migrate existing local files into Blob.
- The driver does not add virus scanning.
- The driver is project-local and should be reviewed before production use.

## References

- Vercel Deploy Button `stores`: https://vercel.com/docs/deploy-button/source
- Vercel Deploy Button env vars and defaults: https://vercel.com/docs/deploy-button/environment-variables
- Vercel Blob: https://vercel.com/docs/vercel-blob

## Cleanup

To stop using Blob in a test project:

1. disable object storage env vars
2. redeploy
3. delete test uploads from the Blob store
4. delete the Blob store if it is no longer needed

Do not delete a Blob store that contains files referenced by TYPO3 records you
still want to keep.
