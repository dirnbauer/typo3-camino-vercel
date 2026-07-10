<?php

declare(strict_types=1);

namespace Webconsulting\Typo3Vercel\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webconsulting\Typo3CaminoDemo\Http\ByteRange;

final class ByteRangeTest extends TestCase
{
    public function testReturnsNullWithoutRangeHeader(): void
    {
        self::assertNull(ByteRange::fromHeader(null, 1000));
    }

    public function testParsesBoundedAndOpenRanges(): void
    {
        $bounded = ByteRange::fromHeader('bytes=100-199', 1000);
        $open = ByteRange::fromHeader('bytes=900-', 1000);

        self::assertSame(100, $bounded?->start);
        self::assertSame(199, $bounded?->end);
        self::assertSame(100, $bounded?->length());
        self::assertSame(900, $open?->start);
        self::assertSame(999, $open?->end);
    }

    public function testParsesSuffixAndClampsEnd(): void
    {
        $suffix = ByteRange::fromHeader('bytes=-250', 1000);
        $clamped = ByteRange::fromHeader('bytes=950-5000', 1000);

        self::assertSame(750, $suffix?->start);
        self::assertSame(999, $suffix?->end);
        self::assertSame(950, $clamped?->start);
        self::assertSame(999, $clamped?->end);
    }

    #[DataProvider('invalidRangeProvider')]
    public function testRejectsInvalidRanges(string $header): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ByteRange::fromHeader($header, 1000);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidRangeProvider(): iterable
    {
        yield 'multiple ranges' => ['bytes=0-10,20-30'];
        yield 'empty range' => ['bytes=-'];
        yield 'zero suffix' => ['bytes=-0'];
        yield 'start after end' => ['bytes=200-100'];
        yield 'start outside file' => ['bytes=1000-'];
        yield 'wrong unit' => ['items=0-10'];
    }
}
