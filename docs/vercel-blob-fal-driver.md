# Vercel Blob FAL Driver

This project includes a TYPO3 FAL driver for Vercel Blob. It is the preferred
all-Vercel path for durable editor uploads in this starter. The driver is a
project-local extension, not part of TYPO3 core and not an official TYPO3
package.

For setup steps and the shared object-storage environment reference, see
[object storage and durable uploads](object-storage.md). This page documents
the driver itself.

## Why This Exists

Vercel's runtime filesystem is disposable: files written to `fileadmin/`,
`typo3temp/`, or `var/` can disappear after a cold start, redeploy, or
scale-out. TYPO3's answer for durable files is FAL with an object-storage
driver.

Vercel Blob is not S3-compatible, so the repository contains two drivers:

- `vercel_blob` for Vercel Blob
- `vercel_s3` for S3-compatible storage such as Cloudflare R2, AWS S3, MinIO,
  or DigitalOcean Spaces

## Extension Location

- Package: `packages/typo3-vercel-blob-storage`
  (Composer `webconsulting/typo3-vercel-blob-storage`,
  extension key `typo3_vercel_blob_storage`,
  namespace `Webconsulting\Typo3VercelBlobStorage`)
- TYPO3 FAL driver id: `vercel_blob`
- Entry points: `Classes/Resource/Driver/BlobDriver.php`,
  `Classes/Client/VercelBlobClient.php`,
  `Classes/Authentication/BlobCredentials.php`,
  `Classes/DirectUpload/DirectUploadService.php`,
  `scripts/apply-object-storage.php`, and `docker/entrypoint.sh`

## Runtime Model

When object storage is enabled (see the trigger rules in
[object-storage.md](object-storage.md)), the container entrypoint runs
`scripts/apply-object-storage.php`. For `vercel_blob`, the script:

1. creates or updates `sys_file_storage` uid `2` with driver `vercel_blob`
2. stores non-secret driver options in the TYPO3 FlexForm XML and keeps the
   Blob token as an env-var *name*, never as a value in the database
3. makes uid `2` the default writable storage
4. creates `user_upload/`, `_processed_/`, and `_temp_/` in Blob
5. fails startup when verification is enabled and Blob access does not work

The committed Camino seed files stay on local storage uid `1` so demo records
keep working. New editor uploads belong on storage uid `2`. Blob objects live
below the configured prefix, normally `typo3/user_upload/`,
`typo3/_processed_/`, and `typo3/_temp_/`.

Blob makes uploads durable. It does not make the demo SQLite database durable;
backend login and content need `DATABASE_URL`
(see [backend login](backend-login.md)).

## Token Handling

The driver supports two credential modes:

- request OIDC through `VERCEL_OIDC_TOKEN` plus `BLOB_STORE_ID` (preferred:
  short-lived, rotated by Vercel; the driver exports the request token for
  protected child CLI calls)
- `BLOB_READ_WRITE_TOKEN` as compatibility fallback for local development,
  manual CLI/API use, older connected stores, and jobs without request OIDC

## Why Not The Vercel File API?

The Vercel deployment File API uploads build files before a deployment is
created; TYPO3 uploads happen at runtime. Vercel Sandbox filesystems belong to
Sandbox sessions, not this production container. For runtime CMS uploads the
correct Vercel-native product is Blob; use the S3 driver only for S3-ecosystem
providers.

## Upload Size Limit

Vercel rejects request bodies above 4.5 MB, so the container caps normal
TYPO3 uploads at 4 MB (`post_max_size`) to leave room for multipart metadata.
Raising `upload_max_filesize` cannot bypass the platform limit.

For larger files the extension adds a **Media > Large upload** module and a
**Large upload to Vercel Blob** button. Opening the module from the bundled
Camino storage automatically selects the first writable Blob folder and shows
the destination before upload. The flow:

1. validate the authenticated backend user, FAL file mount and permissions,
   filename, declared MIME type, and size
2. issue a short-lived Vercel token restricted to one exact path/type/size
3. upload browser-to-Blob, using multipart for files above 100 MB
4. verify the remote size and MIME type, then create the `sys_file` record

