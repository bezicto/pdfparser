<?php

/**
 * @file
 *          This file is part of the PdfParser library.
 *
 * @license LGPLv3
 *
 * @url     <https://github.com/bezicto/pdfparser>
 */

namespace Smalot\PdfParser\RawData;

use Smalot\PdfParser\PDFObject;

/**
 * Single-pass streaming lexer for PDF page/form content streams (ISO 32000-1:2008, 7.8.2).
 *
 * Outside BT ... ET text blocks, non-text graphics commands (paths, fills, colors, etc.)
 * are skipped at native PCRE-JIT speed with zero intermediate string rewriting or allocations.
 * Inside BT ... ET text blocks, PDF tokens (strings with nested parentheses, hex strings,
 * names, numbers, TJ arrays, nested dictionaries, comments, inline images) are lexed in a
 * single O(N) pass.
 *
 * @internal
 */
class ContentStreamLexer
{
    /**
     * PDF whitespace bytes: NUL, HT, LF, FF, CR, SP (ISO 32000-1, Table 1).
     */
    private const WHITESPACE = "\x00\x09\x0a\x0c\x0d\x20";

    /**
     * PDF delimiter + whitespace bytes (ISO 32000-1, Table 1 & Table 2).
     */
    private const DELIMITERS = "\x00\x09\x0a\x0c\x0d\x20()[]<>{}/%";

    /**
     * Printable ASCII + allowed whitespace bytes for fast binary-stream rejection.
     */
    private const ALLOWED_ASCII = "\x09\x0a\x0d !\"#\$%&'()*+,-./0123456789:;<=>?@ABCDEFGHIJKLMNOPQRSTUVWXYZ[\\]^_`abcdefghijklmnopqrstuvwxyz{|}~";

    /**
     * Fast-skip scanner outside BT ... ET blocks.
     * Skips comments (%...), PDF names (/...), and hex strings (<...>) in C-level PCRE-JIT,
     * stopping only on literal strings '(', dictionaries '<<', or text-relevant operators.
     */
    private const OUTSIDE_BT_PATTERN = '/%[^\r\n]*(*SKIP)(*FAIL)|\/[^\x00\x09\x0a\x0c\x0d\x20()\[\]<>{}\/%]*(*SKIP)(*FAIL)|<(?!<)[^>]*>(*SKIP)(*FAIL)|\(|<<|(?<=^|[\x00\x09\x0a\x0c\x0d\x20()\[\]<>{}\/%])(?:BT|BI|q|Q|cm|Tf|Do|BDC|BMC|EMC)(?=$|[\x00\x09\x0a\x0c\x0d\x20()\[\]<>{}\/%])/';

