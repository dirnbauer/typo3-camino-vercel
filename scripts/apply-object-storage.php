#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/typo3-env.php';

$root = dirname(__DIR__);
chdir($root);
require $root . '/vendor/autoload.php';

if (!typo3_vercel_object_storage_enabled()) {
    fwrite(STDOUT, "TYPO3 object storage is not enabled; keeping local fileadmin storage.\n");
    exit(0);
}

$database = typo3_vercel_database_config();
$pdo = typo3_vercel_object_storage_pdo($database);

if (!typo3_vercel_object_storage_table_exists($pdo)) {
    fwrite(STDOUT, "sys_file_storage does not exist yet; object storage will be applied after TYPO3 setup.\n");
    exit(0);
}

$setup = typo3_vercel_object_storage_setup();
$driverName = $setup['driver'];
$configuration = $setup['configuration'];
$storageUid = $setup['storageUid'];
$storageName = $setup['storageName'];
$makeDefault = $setup['makeDefault'];
$processingFolder = $setup['processingFolder'];
$columns = typo3_vercel_table_columns($pdo, 'sys_file_storage');

if ($columns === []) {
    fwrite(STDERR, "Could not inspect sys_file_storage columns.\n");
    exit(1);
}

$now = time();
$values = [
    'uid' => $storageUid,
    'pid' => 0,
    'tstamp' => $now,
    'crdate' => $now,
    'name' => $storageName,
    'description' => 'Created from Vercel object storage environment variables.',
    'driver' => $driverName,
    'configuration' => typo3_vercel_flexform_xml($configuration),
    'is_online' => 1,
    'auto_extract_metadata' => 1,
    'is_browsable' => 1,
    'is_public' => 1,
    'is_writable' => 1,
    'is_default' => $makeDefault ? 1 : 0,
    'processingfolder' => $processingFolder,
];
$values = array_intersect_key($values, array_flip($columns));

// The stored row and its remote folders only need to be (re)created when the
// computed configuration actually changes. Skipping an unchanged row avoids the
// per-cold-start cost of the write plus the network folder verification, which
// otherwise runs on every container boot whenever object-storage env vars are set.
$volatileColumns = ['tstamp' => true, 'crdate' => true];
$comparableValues = array_diff_key($values, $volatileColumns);
$existingRow = typo3_vercel_existing_storage_row($pdo, $storageUid, array_keys($comparableValues));
$objectStorageChanged = !($existingRow !== null && typo3_vercel_storage_row_matches($existingRow, $comparableValues));

if (!$objectStorageChanged) {
    fwrite(STDOUT, sprintf(
        "TYPO3 object storage uid %d already matches the configured %s driver; skipping.\n",
        $storageUid,
        $driverName
    ));
} else {
    try {
        $pdo->beginTransaction();

        if ($makeDefault && in_array('is_default', $columns, true)) {
            $statement = $pdo->prepare('UPDATE sys_file_storage SET is_default = 0 WHERE uid <> :uid');
            $statement->execute(['uid' => $storageUid]);
        }

        if (typo3_vercel_storage_exists($pdo, $storageUid)) {
            typo3_vercel_update_storage_row($pdo, $values, $storageUid);
        } else {
            $statement = $pdo->prepare(
                'INSERT INTO sys_file_storage (' . implode(', ', array_keys($values)) . ') VALUES (:' . implode(', :', array_keys($values)) . ')'
            );
            $statement->execute($values);
        }

        $pdo->commit();
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        // A concurrent cold start may have inserted uid first. Converge to the same
        // configuration with a plain UPDATE instead of failing this instance's boot.
        if (typo3_vercel_is_duplicate_key_error($exception)) {
            try {
                typo3_vercel_update_storage_row($pdo, $values, $storageUid);
            } catch (Throwable $updateException) {
                fwrite(STDERR, sprintf("Applying TYPO3 object storage failed: %s\n", $updateException->getMessage()));
                exit(1);
            }
        } else {
            fwrite(STDERR, sprintf("Applying TYPO3 object storage failed: %s\n", $exception->getMessage()));
            exit(1);
        }
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        fwrite(STDERR, sprintf("Applying TYPO3 object storage failed: %s\n", $exception->getMessage()));
        exit(1);
    }
}

