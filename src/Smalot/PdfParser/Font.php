<?php

/**
 * @file
 *          This file is part of the PdfParser library.
 *
 * @author  Sébastien MALOT <sebastien@malot.fr>
 *
 * @date    2017-01-03
 *
 * @license LGPLv3
 *
 * @url     <https://github.com/smalot/pdfparser>
 *
 *  PdfParser is a pdf library written in PHP, extraction oriented.
 *  Copyright (C) 2017 - Sébastien MALOT <sebastien@malot.fr>
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU Lesser General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU Lesser General Public License for more details.
 *
 *  You should have received a copy of the GNU Lesser General Public License
 *  along with this program.
 *  If not, see <http://www.pdfparser.org/sites/default/LICENSE.txt>.
 */

namespace Smalot\PdfParser;

use Smalot\PdfParser\Encoding\WinAnsiEncoding;
use Smalot\PdfParser\Exception\EncodingNotFoundException;

/**
 * Class Font
 */
class Font extends PDFObject
{
    public const MISSING = '?';

    /**
     * @var array
     */
    protected $table;

    /**
     * @var array
     */
    protected $rangeOffsets = [];

    /**
     * @var array
     */
    protected $tableSizes;

    /**
     * Caches results from uchr (bounded to prevent unbounded static memory growth).
     *
     * @var array<int, string>
     */
    private static $uchrCache = [];

    private const UCHR_CACHE_LIMIT = 8192;

    /**
     * Code space byte lengths declared in begincodespacerange (ordered shortest to longest
     * for variable-length CJK CMaps).
     *
     * @var array<int, array{0: int, 1: int, 2: int}>
     */
    protected $codeSpaceRanges = [];

    /**
     * In some PDF-files encoding could be referenced by object id but object itself does not contain
     * `/Type /Encoding` in its dictionary. These objects wouldn't be initialized as Encoding in
     * \Smalot\PdfParser\PDFObject::factory() during file parsing (they would be just PDFObject).
     *
     * Therefore, we create an instance of Encoding from them during decoding and cache this value in this property.
     *
     * @var Encoding|null
     *
     * @see https://github.com/smalot/pdfparser/pull/500
     */
    private $initializedEncodingByPdfObject;

    public function init()
    {
        // Defer loadTranslateTable() until first font translation/width lookup
        // so unused fonts incur zero CMap parsing CPU or RAM overhead.
    }

    public function getName(): string
    {
        return $this->has('BaseFont') ? (string) $this->get('BaseFont') : '[Unknown]';
    }

    public function getType(): string
    {
        return (string) $this->header->get('Subtype');
    }

    public function getDetails(bool $deep = true): array
    {
        $details = [];

        $details['Name'] = $this->getName();
        $details['Type'] = $this->getType();
        $details['Encoding'] = $this->getEncodingName();

        $details += parent::getDetails($deep);

        return $details;
    }

    /**
     * Returns the name of the encoding or 'Ansi', if no name is given.
     *
     * Encoding is either a name or an encoding dictionary, in which all entries (e.g. Type, BaseEncoding) are optional.
     *
     * 'Ansi' is only returned for backward compatibility. According to the spec, StandardEncoding
     * or the font's built-in encoding applies, if no name is given.
     *
     * @see https://opensource.adobe.com/dc-acrobat-sdk-docs/pdfstandards/PDF32000_2008.pdf#page=271 ISO 32000-1:2008, 9.6.6.1, Table 114
     */
    private function getEncodingName(): string
    {
        $encoding = $this->get('Encoding');

        // encoding dictionary, either inline (Header) or referenced indirectly (PDFObject, Encoding)
        if ($encoding instanceof PDFObject || $encoding instanceof Header) {
            $encoding = $encoding->get('BaseEncoding');
        }

        $name = $encoding instanceof Element ? (string) $encoding : '';

        return '' !== $name ? $name : 'Ansi';
    }

