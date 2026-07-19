# Included TYPO3 Packages

This starter requires the current Composer-installable TYPO3 CMS 14.3 system
package set directly in `composer.json`, plus Camino.

It also includes `apache-solr-for-typo3/solr` at `^14.0@beta` for optional
Apache Solr search integration (`composer.lock` currently resolves it to
14.0.0-RC1, Apache Solr 10.0.0, configset `ext_solr_14_0_0`).

The added system packages are:

- `typo3/cms-extensionmanager`
- `typo3/cms-filemetadata`
- `typo3/cms-linkvalidator`
- `typo3/cms-lowlevel`
- `typo3/cms-redirects`
- `typo3/cms-reports`
- `typo3/cms-workspaces`

`typo3/cms-adminpanel` and `typo3/cms-indexed-search` are intentionally not
installed: the admin panel logs every SQL query on backend-authenticated
frontend renders (the Visual Editor path) and registers a debug-level
in-memory log writer on all requests, and indexed search duplicates the Solr
stack. Re-add either with `composer require` if you need it.

The full direct TYPO3 CMS package list is the `typo3/cms-*` block in
`composer.json`. `composer.lock` currently resolves these packages to TYPO3
`v14.3.5`.

## Legacy Package Names

Some package names from older TYPO3 releases are no longer separate packages in
TYPO3 14.3. Composer shows them as replaced by `typo3/cms-backend`, for
example:

- `typo3/cms-about`
- `typo3/cms-context-help`
- `typo3/cms-cshmanual`
- `typo3/cms-recordlist`
- `typo3/cms-setup`
- `typo3/cms-t3editor`
- `typo3/cms-wizard-crpages`
- `typo3/cms-wizard-sortpages`

Do not add those old names as separate requirements. They are already satisfied
by the current TYPO3 backend package.

## Styleguide

`typo3/cms-styleguide` is intentionally not installed in this public Vercel
template. It is a backend showcase/development extension, not a production
system extension, and it adds backend surface area that is not needed for this
Camino demo. Add it only in a local development branch if you specifically need
TYPO3 backend UI examples.
