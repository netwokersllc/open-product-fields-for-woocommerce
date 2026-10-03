<?php

declare (strict_types=1);
namespace OPF\Vendor\Rowbot\URL\String;

use function rawurlencode;
use function strpbrk;
/**
 * A helper class for working with UTF-8 code points.
 *
 * @see https://infra.spec.whatwg.org/#code-points
 */
final class CodePoint
{
    public const C0_CONTROL_PERCENT_ENCODE_SET = 1;
    public const FRAGMENT_PERCENT_ENCODE_SET = 2;
    public const PATH_PERCENT_ENCODE_SET = 3;
    public const USERINFO_PERCENT_ENCODE_SET = 4;
    public const ASCII_ALPHA_MASK = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
    public const ASCII_ALNUM_MASK = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    public const ASCII_DIGIT_MASK = '0123456789';
    public const OCTAL_DIGIT_MASK = '01234567';
    public const HEX_DIGIT_MASK = 'ABCDEFabcdef0123456789';
    public const EOF = '';
    /**
     * @codeCoverageIgnore
     */
    private function __construct()
    {
    }
    /**
     * @see https://url.spec.whatwg.org/#url-code-points
     */
    public static function isUrlCodePoint(string $codePoint): bool
    {
        return (strpbrk($codePoint, self::ASCII_ALNUM_MASK) === $codePoint || $codePoint === '!' || $codePoint === '$' || $codePoint >= '&' && $codePoint <= '/' || $codePoint === ':' || $codePoint === ';' || $codePoint === '=' || $codePoint === '?' || $codePoint === '@' || $codePoint === '_' || $codePoint === '~' || $codePoint >= "\xa0" && $codePoint <= "􏿽") && ($codePoint < "���" || $codePoint > "���") && ($codePoint < "﷐" || $codePoint > "﷯") && $codePoint !== "￾" && $codePoint !== "￿" && $codePoint !== "🿾" && $codePoint !== "🿿" && $codePoint !== "𯿾" && $codePoint !== "𯿿" && $codePoint !== "𿿾" && $codePoint !== "𿿿" && $codePoint !== "񏿾" && $codePoint !== "񏿿" && $codePoint !== "񟿾" && $codePoint !== "񟿿" && $codePoint !== "񯿾" && $codePoint !== "񯿿" && $codePoint !== "񿿾" && $codePoint !== "񿿿" && $codePoint !== "򏿾" && $codePoint !== "򏿿" && $codePoint !== "򟿾" && $codePoint !== "򟿿" && $codePoint !== "򯿾" && $codePoint !== "򯿿" && $codePoint !== "򿿾" && $codePoint !== "򿿿" && $codePoint !== "󏿾" && $codePoint !== "󏿿" && $codePoint !== "󟿾" && $codePoint !== "󟿿" && $codePoint !== "󯿾" && $codePoint !== "󯿿" && $codePoint !== "󿿾" && $codePoint !== "󿿿" && $codePoint !== "􏿾" && $codePoint !== "􏿿";
    }
    /**
     * Encodes a code point if the code point is not part of the specified encode set.
     *
     * @see https://url.spec.whatwg.org/#utf-8-percent-encode
     *
     * @param string $codePoint        A code point to be encoded.
     * @param int    $percentEncodeSet The encode set used to decide whether or not the code point should be percent
     *                                 encoded.
     */
    public static function utf8PercentEncode(string $codePoint, int $percentEncodeSet = self::C0_CONTROL_PERCENT_ENCODE_SET): string
    {
        $result = \false;
        switch ($percentEncodeSet) {
            case self::USERINFO_PERCENT_ENCODE_SET:
                $result = $codePoint === '/' || $codePoint === ':' || $codePoint === ';' || $codePoint === '=' || $codePoint === '@' || $codePoint === '[' || $codePoint === '\\' || $codePoint === ']' || $codePoint === '^' || $codePoint === '|';
                if ($result) {
                    break;
                }
            // No break.
            case self::PATH_PERCENT_ENCODE_SET:
                $result = $codePoint === '#' || $codePoint === '?' || $codePoint === '{' || $codePoint === '}';
                if ($result) {
                    break;
                }
            // No break.
            case self::FRAGMENT_PERCENT_ENCODE_SET:
                $result = $codePoint === ' ' || $codePoint === '"' || $codePoint === '<' || $codePoint === '>' || $codePoint === '`';
                if ($result) {
                    break;
                }
            // No break.
            case self::C0_CONTROL_PERCENT_ENCODE_SET:
                $result = $codePoint >= "\x00" && $codePoint <= "\x1f" || $codePoint >= "~";
                break;
        }
        if (!$result) {
            return $codePoint;
        }
        return rawurlencode($codePoint);
    }
}