    /**
     * @return string|bool
     */
    public function translateChar(string $char, bool $use_default = true)
    {
        if (null === $this->table) {
            $this->loadTranslateTable();
        }

        $len = \strlen($char);
        if (1 === $len) {
            $dec = \ord($char);
        } elseif (2 === $len) {
            $dec = (\ord($char[0]) << 8) | \ord($char[1]);
        } else {
            $dec = hexdec(bin2hex($char));
        }

        if (\array_key_exists($dec, $this->table)) {
            return $this->table[$dec];
        }

        // On-demand evaluation for wide CMap ranges (avoids pre-allocating tens of thousands of array keys)
        if (!empty($this->rangeOffsets)) {
            foreach ($this->rangeOffsets as $range) {
                if ($dec >= $range['from'] && $dec <= $range['to']) {
                    $this->table[$dec] = $range['prefix'].self::uchr($dec - $range['from'] + $range['offset']);

                    return $this->table[$dec];
                }
            }
        }

        // fallback for decoding single-byte ANSI characters that are not in the lookup table
        $fallbackDecoded = $char;
        if (
            $len < 2
            && $this->has('Encoding')
            && $this->get('Encoding') instanceof Encoding
        ) {
            try {
                if (WinAnsiEncoding::class === $this->get('Encoding')->__toString()) {
                    $fallbackDecoded = self::uchr($dec);
                }
            } catch (EncodingNotFoundException $e) {
                // Encoding->getEncodingClass() throws EncodingNotFoundException when BaseEncoding doesn't exists
                // See table 5.11 on PDF 1.5 specs for more info
            }
        }

        return $use_default ? self::MISSING : $fallbackDecoded;
    }

    /**
     * Convert unicode character code to "utf-8" encoded string.
     *
     * @param int|float $code Unicode character code. Will be casted to int internally!
     */
    public static function uchr($code): string
    {
        // A float outside the integer range (e.g. the result of a hexdec() overflow),
        // INF or NAN can never be a valid Unicode code point.
        if (\is_float($code) && (!\is_finite($code) || $code < \PHP_INT_MIN || $code >= \PHP_INT_MAX + 1)) {
            return self::MISSING;
        }

        $code = (int) $code;

        if ($code < 0 || $code > 0x10FFFF) {
            return self::MISSING;
        }

        // Fast path for ASCII (0x00..0x7F): direct single-byte chr()
        if ($code < 0x80) {
            return \chr($code);
        }

        if (isset(self::$uchrCache[$code])) {
            return self::$uchrCache[$code];
        }

        if ($code < 0x800) {
            $utf8 = \chr(0xC0 | ($code >> 6)).\chr(0x80 | ($code & 0x3F));
        } elseif ($code < 0x10000) {
            $utf8 = \chr(0xE0 | ($code >> 12))
                .\chr(0x80 | (($code >> 6) & 0x3F))
                .\chr(0x80 | ($code & 0x3F));
        } else {
            $utf8 = \chr(0xF0 | ($code >> 18))
                .\chr(0x80 | (($code >> 12) & 0x3F))
                .\chr(0x80 | (($code >> 6) & 0x3F))
                .\chr(0x80 | ($code & 0x3F));
        }

        if (\count(self::$uchrCache) < self::UCHR_CACHE_LIMIT) {
            self::$uchrCache[$code] = $utf8;
        }

        return $utf8;
    }

    /**
     * Decode a UTF-16BE hexadecimal string into UTF-8, combining UTF-16 surrogate pairs
     * while staying tolerant of odd-length hex fragments.
     */
    private static function decodeUtf16BeHex(string $hex): string
    {
        $parts = str_split($hex, 4);
        $count = \count($parts);
        $text = '';

        for ($i = 0; $i < $count; ++$i) {
            $unit = (int) hexdec($parts[$i]);
            if (
                $unit >= 0xD800 && $unit <= 0xDBFF
                && ($i + 1) < $count
                && 4 === \strlen($parts[$i + 1])
            ) {
                $low = (int) hexdec($parts[$i + 1]);
                if ($low >= 0xDC00 && $low <= 0xDFFF) {
                    $codePoint = 0x10000 + (($unit - 0xD800) << 10) + ($low - 0xDC00);
                    $text .= self::uchr($codePoint);
                    ++$i;
                    continue;
                }
            }
            $text .= self::uchr($unit);
        }

        return $text;
    }