$localProcessingTarget = typo3_vercel_local_storage_processing_target($storageUid, $driverName);
$localStorageChanged = typo3_vercel_apply_local_storage_processing_folder($pdo, $storageUid, $localProcessingTarget);

if (!$objectStorageChanged && !$localStorageChanged) {
    exit(0);
}

if (typo3_vercel_bool_env('TYPO3_OBJECT_STORAGE_VERIFY_ON_BOOT', true)
    && ($driverName !== 'vercel_blob' || typo3_vercel_blob_boot_token_available($configuration))
) {
    typo3_vercel_verify_object_storage(
        $driverName,
        $configuration,
        $storageUid,
        $processingFolder,
        $localProcessingTarget
    );
} elseif ($driverName === 'vercel_blob' && !typo3_vercel_blob_boot_token_available($configuration)) {
    fwrite(STDOUT, "Vercel Blob uses request-scoped OIDC; remote verification is deferred until an HTTP request supplies the OIDC header.\n");
}

fwrite(STDOUT, sprintf(
    "TYPO3 object storage uid %d uses driver %s (%s).%s\n",
    $storageUid,
    $driverName,
    $setup['label'],
    $makeDefault ? ' It is the default upload storage.' : ''
));

function typo3_vercel_object_storage_enabled(): bool
{
    $explicit = typo3_vercel_env('TYPO3_OBJECT_STORAGE_ENABLED');
    if ($explicit !== null) {
        return typo3_vercel_bool_env('TYPO3_OBJECT_STORAGE_ENABLED', false);
    }
    return typo3_vercel_env('TYPO3_OBJECT_STORAGE_DRIVER') !== null
        || typo3_vercel_env('TYPO3_S3_BUCKET') !== null
        || typo3_vercel_bool_env('TYPO3_BLOB_ENABLED', false)
        || typo3_vercel_env('BLOB_READ_WRITE_TOKEN') !== null
        || typo3_vercel_env('BLOB_STORE_ID') !== null;
}

function typo3_vercel_object_storage_setup(): array
{
    $driverName = typo3_vercel_object_storage_driver();
    $configuration = match ($driverName) {
        'vercel_blob' => typo3_vercel_blob_object_storage_configuration(),
        'vercel_s3' => typo3_vercel_s3_object_storage_configuration(),
        default => throw new RuntimeException('Unsupported object storage driver: ' . $driverName),
    };

    return [
        'driver' => $driverName,
        'configuration' => $configuration,
        'storageUid' => (int)typo3_vercel_first_env(['TYPO3_OBJECT_STORAGE_STORAGE_UID', 'TYPO3_BLOB_STORAGE_UID', 'TYPO3_S3_STORAGE_UID'], '2'),
        'storageName' => typo3_vercel_first_env(
            ['TYPO3_OBJECT_STORAGE_STORAGE_NAME', 'TYPO3_BLOB_STORAGE_NAME', 'TYPO3_S3_STORAGE_NAME'],
            $driverName === 'vercel_blob' ? 'Vercel Blob uploads' : 'Object storage uploads'
        ),
        'makeDefault' => typo3_vercel_bool_env('TYPO3_OBJECT_STORAGE_MAKE_DEFAULT', typo3_vercel_bool_env('TYPO3_S3_MAKE_DEFAULT', true)),
        'processingFolder' => typo3_vercel_first_env(
            ['TYPO3_OBJECT_STORAGE_PROCESSING_FOLDER', 'TYPO3_BLOB_PROCESSING_FOLDER', 'TYPO3_S3_PROCESSING_FOLDER'],
            '_processed_'
        ),
        'label' => $driverName === 'vercel_blob'
            ? 'Vercel Blob'
            : 'S3-compatible bucket ' . ($configuration['bucket'] ?? ''),
    ];
}