    /**
     * Detect whether a stream appears to be raw binary data rather than a PDF content stream.
     * Inspects only bytes before the first '(' literal string or 'ID' inline image data marker.
     */
    public static function isBinaryContent(string $content): bool
    {
        $len = \strlen($content);
        if (0 === $len) {
            return false;
        }

        // Determine prefix end: earliest '(' or 'ID' followed by whitespace
        $prefixEnd = $len;
        $parenPos = strpos($content, '(');
        if (false !== $parenPos) {
            $prefixEnd = $parenPos;
        }

        $searchPos = 0;
        while ($searchPos < $prefixEnd && false !== ($idPos = strpos($content, 'ID', $searchPos))) {
            if ($idPos >= $prefixEnd) {
                break;
            }
            $nextIdx = $idPos + 2;
            if ($nextIdx < $len && false !== strpos(" \t\r\n\f\v", $content[$nextIdx])) {
                $prefixEnd = $idPos;
                break;
            }
            $searchPos = $idPos + 2;
        }

        if (0 === $prefixEnd) {
            return false;
        }

        // Fast C-speed ASCII scan: 99.9% of real content streams pass here in microseconds
        $asciiSpan = strspn($content, self::ALLOWED_ASCII, 0, $prefixEnd);
        if ($asciiSpan >= $prefixEnd) {
            return false;
        }

        // First non-allowed-ASCII byte: if < 0x80 it is a disallowed control byte (binary!)
        if (\ord($content[$asciiSpan]) < 0x80) {
            return true;
        }

        // Otherwise check remaining prefix slice for valid UTF-8 with no disallowed control bytes
        $tail = substr($content, $asciiSpan, $prefixEnd - $asciiSpan);
        if (!mb_check_encoding($tail, 'UTF-8')) {
            return true;
        }

        return 1 === preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $tail);
    }

    /**
     * Lex a PDF content stream in a single pass, returning only commands needed for
     * text extraction and positioning.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function lexForText(?string $content): array
    {
        if (null === $content || '' === $content) {
            return [];
        }

        // Fast exit: streams containing no BT, Do, or ActualText can never emit text
        if (
            false === strpos($content, 'BT')
            && false === strpos($content, 'Do')
            && false === strpos($content, 'ActualText')
        ) {
            return [];
        }

        if (self::isBinaryContent($content)) {
            return [];
        }

        $commands = [];
        $len = \strlen($content);
        $pos = 0;
        $lastDictStr = null;
        $lastDictEnd = -1;

        while ($pos < $len) {
            if (1 !== preg_match(self::OUTSIDE_BT_PATTERN, $content, $match, \PREG_OFFSET_CAPTURE, $pos)) {
                break;
            }

            $token = $match[0][0];
            $matchPos = $match[0][1];
            $afterMatch = $matchPos + \strlen($token);

            if ('(' === $token) {
                $pos = self::skipLiteralString($content, $matchPos, $len);
                continue;
            }

            if ('<<' === $token) {
                $dictEnd = self::skipDictionary($content, $matchPos, $len);
                $lastDictStr = substr($content, $matchPos, $dictEnd - $matchPos);
                $lastDictEnd = $dictEnd;
                $pos = $dictEnd;
                continue;
            }

            if ('BI' === $token) {
                $pos = self::skipInlineImage($content, $afterMatch, $len);
                continue;
            }

            switch ($token) {
                case 'BT':
                    $commands[] = ['o' => 'BT'];
                    $pos = self::lexInsideTextBlock($content, $afterMatch, $len, $commands);
                    break;

                case 'q':
                case 'Q':
                case 'EMC':
                    $commands[] = ['o' => $token];
                    $pos = $afterMatch;
                    break;

                case 'cm':
                    $nums = self::extractTrailingNumbers($content, $matchPos, 6);
                    if (null !== $nums) {
                        $commands[] = ['o' => 'cm', 'args' => $nums];
                    }
                    $pos = $afterMatch;
                    break;

                case 'Tf':
                    $tf = self::extractTrailingTf($content, $matchPos);
                    if (null !== $tf) {
                        $commands[] = ['o' => 'Tf', 'font' => $tf[0], 'size' => $tf[1]];
                    }
                    $pos = $afterMatch;
                    break;

                case 'Do':
                    $name = self::extractTrailingName($content, $matchPos);
                    if (null !== $name) {
                        $commands[] = ['o' => 'Do', 'name' => $name];
                    }
                    $pos = $afterMatch;
                    break;

                case 'BMC':
                    $tag = self::extractTrailingName($content, $matchPos);
                    if (null !== $tag) {
                        $commands[] = ['o' => 'BMC', 'tag' => $tag];
                    }
                    $pos = $afterMatch;
                    break;

                case 'BDC':
                    $dict = null;
                    if (null !== $lastDictStr && $lastDictEnd <= $matchPos) {
                        $gap = $matchPos - $lastDictEnd;
                        if (0 === $gap || strspn($content, self::WHITESPACE, $lastDictEnd, $gap) === $gap) {
                            $dict = $lastDictStr;
                        }
                    }
                    $commands[] = ['o' => 'BDC', 'dict' => $dict];
                    $lastDictStr = null;
                    $pos = $afterMatch;
                    break;

                default:
                    $pos = $afterMatch;
                    break;
            }
        }

        return $commands;
    }

    /**
     * Parse the interior of a TJ array (without enclosing '[' and ']') into subcommands
     * in a single O(N) linear pass. Handles nested balanced parentheses, escaped backslashes,
     * hex strings with whitespace, leading kerning offsets, and consecutive numbers.
     *
     * @return array<int, array<string, string>>
     */
    public static function parseTjArray(string $inner): array
    {
        $len = \strlen($inner);
        $pos = 0;

        return self::readTjArrayElements($inner, $pos, $len, false);
    }

    /**
     * Lex tokens inside a BT ... ET text block until ET or EOF.
     */
    private static function lexInsideTextBlock(string $content, int $pos, int $len, array &$commands): int
    {
        $operands = [];

        while ($pos < $len) {
            $pos += strspn($content, self::WHITESPACE, $pos);
            if ($pos >= $len) {
                break;
            }

            $ch = $content[$pos];

            if ('%' === $ch) {
                $pos += strcspn($content, "\r\n", $pos);
                continue;
            }

            if ('(' === $ch) {
                $str = self::readLiteralString($content, $pos, $len);
                $operands[] = ['(', $str];
                continue;
            }

            if ('<' === $ch) {
                if (($pos + 1) < $len && '<' === $content[$pos + 1]) {
                    $dictStart = $pos;
                    $pos = self::skipDictionary($content, $pos, $len);
                    $operands[] = ['<<', substr($content, $dictStart, $pos - $dictStart)];
                } else {
                    $hex = self::readHexString($content, $pos, $len);
                    $operands[] = ['<', $hex];
                }
                continue;
            }

            if ('[' === $ch) {
                ++$pos;
                $items = self::readTjArrayElements($content, $pos, $len, true);
                $operands[] = ['[', $items];
                continue;
            }

            if ('/' === $ch) {
                ++$pos;
                $span = strcspn($content, self::DELIMITERS, $pos);
                $operands[] = ['/', substr($content, $pos, $span)];
                $pos += $span;
                continue;
            }

            if ("'" === $ch || '"' === $ch) {
                ++$pos;
                self::emitTextOperator($ch, $operands, $commands);
                $operands = [];
                continue;
            }

            if (']' === $ch || ')' === $ch || '>' === $ch) {
                // Stray closing delimiter in malformed stream
                ++$pos;
                continue;
            }

            // Read regular token (number, boolean, null, or PDF operator)
            $span = strcspn($content, self::DELIMITERS, $pos);
            if (0 === $span) {
                ++$pos;
                continue;
            }
            $word = substr($content, $pos, $span);
            $pos += $span;

            // Fast numeric check (first char is digit, '-', '+', or '.')
            $first = $word[0];
            if (($first >= '0' && $first <= '9') || '-' === $first || '+' === $first || '.' === $first) {
                if (is_numeric($word)) {
                    $operands[] = ['n', $word];
                    continue;
                }
            }

            if ('true' === $word || 'false' === $word || 'null' === $word) {
                $operands[] = ['w', $word];
                continue;
            }

            if ('ET' === $word) {
                $commands[] = ['o' => 'ET'];

                return $pos;
            }

            if ('BI' === $word) {
                $pos = self::skipInlineImage($content, $pos, $len);
                $operands = [];
                continue;
            }

            self::emitTextOperator($word, $operands, $commands);
            $operands = [];
        }

        return $pos;
    }

    /**
     * Convert an operator and its accumulated operands inside a text block into a structured command.
     */
    private static function emitTextOperator(string $op, array $operands, array &$commands): void
    {
        $count = \count($operands);

        switch ($op) {
            case 'Tj':
            case "'":
            case '"':
                if ($count > 0) {
                    $last = $operands[$count - 1];
                    if ('(' === $last[0] || '<' === $last[0]) {
                        $commands[] = ['o' => $op, 't' => $last[0], 'c' => $last[1]];
                    }
                }
                break;

            case 'TJ':
                if ($count > 0) {
                    $last = $operands[$count - 1];
                    if ('[' === $last[0]) {
                        $commands[] = ['o' => 'TJ', 'c' => $last[1]];
                    }
                }
                break;

            case 'Tf':
                if ($count >= 2 && '/' === $operands[$count - 2][0]) {
                    $commands[] = [
                        'o' => 'Tf',
                        'font' => $operands[$count - 2][1],
                        'size' => (float) $operands[$count - 1][1],
                    ];
                }
                break;

            case 'Td':
            case 'TD':
                if ($count >= 2) {
                    $commands[] = [
                        'o' => $op,
                        'x' => (float) $operands[$count - 2][1],
                        'y' => (float) $operands[$count - 1][1],
                    ];
                }
                break;

            case 'Tm':
            case 'cm':
                if ($count >= 6) {
                    $base = $count - 6;
                    $commands[] = [
                        'o' => $op,
                        'args' => [
                            (float) $operands[$base][1],
                            (float) $operands[$base + 1][1],
                            (float) $operands[$base + 2][1],
                            (float) $operands[$base + 3][1],
                            (float) $operands[$base + 4][1],
                            (float) $operands[$base + 5][1],
                        ],
                    ];
                }
                break;

            case 'TL':
                if ($count >= 1) {
                    $commands[] = ['o' => 'TL', 'v' => (float) $operands[$count - 1][1]];
                }
                break;

            case 'T*':
            case 'q':
            case 'Q':
            case 'BT':
            case 'EMC':
                $commands[] = ['o' => $op];
                break;

            case 'Do':
                if ($count >= 1 && '/' === $operands[$count - 1][0]) {
                    $commands[] = ['o' => 'Do', 'name' => $operands[$count - 1][1]];
                }
                break;

            case 'BMC':
                if ($count >= 1 && '/' === $operands[$count - 1][0]) {
                    $commands[] = ['o' => 'BMC', 'tag' => $operands[$count - 1][1]];
                }
                break;

            case 'BDC':
                $dict = null;
                if ($count >= 1 && '<<' === $operands[$count - 1][0]) {
                    $dict = $operands[$count - 1][1];
                }
                $commands[] = ['o' => 'BDC', 'dict' => $dict];
                break;

            default:
                // Non-positioning operators inside BT..ET (Tc, Tw, Tz, Tr, Ts, rg, gs, etc.) ignored
                break;
        }
    }

    /**
     * Read elements of a PDF array [...] as TJ subcommand entries.
     *
     * @return array<int, array<string, string>>
     */
    private static function readTjArrayElements(string $content, int &$pos, int $len, bool $stopOnCloseBracket): array
    {
        $items = [];

        while ($pos < $len) {
            $pos += strspn($content, self::WHITESPACE, $pos);
            if ($pos >= $len) {
                break;
            }

            $ch = $content[$pos];

            if (']' === $ch && $stopOnCloseBracket) {
                ++$pos;
                break;
            }

            if ('%' === $ch) {
                $pos += strcspn($content, "\r\n", $pos);
                continue;
            }

            if ('(' === $ch) {
                $items[] = [
                    PDFObject::TYPE => '(',
                    PDFObject::OPERATOR => 'TJ',
                    PDFObject::COMMAND => self::readLiteralString($content, $pos, $len),
                ];
                continue;
            }

            if ('<' === $ch) {
                if (($pos + 1) < $len && '<' === $content[$pos + 1]) {
                    $pos = self::skipDictionary($content, $pos, $len);
                } else {
                    $items[] = [
                        PDFObject::TYPE => '<',
                        PDFObject::OPERATOR => 'TJ',
                        PDFObject::COMMAND => self::readHexString($content, $pos, $len),
                    ];
                }
                continue;
            }

            if ('[' === $ch) {
                // Nested array (e.g. dash pattern inside BT..ET); skip balanced
                ++$pos;
                self::readTjArrayElements($content, $pos, $len, true);
                continue;
            }

            $span = strcspn($content, self::DELIMITERS, $pos);
            if (0 === $span) {
                ++$pos;
                continue;
            }
            $tok = substr($content, $pos, $span);
            $pos += $span;

            if (is_numeric($tok)) {
                $items[] = [
                    PDFObject::TYPE => 'n',
                    PDFObject::OPERATOR => '',
                    PDFObject::COMMAND => $tok,
                ];
            }
        }

        return $items;
    }

    /**
     * Read a balanced PDF literal string starting at '(' (advancing $pos past the closing ')').
     * Preserves inner escape sequences for Font::decodeText() while collapsing escaped line continuations.
     */
    private static function readLiteralString(string $content, int &$pos, int $len): string
    {
        ++$pos; // skip opening '('
        $start = $pos;
        $depth = 1;
        $hasBackslash = false;

        while ($pos < $len && $depth > 0) {
            $pos += strcspn($content, '()\\', $pos);
            if ($pos >= $len) {
                break;
            }
            $ch = $content[$pos];
            if ('\\' === $ch) {
                $hasBackslash = true;
                $pos += 2;
            } elseif ('(' === $ch) {
                ++$depth;
                ++$pos;
            } else { // ')'
                --$depth;
                ++$pos;
            }
        }

        $raw = 0 === $depth
            ? substr($content, $start, $pos - $start - 1)
            : substr($content, $start);

        // Collapse PDF escaped line continuations (ISO 32000-1, 7.3.4.2)
        if ($hasBackslash && (false !== strpos($raw, "\\\n") || false !== strpos($raw, "\\\r"))) {
            $raw = str_replace(["\\\r\n", "\\\r", "\\\n"], '', $raw);
        }

        return $raw;
    }

    /**
     * Skip a balanced PDF literal string starting at '(' and return the offset after ')'.
     */
    private static function skipLiteralString(string $content, int $pos, int $len): int
    {
        ++$pos; // skip '('
        $depth = 1;

        while ($pos < $len && $depth > 0) {
            $pos += strcspn($content, '()\\', $pos);
            if ($pos >= $len) {
                return $len;
            }
            $ch = $content[$pos];
            if ('\\' === $ch) {
                $pos += 2;
            } elseif ('(' === $ch) {
                ++$depth;
                ++$pos;
            } else {
                --$depth;
                ++$pos;
            }
        }

        return min($pos, $len);
    }

    /**
     * Read a PDF hexadecimal string starting at '<' (advancing $pos past '>') with whitespace stripped.
     */
    private static function readHexString(string $content, int &$pos, int $len): string
    {
        ++$pos; // skip '<'
        $end = strpos($content, '>', $pos);
        if (false === $end) {
            $raw = substr($content, $pos);
            $pos = $len;
        } else {
            $raw = substr($content, $pos, $end - $pos);
            $pos = $end + 1;
        }

        if ('' !== $raw && strcspn($raw, self::WHITESPACE) < \strlen($raw)) {
            $raw = strtr($raw, ["\x00" => '', "\x09" => '', "\x0a" => '', "\x0c" => '', "\x0d" => '', ' ' => '']);
        }

        return $raw;
    }

    /**
     * Skip a balanced nested PDF dictionary starting at '<<' and return the offset after '>>'.
     */
    private static function skipDictionary(string $content, int $pos, int $len): int
    {
        $pos += 2; // skip '<<'
        $depth = 1;

        while ($pos < $len && $depth > 0) {
            $pos += strcspn($content, '<>(%', $pos);
            if ($pos >= $len) {
                return $len;
            }
            $ch = $content[$pos];
            if ('(' === $ch) {
                $pos = self::skipLiteralString($content, $pos, $len);
            } elseif ('%' === $ch) {
                $pos += strcspn($content, "\r\n", $pos);
            } elseif ('<' === $ch) {
                if (($pos + 1) < $len && '<' === $content[$pos + 1]) {
                    ++$depth;
                    $pos += 2;
                } else {
                    $end = strpos($content, '>', $pos + 1);
                    $pos = false === $end ? $len : $end + 1;
                }
            } else { // '>'
                if (($pos + 1) < $len && '>' === $content[$pos + 1]) {
                    --$depth;
                    $pos += 2;
                } else {
                    ++$pos;
                }
            }
        }

        return min($pos, $len);
    }

    /**
     * Skip an inline image block starting immediately after 'BI' through 'ID <binary> EI'.
     */
    private static function skipInlineImage(string $content, int $pos, int $len): int
    {
        // Step 1: scan inline image dictionary tokens until 'ID' operator
        $foundId = false;
        while ($pos < $len) {
            $pos += strspn($content, self::WHITESPACE, $pos);
            if ($pos >= $len) {
                return $len;
            }
            $ch = $content[$pos];
            if ('(' === $ch) {
                $pos = self::skipLiteralString($content, $pos, $len);
            } elseif ('%' === $ch) {
                $pos += strcspn($content, "\r\n", $pos);
            } elseif ('<' === $ch && ($pos + 1) < $len && '<' === $content[$pos + 1]) {
                $pos = self::skipDictionary($content, $pos, $len);
            } elseif ('<' === $ch) {
                $end = strpos($content, '>', $pos + 1);
                $pos = false === $end ? $len : $end + 1;
            } elseif ('/' === $ch) {
                ++$pos;
                $pos += strcspn($content, self::DELIMITERS, $pos);
            } elseif ('[' === $ch || ']' === $ch) {
                ++$pos;
            } else {
                $span = strcspn($content, self::DELIMITERS, $pos);
                if (0 === $span) {
                    ++$pos;
                    continue;
                }
                $tok = substr($content, $pos, $span);
                $pos += $span;
                if ('ID' === $tok) {
                    $foundId = true;
                    break;
                }
                if ('EI' === $tok) {
                    return $pos;
                }
            }
        }

        if (!$foundId || $pos >= $len) {
            return min($pos, $len);
        }

        // Skip single whitespace byte (or \r\n) after ID
        if ("\r" === $content[$pos] && ($pos + 1) < $len && "\n" === $content[$pos + 1]) {
            $pos += 2;
        } else {
            ++$pos;
        }

        // Step 2: locate terminating EI preceded by whitespace and followed by whitespace/EOF
        while ($pos < $len && false !== ($eiPos = strpos($content, 'EI', $pos))) {
            $beforeOk = $eiPos > 0 && false !== strpos(self::WHITESPACE, $content[$eiPos - 1]);
            $afterIdx = $eiPos + 2;
            $afterOk = $afterIdx >= $len || false !== strpos(self::DELIMITERS, $content[$afterIdx]);
            if ($beforeOk && $afterOk) {
                return $afterIdx;
            }
            $pos = $eiPos + 2;
        }

        return $len;
    }

    /**
     * Extract the trailing $count numeric operands immediately preceding $opPos using fast byte scanning.
     *
     * @return array<int, float>|null
     */
    private static function extractTrailingNumbers(string $content, int $opPos, int $count): ?array
    {
        $idx = $opPos - 1;
        $nums = [];

        for ($n = 0; $n < $count; ++$n) {
            while ($idx >= 0 && false !== strpos(self::WHITESPACE, $content[$idx])) {
                --$idx;
            }
            if ($idx < 0) {
                return null;
            }
            $end = $idx + 1;
            while ($idx >= 0 && false === strpos(self::DELIMITERS, $content[$idx])) {
                --$idx;
            }
            $tok = substr($content, $idx + 1, $end - $idx - 1);
            if (!is_numeric($tok)) {
                return null;
            }
            $nums[] = (float) $tok;
        }

        return array_reverse($nums);
    }

    /**
     * Extract trailing '/FontName size' operands immediately preceding a Tf operator.
     *
     * @return array{0: string, 1: float}|null
     */
    private static function extractTrailingTf(string $content, int $opPos): ?array
    {
        $idx = $opPos - 1;
        while ($idx >= 0 && false !== strpos(self::WHITESPACE, $content[$idx])) {
            --$idx;
        }
        if ($idx < 0) {
            return null;
        }
        $sizeEnd = $idx + 1;
        while ($idx >= 0 && false === strpos(self::DELIMITERS, $content[$idx])) {
            --$idx;
        }
        $sizeTok = substr($content, $idx + 1, $sizeEnd - $idx - 1);
        if (!is_numeric($sizeTok)) {
            return null;
        }

        $name = self::extractTrailingName($content, $idx + 1);
        if (null === $name) {
            return null;
        }

        return [$name, (float) $sizeTok];
    }

    /**
     * Extract trailing '/Name' operand immediately preceding a Do, BMC, or Tf size token.
     */
    private static function extractTrailingName(string $content, int $opPos): ?string
    {
        $idx = $opPos - 1;
        while ($idx >= 0 && false !== strpos(self::WHITESPACE, $content[$idx])) {
            --$idx;
        }
        if ($idx < 0) {
            return null;
        }
        $end = $idx + 1;
        while ($idx >= 0 && false === strpos(self::DELIMITERS, $content[$idx])) {
            --$idx;
        }
        if ($idx < 0 || '/' !== $content[$idx]) {
            return null;
        }
        $nameLen = $end - $idx - 1;

        return $nameLen > 0 ? substr($content, $idx + 1, $nameLen) : null;
    }
}