    /**
     * Init internal chars translation table by ToUnicode CMap.
     */
    public function loadTranslateTable(): array
    {
        if (null !== $this->table) {
            return $this->table;
        }

        $this->table = [];
        $this->rangeOffsets = [];
        $this->codeSpaceRanges = [];
        $this->tableSizes = [
            'from' => 1,
            'to' => 1,
        ];

        if ($this->has('ToUnicode')) {
            $toUnicodeObj = $this->get('ToUnicode');
            $content = \is_object($toUnicodeObj) && method_exists($toUnicodeObj, 'getContent') ? (string) $toUnicodeObj->getContent() : '';
            $matches = [];

            // Support for multiple spacerange sections and variable-length CJK codespaces
            if (preg_match_all('/begincodespacerange(?P<sections>.*?)endcodespacerange/s', $content, $matches)) {
                $firstSectionSet = false;
                foreach ($matches['sections'] as $section) {
                    $regexp = '/<(?P<from>[0-9A-F]+)>\s*<(?P<to>[0-9A-F]+)>/is';

                    if (preg_match_all($regexp, $section, $csMatches) > 0) {
                        if (!$firstSectionSet) {
                            $this->tableSizes = [
                                'from' => max(1, (int) (\strlen($csMatches['from'][0]) / 2)),
                                'to' => max(1, (int) (\strlen($csMatches['to'][0]) / 2)),
                            ];
                            $firstSectionSet = true;
                        }
                        foreach ($csMatches['from'] as $k => $fromHex) {
                            $byteLen = max(1, (int) (\strlen($fromHex) / 2));
                            $this->codeSpaceRanges[] = [$byteLen, hexdec($fromHex), hexdec($csMatches['to'][$k])];
                        }
                    }
                }
            }

            // Support for multiple bfchar sections
            if (preg_match_all('/beginbfchar(?P<sections>.*?)endbfchar/s', $content, $matches)) {
                foreach ($matches['sections'] as $section) {
                    $regexp = '/<(?P<from>[0-9A-F]+)>\s*<(?P<to>[0-9A-F]+)>/is';

                    if (preg_match_all($regexp, $section, $bfMatches) > 0) {
                        $this->tableSizes['from'] = max(1, (int) (\strlen($bfMatches['from'][0]) / 2));

                        foreach ($bfMatches['from'] as $key => $from) {
                            $this->table[hexdec($from)] = self::decodeUtf16BeHex($bfMatches['to'][$key]);
                        }
                    }
                }
            }

            // Support for multiple bfrange sections
            if (preg_match_all('/beginbfrange(?P<sections>.*?)endbfrange/s', $content, $matches)) {
                foreach ($matches['sections'] as $section) {
                    /**
                     * Regexp to capture <from>, <to>, and either <offset> or [...] items.
                     */
                    $regexp = '/<(?P<from>[0-9A-F]+)>\s*<(?P<to>[0-9A-F]+)>\s*(?P<dest><[0-9A-F]+>|\[[\t\r\n<>0-9A-F ]+\])/is';

                    if (preg_match_all($regexp, $section, $brMatches) <= 0) {
                        continue;
                    }

                    foreach ($brMatches['from'] as $key => $from) {
                        $char_from = hexdec($from);
                        $char_to = hexdec($brMatches['to'][$key]);
                        $dest = $brMatches['dest'][$key];

                        if ('<' === $dest[0]) {
                            $offsetHex = substr($dest, 1, -1);
                            if ('' === $offsetHex) {
                                continue;
                            }
                            $parts = str_split($offsetHex, 4);
                            $lastPart = array_pop($parts);
                            $offset = hexdec($lastPart);
                            $prefix = [] !== $parts ? self::decodeUtf16BeHex(implode('', $parts)) : '';

                            // Lazy-evaluate wide ranges (> 256) on-demand to prevent memory exhaustion
                            if (($char_to - $char_from) > 256) {
                                $this->rangeOffsets[] = [
                                    'from' => $char_from,
                                    'to' => $char_to,
                                    'offset' => $offset,
                                    'prefix' => $prefix,
                                ];
                            } else {
                                for ($char = $char_from; $char <= $char_to; ++$char) {
                                    $this->table[$char] = $prefix.self::uchr($char - $char_from + $offset);
                                }
                            }
                        } else {
                            // Support for : <srcCode1> <srcCodeN> [<dstString1> <dstString2> ... <dstStringN>]
                            $strings = [];
                            $matched = preg_match_all('/<(?P<string>[0-9A-F]+)>/is', $dest, $strings);
                            if (false === $matched || 0 === $matched) {
                                continue;
                            }

                            foreach ($strings['string'] as $position => $string) {
                                $this->table[$char_from + $position] = self::decodeUtf16BeHex($string);
                            }
                        }
                    }
                }
            }
        }

        return $this->table;
    }

