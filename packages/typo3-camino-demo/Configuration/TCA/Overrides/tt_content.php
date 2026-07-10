<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') or die('Access denied.');

$label = 'LLL:EXT:typo3_camino_demo/Resources/Private/Language/locallang_db.xlf:tt_content.CType.visualEditorDemo';

ExtensionManagementUtility::addTcaSelectItem(
    'tt_content',
    'CType',
    [
        'label' => $label,
        'value' => 'typo3_camino_visual_editor_demo',
        'icon' => 'module-page-edit',
        'group' => 'special',
    ],
);

$GLOBALS['TCA']['tt_content']['ctrl']['typeicon_classes']['typo3_camino_visual_editor_demo'] = 'module-page-edit';
$GLOBALS['TCA']['tt_content']['types']['typo3_camino_visual_editor_demo'] = [
    'showitem' => '
        --palette--;;general,
        --palette--;;headers,
        bodytext,
        --div--;core.form.tabs:appearance,
            --palette--;;frames,
        --div--;core.form.tabs:access,
            --palette--;;hidden,
            --palette--;;access,
        --div--;core.form.tabs:extended,
    ',
    'columnsOverrides' => [
        'bodytext' => [
            'config' => [
                'enableRichtext' => true,
            ],
        ],
    ],
];
