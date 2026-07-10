<?php

declare(strict_types=1);

namespace Webconsulting\Typo3CaminoDemo\Http;

final readonly class ByteRange
{
    private function __construct(
        public int $start,
        public int $end,
    ) {
    }

    public function length(): int
    {
        return $this->end - $this->start + 1;
    }

    public static function fromHeader(?string $header, int $fileSize): ?self
    {
        if ($header === null || trim($header) === '') {
            return null;
        }
        if ($fileSize < 1) {
            throw new \InvalidArgumentException('Byte ranges require a non-empty file.');
        }

        if (preg_match('/\Abytes=(\d*)-(\d*)\z/', trim($header), $matches) !== 1) {
            throw new \InvalidArgumentException('Only one byte range is supported.');
        }

        $startValue = $matches[1];
        $endValue = $matches[2];
        if ($startValue === '' && $endValue === '') {
            throw new \InvalidArgumentException('The byte range is empty.');
        }

        if ($startValue === '') {
            $suffixLength = (int)$endValue;
            if ($suffixLength < 1) {
                throw new \InvalidArgumentException('The byte suffix length must be positive.');
            }

            return new self(max(0, $fileSize - $suffixLength), $fileSize - 1);
        }

        $start = (int)$startValue;
        if ($start >= $fileSize) {
            throw new \InvalidArgumentException('The byte range starts beyond the file.');
        }

        $end = $endValue === '' ? $fileSize - 1 : min((int)$endValue, $fileSize - 1);
        if ($end < $start) {
            throw new \InvalidArgumentException('The byte range end precedes its start.');
        }

        return new self($start, $end);
    }
}
