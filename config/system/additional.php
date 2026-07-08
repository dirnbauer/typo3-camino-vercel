<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/scripts/typo3-env.php';

$GLOBALS['TYPO3_CONF_VARS'] = array_replace_recursive(
    $GLOBALS['TYPO3_CONF_VARS'] ?? [],
    typo3_vercel_settings(),
);
