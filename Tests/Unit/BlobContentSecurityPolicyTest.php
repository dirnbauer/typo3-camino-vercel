<?php

declare(strict_types=1);

namespace Webconsulting\Typo3Vercel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Directive;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\MutationCollection;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Scope;

final class BlobContentSecurityPolicyTest extends TestCase
{
    public function testBackendAllowsImagesFromPublicVercelBlobStorage(): void
    {
        $policyMap = require dirname(__DIR__, 2)
            . '/packages/typo3-vercel-blob-storage/Configuration/ContentSecurityPolicies.php';
        $mutations = $policyMap[Scope::backend()];

        self::assertInstanceOf(MutationCollection::class, $mutations);

        $imageSources = [];
        foreach ($mutations->mutations as $mutation) {
            if ($mutation->directive !== Directive::ImgSrc) {
                continue;
            }
            foreach ($mutation->sources as $source) {
                $imageSources[] = (string)$source;
            }
        }

        self::assertContains('https://*.blob.vercel-storage.com', $imageSources);
    }
}
