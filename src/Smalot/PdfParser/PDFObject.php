<?php

declare(strict_types=1);

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

use Smalot\PdfParser\Exception\InvalidDictionaryObjectException;
use Smalot\PdfParser\RawData\ContentStreamLexer;
use Smalot\PdfParser\RawData\LazyStream;
use Smalot\PdfParser\XObject\Form;
use Smalot\PdfParser\XObject\Image;

/**
 * Class PDFObject
 */
class PDFObject
{
    public const string TYPE = 't';

    public const string OPERATOR = 'o';

    public const string COMMAND = 'c';

    /**
     * Size of the chunks (in bytes) getSectionsText() processes a formatted
     * stream in; bounds the per-chunk line array.
     */
    private const int SECTIONS_CHUNK_SIZE = 1024 * 1024;

    /**
     * The recursion stack.
     */
    public static array $recursionStack = [];

    protected Header $header;

    /**
     * Deferred stream decoder (decoded on first getContent() call and then released).
     */
    protected ?LazyStream $lazyStream = null;

    protected bool $addPositionWhitespace = false;

    public function __construct(
        protected Document $document,
        ?Header $header = null,
        protected ?string $content = null,
        protected ?Config $config = null,
    ) {
        $this->header = $header ?? new Header();
    }

    public function init(): void
    {
    }

    public function getDocument(): Document
    {
        return $this->document;
    }

    public function getHeader(): Header
    {
        return $this->header;
    }

    public function getConfig(): ?Config
    {
        return $this->config;
    }

    public function get(string $name): Element|self|Header
    {
        return $this->header->get($name);
    }

    public function has(string $name): bool
    {
        return $this->header->has($name);
    }

    public function getDetails(bool $deep = true): array
    {
        return $this->header->getDetails($deep);
    }

    /**
     * Attach a deferred stream decoder to be resolved on first getContent() call.
     *
     * @internal
     */
    public function setLazyStream(?LazyStream $lazyStream): void
    {
        $this->lazyStream = $lazyStream;
    }

    public function getContent(): ?string
    {
        if (null !== $this->lazyStream) {
            $this->content = $this->lazyStream->decode();
            $this->lazyStream = null;
        }

        return $this->content;
    }

    /**
     * Creates a duplicate of the document stream with
     * strings and other items replaced by $char. Formerly
     * getSectionsText() used this output to more easily gather offset
     * values to extract text from the *actual* document stream.
     *
     * @deprecated function is no longer used and will be removed in a future release
     *
     * @internal
     */
    public function cleanContent(string $content, string $char = 'X'): string
    {
        $char = $char[0];
        $content = str_replace(['\\\\', '\\)', '\\('], $char.$char, $content);

        // Remove image bloc with binary content
        preg_match_all('/\s(BI\s.*?(\sID\s).*?(\sEI))\s/s', $content, $matches, \PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as $part) {
            $content = substr_replace($content, str_repeat($char, \strlen($part[0])), $part[1], \strlen($part[0]));
        }

        // Clean content in square brackets [.....]
        preg_match_all('/\[((\(.*?\)|[0-9\.\-\s]*)*)\]/s', $content, $matches, \PREG_OFFSET_CAPTURE);
        foreach ($matches[1] as $part) {
            $content = substr_replace($content, str_repeat($char, \strlen($part[0])), $part[1], \strlen($part[0]));
        }

        // Clean content in round brackets (.....)
        preg_match_all('/\((.*?)\)/s', $content, $matches, \PREG_OFFSET_CAPTURE);
        foreach ($matches[1] as $part) {
            $content = substr_replace($content, str_repeat($char, \strlen($part[0])), $part[1], \strlen($part[0]));
        }

        // Clean structure
        if ($parts = preg_split('/(<|>)/s', $content, -1, \PREG_SPLIT_NO_EMPTY | \PREG_SPLIT_DELIM_CAPTURE)) {
            $content = '';
            $level = 0;
            foreach ($parts as $part) {
                if ('<' === $part) {
                    ++$level;
                }

                $content .= 0 === $level ? $part : str_repeat($char, \strlen($part));

                if ('>' === $part) {
                    --$level;
                }
            }
        }

        // Clean BDC and EMC markup
        preg_match_all(
            '/(\/[A-Za-z0-9\_]*\s*'.preg_quote($char).'*BDC)/s',
            $content,
            $matches,
            \PREG_OFFSET_CAPTURE
        );
        foreach ($matches[1] as $part) {
            $content = substr_replace($content, str_repeat($char, \strlen($part[0])), $part[1], \strlen($part[0]));
        }

        preg_match_all('/\s(EMC)\s/s', $content, $matches, \PREG_OFFSET_CAPTURE);
        foreach ($matches[1] as $part) {
            $content = substr_replace($content, str_repeat($char, \strlen($part[0])), $part[1], \strlen($part[0]));
        }

        return $content;
    }