function typo3_vercel_object_storage_driver(): string
{
    $driver = strtolower((string)typo3_vercel_env('TYPO3_OBJECT_STORAGE_DRIVER', ''));
    if (in_array($driver, ['vercel_blob', 'blob', 'vercel-blob'], true)) {
        return 'vercel_blob';
    }
    if (in_array($driver, ['vercel_s3', 's3', 's3-compatible'], true)) {
        return 'vercel_s3';
    }
    if (typo3_vercel_bool_env('TYPO3_BLOB_ENABLED', false)
        || typo3_vercel_env('BLOB_READ_WRITE_TOKEN') !== null
        || typo3_vercel_env('BLOB_STORE_ID') !== null
    ) {
        return 'vercel_blob';
    }
    return 'vercel_s3';
}

function typo3_vercel_s3_object_storage_configuration(): array
{
    $bucket = typo3_vercel_env('TYPO3_S3_BUCKET');
    if ($bucket === null) {
        fwrite(STDERR, "TYPO3_S3_BUCKET is required when object storage is enabled.\n");
        exit(1);
    }

    $accessKey = typo3_vercel_env('TYPO3_S3_ACCESS_KEY_ID') ?? typo3_vercel_env('AWS_ACCESS_KEY_ID');
    $secretKey = typo3_vercel_env('TYPO3_S3_SECRET_ACCESS_KEY') ?? typo3_vercel_env('AWS_SECRET_ACCESS_KEY');
    $useInstanceCredentials = typo3_vercel_bool_env('TYPO3_S3_USE_INSTANCE_CREDENTIALS', false);
    if (!$useInstanceCredentials && ($accessKey === null || $secretKey === null)) {
        fwrite(STDERR, "TYPO3_S3_ACCESS_KEY_ID and TYPO3_S3_SECRET_ACCESS_KEY are required unless TYPO3_S3_USE_INSTANCE_CREDENTIALS=1.\n");
        exit(1);
    }

    $publicBaseUrl = typo3_vercel_env('TYPO3_S3_PUBLIC_BASE_URL');
    $signedUrlTtl = (int)typo3_vercel_env('TYPO3_S3_SIGNED_URL_TTL', '0');
    if ($publicBaseUrl === null && $signedUrlTtl <= 0) {
        fwrite(STDERR, "TYPO3_S3_PUBLIC_BASE_URL is required for public TYPO3 assets. Alternatively set TYPO3_S3_SIGNED_URL_TTL for private signed URLs.\n");
        exit(1);
    }

    return [
        'bucket' => $bucket,
        'region' => typo3_vercel_env('TYPO3_S3_REGION', 'auto'),
        'endpoint' => typo3_vercel_env('TYPO3_S3_ENDPOINT', ''),
        'accessKey' => $accessKey ?? '',
        'secretKey' => $secretKey ?? '',
        'prefix' => typo3_vercel_env('TYPO3_S3_PREFIX', ''),
        'publicBaseUrl' => $publicBaseUrl ?? '',
        'signedUrlTtl' => (string)$signedUrlTtl,
        'pathStyleEndpoint' => typo3_vercel_bool_env('TYPO3_S3_PATH_STYLE_ENDPOINT', true) ? '1' : '0',
        'defaultFolder' => typo3_vercel_env('TYPO3_S3_DEFAULT_FOLDER', 'user_upload'),
        'cacheControl' => typo3_vercel_env('TYPO3_S3_CACHE_CONTROL', 'public, max-age=31536000, immutable'),
        'caseSensitive' => typo3_vercel_bool_env('TYPO3_S3_CASE_SENSITIVE', true) ? '1' : '0',
    ];
}

