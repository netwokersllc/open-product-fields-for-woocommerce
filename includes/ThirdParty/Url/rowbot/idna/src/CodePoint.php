<?php

declare (strict_types=1);
namespace OPF\Vendor\Rowbot\Idna;

use function chr;
use function ord;
use function strlen;
final class CodePoint
{
    /**
     * Takes a Unicode code point and encodes it. The return behavior is undefined if the given
     * code point is outside the range 0..10FFFF.
     *
     * @see https://encoding.spec.whatwg.org/#utf-8-encoder
     */
    public static function encode(int $codePoint): string
    {
        if ($codePoint >= 0x0 && $codePoint <= 0x7f) {
            return chr($codePoint);
        }
        $count = 0;
        $offset = 0;
        if ($codePoint >= 0x80 && $codePoint <= 0x7ff) {
            $count = 1;
            $offset = 0xc0;
        } elseif ($codePoint >= 0x800 && $codePoint <= 0xffff) {
            $count = 2;
            $offset = 0xe0;
        } elseif ($codePoint >= 0x10000 && $codePoint <= 0x10ffff) {
            $count = 3;
            $offset = 0xf0;
        }
        $bytes = chr(($codePoint >> 6 * $count) + $offset);
        while ($count > 0) {
            $temp = $codePoint >> 6 * ($count - 1);
            $bytes .= chr(0x80 | $temp & 0x3f);
            --$count;
        }
        return $bytes;
    }
    /**
     * Takes a UTF-8 encoded string and converts it into a series of integer code points. Any
     * invalid byte sequences will be replaced by a U+FFFD replacement code point.
     *
     * @see https://encoding.spec.whatwg.org/#utf-8-decoder
     *
     * @return list<int>
     */
    public static function utf8Decode(string $input): array
    {
        $bytesSeen = 0;
        $bytesNeeded = 0;
        $lowerBoundary = 0x80;
        $upperBoundary = 0xbf;
        $codePoint = 0;
        $codePoints = [];
        $length = strlen($input);
        for ($i = 0; $i < $length; ++$i) {
            $byte = ord($input[$i]);
            if ($bytesNeeded === 0) {
                if ($byte >= 0x0 && $byte <= 0x7f) {
                    $codePoints[] = $byte;
                    continue;
                }
                if ($byte >= 0xc2 && $byte <= 0xdf) {
                    $bytesNeeded = 1;
                    $codePoint = $byte & 0x1f;
                } elseif ($byte >= 0xe0 && $byte <= 0xef) {
                    if ($byte === 0xe0) {
                        $lowerBoundary = 0xa0;
                    } elseif ($byte === 0xed) {
                        $upperBoundary = 0x9f;
                    }
                    $bytesNeeded = 2;
                    $codePoint = $byte & 0xf;
                } elseif ($byte >= 0xf0 && $byte <= 0xf4) {
                    if ($byte === 0xf0) {
                        $lowerBoundary = 0x90;
                    } elseif ($byte === 0xf4) {
                        $upperBoundary = 0x8f;
                    }
                    $bytesNeeded = 3;
                    $codePoint = $byte & 0x7;
                } else {
                    $codePoints[] = 0xfffd;
                }
                continue;
            }
            if ($byte < $lowerBoundary || $byte > $upperBoundary) {
                $codePoint = 0;
                $bytesNeeded = 0;
                $bytesSeen = 0;
                $lowerBoundary = 0x80;
                $upperBoundary = 0xbf;
                --$i;
                $codePoints[] = 0xfffd;
                continue;
            }
            $lowerBoundary = 0x80;
            $upperBoundary = 0xbf;
            $codePoint = $codePoint << 6 | $byte & 0x3f;
            if (++$bytesSeen !== $bytesNeeded) {
                continue;
            }
            $codePoints[] = $codePoint;
            $codePoint = 0;
            $bytesNeeded = 0;
            $bytesSeen = 0;
        }
        // String unexpectedly ended, so append a U+FFFD code point.
        if ($bytesNeeded !== 0) {
            $codePoints[] = 0xfffd;
        }
        return $codePoints;
    }
}
