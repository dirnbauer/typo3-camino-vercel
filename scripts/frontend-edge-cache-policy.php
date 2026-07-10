#!/usr/bin/env php
<?php

declare(strict_types=1);

use Webconsulting\Typo3VercelStorage\Cache\FrontendEdgeCachePolicy;

require dirname(__DIR__) . '/vendor/autoload.php';

echo (new FrontendEdgeCachePolicy())->ttl() > 0 ? "1\n" : "0\n";
