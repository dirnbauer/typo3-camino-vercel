<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') or die('Access denied.');

ExtensionManagementUtility::addTcaSelectItem(
    'tt_content',
    'CType',
    [
        'label' => 'Vercel Solr Demo Results',
        'value' => 'vercel_solr_demo_results',
        'icon' => 'content-plugin',
        'group' => 'plugins',
    ],
);

$GLOBALS['TCA']['tt_content']['ctrl']['typeicon_classes']['vercel_solr_demo_results'] = 'content-plugin';
$GLOBALS['TCA']['tt_content']['types']['vercel_solr_demo_results'] = [
    'showitem' => '
            --palette--;;general,
            --palette--;;headers,
            pi_flexform,
        --div--;core.form.tabs:appearance,
            --palette--;;frames,
            --palette--;;appearanceLinks,
        --div--;core.form.tabs:access,
            --palette--;;hidden,
            --palette--;;access,
        --div--;core.form.tabs:categories,
            categories,
        --div--;core.form.tabs:extended,
    ',
];
