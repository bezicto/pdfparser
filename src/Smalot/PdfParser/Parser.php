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

use Smalot\PdfParser\Element\ElementArray;
use Smalot\PdfParser\Element\ElementBoolean;
use Smalot\PdfParser\Element\ElementDate;
use Smalot\PdfParser\Element\ElementHexa;
use Smalot\PdfParser\Element\ElementName;
use Smalot\PdfParser\Element\ElementNull;
use Smalot\PdfParser\Element\ElementNumeric;
use Smalot\PdfParser\Element\ElementString;
use Smalot\PdfParser\Element\ElementXRef;
use Smalot\PdfParser\RawData\LazyStream;
use Smalot\PdfParser\RawData\RawDataParser;
use Smalot\PdfParser\XObject\Image;

/**
 * Class Parser
 */
class Parser
{
    /**
     * @var Config
     */
    private $config;

    /**
     * @var PDFObject[]
     */
    protected $objects = [];

    protected $rawDataParser;

    public function __construct($cfg = [], ?Config $config = null)
    {
        $this->config = $config ?: new Config();
        $this->rawDataParser = new RawDataParser($cfg, $this->config);
    }

    public function getConfig(): Config
    {
        return $this->config;
    }

    /**
     * @throws \Exception
     */
    public function parseFile(string $filename): Document
    {
        if (!is_file($filename) || !is_readable($filename)) {
            throw new \Exception('Unable to read file: '.$filename);
        }

        $content = @file_get_contents($filename);
        if (false === $content) {
            throw new \Exception('Unable to read file: '.$filename);
        }

        // parseContent() holds the only reference to the file content, which
        // enables it to release the content.
        return $this->parseContent($content);
    }

    /**
     * @param string $content PDF content to parse
     *
     * @throws \Exception if secured PDF file was detected
     * @throws \Exception if no object list was found
     */
    public function parseContent(string $content): Document
    {
        // Normalize the raw data and decode the cross-reference/trailer table.
        list($xref, $pdfData) = $this->rawDataParser->parseHeaderAndXref($content);

        // $pdfData is a copy of $content, if $content had to be normalized (bytes
        // in front of the header, line endings). $content is released to avoid
        // holding both while the objects are parsed.
        unset($content);

        if (isset($xref['trailer']['encrypt']) && false === $this->config->getIgnoreEncryption()) {
            throw new \Exception('Secured pdf file are currently not supported.');
        }

        // Create destination object.
        $document = new Document();
        $this->objects = [];

        // The raw objects are decoded one at a time with lazy stream decompression.
        // Only structural streams (/ObjStm, /XRef) are decompressed during parsing;
        // page/font/image/metadata streams are decompressed on-demand when accessed.
        foreach ($this->rawDataParser->iterateIndirectObjects($pdfData, $xref, true) as $id => $structure) {
            $this->parseObject($id, $structure, $document);
        }

        if (empty($this->objects)) {
            throw new \Exception('Object list not found. Possible secured file.');
        }

        $document->setTrailer($this->parseTrailer($xref['trailer'], $document));
        $document->setObjects($this->objects);

        return $document;
    }

    protected function parseTrailer(array $structure, ?Document $document)
    {
        $trailer = [];

        foreach ($structure as $name => $values) {
            $name = ucfirst($name);

            if (is_numeric($values)) {
                $trailer[$name] = new ElementNumeric($values);
            } elseif (\is_array($values)) {
                $value = $this->parseTrailer($values, null);
                $trailer[$name] = new ElementArray($value, null);
            } elseif (false !== strpos($values, '_')) {
                $trailer[$name] = new ElementXRef($values, $document);
            } else {
                $trailer[$name] = $this->parseHeaderElement('(', $values, $document);
            }
        }

        return new Header($trailer, $document);
    }

