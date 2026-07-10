<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') or die('Access denied.');

ExtensionManagementUtility::addTypoScript(
    'Typo3CaminoDemo',
    'setup',
    <<<'TYPOSCRIPT'
tt_content.typo3_camino_visual_editor_demo = FLUIDTEMPLATE
tt_content.typo3_camino_visual_editor_demo {
  templateRootPaths.10 = EXT:typo3_camino_demo/Resources/Private/Templates/
  templateName = Content/VisualEditorDemo

  dataProcessing.10 = record-transformation
}

page.includeCSS.typo3CaminoVisualEditorDemo = EXT:typo3_camino_demo/Resources/Public/Css/visual-editor-demo.css
TYPOSCRIPT,
    'defaultContentRendering',
);
