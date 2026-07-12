<?php

declare(strict_types=1);

namespace Webconsulting\Typo3Vercel\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

#[CoversNothing]
final class CaminoAccessibilityTemplateTest extends TestCase
{
    private const TEMPLATE_ROOT = '/packages/typo3-camino-demo/Resources/Private/Templates/';

    public function testStageAndPageContentAreInsideMainLandmark(): void
    {
        $layout = $this->template('Layouts/Pages/Default.fluid.html');

        self::assertSame(1, substr_count($layout, '<main'));
        self::assertStringContainsString('<main id="main-content">', $layout);
        self::assertLessThan(strpos($layout, '</main>'), strpos($layout, 'content.stage'));
        self::assertLessThan(strpos($layout, '</main>'), strpos($layout, 'content.content'));
    }

    public function testAccessibilityTemplatesOverrideCaminoAtRuntime(): void
    {
        $root = dirname(__DIR__, 2);
        $site = Yaml::parseFile($root . '/config/sites/camino/config.yaml');
        $themePosition = array_search('typo3/theme-camino', $site['dependencies'], true);
        $demoPosition = array_search('webconsulting/typo3-camino-demo', $site['dependencies'], true);
        $setup = file_get_contents(
            $root . '/packages/typo3-camino-demo/Configuration/Sets/CaminoDemo/setup.typoscript',
        );

        self::assertIsInt($themePosition);
        self::assertIsInt($demoPosition);
        self::assertGreaterThan($themePosition, $demoPosition);
        self::assertIsString($setup);
        self::assertStringContainsString('page.10.paths.20 = EXT:typo3_camino_demo/', $setup);
    }

    public function testLogoLinksHaveAccessibleNames(): void
    {
        foreach (['Header.fluid.html', 'Footer.fluid.html'] as $partial) {
            $template = $this->template('Partials/Pages/' . $partial);

            self::assertMatchesRegularExpression(
                '/<f:link\.typolink[^>]+class="(?:header|footer)__logo"[^>]+additionalAttributes="\{\'aria-label\': breadcrumb\.0\.title\}"/s',
                $template,
            );
        }
    }

    private function template(string $relativePath): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . self::TEMPLATE_ROOT . $relativePath);
        self::assertIsString($contents);
        return $contents;
    }
}
