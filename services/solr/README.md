# Experimental Vercel Solr Service

This directory contains the internal Solr service used by the demo Vercel
deployment.

It uses:

- `typo3solr/ext-solr:14.0.0-beta3`
- Apache Solr 10.0.0
- EXT:solr configset `ext_solr_14_0_0`
- enabled cores: `core_en` and `core_de`

The service is wired through Vercel Services and a private service binding. It
is not exposed through a public rewrite. TYPO3 receives the generated internal
URL as:

```text
TYPO3_SOLR_SERVICE_URL
```

The TYPO3 app uses that binding only when Solr is explicitly enabled with:

```dotenv
TYPO3_SOLR_ENABLED=1
```

This is demo infrastructure only. The Solr index lives inside Vercel runtime
storage and is not a durable production index. Use an external managed Solr
endpoint for production.
