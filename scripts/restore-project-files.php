<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$checkOnly = in_array('--check', $argv, true);
$projectFiles = [
    'Build/ProjectFiles/config/system/settings.php' => 'config/system/settings.php',
    'Build/ProjectFiles/public/index.php' => 'public/index.php',
];

foreach ($projectFiles as $source => $target) {
    $sourcePath = $root . '/' . $source;
    $targetPath = $root . '/' . $target;

    if (!is_file($sourcePath)) {
        fwrite(STDERR, sprintf("Immutable project file is missing: %s\n", $source));
        exit(1);
    }

    if (is_file($targetPath) && hash_file('sha256', $sourcePath) === hash_file('sha256', $targetPath)) {
        continue;
    }

    if ($checkOnly) {
        fwrite(STDERR, sprintf("Generated project file is out of sync: %s\n", $target));
        exit(1);
    }

    if (!is_dir(dirname($targetPath)) && !mkdir(dirname($targetPath), 0775, true) && !is_dir(dirname($targetPath))) {
        fwrite(STDERR, sprintf("Could not create project directory for: %s\n", $target));
        exit(1);
    }

    if (!copy($sourcePath, $targetPath)) {
        fwrite(STDERR, sprintf("Could not restore generated project file: %s\n", $target));
        exit(1);
    }
}
