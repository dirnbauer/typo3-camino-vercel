<?php

declare(strict_types=1);

/*
 * Lightweight keep-warm endpoint for Vercel Cron.
 *
 * It intentionally does NOT boot TYPO3: the goal is to keep a container instance
 * (and its warm opcache and PHP workers) alive between requests so real traffic
 * avoids a cold start. Point a Vercel Cron job at /_vercel_keepalive.php on a
 * short interval. The response is never cached.
 */

http_response_code(200);
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store, private, max-age=0');

echo "ok\n";
