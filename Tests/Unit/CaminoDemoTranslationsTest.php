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
    private const ALLOWED_BODYTEXT_TAGS = ['a', 'br', 'em', 'i', 'li', 'p', 'strong', 'ul'];

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

    public function testTranslatedBodytextHasValidMarkupAndListItemIds(): void
    {
        $translations = require dirname(__DIR__, 2) . '/packages/typo3-camino-demo/Configuration/Demo/Translations.php';

        foreach ($translations as $languageId => $translation) {
            foreach ($translation['content'] as $contentId => $record) {
                $bodytext = $record['bodytext'] ?? '';
                if ($bodytext === '') {
                    continue;
                }

                $context = sprintf('language %d, content %d', $languageId, $contentId);
                preg_match_all('~</?([^\s>/]+)~u', $bodytext, $tagMatches);
                $tags = array_unique(array_map('strtolower', $tagMatches[1]));
                self::assertSame(
                    [],
                    array_values(array_diff($tags, self::ALLOWED_BODYTEXT_TAGS)),
                    sprintf('Unsupported HTML tag in %s.', $context),
                );

                foreach (['a', 'em', 'i', 'li', 'p', 'strong', 'ul'] as $tag) {
                    self::assertSame(
                        preg_match_all(sprintf('~<%s(?:\s|>)~i', $tag), $bodytext),
                        substr_count(strtolower($bodytext), sprintf('</%s>', $tag)),
                        sprintf('Unbalanced <%s> markup in %s.', $tag, $context),
                    );
                }

                preg_match_all('~<li\b([^>]*)>~iu', $bodytext, $listItemTags);
                $listItemIds = [];
                foreach ($listItemTags[1] as $attributes) {
                    preg_match_all('~\bdata-list-item-id\s*=\s*"([^"]*)"~u', $attributes, $attributeMatches);
                    self::assertCount(
                        1,
                        $attributeMatches[1],
                        sprintf('Every list item needs exactly one identifier in %s.', $context),
                    );
                    $listItemIds[] = $attributeMatches[1][0];
                }

                self::assertSame(
                    substr_count($bodytext, 'data-list-item-id='),
                    count($listItemIds),
                    sprintf('List item identifier outside an opening <li> tag in %s.', $context),
                );
                self::assertSame(
                    preg_match_all('~<li(?:\s|>)~i', $bodytext),
                    count($listItemIds),
                    sprintf('Every list item needs an identifier in %s.', $context),
                );

                foreach ($listItemIds as $listItemId) {
                    self::assertMatchesRegularExpression(
                        '~^e[0-9a-f]{32}$~',
                        $listItemId,
                        sprintf('Invalid list item identifier in %s.', $context),
                    );
                }
            }
        }
    }

    public function testHungarianHomeHeadingIsLocalized(): void
    {
        $translations = require dirname(__DIR__, 2) . '/packages/typo3-camino-demo/Configuration/Demo/Translations.php';

        self::assertSame('Miért járjuk végig a Caminót?', $translations[4]['content'][44]['header']);
    }

    public function testCaminoFrancesSourceCorrectionReplacesPortugueseRouteFacts(): void
    {
        $corrections = require dirname(__DIR__, 2) . '/packages/typo3-camino-demo/Configuration/Demo/SourceCorrections.php';
        $translations = require dirname(__DIR__, 2) . '/packages/typo3-camino-demo/Configuration/Demo/Translations.php';

        self::assertStringContainsString('approximately 780 km', $corrections[7]['bodytext']);
        self::assertStringContainsString('First-time pilgrims', $corrections[7]['bodytext']);
        self::assertStringNotContainsString('Porto', $corrections[7]['bodytext']);
        self::assertStringNotContainsString('Lisbon', $corrections[7]['bodytext']);

        foreach ($translations as $languageId => $translation) {
            self::assertStringContainsString(
                '780',
                $translation['content'][7]['bodytext'],
                sprintf('Language %d must contain the corrected French Way distance.', $languageId),
            );
        }
    }
}