    protected function parseObject(string $id, array $structure, ?Document $document)
    {
        $header = new Header([], $document);
        $content = '';
        $lazyStream = null;

        foreach ($structure as $position => $part) {
            if (\is_int($part)) {
                $part = [null, null];
            }
            switch ($part[0]) {
                case '[':
                    $elements = [];

                    foreach ($part[1] as $sub_element) {
                        $sub_type = $sub_element[0];
                        $sub_value = $sub_element[1];
                        $elements[] = $this->parseHeaderElement($sub_type, $sub_value, $document);
                    }

                    $header = new Header($elements, $document);
                    break;

                case '<<':
                    $header = $this->parseHeader($part[1], $document);
                    break;

                case 'stream':
                    $payload = isset($part[3][0]) ? $part[3][0] : $part[1];
                    if ($payload instanceof LazyStream) {
                        if ($header->get('Type')->equals('ObjStm')) {
                            $content = $payload->decode();
                        } else {
                            $lazyStream = $payload;
                            $content = '';
                        }
                    } else {
                        $content = (string) $payload;
                    }

                    if ($header->get('Type')->equals('ObjStm')) {
                        list($objectNumbers, $byteOffsets, $firstObjectOffset) = $this->getObjectStreamIndex($header, $content);
                        $content = substr($content, $firstObjectOffset);
                        $table = [];

                        foreach ($byteOffsets as $key => $byteOffset) {
                            // A byte offset with as many digits as PHP_INT_MAX or more
                            // is located behind the content in any case
                            $position = \strlen($byteOffset) < \strlen((string) \PHP_INT_MAX) ? (int) $byteOffset : \PHP_INT_MAX;
                            $table[$position] = $objectNumbers[$key];
                        }

                        ksort($table);

                        $ids = array_values($table);
                        $positions = array_keys($table);
                        $contentLen = \strlen($content);

                        foreach ($positions as $index => $position) {
                            $subId = $ids[$index].'_0';
                            if (isset($this->objects[$subId])) {
                                // A newer incremental revision already defined this object
                                continue;
                            }
                            $next_position = isset($positions[$index + 1]) ? $positions[$index + 1] : $contentLen;
                            $sub_header = $position < $contentLen
                                ? $this->parseObjectStreamEntry($content, (int) $position, (int) $next_position, $document)
                                : new Header([], $document);

                            $object = PDFObject::factory($document, $sub_header, '', $this->config);
                            $this->objects[$subId] = $object;
                        }

                        // It is not necessary to store this content.

                        return;
                    }
                    break;

                default:
                    if ('null' != $part) {
                        $element = $this->parseHeaderElement($part[0], $part[1], $document);

                        if ($element) {
                            $header = new Header([$element], $document);
                        }
                    }
                    break;
            }
        }

        if (!isset($this->objects[$id])) {
            $object = PDFObject::factory($document, $header, $content, $this->config);
            if (null !== $lazyStream && !($object instanceof Image && false === $this->config->getRetainImageContent())) {
                $object->setLazyStream($lazyStream);
            }
            $this->objects[$id] = $object;
        }
    }

    /**
     * Parse an embedded object entry from an /ObjStm body using the fast byte-level RawDataParser
     * instead of the legacy regex Header::parse() parser.
     */
    private function parseObjectStreamEntry(string $streamBody, int $start, int $end, ?Document $document): Header
    {
        $slice = substr($streamBody, $start, max(0, $end - $start));
        if ('' === $slice) {
            return new Header([], $document);
        }

        $raw = $this->rawDataParser->parseObjectFromString($slice, 0);
        if ('<<' === $raw[0] && \is_array($raw[1])) {
            return $this->parseHeader($raw[1], $document);
        }

        if ('[' === $raw[0] && \is_array($raw[1])) {
            $elements = [];
            foreach ($raw[1] as $sub) {
                $elements[] = $this->parseHeaderElement($sub[0], $sub[1], $document);
            }

            return new Header($elements, $document);
        }

        return new Header([], $document);
    }

