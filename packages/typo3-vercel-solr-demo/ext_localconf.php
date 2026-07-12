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

# Register EXT:solr's native suggest endpoint. Its frontend controller uses
# fetch, AbortController, and autoComplete.js; no jQuery is loaded.
tx_solr_suggest = PAGE
tx_solr_suggest {
  typeNum = 7384
  config {
    disableAllHeaderCode = 1
    xhtml_cleaning = 0
    admPanel = 0
    additionalHeaders.10.header = Content-type: application/json
    no_cache = 0
    debug = 0
  }

  10 = USER_INT
  10 {
    userFunc = Webconsulting\Typo3VercelSolrDemo\Content\SolrSearchContent->renderSuggest
  }
}

plugin.tx_solr.suggest = 1
plugin.tx_solr.suggest {
  numberOfSuggestions = 6
  suggestField = spell
  showTopResults = 1
  numberOfTopResults = 4
}

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

page.includeCSS.typo3VercelSolrDemoSearch = EXT:typo3_vercel_solr_demo/Resources/Public/Css/search.css

TYPOSCRIPT,
    'defaultContentRendering',
);