    /**
     * Set custom char translation table where:
     * - key - integer character code;
     * - value - "utf-8" encoded value;
     *
     * @return void
     */
    public function setTable(array $table)
    {
        $this->table = $table;
        $this->rangeOffsets = [];
    }

    /**
     * Calculate text width with data from header 'Widths'. If width of character is not found then character is added to missing array.
     */
    public function calculateTextWidth(string $text, ?array &$missing = null): ?float
    {
        if (null === $this->table) {
            $this->loadTranslateTable();
        }

        $index_map = array_flip($this->table);
        $details = $this->getDetails();

        // Usually, Widths key is set in $details array, but if it isn't use an empty array instead.
        $widths = $details['Widths'] ?? [];

        /*
         * Widths array is zero indexed but table is not. We must map them based on FirstChar and LastChar
         *
         * Note: Without the change you would see warnings in PHP 8.4 because the values of FirstChar or LastChar
         *       can be null sometimes.
         */
        $width_map = array_flip(range((int) $details['FirstChar'], (int) $details['LastChar']));

        $width = null;
        $missing = [];
        $textLength = mb_strlen($text);
        for ($i = 0; $i < $textLength; ++$i) {
            $char = mb_substr($text, $i, 1);
            if (!\array_key_exists($char, $index_map) && !empty($this->rangeOffsets)) {
                $unpacked = unpack('N', mb_convert_encoding($char, 'UTF-32BE', 'UTF-8'));
                if (false !== $unpacked && isset($unpacked[1])) {
                    $codepoint = $unpacked[1];
                    foreach ($this->rangeOffsets as $range) {
                        if ('' === $range['prefix']) {
                            $dec = $range['from'] + ($codepoint - $range['offset']);
                            if (\is_int($dec) && $dec >= $range['from'] && $dec <= $range['to']) {
                                $this->table[$dec] = $char;
                                $index_map[$char] = $dec;
                                break;
                            }
                        }
                    }
                }
            }
            if (
                !\array_key_exists($char, $index_map)
                || !\array_key_exists($index_map[$char], $width_map)
                || !\array_key_exists($width_map[$index_map[$char]], $widths)
            ) {
                $missing[] = $char;
                continue;
            }
            $width_index = $width_map[$index_map[$char]];
            $width += $widths[$width_index];
        }

        return $width;
    }