    /**
     * Provides the index of an object stream and the byte offset of its first
     * object.
     *
     * The index consists of pairs of integers: the number of an object and its
     * byte offset relative to the first object. The index is expected to end
     * with the last pair, which directly follows another pair.
     *
     * Integer objects behind the index look like pairs. /First, the byte offset
     * of the first object, tells them apart. It is used, if it is located
     * between two pairs and the number of pairs in front of it equals /N (as
     * far as /N is usable).
     *
     * @return array{0: array<int, string>, 1: array<int, string>, 2: int} [$objectNumbers, $byteOffsets, $firstObjectOffset]
     *
     * @see https://opensource.adobe.com/dc-acrobat-sdk-docs/pdfstandards/PDF32000_2008.pdf#page=53 ISO 32000-1:2008, 7.5.7 (object streams)
     * @see https://opensource.adobe.com/dc-acrobat-sdk-docs/pdfstandards/PDF32000_2008.pdf#page=54 ISO 32000-1:2008, 7.5.7, Table 16 (N, First)
     */
    private function getObjectStreamIndex(Header $header, string $content): array
    {
        // \G lets a pair start where the previous one ends. White-space is \s
        // and the null character.
        $pattern = '/\G[\s\0]*(\d+)[\s\0]+(\d+)[\s\0]*/';
        preg_match_all($pattern, $content, $index);
        $length = \strlen(implode('', $index[0]));

        $first = $this->getObjectStreamInteger($header, 'First', $length - 1);

        if (
            null === $first
            // only white-space between /First and the end of the pairs
            || false === strpbrk(substr($content, $first, $length - $first), '0123456789')
            // /First is located inside of a number
            || (0 < $first && 2 === strspn($content, '0123456789', $first - 1, 2))
        ) {
            return [$index[1], $index[2], $length];
        }

        preg_match_all($pattern, substr($content, 0, $first), $indexInFrontOfFirst);
        $numberOfObjects = $this->getObjectStreamInteger($header, 'N', \strlen($content));

        if (
            $first !== \strlen(implode('', $indexInFrontOfFirst[0]))
            || (null !== $numberOfObjects && \count($indexInFrontOfFirst[0]) !== $numberOfObjects)
        ) {
            return [$index[1], $index[2], $length];
        }

        return [$indexInFrontOfFirst[1], $indexInFrontOfFirst[2], $first];
    }

    /**
     * Provides an integer entry of the dictionary of an object stream. Null is
     * returned, if the entry is missing, an indirect reference or outside of
     * the range from 0 to $max.
     *
     * @see https://opensource.adobe.com/dc-acrobat-sdk-docs/pdfstandards/PDF32000_2008.pdf#page=54 ISO 32000-1:2008, 7.5.7, Table 16 (N, First)
     */
    private function getObjectStreamInteger(Header $header, string $name, int $max): ?int
    {
        $element = $header->get($name);

        if (!$element instanceof ElementNumeric) {
            return null;
        }

        $value = $element->getContent();

        return 0 <= $value && $value <= $max ? (int) $value : null;
    }

    /**
     * @throws \Exception
     */
    protected function parseHeader(array $structure, ?Document $document): Header
    {
        $elements = [];
        $count = \count($structure);

        for ($position = 0; $position + 1 < $count; $position += 2) {
            $name = $structure[$position][1];
            $type = $structure[$position + 1][0];
            $value = $structure[$position + 1][1];

            $elements[$name] = $this->parseHeaderElement($type, $value, $document);
        }

        return new Header($elements, $document);
    }

    /**
     * @param string|array $value
     *
     * @return Element|Header|null
     *
     * @throws \Exception
     */
    protected function parseHeaderElement(?string $type, $value, ?Document $document)
    {
        $valueIsEmpty = null == $value || '' == $value || false == $value;
        if (('<<' === $type || '>>' === $type) && $valueIsEmpty) {
            $value = [];
        }

        switch ($type) {
            case '<<':
            case '>>':
                return $this->parseHeader($value, $document);

            case 'numeric':
                return new ElementNumeric($value);

            case 'boolean':
                return new ElementBoolean($value);

            case 'null':
                return new ElementNull();

            case '(':
                if ($date = ElementDate::parse('('.$value.')', $document)) {
                    return $date;
                }

                return ElementString::parse('('.$value.')', $document);

            case '<':
                return $this->parseHeaderElement('(', ElementHexa::decode($value), $document);

            case '/':
                return ElementName::parse('/'.$value, $document);

            case 'ojbref': // old mistake in tcpdf parser
            case 'objref':
                return new ElementXRef($value, $document);

            case '[':
                $values = [];

                if (\is_array($value)) {
                    foreach ($value as $sub_element) {
                        $sub_type = $sub_element[0];
                        $sub_value = $sub_element[1];
                        $values[] = $this->parseHeaderElement($sub_type, $sub_value, $document);
                    }
                }

                return new ElementArray($values, $document);

            case 'endstream':
            case 'obj': // I don't know what it means but got my project fixed.
            case '':
                // Nothing to do with.
                return null;

            default:
                throw new \Exception('Invalid type: "'.$type.'".');
        }
    }
}
