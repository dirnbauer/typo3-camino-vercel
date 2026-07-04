<?php

declare(strict_types=1);

return [
    'frontend' => [
        'webconsulting/typo3-vercel-storage/frontend-cache-headers' => [
            'target' => \Webconsulting\Typo3VercelStorage\Middleware\VercelFrontendCacheHeaders::class,
            'after' => [
                'typo3/cms-frontend/csp-headers',
            ],
            'before' => [
                'typo3/cms-core/response-propagation',
            ],
        ],
    ],
];
