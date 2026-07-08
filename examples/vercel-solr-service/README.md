# Experimental Vercel Solr Container

This is a reference only. It is intentionally not wired into the main
`vercel.json`.

Use this only to understand how a Solr HTTP container could start on Vercel.
Do not use it as production TYPO3 search without first solving durable
`/var/solr` storage, private access, backups, monitoring, and upgrade handling.

For production, use external managed Solr and configure TYPO3 with
`TYPO3_SOLR_URL`.