The payload never passes through PHP, and FAL hashing uses remote object
fingerprints instead of downloading the object. The default limit is 5 GiB;
`TYPO3_BLOB_DIRECT_UPLOAD_MAX_BYTES` can raise it up to Vercel Blob's 5 TB
hard limit. `TYPO3_BLOB_DIRECT_UPLOAD_TOKEN_TTL` defaults to four hours and
accepts 300 to 86,400 seconds.

Security trade-off: the declared MIME type is constrained by the file
extension and rechecked against Blob metadata, but the server never scans the
full file. HTML, JavaScript, SVG, XML, and related active formats are
therefore blocked. Add an asynchronous scanner/quarantine workflow before
accepting untrusted public uploads.

Very large images can upload successfully but later fail when
TYPO3/ImageMagick must download and transform the original within Vercel's
temporary-disk and request-duration limits. Store large videos and archives as
downloads; process large media outside the request path.

## Public Versus Private Blob Stores

Use a public Blob store. TYPO3 frontend images, downloads, and processed files
need public URLs; private Blob mode does not produce them and is not a good
default for normal site assets.

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

Then in TYPO3: log in, open **Filelist**, confirm the `Vercel Blob uploads`
storage, upload a small image, place it on a page, confirm the frontend URL is
served from Blob, redeploy, and confirm the image survived.

## CLI Smoke Probe

Test the Blob store outside TYPO3 with the Vercel CLI:

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

If the CLI reports an OIDC/store-id mismatch, pass a read/write token from a
local ignored env file. Do not print or commit the token.

## Performance Notes

Blob solves durable files; it does not remove Vercel cold starts, and backend
pages stay uncached because they use cookies and sessions. See
[performance notes](performance.md) for measurements.

## Troubleshooting

### Storage Is Not Created

Confirm `BLOB_READ_WRITE_TOKEN` (or OIDC store credentials) exists in the
target environment, then redeploy: the entrypoint applies object storage only
during container boot. Without a connected store, set
`TYPO3_OBJECT_STORAGE_ENABLED=1` and
`TYPO3_OBJECT_STORAGE_DRIVER=vercel_blob` explicitly.

### Boot Fails During Object-Storage Verification

Keep `TYPO3_OBJECT_STORAGE_VERIFY_ON_BOOT=1` for production; a failing boot
means the Blob credentials or store configuration are wrong. Check that the
store is connected to the correct project and environment,
`TYPO3_BLOB_ACCESS=public`, and `TYPO3_BLOB_PREFIX` has no leading slash.

### Uploads Still Go To Local Storage

Inspect `sys_file_storage`: the Blob storage should have
`driver = 'vercel_blob'` and `is_default = 1`. If the record is missing, run
one deploy with the explicit enable variables above.

### Existing Camino Images Still Use Local URLs

Expected: demo records reference committed seed files on storage uid `1`.
Migrating existing files into Blob is intentionally not automated.

### Login Is Still Unstable

Add a real database; Blob does not persist `be_sessions`.

### The Backend Is Still Slow On First Hit

That is a cold start, not a Blob problem. Keep one-shot setup flags disabled
after setup. Use the always-on profile if predictable first-request latency is
required (see [performance](performance.md)).

## Security Notes

- Never commit `.env` files or put Blob tokens in a Deploy Button URL.
- Use a dedicated Blob store or prefix per project.
- Public Blob stores are for public site assets; do not upload private files
  without a separate access model.
- Keep TYPO3 upload file-extension restrictions enabled; add malware scanning
  when untrusted users can upload.

## Limitations

- A one-click clone cannot inherit the public demo's Blob store; the Deploy
  Button creates a new store instead.
- The driver does not make SQLite durable, does not persist `var/` caches,
  does not migrate existing local files, and does not scan uploads.
- The driver is project-local; review it before production use.

## References

- Vercel Deploy Button `stores`: https://vercel.com/docs/deploy-button/source
- Vercel Blob: https://vercel.com/docs/vercel-blob

## Cleanup

Disable the object-storage env vars, redeploy, then delete test uploads and
the store if no longer needed. Do not delete a Blob store that still contains
files referenced by TYPO3 records you want to keep.