    /**
     * Takes a string of PDF document stream text and formats
     * it into a multi-line string with one PDF command on each line,
     * separated by \r\n. If the given string is null, or binary data
     * is detected instead of a document stream then return an empty
     * string.
     *
     * @see https://opensource.adobe.com/dc-acrobat-sdk-docs/pdfstandards/PDF32000_2008.pdf#page=23 ISO 32000-1:2008, 7.3.4.2 (literal strings)
     * @see https://opensource.adobe.com/dc-acrobat-sdk-docs/pdfstandards/PDF32000_2008.pdf#page=258 ISO 32000-1:2008, 9.4.3, Table 109 (Tj, TJ)
     * @see https://opensource.adobe.com/dc-acrobat-sdk-docs/pdfstandards/PDF32000_2008.pdf#page=561 ISO 32000-1:2008, 14.6.1, Table 320 (BDC, BMC, DP, MP)
     */
    private function formatContent(?string $content): string
    {
        if (null === $content) {
            return '';
        }

        // Outside of (String) and inline image content in PDF document
        // streams, all text should conform to UTF-8. Test for binary
        // content by deleting everything after the first open-
        // parenthesis ( which indicates the beginning of a string, or
        // the first ID command which indicates the beginning of binary
        // inline image content. Then test what remains for valid
        // UTF-8. If it's not UTF-8, return an empty string as this
        // $content is most likely binary. Unfortunately, using
        // mb_check_encoding(..., 'UTF-8') is not strict enough, so the
        // following regexp, adapted from the W3, is used. See:
        // https://www.w3.org/International/questions/qa-forms-utf-8.en
        // We use preg_replace() instead of preg_match() to avoid "JIT
        // stack limit exhausted" errors on larger files.
        $utf8Filter = preg_replace('/(
            [\x09\x0A\x0D\x20-\x7E] |            # ASCII
            [\xC2-\xDF][\x80-\xBF] |             # non-overlong 2-byte
            \xE0[\xA0-\xBF][\x80-\xBF] |         # excluding overlongs
            [\xE1-\xEC\xEE\xEF][\x80-\xBF]{2} |  # straight 3-byte
            \xED[\x80-\x9F][\x80-\xBF] |         # excluding surrogates
            \xF0[\x90-\xBF][\x80-\xBF]{2} |      # planes 1-3
            [\xF1-\xF3][\x80-\xBF]{3} |          # planes 4-15
            \xF4[\x80-\x8F][\x80-\xBF]{2}        # plane 16
        )/xs', '', (string) preg_replace('/(\(|ID\s).*$/s', '', $content));

        if ('' !== $utf8Filter) {
            return '';
        }

        // Find all inline image content and replace them so they aren't
        // affected by the next steps
        $pdfInlineImages = [];
        $offsetBI = 0;
        while (1 === preg_match('/\sBI\s(\/.+?)\sID\s(.+?)\sEI(?=\s|$)/s', $content, $text, \PREG_OFFSET_CAPTURE, $offsetBI)) {
            // Attempt to determine if this instance of the 'BI' command
            // actually occurred within a (string) using the following
            // steps:

            // Step 1: Remove any escaped slashes and parentheses from
            // the alleged image characteristics data
            $para = str_replace(['\\\\', '\\(', '\\)'], '', $text[1][0]);

            // Step 2: Remove all correctly ordered and balanced
            // parentheses from (strings)
            do {
                $paraTest = $para;
                $para = (string) preg_replace('/\(([^()]*)\)/', '$1', $paraTest);
            } while ($para !== $paraTest);

            $paraOpen = strpos($para, '(');
            $paraClose = strpos($para, ')');

            // Check: If the remaining text contains a close parenthesis
            // ')' AND it occurs before any open parenthesis, then we
            // are almost certain to be inside a (string)
            if (false !== $paraClose && 0 < $paraClose && (false === $paraOpen || $paraClose < $paraOpen)) {
                // Bump the search offset forward and match again
                $offsetBI = $text[1][1];
                continue;
            }

            // Step 3: Double check that this is actually inline image
            // data by parsing the alleged image characteristics as a
            // dictionary
            $dict = $this->parseDictionary('<<'.$text[1][0].'>>');

            // Check if an image Width and Height are set in the dict
            if ((isset($dict['W']) || isset($dict['Width']))
                && (isset($dict['H']) || isset($dict['Height']))) {
                $id = uniqid('IMAGE_', true);
                $pdfInlineImages[$id] = [
                    (string) preg_replace(['/\r\n/', '/\r/', '/\n/'], ' ', $text[1][0]),
                    (string) preg_replace(['/\r\n/', '/\r/', '/\n/'], '', $text[2][0]),
                ];
                $content = (string) preg_replace(
                    '/'.preg_quote($text[0][0], '/').'/',
                    '^^^'.$id.'^^^',
                    $content,
                    1
                );
            } else {
                // If there was no valid dictionary, or a height and width
                // weren't specified, then we don't know what this is, so
                // just leave it alone; bump the search offset forward and
                // match again
                $offsetBI = $text[1][1];
            }
        }

        // Find all strings () and replace them so they aren't affected
        // by the next steps
        $pdfstrings = [];
        $attempt = '(';
        // The search starts at $offset. The content in front of $offset is
        // collected in $processed, with each string replaced by a placeholder.
        // The effort grows linearly with the number of strings, which matters
        // for TJ arrays consisting of thousands of string operands.
        $offset = 0;
        $processed = '';
        while (1 === preg_match('/'.preg_quote($attempt, '/').'.*?\)/s', $content, $text, \PREG_OFFSET_CAPTURE, $offset)) {
            [$string, $stringPos] = $text[0];

            // Remove all escaped slashes and parentheses from the target text
            $para = str_replace(['\\\\', '\\(', '\\)'], '', $string);

            // PDF strings can contain unescaped parentheses as long as
            // they're balanced, so check for balanced parentheses
            $left = preg_match_all('/\(/', $para);
            $right = preg_match_all('/\)/', $para);

            if ('' !== $para && ')' === $para[-1] && $left === $right) {
                // Replace the string with a unique placeholder
                $id = uniqid('STRING_', true);
                $pdfstrings[$id] = $string;
                $processed .= substr($content, $offset, $stringPos - $offset).'@@@'.$id.'@@@';
                $offset = $stringPos + \strlen($string);

                // Reset to search for the next string
                $attempt = '(';
            } else {
                // We had unbalanced parentheses, so use the current
                // match as a base to find a longer string
                $attempt = $string;
            }
        }
        $content = $processed.substr($content, $offset);

        // Remove all carriage returns and line-feeds from the document stream
        $content = str_replace(["\r", "\n"], ' ', trim($content));

        // Find all dictionary << >> commands and replace them so they
        // aren't affected by the next steps
        $dictstore = [];
        while (1 === preg_match('/(<<.*?>> *)(BDC|BMC|DP|MP)/s', $content, $dicttext)) {
            $dictid = uniqid('DICT_', true);
            $dictstore[$dictid] = $dicttext[1];
            $content = (string) preg_replace(
                '/'.preg_quote($dicttext[0], '/').'/',
                ' ###'.$dictid.'###'.$dicttext[2],
                $content,
                1
            );
        }

        // Normalize white-space in the document stream
        $content = (string) preg_replace('/\s{2,}/', ' ', $content);

        // Find all valid PDF operators and add \r\n after each; this
        // ensures there is just one command on every line
        // Source: https://ia801001.us.archive.org/1/items/pdf1.7/pdf_reference_1-7.pdf - Appendix A
        // Source: https://archive.org/download/pdf320002008/PDF32000_2008.pdf - Annex A
        // Note: PDF Reference 1.7 lists 'I' and 'rI' as valid commands, while
        //       PDF 32000:2008 lists them as 'i' and 'ri' respectively. Both versions
        //       appear here in the list for completeness.
        $operators = [
            'b*', 'b', 'BDC', 'BMC', 'B*', 'BI', 'BT', 'BX', 'B', 'cm', 'cs', 'c', 'CS',
            'd0', 'd1', 'd', 'Do', 'DP', 'EMC', 'EI', 'ET', 'EX', 'f*', 'f', 'F', 'gs',
            'g', 'G', 'h', 'i', 'ID', 'I', 'j', 'J', 'k', 'K', 'l', 'm', 'MP', 'M', 'n',
            'q', 'Q', 're', 'rg', 'ri', 'rI', 'RG', 'scn', 'sc', 'sh', 's', 'SCN', 'SC',
            'S', 'T*', 'Tc', 'Td', 'TD', 'Tf', 'TJ', 'Tj', 'TL', 'Tm', 'Tr', 'Ts', 'Tw',
            'Tz', 'v', 'w', 'W*', 'W', 'y', '\'', '"',
        ];
        foreach ($operators as $operator) {
            $content = (string) preg_replace(
                '/(?<!\w|\/)'.preg_quote($operator, '/').'(?![\w10\*])/',
                $operator."\r\n",
                $content
            );
        }

        // Restore the original content of the dictionary << >> commands, all
        // placeholders in one pass over the content
        if ([] !== $dictstore) {
            $dictMap = [];
            foreach ($dictstore as $id => $dict) {
                $dictMap['###'.$id.'###'] = $dict;
            }
            $content = strtr($content, $dictMap);
        }

        // Restore the original string content, all placeholders in one pass
        // over the content
        $stringMap = [];
        foreach ($pdfstrings as $id => $text) {
            // Strings may contain escaped newlines, or literal newlines
            // and we should clean these up before replacing the string
            // back into the content stream; this ensures no strings are
            // split between two lines (every command must be on one line)
            $stringMap['@@@'.$id.'@@@'] = str_replace(
                ["\\\r\n", "\\\r", "\\\n", "\r", "\n"],
                ['', '', '', '\r', '\n'],
                $text
            );
        }
        if ([] !== $stringMap) {
            $content = strtr($content, $stringMap);
        }

        // Restore the original content of any inline images
        $pdfInlineImages = array_reverse($pdfInlineImages, true);
        foreach ($pdfInlineImages as $id => $image) {
            $content = str_replace(
                '^^^'.$id.'^^^',
                "\r\nBI\r\n".$image[0]." ID\r\n".$image[1]." EI\r\n",
                $content
            );
        }

        return trim((string) preg_replace(['/(\r\n){2,}/', '/\r\n +/'], "\r\n", $content));
    }

    /**
     * Takes an entire, unformatted document stream as a string, formats
     * it, then filters out commands that aren't needed for text
     * positioning/extraction. It returns an array of unprocessed PDF
     * commands, one command per element.
     *
     * Commands inside of a text object (BT ... ET) are kept entirely, outside
     * of it only the ones isKeptOutsideTextBlock() approves.
     *
     * The formatted stream is processed in line-aligned chunks of about
     * SECTIONS_CHUNK_SIZE bytes, so only the lines of one chunk are held in
     * memory at a time. The result doesn't depend on the chunk size.
     *
     * @see https://opensource.adobe.com/dc-acrobat-sdk-docs/pdfstandards/PDF32000_2008.pdf#page=89 ISO 32000-1:2008, 7.8.2 (content streams)
     * @see https://opensource.adobe.com/dc-acrobat-sdk-docs/pdfstandards/PDF32000_2008.pdf#page=256 ISO 32000-1:2008, 9.4.1, Table 107 (BT, ET)
     *
     * @internal
     */
    public function getSectionsText(?string $content): array
    {
        $sections = [];

        // A formatted stream has one command on every line. A graphics-heavy
        // page can consist of hundreds of thousands of lines, of which only a
        // handful are kept below.
        $cleaned = $this->formatContent($content);
        $length = \strlen($cleaned);

        $inTextBlock = false;
        $chunkSize = self::SECTIONS_CHUNK_SIZE;
        $offset = 0;
        while ($offset < $length) {
            // A chunk ends at the first line boundary at or after $chunkSize
            // bytes, so a command is never split across chunks; the
            // $inTextBlock flag carries across them.
            $end = min($offset + $chunkSize, $length);
            if ($end < $length) {
                $end += strcspn($cleaned, "\r\n", $end);
            }

            // Split the chunk into lines. A stream which fits in one chunk (the
            // common case) is used as is, which avoids copying it via substr().
            $chunk = (0 === $offset && $length === $end)
                ? $cleaned
                : substr($cleaned, $offset, $end - $offset);
            $textCleaned = preg_split('/(\r\n|\n|\r)/', $chunk, -1, \PREG_SPLIT_NO_EMPTY) ?: [];

            // Advance past the chunk and the run of delimiters following it.
            $offset = $end + strspn($cleaned, "\r\n", $end);

            // Once the final chunk is split into lines, the formatted stream is
            // no longer needed. It gets released before the lines are filtered
            // to keep peak memory usage low.
            if ($offset >= $length) {
                $cleaned = $chunk = '';
            }

            // Filter lines
            foreach ($textCleaned as $line) {
                $line = trim($line);

                // Skip empty lines
                if ('' === $line) {
                    continue;
                }

                // If a 'BT' is encountered, set the $inTextBlock flag
                if (str_ends_with($line, 'BT')) {
                    $inTextBlock = true;
                    $sections[] = $line;

                    // If an 'ET' is encountered, unset the $inTextBlock flag
                } elseif ('ET' === $line) {
                    $inTextBlock = false;
                    $sections[] = $line;

                    // Inside a BT ... ET block keep every line; outside it, keep
                    // only the few commands needed for positioning/extraction.
                } elseif ($inTextBlock || $this->isKeptOutsideTextBlock($line)) {
                    $sections[] = $line;
                }
            }
        }

        return $sections;
    }

    /**
     * Whether a (trimmed, non-empty) line outside a BT...ET text block is one of
     * the few commands worth keeping for text positioning/extraction.
     *
     * Care should be taken to ensure a command being checked for *only* matches
     * that command. For instance, a simple search for 'c' may also match the
     * 'sc' command. See the command list in the formatContent() method above.
     * Add more commands to keep here as you find them in weird PDFs!
     *
     * @see https://opensource.adobe.com/dc-acrobat-sdk-docs/pdfstandards/PDF32000_2008.pdf#page=135 ISO 32000-1:2008, 8.4.4, Table 57 (q, Q, cm)
     * @see https://opensource.adobe.com/dc-acrobat-sdk-docs/pdfstandards/PDF32000_2008.pdf#page=561 ISO 32000-1:2008, 14.6.1, Table 320 (BDC, BMC, DP, MP, EMC)
     * @see https://opensource.adobe.com/dc-acrobat-sdk-docs/pdfstandards/PDF32000_2008.pdf#page=251 ISO 32000-1:2008, 9.3.1, Table 105 (Tf)
     * @see https://opensource.adobe.com/dc-acrobat-sdk-docs/pdfstandards/PDF32000_2008.pdf#page=210 ISO 32000-1:2008, 8.8.1, Table 87 (Do)
     */
    private function isKeptOutsideTextBlock(string $line): bool
    {
        return 'q' === $line[-1] || 'Q' === $line[-1]  // save/restore graphics state
            || 1 === preg_match('/(?<!\w)B[DM]C$/', $line)  // begin marked content sequence
            || 1 === preg_match('/(?<!\w)[DM]P$/', $line)   // marked content point
            || 1 === preg_match('/(?<!\w)EMC$/', $line)     // end marked content sequence
            || 1 === preg_match('/(?<!\w)cm$/', $line)      // graphics position change
            || 1 === preg_match('/(?<!\w)Tf$/', $line)      // font change
            || 1 === preg_match('/(?<!\w)Do$/', $line);     // invoke named XObject
    }

    private function getDefaultFont(?Page $page = null): Font
    {
        if (null !== $page) {
            $firstPageFont = array_first($page->getFonts());
            if (null !== $firstPageFont) {
                return $firstPageFont;
            }
        }

        return $this->document->getFirstFont()
            ?? new Font($this->document, null, null, $this->config);
    }

    /**
     * Decode a '[]TJ' command and attempt to use alternate
     * fonts if the current font results in output that contains
     * Unicode control characters.
     *
     * @internal
     *
     * @param array<int, array<string, string|bool>> $command
     */
    private function getTJUsingFontFallback(Font $font, array $command, ?Page $page = null, float $fontFactor = 4): string
    {
        $orig_text = $font->decodeText($command, $fontFactor);
        $text = $orig_text;

        // If the decoded text contains UTF-8 control characters (excluding whitespace \x09-\x0d)
        // or a NUL byte, the font being used may be wrong. Try other unique fonts on the page.
        if (null !== $page && (str_contains($text, "\x00") || 1 === preg_match('/[\x00-\x08\x0e-\x1f\x7f]/', $text))) {
            $seen = [spl_object_id($font) => true];
            foreach ($page->getFonts() as $candidateFont) {
                $objectId = spl_object_id($candidateFont);
                if (isset($seen[$objectId])) {
                    continue;
                }
                $seen[$objectId] = true;

                $text = $candidateFont->decodeText($command, $fontFactor);
                if (!str_contains($text, "\x00") && 0 === preg_match('/[\x00-\x08\x0e-\x1f\x7f]/', $text)) {
                    return $text;
                }
            }

            return $orig_text;
        }

        return $text;
    }

    /**
     * Expects a string that is a full PDF dictionary object,
     * including the outer enclosing << >> angle brackets
     *
     * @internal
     *
     * @throws InvalidDictionaryObjectException
     */
    public function parseDictionary(string $dictionary): array
    {
        // Normalize whitespace
        $dictionary = (string) preg_replace(['/\r/', '/\n/', '/\s{2,}/'], ' ', trim($dictionary));

        if (!str_starts_with($dictionary, '<<')) {
            throw new InvalidDictionaryObjectException('Not a valid dictionary object.');
        }

        $parsed = [];
        $stack = [];
        $currentName = '';
        $arrayTypeNumeric = false;

        // Remove outer layer of dictionary, and split on tokens
        $split = preg_split(
            '/(<<|>>|\[|\]|\/[^\s\/\[\]\(\)<>]*)/',
            trim((string) preg_replace('/^<<|>>$/', '', $dictionary)),
            -1,
            \PREG_SPLIT_NO_EMPTY | \PREG_SPLIT_DELIM_CAPTURE
        ) ?: [];

        foreach ($split as $token) {
            $token = trim($token);
            switch ($token) {
                case '':
                    break;

                    // Open numeric array
                case '[':
                    $parsed[$currentName] = [];
                    $arrayTypeNumeric = true;

                    // Move up one level in the stack
                    $stack[\count($stack)] = &$parsed;
                    $parsed = &$parsed[$currentName];
                    $currentName = '';
                    break;

                    // Open hashed array
                case '<<':
                    $parsed[$currentName] = [];
                    $arrayTypeNumeric = false;

                    // Move up one level in the stack
                    $stack[\count($stack)] = &$parsed;
                    $parsed = &$parsed[$currentName];
                    $currentName = '';
                    break;

                    // Close numeric array
                case ']':
                    // Revert string type arrays back to a single element
                    if (\is_array($parsed) && 1 === \count($parsed)
                        && isset($parsed[0]) && \is_string($parsed[0])
                        && '' !== $parsed[0] && '/' !== $parsed[0][0]) {
                        $parsed = '['.$parsed[0].']';
                    }
                    // Close hashed array
                    // no break
                case '>>':
                    $arrayTypeNumeric = false;

                    // Move down one level in the stack
                    $stackDepth = \count($stack);
                    if ($stackDepth > 0) {
                        $parsed = &$stack[$stackDepth - 1];
                        unset($stack[$stackDepth - 1]);
                    }
                    break;

                default:
                    // If value begins with a slash, then this is a name
                    // Add it to the appropriate array
                    if (str_starts_with($token, '/')) {
                        $currentName = substr($token, 1);
                        if ($arrayTypeNumeric) {
                            $parsed[] = $currentName;
                            $currentName = '';
                        }
                    } elseif ('' !== $currentName) {
                        if (!$arrayTypeNumeric) {
                            $parsed[$currentName] = $token;
                        }
                        $currentName = '';
                    } else {
                        $parsed[] = $token;
                    }
            }
        }

        return $parsed;
    }

    /**
     * Returns the text content of a PDF as a string. Attempts to add
     * whitespace for spacing and line-breaks where appropriate.
     *
     * getText() leverages getTextArray() to get the content
     * of the document, setting the addPositionWhitespace flag to true
     * so whitespace is inserted in a logical way for reading by
     * humans.
     */
    public function getText(?Page $page = null): string
    {
        $this->addPositionWhitespace = true;
        $result = $this->getTextArray($page);
        $this->addPositionWhitespace = false;

        return implode('', $result).' ';
    }

    /**
     * Returns the text content of a PDF as an array of strings. No
     * extra whitespace is inserted besides what is actually encoded in
     * the PDF text.
     *
     * @throws \Exception
     */
    public function getTextArray(?Page $page = null): array
    {
        $result = [];
        $text = [];

        $marked_stack = [];
        $last_written_position = false;

        $content = $this->getContent();
        $commands = ContentStreamLexer::lexForText($content);
        if ([] === $commands) {
            return [];
        }

        $current_font = $this->getDefaultFont($page);
        $current_font_size = 1.0;
        $current_text_leading = 0.0;

        $current_position = ['x' => false, 'y' => false];
        $current_position_tm = [
            'a' => 1.0, 'b' => 0.0, 'c' => 0.0,
            'i' => 0.0, 'j' => 1.0, 'k' => 0.0,
            'x' => 0.0, 'y' => 0.0, 'z' => 1.0,
        ];
        $current_position_td = ['x' => 0.0, 'y' => 0.0];
        $current_position_cm = [
            'a' => 1.0, 'b' => 0.0, 'c' => 0.0,
            'i' => 0.0, 'j' => 1.0, 'k' => 0.0,
            'x' => 0.0, 'y' => 0.0, 'z' => 1.0,
        ];

        $clipped_font = [];
        $clipped_position_cm = [];

        $uniqueId = $this->getUniqueId();
        self::$recursionStack[$uniqueId] = true;
        $doTextCache = [];

        try {
            foreach ($commands as $command) {
                switch ($command['o']) {
                    // Begin text object
                    case 'BT':
                        // Reset text positioning matrices
                        $current_position_tm = [
                            'a' => 1.0, 'b' => 0.0, 'c' => 0.0,
                            'i' => 0.0, 'j' => 1.0, 'k' => 0.0,
                            'x' => 0.0, 'y' => 0.0, 'z' => 1.0,
                        ];
                        $current_position_td = ['x' => 0.0, 'y' => 0.0];
                        $current_text_leading = 0.0;
                        break;

                        // Begin marked content sequence with property list
                    case 'BDC':
                        if (isset($command['dict']) && \is_string($command['dict'])) {
                            $dict = $this->parseDictionary($command['dict']);

                            // Check for ActualText block
                            if (isset($dict['ActualText']) && \is_string($dict['ActualText']) && '' !== $dict['ActualText']) {
                                if ('[' === $dict['ActualText'][0]) {
                                    $marked_stack[] = [
                                        'ActualText' => [
                                            'o' => 'TJ',
                                            'c' => ContentStreamLexer::parseTjArray(trim($dict['ActualText'], '[]')),
                                        ],
                                    ];
                                } elseif ('<' === $dict['ActualText'][0] || '(' === $dict['ActualText'][0]) {
                                    $atCmds = $this->getCommandsText($dict['ActualText'].'Tj');
                                    if (isset($atCmds[0])) {
                                        $marked_stack[] = ['ActualText' => $atCmds[0]];
                                    }
                                }
                            }
                        }
                        break;

                        // Begin marked content sequence
                    case 'BMC':
                        if (isset($command['tag']) && 'ReversedChars' === $command['tag']) {
                            $result = array_merge($result, $text);
                            $text = [];
                            $marked_stack[] = ['ReversedChars' => true];
                        }
                        break;

                        // set graphics position matrix
                    case 'cm':
                        $args = $command['args'];
                        $current_position_cm = [
                            'a' => $args[0], 'b' => $args[1], 'c' => 0.0,
                            'i' => $args[2], 'j' => $args[3], 'k' => 0.0,
                            'x' => $args[4], 'y' => $args[5], 'z' => 1.0,
                        ];
                        break;

                    case 'Do':
                        if (null === $page || !isset($command['name'])) {
                            break;
                        }

                        $xobject = $page->getXObject($command['name']);

                        if (!$xobject instanceof self || $xobject instanceof Image) {
                            break;
                        }

                        $xobjUid = $xobject->getUniqueId();
                        if (isset(self::$recursionStack[$xobjUid])) {
                            break;
                        }

                        if (isset($doTextCache[$xobjUid])) {
                            $objectText = $doTextCache[$xobjUid];
                        } else {
                            $objectText = $xobject->getText($page);
                            $doTextCache[$xobjUid] = $objectText;
                        }

                        if ($xobject instanceof Form && ' ' === $objectText) {
                            break;
                        }

                        $text[] = $objectText;
                        break;

                        // End text object
                    case 'ET':
                        break;

                        // Store current selected font and graphics matrix
                    case 'q':
                        $clipped_font[] = [$current_font, $current_font_size];
                        $clipped_position_cm[] = $current_position_cm;
                        break;

                        // Restore previous selected font and graphics matrix
                    case 'Q':
                        if ([] !== $clipped_font) {
                            [$current_font, $current_font_size] = array_pop($clipped_font);
                        }
                        if ([] !== $clipped_position_cm) {
                            $current_position_cm = array_pop($clipped_position_cm);
                        }
                        break;

                        // End marked content sequence
                    case 'EMC':
                        if ([] !== $marked_stack) {
                            $marked = array_pop($marked_stack);
                            $action = array_key_first($marked);
                            $data = $marked[$action];

                            switch ($action) {
                                case 'ReversedChars':
                                    foreach ($text as $key => $t) {
                                        $text[$key] = implode('', array_reverse(
                                            mb_str_split($t, 1, mb_internal_encoding())
                                        ));
                                    }
                                    $result = array_merge($result, $text);
                                    $text = [];
                                    break;

                                case 'ActualText':
                                    $command = $data;
                                    break;
                            }
                        }

                        if ('Tj' !== $command['o'] && 'TJ' !== $command['o']) {
                            break;
                        }

                        // no break
                    case "'":
                    case '"':
                        if ("'" === $command['o'] || '"' === $command['o']) {
                            $current_position['x'] = 0.0;
                            $current_position_td['x'] = 0.0;
                            $current_position_td['y'] += $current_text_leading;
                        }
                        // no break
                    case 'Tj':
                        $command[self::COMMAND] = [$command];
                        // no break
                    case 'TJ':
                        $actual_text = false;
                        $reverse_text = false;
                        foreach ($marked_stack as $marked) {
                            if (isset($marked['ActualText'])) {
                                $actual_text = true;
                            }
                            if (isset($marked['ReversedChars'])) {
                                $reverse_text = true;
                            }
                        }

                        if (!$actual_text && \is_array($last_written_position)) {
                            $currentX = $last_written_position[0];
                            $currentY = $last_written_position[1];
                            $last_written_position = false;
                        } else {
                            $currentX = $current_position_cm['x'] + $current_position_tm['x'] + $current_position_td['x'];
                            $currentY = $current_position_cm['y'] + $current_position_tm['y'] + $current_position_td['y'];
                        }
                        $whiteSpace = '';

                        $factorX = -$current_font_size * $current_position_tm['a'] - $current_font_size * $current_position_tm['i'];
                        $factorY = $current_font_size * $current_position_tm['b'] + $current_font_size * $current_position_tm['j'];

                        if ($this->addPositionWhitespace && false !== $current_position['x']) {
                            $curY = $currentY - $current_position['y'];
                            if (abs($curY) >= abs($factorY) / 4) {
                                $whiteSpace = "\n";
                            } else {
                                $curX = $reverse_text
                                    ? $current_position['x'] - $currentX
                                    : $currentX - $current_position['x'];

                                if ($curX >= abs($factorX * 7)) {
                                    $whiteSpace = "\t";
                                } elseif ($curX >= abs($factorX * 2)) {
                                    $whiteSpace = ' ';
                                }
                            }
                        }

                        $newtext = $this->getTJUsingFontFallback(
                            $current_font,
                            $command[self::COMMAND],
                            $page,
                            $factorX
                        );

                        if (!$actual_text) {
                            $newtext = str_replace(["\r", "\n"], '', $newtext);
                            if ($reverse_text) {
                                $text[] = (string) preg_replace('/  $/', ' ', $newtext.$whiteSpace);
                            } else {
                                $lastIdx = array_key_last($text);
                                if (' ' === $whiteSpace && null !== $lastIdx && '' !== $text[$lastIdx] && ' ' === $text[$lastIdx][-1]) {
                                    $text[$lastIdx] = substr($text[$lastIdx], 0, -1);
                                }
                                $combined = $whiteSpace.$newtext;
                                if (\strlen($combined) >= 2 && (' ' === $combined[0] || "\t" === $combined[0]) && (' ' === $combined[1] || "\t" === $combined[1])) {
                                    $combined = ' '.substr($combined, 2);
                                }
                                $text[] = $combined;
                            }

                            $factor = $factorX / 2;
                            $current_position = [
                                'x' => $currentX - mb_strlen($newtext) * $factor,
                                'y' => $currentY,
                            ];
                        } elseif (false === $last_written_position) {
                            $last_written_position = [$currentX, $currentY];
                            $current_position['x'] = $currentX;
                        }
                        break;

                        // move to start of next line
                    case 'T*':
                        $current_position['x'] = 0.0;
                        $current_position_td['x'] = 0.0;
                        $current_position_td['y'] += $current_text_leading;
                        break;

                        // move text current point and set leading
                    case 'Td':
                    case 'TD':
                        $x = $command['x'];
                        $y = $command['y'];

                        if ('TD' === $command['o']) {
                            $current_text_leading = -$y * $current_position_tm['b'] - $y * $current_position_tm['j'];
                        }

                        $current_position_td = [
                            'x' => $current_position_td['x'] + $x * $current_position_tm['a'] + $x * $current_position_tm['i'],
                            'y' => $current_position_td['y'] + $y * $current_position_tm['b'] + $y * $current_position_tm['j'],
                        ];
                        break;

                    case 'Tf':
                        if (null !== $page) {
                            $new_font = $page->getFont($command['font']);
                            if (null !== $new_font) {
                                $current_font = $new_font;
                                $current_font_size = $command['size'];
                            }
                        }
                        break;

                        // set leading
                    case 'TL':
                        $y = $command['v'];
                        $current_text_leading = -$y * $current_position_tm['b'] + -$y * $current_position_tm['j'];
                        break;

                        // set text position matrix
                    case 'Tm':
                        $args = $command['args'];
                        $current_position_tm = [
                            'a' => $args[0], 'b' => $args[1], 'c' => 0.0,
                            'i' => $args[2], 'j' => $args[3], 'k' => 0.0,
                            'x' => $args[4], 'y' => $args[5], 'z' => 1.0,
                        ];
                        break;

                    default:
                }
            }
        } finally {
            unset(self::$recursionStack[$uniqueId]);
        }

        return array_merge($result, $text);
    }

    /**
     * Single-pass extraction of text chunks paired with their 6-element transformation matrix (Tm)
     * and optional font info (fontId, fontSize). Avoids the legacy 3-pass getDataTm pipeline
     * (extractRawData -> extractDecodedRawData -> getDataCommands + getTextArray).
     *
     * @internal
     */
    public function extractDataTm(?Page $page = null): array
    {
        $content = $this->getContent();
        $commands = ContentStreamLexer::lexForText($content);
        if ([] === $commands) {
            return [];
        }

        $defaultTm = ['1', '0', '0', '1', '0', '0'];
        $concatTm = ['1', '0', '0', '1', '0', '0'];
        $graphicsStatesStack = [];
        $fontStack = [];

        $Tm = $defaultTm;
        $Tl = 0.0;
        $Tx = 0.0;
        $Ty = 0.0;

        $currentFont = $this->getDefaultFont($page);
        $fontId = -1;
        $fontSize = 1;
        $includeFontInfo = $this->config?->getDataTmFontInfoHasToBeIncluded() ?? false;

        $markedStack = [];
        $extractedData = [];

        foreach ($commands as $command) {
            switch ($command['o']) {
                case 'BT':
                    $Tm = $defaultTm;
                    $Tl = 0.0;
                    $Tx = 0.0;
                    $Ty = 0.0;
                    break;

                case 'cm':
                    $newConcatTm = $command['args'];
                    $tempMatrix = [];
                    $tempMatrix[0] = (float) $concatTm[0] * $newConcatTm[0] + (float) $concatTm[1] * $newConcatTm[2];
                    $tempMatrix[1] = (float) $concatTm[0] * $newConcatTm[1] + (float) $concatTm[1] * $newConcatTm[3];
                    $tempMatrix[2] = (float) $concatTm[2] * $newConcatTm[0] + (float) $concatTm[3] * $newConcatTm[2];
                    $tempMatrix[3] = (float) $concatTm[2] * $newConcatTm[1] + (float) $concatTm[3] * $newConcatTm[3];
                    $tempMatrix[4] = (float) $concatTm[4] * $newConcatTm[0] + (float) $concatTm[5] * $newConcatTm[2] + $newConcatTm[4];
                    $tempMatrix[5] = (float) $concatTm[4] * $newConcatTm[1] + (float) $concatTm[5] * $newConcatTm[3] + $newConcatTm[5];
                    $concatTm = $tempMatrix;
                    break;

                case 'TL':
                    $Tl = $command['v'] * (float) $Tm[3];
                    break;

                case 'Td':
                    $Tx += $command['x'] * (float) $Tm[0];
                    $Ty += $command['y'] * (float) $Tm[3];
                    $Tm[4] = (string) $Tx;
                    $Tm[5] = (string) $Ty;
                    break;

                case 'TD':
                    $Tl = -($command['y'] * (float) $Tm[3]);
                    $Tx += $command['x'] * (float) $Tm[0];
                    $Ty += $command['y'] * (float) $Tm[3];
                    $Tm[4] = (string) $Tx;
                    $Tm[5] = (string) $Ty;
                    break;

                case 'Tm':
                    $rawTm = $command['args'];
                    $tempMatrix = [];
                    $tempMatrix[0] = $rawTm[0] * (float) $concatTm[0] + $rawTm[1] * (float) $concatTm[2];
                    $tempMatrix[1] = $rawTm[0] * (float) $concatTm[1] + $rawTm[1] * (float) $concatTm[3];
                    $tempMatrix[2] = $rawTm[2] * (float) $concatTm[0] + $rawTm[3] * (float) $concatTm[2];
                    $tempMatrix[3] = $rawTm[2] * (float) $concatTm[1] + $rawTm[3] * (float) $concatTm[3];
                    $tempMatrix[4] = $rawTm[4] * (float) $concatTm[0] + $rawTm[5] * (float) $concatTm[2] + (float) $concatTm[4];
                    $tempMatrix[5] = $rawTm[4] * (float) $concatTm[1] + $rawTm[5] * (float) $concatTm[3] + (float) $concatTm[5];
                    $Tm = $tempMatrix;
                    $Tx = (float) $Tm[4];
                    $Ty = (float) $Tm[5];
                    break;

                case 'T*':
                    $Ty -= $Tl;
                    $Tm[5] = (string) $Ty;
                    break;

                case 'Tf':
                    $fontId = $command['font'];
                    $fontSize = $command['size'];
                    if (null !== $page) {
                        $newFont = $page->getFont($fontId);
                        if (null !== $newFont) {
                            $currentFont = $newFont;
                        }
                    }
                    break;

                case 'q':
                    $graphicsStatesStack[] = $concatTm;
                    $fontStack[] = [$currentFont, $fontId, $fontSize];
                    break;

                case 'Q':
                    if ([] !== $graphicsStatesStack) {
                        $concatTm = array_pop($graphicsStatesStack);
                    }
                    if ([] !== $fontStack) {
                        [$currentFont, $fontId, $fontSize] = array_pop($fontStack);
                    }
                    break;

                case 'BDC':
                    if (isset($command['dict']) && \is_string($command['dict'])) {
                        $dict = $this->parseDictionary($command['dict']);
                        if (isset($dict['ActualText']) && \is_string($dict['ActualText']) && '' !== $dict['ActualText']) {
                            if ('[' === $dict['ActualText'][0]) {
                                $markedStack[] = [
                                    'ActualText' => [
                                        'o' => 'TJ',
                                        'c' => ContentStreamLexer::parseTjArray(trim($dict['ActualText'], '[]')),
                                    ],
                                ];
                            } elseif ('<' === $dict['ActualText'][0] || '(' === $dict['ActualText'][0]) {
                                $atCmds = $this->getCommandsText($dict['ActualText'].'Tj');
                                if (isset($atCmds[0])) {
                                    $markedStack[] = ['ActualText' => $atCmds[0]];
                                }
                            }
                        }
                    }
                    break;

                case 'EMC':
                    if ([] !== $markedStack) {
                        $marked = array_pop($markedStack);
                        if (isset($marked['ActualText'])) {
                            $command = $marked['ActualText'];
                        } else {
                            break;
                        }
                    } else {
                        break;
                    }
                    if ('Tj' !== $command['o'] && 'TJ' !== $command['o']) {
                        break;
                    }
                    // no break
                case "'":
                case '"':
                    if ("'" === $command['o'] || '"' === $command['o']) {
                        $Ty -= $Tl;
                        $Tm[5] = (string) $Ty;
                    }
                    // no break
                case 'Tj':
                    $command[self::COMMAND] = [$command];
                    // no break
                case 'TJ':
                    $hasPendingActualText = false;
                    foreach ($markedStack as $m) {
                        if (isset($m['ActualText'])) {
                            $hasPendingActualText = true;
                            break;
                        }
                    }
                    if ($hasPendingActualText) {
                        break;
                    }

                    $factorX = -(float) $fontSize * (float) $Tm[0] - (float) $fontSize * (float) $Tm[2];
                    $decoded = $this->getTJUsingFontFallback($currentFont, $command[self::COMMAND], $page, $factorX);
                    $decoded = str_replace(["\r", "\n"], '', $decoded);
                    if (\strlen($decoded) >= 2 && (' ' === $decoded[0] || "\t" === $decoded[0]) && (' ' === $decoded[1] || "\t" === $decoded[1])) {
                        $decoded = ' '.substr($decoded, 2);
                    }

                    $entry = [$Tm, $decoded];
                    if ($includeFontInfo && ('Tj' === $command['o'] || 'TJ' === $command['o'])) {
                        $entry[] = $fontId;
                        $entry[] = $fontSize;
                    }
                    $extractedData[] = $entry;
                    break;

                default:
                    break;
            }
        }

        return $extractedData;
    }

    /**
     * getCommandsText() expects the content of $text_part to be an
     * already formatted, single-line command from a document stream.
     * The companion function getSectionsText() returns a document
     * stream as an array of single commands for just this purpose.
     * Because of this, the argument $offset is no longer used, and
     * may be removed in a future PdfParser release.
     *
     * A better name for this function would be getCommandText()
     * since it now always works on just one command.
     */
    public function getCommandsText(string $text_part, int &$offset = 0): array
    {
        $commands = $matches = [];

        preg_match('/^(([\/\[\(<])?.*)(?<!\w)([a-z01\'\"*]+)$/i', $text_part, $matches);

        // If no valid command is detected, return an empty array
        if (!isset($matches[1], $matches[2], $matches[3])) {
            return [];
        }

        $type = $matches[2];
        $operator = $matches[3];
        $command = trim($matches[1]);

        if ('TJ' === $operator) {
            $command = ContentStreamLexer::parseTjArray(trim($command, '[]'));
        } elseif ('Tj' === $operator || "'" === $operator || '"' === $operator) {
            // Depending on the string type, trim the data of the
            // appropriate delimiters
            if ('(' === $type) {
                // Don't use trim() here since a () string may end with
                // a balanced or escaped right parentheses, and trim()
                // will delete both. Both strings below are valid:
                //   eg. (String())
                //   eg. (String\))
                $command = (string) preg_replace('/^\(|\)$/', '', $command);
            } elseif ('<' === $type) {
                $command = trim($command, '<>');
            }
        } elseif ('/' === $type) {
            $command = substr($command, 1);
        }

        $commands[] = [
            self::TYPE => $type,
            self::OPERATOR => $operator,
            self::COMMAND => $command,
        ];

        return $commands;
    }

    public static function factory(
        Document $document,
        Header $header,
        ?string $content,
        ?Config $config = null,
    ): self {
        return match ($header->get('Type')->getContent()) {
            'XObject' => self::factoryXObject($document, $header, $content, $config),
            'Pages' => new Pages($document, $header, $content, $config),
            'Page' => new Page($document, $header, $content, $config),
            'Encoding' => new Encoding($document, $header, $content, $config),
            'Font' => self::factoryFont($document, $header, $content, $config),
            default => !$header->has('Type')
                // ISO 32000-1:2008, Tables 89 & 95: /Type /XObject is optional on Form and Image XObjects
                ? self::factoryXObject($document, $header, $content, $config)
                : new self($document, $header, $content, $config),
        };
    }

    private static function factoryXObject(
        Document $document,
        Header $header,
        ?string $content,
        ?Config $config,
    ): self {
        return match ($header->get('Subtype')->getContent()) {
            'Image' => new Image(
                $document,
                $header,
                (null === $config || $config->getRetainImageContent()) ? $content : null,
                $config
            ),
            'Form' => new Form($document, $header, $content, $config),
            default => new self($document, $header, $content, $config),
        };
    }

    private static function factoryFont(
        Document $document,
        Header $header,
        ?string $content,
        ?Config $config,
    ): Font {
        $subtype = $header->get('Subtype')->getContent();
        if (\is_string($subtype) && '' !== $subtype) {
            $classname = '\\Smalot\\PdfParser\\Font\\Font'.$subtype;
            if (class_exists($classname)) {
                return new $classname($document, $header, $content, $config);
            }
        }

        return new Font($document, $header, $content, $config);
    }

    /**
     * Returns unique id identifying the object.
     */
    protected function getUniqueId(): string
    {
        return spl_object_hash($this);
    }
}
