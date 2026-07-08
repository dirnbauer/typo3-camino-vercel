<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') or die('Access denied.');

ExtensionManagementUtility::addTypoScript(
    'Typo3VercelSolrDemo',
    'setup',
    <<<'TYPOSCRIPT'
# Render the demo result content element without Fluid Styled Content.
#
# The stock EXT:solr result plugin is correct for normal TYPO3 hosting, but on
# the internal Vercel demo Solr service a cold service instance can surface as a
# frontend exception. This small Camino demo renderer still queries Solr, but it
# catches service warmup and renders a controlled page.
tt_content.vercel_solr_demo_results = USER_INT
tt_content.vercel_solr_demo_results {
  userFunc = Webconsulting\Typo3VercelSolrDemo\Content\SolrSearchContent->render
}

tt_content.solr_pi_results < tt_content.vercel_solr_demo_results

tt_content.solr_pi_search = EXTBASEPLUGIN
tt_content.solr_pi_search {
  extensionName = Solr
  pluginName = pi_search
}

tt_content.solr_pi_frequentlysearched = EXTBASEPLUGIN
tt_content.solr_pi_frequentlysearched {
  extensionName = Solr
  pluginName = pi_frequentlySearched
}
TYPOSCRIPT,
    'defaultContentRendering',
);