    /**
     * Decode hexadecimal encoded string. If $add_braces is true result value would be wrapped by parentheses.
     */
    public static function decodeHexadecimal(string $hexa, bool $add_braces = false): string
    {
        // Special shortcut for XML content.
        if (false !== stripos($hexa, '<?xml')) {
            return $hexa;
        }

        $text = '';
        $parts = preg_split('/(<[a-f0-9\s]+>)/si', $hexa, -1, \PREG_SPLIT_NO_EMPTY | \PREG_SPLIT_DELIM_CAPTURE);

        foreach ($parts as $part) {
            if (preg_match('/^<[a-f0-9\s]+>$/si', $part)) {
                // strip whitespace
                $part = preg_replace("/\s/", '', $part);
                $part = trim($part, '<>');
                if ($add_braces) {
                    $text .= '(';
                }

                $part = pack('H*', $part);
                $text .= ($add_braces ? preg_replace('/\\\/s', '\\\\\\', $part) : $part);

                if ($add_braces) {
                    $text .= ')';
                }
            } else {
                $text .= $part;
            }
        }

        return $text;
    }

    /**
     * Decode string with octal-decoded chunks.
     */
    public static function decodeOctal(string $text): string
    {
        // Replace all double backslashes \\ with a special string
        $text = strtr($text, ['\\\\' => '[**pdfparserdblslsh**]']);

        // Now we can replace all octal codes without worrying about
        // escaped backslashes
        $text = preg_replace_callback('/\\\\([0-7]{1,3})/', function ($m) {
            return \chr(octdec($m[1]));
        }, $text);

        // Unescape any parentheses
        $text = str_replace(['\\(', '\\)'], ['(', ')'], $text);

        // Replace instances of the special string with a single backslash
        return str_replace('[**pdfparserdblslsh**]', '\\', $text);
    }

    /**
     * Decode string with html entity encoded chars.
     */
    public static function decodeEntities(string $text): string
    {
        return preg_replace_callback('/#([0-9a-f]{2})/i', function ($m) {
            return \chr(hexdec($m[1]));
        }, $text);
    }

    /**
     * Check if given string is Unicode text (by BOM);
     * If true - decode to "utf-8" encoded string.
     * Otherwise - return text as is.
     *
     * @todo Rename in next major release to make the name correspond to reality (for ex. decodeIfUnicode())
     */
    public static function decodeUnicode(string $text): string
    {
        if ("\xFE\xFF" === substr($text, 0, 2)) {
            // Strip U+FEFF byte order marker and decode UTF-16BE (including surrogate pairs).
            $decode = substr($text, 2);
            $text = '';
            $length = \strlen($decode);

            for ($i = 0; $i < $length; $i += 2) {
                if ($i + 1 >= $length) {
                    $text .= self::uchr(\ord($decode[$i]));
                    break;
                }
                $unit = (\ord($decode[$i]) << 8) | \ord($decode[$i + 1]);
                if ($unit >= 0xD800 && $unit <= 0xDBFF && ($i + 3) < $length) {
                    $low = (\ord($decode[$i + 2]) << 8) | \ord($decode[$i + 3]);
                    if ($low >= 0xDC00 && $low <= 0xDFFF) {
                        $text .= self::uchr(0x10000 + (($unit - 0xD800) << 10) + ($low - 0xDC00));
                        $i += 2;
                        continue;
                    }
                }
                $text .= self::uchr($unit);
            }
        }

        return $text;
    }

    /**
     * @todo Deprecated, use $this->config->getFontSpaceLimit() instead.
     */
    protected function getFontSpaceLimit(): int
    {
        return null !== $this->config ? $this->config->getFontSpaceLimit() : -50;
    }

