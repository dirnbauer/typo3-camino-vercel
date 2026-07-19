# Object Storage And Durable Uploads

## The Rule

TYPO3 uploads are durable on Vercel only when object storage is configured.
Without it, uploaded files land on the runtime filesystem and can disappear
after a cold start, redeploy, or scale-out.

This starter includes two TYPO3 14 FAL drivers:

- `vercel_blob` for Vercel Blob
- `vercel_s3` for S3-compatible storage: Cloudflare R2, AWS S3, MinIO,
  DigitalOcean Spaces, and similar providers

Vercel Blob is not S3-compatible, so it uses the separate
`typo3_vercel_blob_storage` extension instead of the S3 driver. Use Vercel
Blob for the all-Vercel path; its driver details are in the
[Vercel Blob FAL driver manual](vercel-blob-fal-driver.md). Vercel's
deployment File API and Sandbox filesystems are different products for build
artifacts and sandbox sessions; they are not runtime CMS storage.

## What The Entrypoint Does

Object storage is applied during container boot when
`TYPO3_OBJECT_STORAGE_ENABLED=1`, when `TYPO3_OBJECT_STORAGE_DRIVER` is set,
or when a connected Vercel Blob store provides credentials. An explicit
`TYPO3_OBJECT_STORAGE_ENABLED=0` disables it.

`docker/entrypoint.sh` then runs `scripts/apply-object-storage.php`, which:

- creates or updates `sys_file_storage` uid `2` with driver `vercel_blob` or
  `vercel_s3` and makes it the default writable upload storage
- stores non-secret driver settings in TYPO3's FlexForm configuration
- creates `user_upload/`, `_processed_/`, `_processed_local_/`, and `_temp_/`
  in object storage
- points local storages' `processingfolder` at `2:/_processed_local_/` and
  purges their stale processed-file records once, so image derivatives are
  durable instead of dying with an instance
- verifies storage access (unless `TYPO3_OBJECT_STORAGE_VERIFY_ON_BOOT=0`)
  when a record is created or its configuration changes; unchanged boots
  skip both the database writes and the network check to keep cold starts cheap
- fails the container loudly when verification runs and does not pass, so
  uploads cannot silently fall back to the temporary filesystem
- leaves the committed Camino seed files on local storage uid `1`, so demo
  records that reference local files keep working

The image also ships baked derivatives for Camino's seed pages; they cover
first renders and previously cached HTML. All newly generated derivatives —
for seed files and uploads alike — live on object storage.

## Vercel Blob Setup

### Easiest path: Deploy Button

Use the README Deploy Button and keep the public Blob store enabled. Vercel
creates the store and its credentials (request OIDC with `BLOB_STORE_ID`, or
`BLOB_READ_WRITE_TOKEN` on older connections); the starter detects them and
enables the `vercel_blob` driver automatically. No Blob fields to fill in,
and no secrets ever belong in a Deploy Button URL.

The defaults used by the starter:

```dotenv
TYPO3_OBJECT_STORAGE_ENABLED=1
TYPO3_OBJECT_STORAGE_DRIVER=vercel_blob
TYPO3_OBJECT_STORAGE_VERIFY_ON_BOOT=1
TYPO3_BLOB_ACCESS=public
TYPO3_BLOB_PREFIX=typo3/
```

To turn automatic Blob storage off in a disposable test project, set
`TYPO3_OBJECT_STORAGE_ENABLED=0`.

### Manual path: Vercel CLI

```bash
vercel blob create-store typo3-camino-uploads --access public --environment production --yes
vercel env add TYPO3_OBJECT_STORAGE_ENABLED production --value 1 --yes
vercel env add TYPO3_OBJECT_STORAGE_DRIVER production --value vercel_blob --yes
vercel env add TYPO3_OBJECT_STORAGE_VERIFY_ON_BOOT production --value 1 --yes
vercel env add TYPO3_BLOB_ACCESS production --value public --yes
vercel env add TYPO3_BLOB_PREFIX production --value typo3/ --yes
vercel deploy --prod
```

Keep the explicit enable/driver variables for manual setups so the template
never switches storage drivers merely because a token exists.

### Optional Blob variables

```dotenv
TYPO3_BLOB_STORE_ID=<store-id-if-not-provided-by-vercel>
TYPO3_BLOB_TOKEN_ENV_NAME=BLOB_READ_WRITE_TOKEN
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
# Derivatives of local storages; "local" reverts, "unmanaged" leaves rows alone
TYPO3_LOCAL_STORAGE_PROCESSING_FOLDER=2:/_processed_local_/
```

Use a public Blob store: private stores do not produce public FAL URLs and
are not the right default for frontend assets.

### Upload size

Vercel rejects request bodies above 4.5 MB, so normal TYPO3 uploads are
capped at 4 MB. Larger files use the **Media > Large upload**
browser-to-Blob flow (default limit 5 GiB); see the
[driver manual](vercel-blob-fal-driver.md) for the validation steps and
security trade-offs. The large-upload module is specific to `vercel_blob`;
the S3 driver uses TYPO3's normal uploader and stays subject to the 4 MB
limit.

## S3-Compatible Setup

Set these in Vercel for each environment that accepts uploads:

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

Use a public base URL for normal images and downloads; signed URLs remain a
fallback for private buckets. Add secrets through interactive
`vercel env add TYPO3_S3_ACCESS_KEY_ID production` prompts so they stay out
of shell history.

### Cloudflare R2

1. Create an R2 bucket and an API token with object read/write access.
2. Enable a public bucket URL or attach a custom domain.
3. Set the S3 variables above with
   `TYPO3_S3_ENDPOINT=https://<account-id>.r2.cloudflarestorage.com`,
   `TYPO3_S3_REGION=auto`, and `TYPO3_S3_PATH_STYLE_ENDPOINT=1`.
4. Redeploy; the container creates the TYPO3 storage record on boot.

### AWS S3

Leave `TYPO3_S3_ENDPOINT` empty, set the real region (for example
`eu-central-1`) and `TYPO3_S3_PATH_STYLE_ENDPOINT=0`, and use an IAM user or
role with the smallest practical permissions for the bucket prefix.

## Verification

After deployment:

1. Open `/typo3` > **Filelist** and confirm the object-storage volume exists.
2. Upload a small image and confirm it appears in Blob/S3 under the prefix.
3. Insert it on a page and confirm the frontend URL uses the public
   Blob/S3 base URL.
4. Redeploy and confirm the file is still available.

Or run the authenticated write probe (put, read, delete):

```bash
curl -fsS \
  -H "Authorization: Bearer $CRON_SECRET" \
  'https://your-project.vercel.app/api/health.php?deep=1&write=1'
```

The storage record can also be checked directly:

```sql
SELECT uid, name, driver, is_default, processingfolder
FROM sys_file_storage
WHERE driver IN ('vercel_blob', 'vercel_s3');
```

## Security Notes

- Do not commit access keys; use Vercel's encrypted environment variables.
- Prefer a dedicated bucket/store or prefix per TYPO3 project, and restrict
  write credentials to it.
- Use a public read domain only for files intended to be public.
- Keep TYPO3's allowed file extension checks enabled; scan or moderate
  uploads from untrusted users.

## What This Does Not Solve

Object storage does not make SQLite durable, does not persist `var/` caches,
does not provide virus scanning, and does not migrate existing local files
from storage uid `1` into the bucket.