function typo3_vercel_blob_object_storage_configuration(): array
{
    $access = strtolower((string)typo3_vercel_env('TYPO3_BLOB_ACCESS', 'public'));
    if (!in_array($access, ['public', 'private'], true)) {
        fwrite(STDERR, "TYPO3_BLOB_ACCESS must be public or private.\n");
        exit(1);
    }

    return [
        'storeId' => typo3_vercel_env('TYPO3_BLOB_STORE_ID') ?? typo3_vercel_env('BLOB_STORE_ID', ''),
        'access' => $access,
        'tokenEnvName' => typo3_vercel_env('TYPO3_BLOB_TOKEN_ENV_NAME', 'BLOB_READ_WRITE_TOKEN'),
        'prefix' => typo3_vercel_env('TYPO3_BLOB_PREFIX', 'typo3/'),
        'publicBaseUrl' => typo3_vercel_env('TYPO3_BLOB_PUBLIC_BASE_URL', ''),
        'apiUrl' => typo3_vercel_env('TYPO3_BLOB_API_URL') ?? typo3_vercel_env('VERCEL_BLOB_API_URL', 'https://vercel.com/api/blob'),
        'defaultFolder' => typo3_vercel_env('TYPO3_BLOB_DEFAULT_FOLDER', 'user_upload'),
        'cacheControlMaxAge' => typo3_vercel_env('TYPO3_BLOB_CACHE_CONTROL_MAX_AGE', '3600'),
        'processedCacheControlMaxAge' => typo3_vercel_env('TYPO3_BLOB_PROCESSED_CACHE_CONTROL_MAX_AGE', '31536000'),
        'processingFolder' => typo3_vercel_first_env(
            ['TYPO3_OBJECT_STORAGE_PROCESSING_FOLDER', 'TYPO3_BLOB_PROCESSING_FOLDER'],
            '_processed_'
        ),
        'caseSensitive' => typo3_vercel_bool_env('TYPO3_BLOB_CASE_SENSITIVE', true) ? '1' : '0',
    ];
}

function typo3_vercel_blob_boot_token_available(array $configuration): bool
{
    $tokenEnvName = (string)($configuration['tokenEnvName'] ?? 'BLOB_READ_WRITE_TOKEN');
    foreach (array_unique([$tokenEnvName, 'BLOB_READ_WRITE_TOKEN', 'VERCEL_OIDC_TOKEN']) as $envName) {
        $value = getenv($envName);
        if (is_string($value) && trim($value) !== '') {
            return true;
        }
    }

    return false;
}

/**
 * Point every local-driver storage's processed-files folder at the durable
 * object storage, and purge the stale sys_file_processedfile rows of a
 * storage whose folder actually changed. Without the purge, rows recorded
 * for the previous (per-instance, ephemeral) local folder keep their old
 * storage reference and fail regeneration against the new processing target.
 */