    /**
     * Single-pass PDF literal string unescape (octal + standard escapes) without double-unescaping.
     */
    private static function decodeLiteralStringEscapes(string $text): string
    {
        if (false === strpos($text, '\\')) {
            return $text;
        }

        return (string) preg_replace_callback(
            '/\\\\([0-7]{1,3}|[\\\\()nrtfb ])/',
            function (array $m): string {
                $seq = $m[1];
                if ($seq[0] >= '0' && $seq[0] <= '7') {
                    return \chr(octdec($seq) & 0xFF);
                }
                switch ($seq) {
                    case '\\':
                        return '\\';
                    case '(':
                        return '(';
                    case ')':
                        return ')';
                    case 'n':
                        return "\n";
                    case 'r':
                        return "\r";
                    case 't':
                        return "\t";
                    case 'f':
                        return "\f";
                    case 'b':
                        return "\x08";
                    case ' ':
                        return ' ';
                }

                return $seq;
            },
            $text
        );
    }

    /**
     * Decode text by commands array.
     */
    public function decodeText(array $commands, float $fontFactor = 4): string
    {
        $word_position = 0;
        $words = [];
        $font_space = $this->getFontSpaceLimit() * abs($fontFactor) / 4;

        foreach ($commands as $command) {
            switch ($command[PDFObject::TYPE]) {
                case 'n':
                    $offset = (float) trim($command[PDFObject::COMMAND]);
                    if ($offset - (float) $font_space < 0) {
                        $word_position = \count($words);
                    }
                    continue 2;
                case '<':
                    $rawHex = $command[PDFObject::COMMAND];
                    // Fast path for valid PDF hex strings: decode directly to binary without
                    // applying literal-string backslash unescaping (which corrupts 2-byte CIDs like 0x5C6E).
                    if ('' === $rawHex || 1 === preg_match('/^[0-9a-f\s]+$/i', $rawHex)) {
                        $cleanHex = preg_replace('/\s+/', '', $rawHex);
                        $text = '' === $cleanHex ? '' : pack('H*', $cleanHex);
                    } else {
                        // Legacy fallback for non-hex payloads containing escape sequences (e.g. Issue #597)
                        $text = self::decodeHexadecimal('<'.$rawHex.'>');
                        $text = str_replace(
                            ['\\\\', '\(', '\)', '\n', '\r', '\t', '\f', '\ ', '\b'],
                            [\chr(92), \chr(40), \chr(41), \chr(10), \chr(13), \chr(9), \chr(12), \chr(32), \chr(8)],
                            $text
                        );
                    }
                    break;

                default:
                    // Single-pass octal + PDF escape decoding avoids corrupting \\n into \<LF>.
                    $text = self::decodeLiteralStringEscapes($command[PDFObject::COMMAND]);
            }

            // add content to result string
            if (isset($words[$word_position])) {
                $words[$word_position] .= $text;
            } else {
                $words[$word_position] = $text;
            }
        }

        foreach ($words as &$word) {
            $word = $this->decodeContent($word);
            $word = str_replace("\t", ' ', $word);
        }

        // Remove internal "words" that are just spaces, but leave them
        // if they are at either end of the array of words. This fixes,
        // for   example,   lines   that   are   justified   to   fill
        // a whole row.
        for ($x = \count($words) - 2; $x >= 1; --$x) {
            if ('' === trim($words[$x], ' ')) {
                unset($words[$x]);
            }
        }
        $words = array_values($words);

        // Cut down on the number of unnecessary internal spaces by
        // imploding the string on the null byte, and checking if the
        // text includes extra spaces on either side. If so, merge
        // where appropriate.
        $words = implode("\x00\x00", $words);
        $words = str_replace(
            [" \x00\x00 ", "\x00\x00 ", " \x00\x00", "\x00\x00"],
            ['  ', ' ', ' ', ' '],
            $words
        );

        return $words;
    }

