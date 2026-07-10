# Object Storage And Durable Uploads

## The Rule

TYPO3 uploads are durable on Vercel only when object storage is configured.
Without object storage, uploaded files are written to the runtime filesystem and
can disappear after a cold start, redeploy, or scale-out.

This starter includes two TYPO3 14 FAL drivers for durable uploads.

Drivers:

- `vercel_blob` for Vercel Blob
- `vercel_s3` for S3-compatible object storage

Supported storage targets:

- Vercel Blob
- Cloudflare R2
- AWS S3
- MinIO
- DigitalOcean Spaces
- other providers with a compatible S3 API

Vercel Blob is not S3-compatible, so it uses the separate
`typo3_vercel_blob_storage` extension instead of the S3 driver.

## Vercel Blob Versus The Vercel File APIs

Use Vercel Blob for TYPO3 uploads.

The Vercel deployment File API (`POST /v2/files`) is for uploading files before
creating a Vercel deployment. It is not runtime storage for CMS uploads and it
does not behave like a persistent `fileadmin` volume.

The Vercel Sandbox filesystem APIs and Sandbox Drives are for Vercel Sandbox
sessions. They are useful for agent/code-execution workflows, but they are not
the production filesystem of this TYPO3 container project.

For TYPO3 FAL, the correct Vercel-native product is still Vercel Blob:

- it is designed for images, videos, and other uploaded files
- it is available on all Vercel plans
- the Deploy Button can create a public Blob store for each clone
- this repo includes the `vercel_blob` FAL driver for it

The Blob driver follows Vercel's connected-store model. An explicitly configured
credential remains an operator override; normal requests prefer the short-lived
Vercel OIDC token with `BLOB_STORE_ID`, then use `BLOB_READ_WRITE_TOKEN` as a
compatibility fallback for older connections and non-request CLI work.

The public demo deployment uses this Vercel Blob path. New projects cloned from
the Deploy Button can create their own Vercel Blob store during deployment. If
you skip that storage step, configure a Blob store or S3-compatible bucket
manually before accepting uploads.
For the full Blob extension manual, see
[Vercel Blob FAL driver](vercel-blob-fal-driver.md).

## Implementation Status

Durable TYPO3 uploads are implemented for Vercel Blob and S3-compatible
storage.

Vercel Blob implementation:

- TYPO3 driver: `vercel_blob`
- PHP namespace: `Webconsulting\Typo3VercelBlobStorage`
- Composer package: `webconsulting/typo3-vercel-blob-storage`
- Boot script: `scripts/apply-object-storage.php`
- Storage record: `sys_file_storage` uid `2` by default

S3-compatible implementation:

- TYPO3 driver: `vercel_s3`
- PHP namespace: `Webconsulting\Typo3VercelStorage`
- Composer package: `webconsulting/typo3-vercel-storage`
- Boot script: `scripts/apply-object-storage.php`
- Storage record: `sys_file_storage` uid `2` by default

When object storage is enabled, the Vercel container creates or updates the
TYPO3 storage record on boot. This happens when
`TYPO3_OBJECT_STORAGE_ENABLED=1`, when `TYPO3_OBJECT_STORAGE_DRIVER` is set, or
when a connected Vercel Blob store provides `BLOB_READ_WRITE_TOKEN`. Explicit
`TYPO3_OBJECT_STORAGE_ENABLED=0` disables automatic Blob setup.

To keep cold starts cheap, the boot script is a no-op once the stored record
already matches the configured driver: when the computed configuration is
unchanged it skips both the database write and the network folder verification.
The verification (and folder creation) therefore runs on the first boot after
object storage is enabled or its configuration changes, not on every request.
When verification does run and fails, the container exits with a clear error so
uploads do not silently fall back to Vercel's temporary filesystem.

## What The Entrypoint Does

When object storage is enabled, `docker/entrypoint.sh` runs
`scripts/apply-object-storage.php`. The script:

- checks that `sys_file_storage` exists
- creates or updates storage uid `2`
- sets the driver to `vercel_blob` or `vercel_s3`
- stores non-secret driver settings in TYPO3's FlexForm configuration
- makes uid `2` the default writable upload storage
- verifies the configured storage (unless `TYPO3_OBJECT_STORAGE_VERIFY_ON_BOOT=0`)
  when the record is created or its configuration changes, and skips the check on
  unchanged boots
- creates `user_upload/`, `_processed_/`, and `_temp_/` in object storage
- leaves the committed Camino seed files on the local storage uid `1`

Keeping the Camino seed assets on uid `1` avoids breaking the demo records that
already reference local files.

The image also contains the generated responsive derivatives needed by Camino's
seed pages. A Linux symlink covers one mixed-case filename in the upstream
Camino database so fresh containers can generate additional sizes on a
case-sensitive filesystem. These baked files are only demo fixtures. New
uploads and their processed derivatives belong on Blob/S3 storage uid `2`.

## Vercel Blob Setup

This is the all-Vercel durable upload path.

### Easiest path: Deploy Button

