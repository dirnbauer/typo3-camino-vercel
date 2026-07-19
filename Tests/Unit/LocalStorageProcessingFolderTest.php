<?php

declare(strict_types=1);

namespace Webconsulting\Typo3Vercel\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class LocalStorageProcessingFolderTest extends TestCase
{
    private const ENV_NAME = 'TYPO3_LOCAL_STORAGE_PROCESSING_FOLDER';

    private string|false $originalValue = false;

    protected function setUp(): void
    {
        $this->originalValue = getenv(self::ENV_NAME);
        putenv(self::ENV_NAME);
        unset($_ENV[self::ENV_NAME]);
    }

    protected function tearDown(): void
    {
        if ($this->originalValue === false) {
            putenv(self::ENV_NAME);
            unset($_ENV[self::ENV_NAME]);
        } else {
            putenv(self::ENV_NAME . '=' . $this->originalValue);
            $_ENV[self::ENV_NAME] = $this->originalValue;
        }
    }

    public function testDefaultsToCombinedIdentifierOnTheObjectStorage(): void
    {
        self::assertSame('2:/_processed_local_/', \typo3_vercel_local_storage_processing_target(2));
        self::assertSame('7:/_processed_local_/', \typo3_vercel_local_storage_processing_target(7));
    }

    public function testLocalKeywordRevertsToTypo3LocalDefault(): void
    {
        $this->setEnv('local');
        self::assertSame('', \typo3_vercel_local_storage_processing_target(2));

        $this->setEnv('LOCAL');
        self::assertSame('', \typo3_vercel_local_storage_processing_target(2));
    }

    public function testUnmanagedKeywordDisablesRowManagement(): void
    {
        $this->setEnv('unmanaged');
        self::assertNull(\typo3_vercel_local_storage_processing_target(2));

        $this->setEnv('Unmanaged');
        self::assertNull(\typo3_vercel_local_storage_processing_target(2));
    }

    public function testExplicitValuesPassThroughVerbatim(): void
    {
        $this->setEnv('2:/custom/');
        self::assertSame('2:/custom/', \typo3_vercel_local_storage_processing_target(2));

        $this->setEnv('_processed_');
        self::assertSame('_processed_', \typo3_vercel_local_storage_processing_target(2));
    }

    public function testCombinedFolderParserMatchesOnlyTheGivenStorage(): void
    {
        self::assertSame('/_processed_local_/', \typo3_vercel_combined_folder_on_storage('2:/_processed_local_/', 2));
        self::assertSame('/custom/', \typo3_vercel_combined_folder_on_storage('2:custom', 2));
        self::assertNull(\typo3_vercel_combined_folder_on_storage('3:/elsewhere/', 2));
        self::assertNull(\typo3_vercel_combined_folder_on_storage('_processed_', 2));
        self::assertNull(\typo3_vercel_combined_folder_on_storage('', 2));
        self::assertNull(\typo3_vercel_combined_folder_on_storage(null, 2));
        self::assertNull(\typo3_vercel_combined_folder_on_storage('2:', 2));
        self::assertNull(\typo3_vercel_combined_folder_on_storage('2://', 2));
    }

    private function setEnv(string $value): void
    {
        putenv(self::ENV_NAME . '=' . $value);
        $_ENV[self::ENV_NAME] = $value;
    }
}