    /**
     * Decode given $text to "utf-8" encoded string.
     *
     * @param bool $unicode This parameter is deprecated and might be removed in a future release
     */
    public function decodeContent(string $text, ?bool &$unicode = null): string
    {
        // If this string begins with a UTF-16BE BOM, then decode it
        // directly as Unicode
        if ("\xFE\xFF" === substr($text, 0, 2)) {
            return $this->decodeUnicode($text);
        }

        if ($this->has('ToUnicode')) {
            return $this->decodeContentByToUnicodeCMapOrDescendantFonts($text);
        }

        if ($this->has('Encoding')) {
            $result = $this->decodeContentByEncoding($text);

            if (null !== $result) {
                return $result;
            }
        }

        return $this->decodeContentByAutodetectIfNecessary($text);
    }

    /**
     * First try to decode $text by ToUnicode CMap.
     * If char translation not found in ToUnicode CMap tries:
     *  - If DescendantFonts exists tries to decode char by one of that fonts.
     *      - If have no success to decode by DescendantFonts interpret $text as a string with "Windows-1252" encoding.
     *  - If DescendantFonts does not exist just return "?" as decoded char.
     *
     * @todo Seems this is invalid algorithm that do not follow pdf-format specification. Must be rewritten.
     */
    private function decodeContentByToUnicodeCMapOrDescendantFonts(string $text): string
    {
        if (null === $this->table) {
            $this->loadTranslateTable();
        }

        $bytes = $this->tableSizes['from'];

        if ($bytes) {
            $result = '';
            $length = \strlen($text);

            if (1 === $bytes) {
                for ($i = 0; $i < $length; ++$i) {
                    $dec = \ord($text[$i]);
                    if (isset($this->table[$dec])) {
                        $result .= $this->table[$dec];
                        continue;
                    }
                    $result .= $this->translateFallbackChar($text[$i]);
                }
            } elseif (2 === $bytes) {
                for ($i = 0; $i < $length; $i += 2) {
                    if ($i + 1 < $length) {
                        $dec = (\ord($text[$i]) << 8) | \ord($text[$i + 1]);
                        if (isset($this->table[$dec])) {
                            $result .= $this->table[$dec];
                            continue;
                        }
                        $char = $text[$i].$text[$i + 1];
                    } else {
                        $char = $text[$i];
                    }
                    $result .= $this->translateFallbackChar($char);
                }
            } else {
                for ($i = 0; $i < $length; $i += $bytes) {
                    $char = substr($text, $i, $bytes);
                    $result .= $this->translateFallbackChar($char);
                }
            }

            $text = $result;
        }

        return $text;
    }

    /**
     * Fallback path when a character code is not present in the direct CMap lookup table.
     */
    private function translateFallbackChar(string $char): string
    {
        if (false !== ($decoded = $this->translateChar($char, false))) {
            return $decoded;
        }

        if ($this->has('DescendantFonts')) {
            if ($this->get('DescendantFonts') instanceof PDFObject) {
                $fonts = $this->get('DescendantFonts')->getHeader()->getElements();
            } else {
                $fonts = $this->get('DescendantFonts')->getContent();
            }
            $decoded = false;

            foreach ($fonts as $font) {
                if ($font instanceof self) {
                    if (false !== ($decoded = $font->translateChar($char, false))) {
                        $decoded = mb_convert_encoding($decoded, 'UTF-8', 'Windows-1252');
                        break;
                    }
                }
            }

            if (false !== $decoded) {
                return $decoded;
            }

            return mb_convert_encoding($char, 'UTF-8', 'Windows-1252');
        }

        return self::MISSING;
    }

