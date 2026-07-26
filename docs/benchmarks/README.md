# Platform benchmark results

Run the comparison from a single client and store the raw result here:

```bash
php scripts/benchmark-platforms.php \
  --runs=20 \
  --output=docs/benchmarks/2026-07-26.json \
  Vercel=https://typo3-camino-vercel.vercel.app \
  Railway=https://example.up.railway.app \
  Sliplane=https://example.sliplane.app \
  Coolify=https://example.example.com
```

The JSON contains every sample, response status, timing phase, remote address,
and the exact Git revision. The command also prints a compact Markdown table.

Run all targets in the same invocation so network location, time, and benchmark
method remain comparable. “First observed” is not a guaranteed cold start; it
is the first request the client observed. The uncached-origin series requests
`/` with a cookie and `Cache-Control: no-cache` to bypass public edge caching.
