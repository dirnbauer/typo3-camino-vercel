# Object Storage And Durable Uploads

## The Rule

TYPO3 uploads are durable on Vercel only when object storage is configured.
Without object storage, uploaded files are written to the runtime filesystem and
can disappear after a cold start, redeploy, or scale-out.

This starter includes a TYPO3 14 FAL driver named `vercel_s3`. It supports
S3-compatible object storage:

- Cloudflare R2
- AWS S3
- MinIO
- DigitalOcean Spaces
- other providers with a compatible S3 API

Vercel Blob is not supported by this driver because Vercel Blob does not expose
an S3-compatible API. Blob needs a separate TYPO3 FAL driver.

## What The Entrypoint Does

When object storage is enabled, `docker/entrypoint.sh` runs
`scripts/apply-object-storage.php`. The script:

- checks that `sys_file_storage` exists
- creates or updates storage uid `2`
- sets the driver to `vercel_s3`
- stores the bucket settings in TYPO3's FlexForm configuration
- makes uid `2` the default writable upload storage
- leaves the committed Camino seed files on the local storage uid `1`

Keeping the Camino seed assets on uid `1` avoids breaking the demo records that
already reference local files.

## Required Variables

Set these in Vercel for Production, Preview, and Development as needed:

```dotenv
TYPO3_OBJECT_STORAGE_ENABLED=1
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
3. Confirm there is an object-storage volume.
4. Upload a small image.
5. Confirm it appears in the bucket under the configured prefix.
6. Insert the image on a page.
7. Confirm the frontend image URL uses `TYPO3_S3_PUBLIC_BASE_URL`.
8. Redeploy the Vercel project.
9. Confirm the uploaded file is still available.

## Security Notes

- Do not commit access keys.
- Set object-storage env vars in Vercel's encrypted environment variable UI or
  through `vercel env add`.
- Prefer a dedicated bucket or prefix for each TYPO3 project.
- Restrict write credentials to the project bucket/prefix.
- Use a public read domain only for files intended to be public.
- Keep TYPO3's allowed file extension checks enabled.
- Scan or moderate uploads if untrusted users can upload files.

## What This Does Not Solve

- It does not make SQLite durable.
- It does not persist `var/` cache files.
- It does not provide virus scanning.
- It does not support Vercel Blob.
- It does not migrate existing files from local storage uid `1` into the bucket.
