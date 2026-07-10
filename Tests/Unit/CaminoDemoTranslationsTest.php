<?php

declare(strict_types=1);

namespace Webconsulting\Typo3Vercel\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

#[CoversNothing]
final class CaminoDemoTranslationsTest extends TestCase
{
    /** @return iterable<string, array{int, string}> */
    public static function languageProvider(): iterable
    {
        yield 'German' => [1, '/de/'];
        yield 'Spanish' => [2, '/es/'];
        yield 'Simplified Chinese' => [3, '/zh/'];
        yield 'Hungarian' => [4, '/hu/'];
    }

    #[DataProvider('languageProvider')]
    public function testLanguageHasCompleteStrictDemoSeed(int $languageId, string $base): void
    {
        $translations = require dirname(__DIR__, 2) . '/packages/typo3-camino-demo/Configuration/Demo/Translations.php';
        self::assertArrayHasKey($languageId, $translations);
        self::assertCount(9, $translations[$languageId]['pages']);
        self::assertCount(52, $translations[$languageId]['content']);
        self::assertCount(18, $translations[$languageId]['listItems']);
        self::assertSame(
            ['/', '/footer-navigation', '/privacy', '/imprint', '/faqs', '/packing-list', '/camino-route-comparison', '/search', '/visual-editor'],
            array_keys($translations[$languageId]['pages']),
        );
        self::assertSame(range(1, 52), array_keys($translations[$languageId]['content']));
        self::assertSame(range(1, 18), array_keys($translations[$languageId]['listItems']));
        self::assertNotSame('', $translations[$languageId]['content'][52]['bodytext']);

        $site = Yaml::parseFile(dirname(__DIR__, 2) . '/config/sites/camino/config.yaml');
        $language = array_values(array_filter(
            $site['languages'],
            static fn(array $candidate): bool => (int)$candidate['languageId'] === $languageId,
        ));
        self::assertCount(1, $language);
        self::assertSame($base, $language[0]['base']);
        self::assertSame('strict', $language[0]['fallbackType']);
        self::assertSame('', $language[0]['fallbacks']);
    }
}