Use the Deploy Button in the README and keep the public Vercel Blob store
enabled. The button uses Vercel's `stores` parameter to create the Blob store.
Vercel then adds `BLOB_READ_WRITE_TOKEN` to the project environment. This
starter sees that token and automatically uses the `vercel_blob` FAL driver.

No Blob settings are required for the normal Deploy Button path.

These are the default values used by the starter:

```dotenv
TYPO3_OBJECT_STORAGE_ENABLED=1
TYPO3_OBJECT_STORAGE_DRIVER=vercel_blob
TYPO3_OBJECT_STORAGE_VERIFY_ON_BOOT=1
TYPO3_BLOB_ACCESS=public
TYPO3_BLOB_PREFIX=typo3/
```

You still need to type your own TYPO3 admin password and encryption key. The
Blob token is created by Vercel and is not placed in the Deploy Button URL.

To turn this off in a disposable test project, set:

```dotenv
TYPO3_OBJECT_STORAGE_ENABLED=0
```

### Manual path: Vercel CLI

1. Create a public Vercel Blob store connected to the project.
2. Enable TYPO3 object storage with the Blob driver.
3. Redeploy production.

```bash
vercel blob create-store typo3-camino-uploads --access public --environment production --yes
vercel env add TYPO3_OBJECT_STORAGE_ENABLED production --value 1 --yes
vercel env add TYPO3_OBJECT_STORAGE_DRIVER production --value vercel_blob --yes
vercel env add TYPO3_OBJECT_STORAGE_VERIFY_ON_BOOT production --value 1 --yes
vercel env add TYPO3_BLOB_ACCESS production --value public --yes
vercel env add TYPO3_BLOB_PREFIX production --value typo3/ --yes
vercel deploy --prod
```

New connected stores can use request-scoped OIDC and `BLOB_STORE_ID`; older
connections may provide `BLOB_READ_WRITE_TOKEN`. The driver supports both.

Optional Blob variables:

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

Use a public Blob store for normal TYPO3 images and downloads. Private Blob
stores do not produce public FAL URLs and are not the right default for public
frontend assets.

### Upload Size

Vercel Functions reject complete request bodies above 4.5 MB before PHP runs.
The container therefore sets `post_max_size` and `upload_max_filesize` to 4 MB.
This is independent of Blob capacity: a normal TYPO3 backend upload still
passes through PHP before the FAL driver writes it to Blob.

For larger media, use **Media > Large upload** or the **Large upload** toolbar
button while viewing a Vercel Blob folder. The implemented flow is:

1. TYPO3 checks the backend user, file mount, folder permissions, name, type,
   and configured size limit.
2. Vercel returns a short-lived upload token scoped to that exact Blob path,
   content type, and size. Overwrite and random suffixes are disabled.
3. The browser uploads directly to Blob. Files above 100 MB use multipart upload.
4. TYPO3 verifies the resulting remote object and registers it in FAL without
   downloading the complete file into PHP.

The default direct-upload limit is 5 GiB. Change it only when needed:

```dotenv
TYPO3_BLOB_DIRECT_UPLOAD_MAX_BYTES=5368709120
TYPO3_BLOB_DIRECT_UPLOAD_TOKEN_TTL=14400
```

The size is capped at Vercel Blob's 5 TB platform limit, and token lifetime is
bounded between five minutes and 24 hours. Active web formats such as HTML,
JavaScript, SVG, and XML are blocked because this path cannot safely run
content inspection on a local upload first.

This large-upload module is specific to `vercel_blob`. The S3-compatible driver
still uses TYPO3's normal uploader and therefore remains subject to the 4 MB
request limit unless a separate direct-to-S3 integration is added.

## S3-Compatible Setup

Set these in Vercel for Production, Preview, and Development as needed:

```dotenv
TYPO3_OBJECT_STORAGE_ENABLED=1
TYPO3_OBJECT_STORAGE_DRIVER=vercel_s3
TYPO3_OBJECT_STORAGE_VERIFY_ON_BOOT=1
TYPO3_S3_BUCKET=<bucket>
TYPO3_S3_REGION=auto
TYPO3_S3_ENDPOINT=<s3-compatible-endpoint>
TYPO3_S3_ACCESS_KEY_ID=<access-key>
TYPO3_S3_SECRET_ACCESS_KEY=<secret-key>
TYPO3_S3_PUBLIC_BASE_URL=<public-bucket-or-cdn-url>
```

Optional:

```dotenv
TYPO3_S3_PREFIX=typo3/
TYPO3_S3_STORAGE_UID=2
TYPO3_S3_STORAGE_NAME=Object storage uploads
TYPO3_S3_PATH_STYLE_ENDPOINT=1
TYPO3_S3_DEFAULT_FOLDER=user_upload
TYPO3_S3_CACHE_CONTROL=public, max-age=31536000, immutable
TYPO3_S3_MAKE_DEFAULT=1
TYPO3_S3_SIGNED_URL_TTL=0
TYPO3_S3_PROCESSING_FOLDER=_processed_
```