function typo3_vercel_apply_local_storage_processing_folder(PDO $pdo, int $objectStorageUid, ?string $target): bool
{
    if ($target === null) {
        return false;
    }

    try {
        $columns = typo3_vercel_table_columns($pdo, 'sys_file_storage');
        $where = "driver = 'Local' AND uid <> :uid";
        if (in_array('deleted', $columns, true)) {
            $where .= ' AND deleted = 0';
        }

        $statement = $pdo->prepare('SELECT uid, processingfolder FROM sys_file_storage WHERE ' . $where);
        $statement->execute(['uid' => $objectStorageUid]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        if ($rows === []) {
            fwrite(STDOUT, "No local sys_file_storage rows yet; the durable processing folder is applied after TYPO3 setup.\n");
            return false;
        }

        $changed = false;
        $update = $pdo->prepare(
            'UPDATE sys_file_storage SET processingfolder = :processingfolder, tstamp = :tstamp WHERE uid = :uid'
        );
        foreach ($rows as $row) {
            $localUid = (int)$row['uid'];
            if ((string)($row['processingfolder'] ?? '') === $target) {
                continue;
            }

            $update->execute(['processingfolder' => $target, 'tstamp' => time(), 'uid' => $localUid]);
            typo3_vercel_purge_stale_processed_files($pdo, $localUid);
            $changed = true;
            fwrite(STDOUT, sprintf(
                "Local storage uid %d processing folder now %s.\n",
                $localUid,
                $target === '' ? 'the TYPO3 local default' : $target
            ));
        }

        return $changed;
    } catch (PDOException $exception) {
        fwrite(STDERR, sprintf("Applying the local storage processing folder failed: %s\n", $exception->getMessage()));
        exit(1);
    }
}

function typo3_vercel_purge_stale_processed_files(PDO $pdo, int $localStorageUid): void
{
    try {
        // Rows carry the storage of the PROCESSING target, not of the original
        // file, so a processing-folder switch leaves rows on either side.
        // Purge by the original file's storage to catch both.
        $statement = $pdo->prepare(
            'DELETE FROM sys_file_processedfile
              WHERE storage = :storage
                 OR original IN (SELECT uid FROM sys_file WHERE storage = :originalStorage)'
        );
        $statement->execute(['storage' => $localStorageUid, 'originalStorage' => $localStorageUid]);
        $purged = $statement->rowCount();
        if ($purged > 0) {
            fwrite(STDOUT, sprintf(
                "Purged %d stale processed-file records of storage uid %d; derivatives regenerate on the new processing target.\n",
                $purged,
                $localStorageUid
            ));
        }
    } catch (PDOException $exception) {
        // A database without the table yet (fresh setup order) is fine.
        $message = strtolower($exception->getMessage());
        if (str_contains($message, 'no such table') || str_contains($message, 'does not exist') || str_contains($message, "doesn't exist")) {
            return;
        }
        throw $exception;
    }
}

function typo3_vercel_verify_object_storage(string $driverName, array $configuration, int $storageUid, string $processingFolder, ?string $localProcessingTarget = null): void
{
    try {
        $driverClass = match ($driverName) {
            'vercel_blob' => \Webconsulting\Typo3VercelBlobStorage\Resource\Driver\BlobDriver::class,
            'vercel_s3' => \Webconsulting\Typo3VercelStorage\Resource\Driver\S3Driver::class,
            default => throw new RuntimeException('Unsupported object storage driver: ' . $driverName),
        };
        $driver = new $driverClass($configuration);
        $driver->setStorageUid($storageUid);
        $driver->processConfiguration();
        $driver->initialize();
        $driver->getDefaultFolder();

        foreach (typo3_vercel_required_object_storage_folders($configuration, $processingFolder, $storageUid, $localProcessingTarget) as $folderIdentifier) {
            if (!$driver->folderExists($folderIdentifier)) {
                $driver->createFolder(trim($folderIdentifier, '/'), '/', true);
            }
        }
    } catch (Throwable $exception) {
        fwrite(STDERR, sprintf("TYPO3 object storage verification failed: %s\n", $exception->getMessage()));
        exit(1);
    }

    fwrite(STDOUT, "TYPO3 object storage verified and required folders exist.\n");
}

function typo3_vercel_first_env(array $names, string $default): string
{
    foreach ($names as $name) {
        $value = typo3_vercel_env($name);
        if ($value !== null) {
            return $value;
        }
    }
    return $default;
}

function typo3_vercel_required_object_storage_folders(
    array $configuration,
    string $processingFolder,
    int $storageUid = 0,
    ?string $localProcessingTarget = null,
): array {
    $folders = [
        '/' . trim((string)($configuration['defaultFolder'] ?? 'user_upload'), '/') . '/',
        '/_temp_/',
    ];

    if (!str_contains($processingFolder, ':')) {
        $folders[] = '/' . trim($processingFolder, '/') . '/';
    }

    $localFolder = typo3_vercel_combined_folder_on_storage($localProcessingTarget, $storageUid);
    if ($localFolder !== null) {
        $folders[] = $localFolder;
    }

    return array_values(array_unique(array_filter(
        $folders,
        static fn (string $folderIdentifier): bool => $folderIdentifier !== '//'
    )));
}

function typo3_vercel_object_storage_pdo(array $database): PDO
{
    $retries = (int)typo3_vercel_env('TYPO3_DB_CONNECT_RETRIES', '20');
    $delay = (int)typo3_vercel_env('TYPO3_DB_CONNECT_RETRY_DELAY', '2');

    for ($attempt = 1; $attempt <= $retries; $attempt++) {
        try {
            return new PDO(
                typo3_vercel_pdo_dsn($database),
                (string)($database['user'] ?? null),
                (string)($database['password'] ?? null),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
        } catch (PDOException $exception) {
            if ($attempt === $retries) {
                fwrite(STDERR, sprintf("Database connection failed while applying object storage: %s\n", $exception->getMessage()));
                exit(1);
            }
            sleep($delay);
        }
    }

    throw new RuntimeException('Unreachable database retry state.');
}

function typo3_vercel_object_storage_table_exists(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT 1 FROM sys_file_storage LIMIT 1');
        return true;
    } catch (PDOException $exception) {
        $message = $exception->getMessage();
        if (str_contains($message, 'no such table') || str_contains($message, 'does not exist') || str_contains($message, "doesn't exist")) {
            return false;
        }
        throw $exception;
    }
}

function typo3_vercel_table_columns(PDO $pdo, string $table): array
{
    $statement = $pdo->query('SELECT * FROM ' . $table . ' WHERE 1 = 0');
    if ($statement === false) {
        return [];
    }

    $columns = [];
    for ($i = 0; $i < $statement->columnCount(); $i++) {
        $meta = $statement->getColumnMeta($i);
        if (isset($meta['name'])) {
            $columns[] = $meta['name'];
        }
    }
    return $columns;
}

function typo3_vercel_storage_exists(PDO $pdo, int $uid): bool
{
    $statement = $pdo->prepare('SELECT uid FROM sys_file_storage WHERE uid = :uid');
    $statement->execute(['uid' => $uid]);
    return $statement->fetchColumn() !== false;
}

/**
 * @param array<int, string> $columns
 * @return array<string, mixed>|null
 */
function typo3_vercel_existing_storage_row(PDO $pdo, int $uid, array $columns): ?array
{
    $safeColumns = array_values(array_filter(
        $columns,
        static fn (string $column): bool => preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $column) === 1
    ));
    if ($safeColumns === []) {
        return null;
    }

    $statement = $pdo->prepare('SELECT ' . implode(', ', $safeColumns) . ' FROM sys_file_storage WHERE uid = :uid');
    $statement->execute(['uid' => $uid]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

/**
 * @param array<string, mixed> $existing
 * @param array<string, mixed> $expected
 */
function typo3_vercel_storage_row_matches(array $existing, array $expected): bool
{
    foreach ($expected as $column => $value) {
        if (!array_key_exists($column, $existing)) {
            return false;
        }
        if ((string)$existing[$column] !== (string)$value) {
            return false;
        }
    }
    return true;
}

/**
 * @param array<string, mixed> $values
 */
function typo3_vercel_update_storage_row(PDO $pdo, array $values, int $uid): void
{
    $updateValues = $values;
    unset($updateValues['uid'], $updateValues['pid'], $updateValues['crdate']);
    $assignments = array_map(static fn (string $column): string => $column . ' = :' . $column, array_keys($updateValues));
    $statement = $pdo->prepare('UPDATE sys_file_storage SET ' . implode(', ', $assignments) . ' WHERE uid = :uid');
    $statement->execute($updateValues + ['uid' => $uid]);
}

function typo3_vercel_is_duplicate_key_error(PDOException $exception): bool
{
    if (in_array((string)$exception->getCode(), ['23000', '23505'], true)) {
        return true;
    }
    $message = strtolower($exception->getMessage());
    return str_contains($message, 'duplicate') || str_contains($message, 'unique constraint');
}

function typo3_vercel_flexform_xml(array $configuration): string
{
    $fields = '';
    foreach ($configuration as $key => $value) {
        $fields .= sprintf(
            '<field index="%s"><value index="vDEF">%s</value></field>',
            typo3_vercel_xml($key),
            typo3_vercel_xml((string)$value),
        );
    }

    return '<?xml version="1.0" encoding="utf-8" standalone="yes" ?>'
        . '<T3FlexForms><data><sheet index="sDEF"><language index="lDEF">'
        . $fields
        . '</language></sheet></data></T3FlexForms>';
}

function typo3_vercel_xml(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
}