    /**
     * Decode content by any type of Encoding (dictionary's item) instance.
     */
    private function decodeContentByEncoding(string $text): ?string
    {
        $encoding = $this->get('Encoding');

        // When Encoding is an inline dictionary (/Encoding << /BaseEncoding ... /Differences [...] >>)
        if ($encoding instanceof Header) {
            if (!$this->initializedEncodingByPdfObject) {
                $enc = new Encoding($this->document, $encoding, null, $this->config);
                $enc->init();
                $this->initializedEncodingByPdfObject = $enc;
            }
            $encoding = $this->initializedEncodingByPdfObject;
        } elseif ($encoding instanceof PDFObject && !($encoding instanceof Encoding)) {
            // When Encoding referenced by object id (/Encoding 520 0 R) without `/Type /Encoding`
            $encoding = $this->getInitializedEncodingByPdfObject($encoding);
        }

        // When Encoding is an Encoding instance
        if ($encoding instanceof Encoding) {
            return $this->decodeContentByEncodingEncoding($text, $encoding);
        }

        // When Encoding is just a name (/Encoding /WinAnsiEncoding)
        if ($encoding instanceof Element) {
            return $this->decodeContentByEncodingElement($text, $encoding);
        }

        // don't double-encode strings already in UTF-8
        if (!mb_check_encoding($text, 'UTF-8')) {
            return mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        }

        return $text;
    }

    /**
     * Returns already created or create a new one if not created before Encoding instance by PDFObject instance.
     */
    private function getInitializedEncodingByPdfObject(PDFObject $PDFObject): Encoding
    {
        if (!$this->initializedEncodingByPdfObject) {
            $this->initializedEncodingByPdfObject = $this->createInitializedEncodingByPdfObject($PDFObject);
        }

        return $this->initializedEncodingByPdfObject;
    }

    /**
     * Decode content when $encoding (given by $this->get('Encoding')) is instance of Encoding.
     */
    private function decodeContentByEncodingEncoding(string $text, Encoding $encoding): string
    {
        $result = '';
        $length = \strlen($text);

        for ($i = 0; $i < $length; ++$i) {
            $dec_av = \ord($text[$i]);
            $dec_ap = $encoding->translateChar($dec_av);
            $result .= self::uchr($dec_ap ?? $dec_av);
        }

        return $result;
    }

    /**
     * Decode content when $encoding (given by $this->get('Encoding')) is instance of Element.
     */
    private function decodeContentByEncodingElement(string $text, Element $encoding): ?string
    {
        $pdfEncodingName = $encoding->getContent();

        // mb_convert_encoding does not support MacRoman/macintosh,
        // so we use iconv() here
        $iconvEncodingName = $this->getIconvEncodingNameOrNullByPdfEncodingName($pdfEncodingName);

        return $iconvEncodingName ? iconv($iconvEncodingName, 'UTF-8//TRANSLIT//IGNORE', $text) : null;
    }

    /**
     * Convert PDF encoding name to iconv-known encoding name.
     */
    private function getIconvEncodingNameOrNullByPdfEncodingName(string $pdfEncodingName): ?string
    {
        $pdfToIconvEncodingNameMap = [
            'StandardEncoding' => 'ISO-8859-1',
            'MacRomanEncoding' => 'MACINTOSH',
            'WinAnsiEncoding' => 'CP1252',
        ];

        return \array_key_exists($pdfEncodingName, $pdfToIconvEncodingNameMap)
            ? $pdfToIconvEncodingNameMap[$pdfEncodingName]
            : null;
    }

    /**
     * If string seems like "utf-8" encoded string do nothing and just return given string as is.
     * Otherwise, interpret string as "Window-1252" encoded string.
     *
     * @return string|false
     */
    private function decodeContentByAutodetectIfNecessary(string $text)
    {
        if (mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }

        return mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        // todo: Why exactly `Windows-1252` used?
    }

    /**
     * Create Encoding instance by PDFObject instance and init it.
     */
    private function createInitializedEncodingByPdfObject(PDFObject $PDFObject): Encoding
    {
        $encoding = $this->createEncodingByPdfObject($PDFObject);
        $encoding->init();

        return $encoding;
    }

    /**
     * Create Encoding instance by PDFObject instance (without init).
     */
    private function createEncodingByPdfObject(PDFObject $PDFObject): Encoding
    {
        $document = $PDFObject->getDocument();
        $header = $PDFObject->getHeader();
        $content = $PDFObject->getContent();
        $config = $PDFObject->getConfig();

        return new Encoding($document, $header, $content, $config);
    }
}