Use a public base URL for normal TYPO3 images and downloads. Signed URLs are
available as a fallback for private buckets, but public content and cacheable
images are simpler with a public bucket domain or CDN domain.

## Cloudflare R2 Setup

1. Create a Cloudflare R2 bucket.
2. Create an R2 API token with object read/write access for that bucket.
3. Enable a public bucket URL or attach a custom public domain.
4. Add the Vercel env vars:

```dotenv
TYPO3_OBJECT_STORAGE_ENABLED=1
TYPO3_S3_BUCKET=<r2-bucket-name>
TYPO3_S3_REGION=auto
TYPO3_S3_ENDPOINT=https://<cloudflare-account-id>.r2.cloudflarestorage.com
TYPO3_S3_ACCESS_KEY_ID=<r2-access-key-id>
TYPO3_S3_SECRET_ACCESS_KEY=<r2-secret-access-key>
TYPO3_S3_PUBLIC_BASE_URL=https://<public-r2-domain>/
TYPO3_S3_PREFIX=typo3/
TYPO3_S3_PATH_STYLE_ENDPOINT=1
```

Then redeploy. On boot, the container creates the TYPO3 storage record. New
backend uploads should go to storage uid `2`.

## Add S3 Variables With Vercel CLI

Run these from the repository root. Use interactive prompts for secrets so they
do not land in shell history:

```bash
vercel env add TYPO3_OBJECT_STORAGE_ENABLED production --value 1 --yes
vercel env add TYPO3_OBJECT_STORAGE_DRIVER production --value vercel_s3 --yes
vercel env add TYPO3_OBJECT_STORAGE_VERIFY_ON_BOOT production --value 1 --yes
vercel env add TYPO3_S3_BUCKET production --value "<bucket>" --yes
vercel env add TYPO3_S3_REGION production --value "auto" --yes
vercel env add TYPO3_S3_ENDPOINT production --value "https://<account-id>.r2.cloudflarestorage.com" --yes
vercel env add TYPO3_S3_PUBLIC_BASE_URL production --value "https://<public-r2-domain>/" --yes
vercel env add TYPO3_S3_PREFIX production --value "typo3/" --yes
vercel env add TYPO3_S3_PATH_STYLE_ENDPOINT production --value 1 --yes
vercel env add TYPO3_S3_ACCESS_KEY_ID production
vercel env add TYPO3_S3_SECRET_ACCESS_KEY production
vercel deploy --prod
```

Repeat the same variables for `preview` if editors should test uploads on
Preview deployments too.

## AWS S3 Setup

For AWS S3, the endpoint can be empty:

```dotenv
TYPO3_OBJECT_STORAGE_ENABLED=1
TYPO3_S3_BUCKET=<bucket>
TYPO3_S3_REGION=eu-central-1
TYPO3_S3_ENDPOINT=
TYPO3_S3_ACCESS_KEY_ID=<iam-access-key-id>
TYPO3_S3_SECRET_ACCESS_KEY=<iam-secret-access-key>
TYPO3_S3_PUBLIC_BASE_URL=https://<bucket-or-cdn-domain>/
TYPO3_S3_PATH_STYLE_ENDPOINT=0
```

Use an IAM user or role with the smallest practical permissions for the bucket
prefix TYPO3 uses.

## Verification

After deployment:

1. Open `/typo3`.
2. Go to **Filelist**.
3. Confirm there is an object-storage volume using `vercel_blob` or `vercel_s3`.
4. Upload a small image.
5. Confirm it appears in Blob/S3 under the configured prefix.
6. Insert the image on a page.
7. Confirm the frontend image URL uses the Blob public URL or
   `TYPO3_S3_PUBLIC_BASE_URL`.
8. Redeploy the Vercel project.
9. Confirm the uploaded file is still available.

An operator can also run the authenticated write health probe:

```bash
curl -fsS \
  -H "Authorization: Bearer $CRON_SECRET" \
  'https://your-project.vercel.app/api/health.php?deep=1&write=1'
```

The Blob check performs put, read, and delete and removes its test object. A
read-only deep check lists the configured prefix without writing.

You can also confirm the storage record from the database:

```sql
SELECT uid, name, driver, is_default, processingfolder
FROM sys_file_storage
WHERE driver = 'vercel_s3';
```

For Blob, use:

```sql
SELECT uid, name, driver, is_default, processingfolder
FROM sys_file_storage
WHERE driver = 'vercel_blob';
```

## Security Notes

- Do not commit access keys.
- Set object-storage env vars in Vercel's encrypted environment variable UI or
  through `vercel env add`.
- Prefer a dedicated bucket or prefix for each TYPO3 project.
- Restrict write credentials to the project store, bucket, or prefix.
- Use a public read domain only for files intended to be public.
- Keep TYPO3's allowed file extension checks enabled.
- Scan or moderate uploads if untrusted users can upload files.

## What This Does Not Solve

- It does not make SQLite durable.
- It does not persist `var/` cache files.
- It does not provide virus scanning.
- It does not migrate existing files from local storage uid `1` into the bucket.
