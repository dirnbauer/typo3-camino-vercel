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
TYPO3_SOLR_APPLY_SITE_SET=1
TYPO3_SOLR_SITE_SET=webconsulting/typo3-vercel-solr-demo
```

The app config writer maps the service root to `solr_path_read: /` and
`solr_core_read: core_en`. Do not configure `solr_path_read: /solr/`; EXT:solr
would then request `/solr/solr/core_en/`.

For Camino, keep the default local Solr site set. The official EXT:solr site set
depends on Fluid Styled Content and conflicts with Camino's custom content
rendering in this starter.

This is demo infrastructure only. The Solr index lives inside Vercel runtime
storage and is not a durable production index. A fresh Vercel Solr service
instance can start with an empty `/tmp` index, and runtime writes from the TYPO3
app can hit a different service instance than later search requests.

To keep the public demo usable, `start-vercel-solr.sh` seeds the static Camino
demo documents into `core_en` on every Solr service startup. That makes demo
search repeatable without pretending that this is durable production indexing.
The script starts a tiny readiness proxy on the Vercel service port immediately,
then starts Solr in the same container. The proxy waits until `core_en` is
reachable and the demo documents are seeded before it forwards Solr requests.
That avoids TYPO3's backend Solr module hitting a half-started service and
showing a false connection error on the first cold request. The tradeoff is that
the first Solr-touching request waits for the Vercel Solr container to finish
cold start.

In live testing, protected Solr probes could see the six seeded documents, but
first Solr-touching requests after scale-to-zero can still take several
seconds. This is acceptable only for the experimental demo service.

Create/update the TYPO3 `/search` page with the protected TYPO3 endpoint after
deploy, not during container boot:

```bash
curl -fsS \
  -H "Authorization: Bearer $CRON_SECRET" \
  "https://your-project.vercel.app/api/cron/typo3-solr-demo.php?limit=50"
```

The endpoint skips runtime indexing by default for this internal service. Set
`TYPO3_SOLR_INDEX_ON_SETUP=1` only when deliberately testing bounded runtime
indexing. Reliable demo data comes from the Solr service startup seed. Use an
external managed Solr 10 endpoint plus an external worker/scheduler for
production indexing.
