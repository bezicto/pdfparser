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

use Smalot\PdfParser\Encoding\PDFDocEncoding;
use Smalot\PdfParser\Exception\MissingCatalogException;

/**
 * Technical references :
 * - http://www.mactech.com/articles/mactech/Vol.15/15.09/PDFIntro/index.html
 * - http://framework.zend.com/issues/secure/attachment/12512/Pdf.php
 * - http://www.php.net/manual/en/ref.pdf.php#74211
 * - http://cpansearch.perl.org/src/JV/PostScript-Font-1.10.02/lib/PostScript/ISOLatin1Encoding.pm
 * - http://cpansearch.perl.org/src/JV/PostScript-Font-1.10.02/lib/PostScript/ISOLatin9Encoding.pm
 * - http://cpansearch.perl.org/src/JV/PostScript-Font-1.10.02/lib/PostScript/StandardEncoding.pm
 * - http://cpansearch.perl.org/src/JV/PostScript-Font-1.10.02/lib/PostScript/WinAnsiEncoding.pm
 *
 * Class Document
 */
class Document
{
    /**
     * @var PDFObject[]
     */
    protected array $objects = [];

    protected array $dictionary = [];

    protected Header $trailer;

    /**
     * @var array<mixed>
     */
    protected array $metadata = [];

    protected ?array $details = null;

    /**
     * Whether document-level XMP metadata objects have already been scanned into $this->metadata.
     */
    private bool $xmpLoaded = false;

    public function __construct()
    {
        $this->trailer = new Header([], $this);
    }

    public function init(): void
    {
        $this->details = null;
        $this->xmpLoaded = false;

        $this->buildDictionary();

        // Only Encoding objects require eager init(); Font loads its ToUnicode CMap lazily,
        // and Element/PDFObject::init() are no-ops.
        foreach ($this->getObjectsByType('Encoding') as $encoding) {
            $encoding->init();
        }
    }

    /**
     * Build dictionary based on type header field.
     */
    protected function buildDictionary(): void
    {
        // Build dictionary.
        $this->dictionary = [];

        foreach ($this->objects as $id => $object) {
            // Cache objects by type and subtype
            $type = $object->getHeader()->get('Type')->getContent();

            if ((\is_string($type) || \is_int($type)) && '' !== $type) {
                if (!isset($this->dictionary[$type])) {
                    $this->dictionary[$type] = [
                        'all' => [],
                        'subtype' => [],
                    ];
                }

                $this->dictionary[$type]['all'][$id] = $object;

                $subtype = $object->getHeader()->get('Subtype')->getContent();
                if ((\is_string($subtype) || \is_int($subtype)) && '' !== $subtype) {
                    if (!isset($this->dictionary[$type]['subtype'][$subtype])) {
                        $this->dictionary[$type]['subtype'][$subtype] = [];
                    }
                    $this->dictionary[$type]['subtype'][$subtype][$id] = $object;
                }
            }
        }
    }

    /**
     * Build details array.
     */
    protected function buildDetails(): void
    {
        // Build details array.
        $details = [];

        // Extract document info
        if ($this->trailer->has('Info')) {
            /** @var PDFObject $info */
            $info = $this->trailer->get('Info');
            // This could be an ElementMissing object, so we need to check for
            // the getHeader method first.
            if (method_exists($info, 'getHeader')) {
                $details = $info->getHeader()->getDetails();
            }
        }

        // Retrieve the page count
        try {
            $pages = $this->getPages();
            $details['Pages'] = \count($pages);
        } catch (\Exception) {
            $details['Pages'] = 0;
        }

        // Decode and repair encoded document properties
        foreach ($details as $key => $value) {
            if (\is_string($value)) {
                // If the string is already UTF-8 encoded, that means we only
                // need to repair Adobe's ham-fisted insertion of line-feeds
                // every ~127 characters, which doesn't seem to be multi-byte
                // safe
                if (mb_check_encoding($value, 'UTF-8')) {
                    // Remove literal backslash + line-feed "\\r"
                    $value = str_replace("\x5c\x0d", '', $value);

                    // Remove backslash plus bytes written into high part of
                    // multibyte unicode character
                    $value = (string) preg_replace_callback(
                        "/\x5c\x5c\xe0([\xb4-\xb8])(.)/",
                        static function (array $match): string {
                            $diff = (\ord($match[1]) - 182) * 64;

                            return PDFDocEncoding::convertPDFDoc2UTF8(\chr((\ord($match[2]) + $diff) & 0xFF));
                        },
                        $value
                    );

                    // Remove bytes written into low part of multibyte unicode
                    // character
                    $value = (string) preg_replace_callback(
                        "/(.)\x9c\xe0([\xb3-\xb7])/",
                        static function (array $match): string {
                            $diff = \ord($match[2]) - 181;

                            return \chr((\ord($match[1]) + $diff) & 0xFF);
                        },
                        $value
                    );

                    // Remove this byte string that Adobe occasionally adds
                    // between two single byte characters in a unicode string
                    $value = str_replace("\xe5\xb0\x8d", '', $value);

                    $details[$key] = $value;
                } else {
                    // If the string is just PDFDocEncoding, remove any line-feeds
                    // and decode the whole thing.
                    $value = str_replace("\\\r", '', $value);
                    $details[$key] = PDFDocEncoding::convertPDFDoc2UTF8($value);
                }
            }
        }

        // Lazily extract document-level XMP metadata once (Catalog /Metadata first to avoid
        // XML-parsing hundreds of embedded per-image XMP streams).
        if (!$this->xmpLoaded) {
            $this->xmpLoaded = true;
            $catalogXmpFound = false;

            if ($this->hasObjectsByType('Catalog')) {
                $catalog = array_first($this->getObjectsByType('Catalog'));
                if ($catalog instanceof PDFObject && $catalog->has('Metadata')) {
                    $metaObj = $catalog->get('Metadata');
                    if ($metaObj instanceof PDFObject) {
                        $metaContent = $metaObj->getContent();
                        if (\is_string($metaContent) && '' !== $metaContent) {
                            $this->extractXMPMetadata($metaContent);
                            $catalogXmpFound = true;
                        }
                    }
                }
            }

            if (!$catalogXmpFound && $this->hasObjectsByType('Metadata')) {
                foreach ($this->getObjectsByType('Metadata') as $metaObj) {
                    if ($metaObj instanceof PDFObject) {
                        $metaContent = $metaObj->getContent();
                        if (\is_string($metaContent) && '' !== $metaContent) {
                            $this->extractXMPMetadata($metaContent);
                        }
                    }
                }
            }
        }

        $details = array_merge($details, $this->metadata);

        $this->details = $details;
    }

    /**
     * Extract XMP Metadata
     */
    public function extractXMPMetadata(string $content): void
    {
        $xml = xml_parser_create();
        xml_parser_set_option($xml, \XML_OPTION_SKIP_WHITE, true);

        if (1 === xml_parse_into_struct($xml, $content, $values, $index)) {
            /*
             * short overview about the following code parts:
             *
             * The output of xml_parse_into_struct is a single dimensional array (= $values), and the $stack is a last-on,
             * first-off array of pointers to positions in $metadata, while iterating through it, that potentially turn the
             * results into a more intuitive multi-dimensional array. When an "open" XML tag is encountered,
             * we save the current $metadata context in the $stack, then create a child array of $metadata and
             * make that the current $metadata context. When a "close" XML tag is encountered, the operations are
             * reversed: the most recently added $metadata context from $stack (IOW, the parent of the current
             * element) is set as the current $metadata context.
             */
            $metadata = [];
            $stack = [];
            foreach ($values as $val) {
                // Standardize to lowercase
                $val['tag'] = strtolower($val['tag']);

                // Ignore structural x: and rdf: XML elements
                if (str_starts_with($val['tag'], 'x:')) {
                    continue;
                } elseif (str_starts_with($val['tag'], 'rdf:') && 'rdf:li' !== $val['tag']) {
                    continue;
                }

                switch ($val['type']) {
                    case 'open':
                        // Create an array of list items
                        if ('rdf:li' === $val['tag']) {
                            $metadata[] = [];

                            // Move up one level in the stack
                            $stack[\count($stack)] = &$metadata;
                            $metadata = &$metadata[\count($metadata) - 1];
                        } else {
                            // Else create an array of named values
                            $metadata[$val['tag']] = [];

                            // Move up one level in the stack
                            $stack[\count($stack)] = &$metadata;
                            $metadata = &$metadata[$val['tag']];
                        }
                        break;

                    case 'complete':
                        if (isset($val['value'])) {
                            // Assign a value to this list item
                            if ('rdf:li' === $val['tag']) {
                                $metadata[] = $val['value'];

                                // Else assign a value to this property
                            } else {
                                $metadata[$val['tag']] = $val['value'];
                            }
                        }
                        break;

                    case 'close':
                        // If the value of this property is an array
                        if (\is_array($metadata)) {
                            // If the value is a single element array
                            // where the element is of type string, use
                            // the value of the first list item as the
                            // value for this property
                            if (1 === \count($metadata) && isset($metadata[0]) && \is_string($metadata[0])) {
                                $metadata = $metadata[0];
                            } elseif ([] === $metadata) {
                                // if the value is an empty array, set
                                // the value of this property to the empty
                                // string
                                $metadata = '';
                            }
                        }

                        // Move down one level in the stack
                        $stackDepth = \count($stack);
                        if ($stackDepth > 0) {
                            $metadata = &$stack[$stackDepth - 1];
                            unset($stack[$stackDepth - 1]);
                        }
                        break;
                }
            }

            // Only use this metadata if it's referring to a PDF
            if (!isset($metadata['dc:format']) || 'application/pdf' === $metadata['dc:format']) {
                // According to the XMP specifications: 'Conflict resolution
                // for separate packets that describe the same resource is
                // beyond the scope of this document.' - Section 6.1
                // Source: https://www.adobe.com/devnet/xmp.html
                // Source: https://github.com/adobe/XMP-Toolkit-SDK/blob/main/docs/XMPSpecificationPart1.pdf
                // So if there are multiple XMP blocks, just merge the values
                // of each found block over top of the existing values
                $this->metadata = array_merge($this->metadata, $metadata);
                $this->details = null;
            }
        }
    }

    public function getDictionary(): array
    {
        return $this->dictionary;
    }

    /**
     * @param PDFObject[] $objects
     */
    public function setObjects(array $objects = []): void
    {
        $this->objects = $objects;

        $this->init();
    }

    /**
     * @return PDFObject[]
     */
    public function getObjects(): array
    {
        return $this->objects;
    }

    public function getObjectById(string $id): PDFObject|Element|null
    {
        return $this->objects[$id] ?? null;
    }

    public function hasObjectsByType(string $type, ?string $subtype = null): bool
    {
        return [] !== $this->getObjectsByType($type, $subtype);
    }

    public function getObjectsByType(string $type, ?string $subtype = null): array
    {
        if (!isset($this->dictionary[$type])) {
            return [];
        }

        if (null !== $subtype && '' !== $subtype) {
            return $this->dictionary[$type]['subtype'][$subtype] ?? [];
        }

        return $this->dictionary[$type]['all'];
    }

    /**
     * @return Font[]
     */
    public function getFonts(): array
    {
        return $this->getObjectsByType('Font');
    }

    public function getFirstFont(): ?Font
    {
        return array_first($this->getFonts());
    }

    /**
     * @return Page[]
     *
     * @throws MissingCatalogException
     */
    public function getPages(): array
    {
        if ($this->hasObjectsByType('Catalog')) {
            // Search for catalog to list pages.
            $catalogue = array_first($this->getObjectsByType('Catalog'));

            /** @var Pages $object */
            $object = $catalogue->get('Pages');
            if (method_exists($object, 'getPages')) {
                return $object->getPages(true);
            }
        }

        if ($this->hasObjectsByType('Pages')) {
            // Search for pages to list kids.
            $pages = [];

            /** @var Pages[] $objects */
            $objects = $this->getObjectsByType('Pages');
            foreach ($objects as $object) {
                $pages = array_merge($pages, $object->getPages(true));
            }

            return $pages;
        }

        if ($this->hasObjectsByType('Page')) {
            // Search for 'page' (unordered pages).
            $pages = $this->getObjectsByType('Page');

            return array_values($pages);
        }

        throw new MissingCatalogException('Missing catalog.');
    }

    public function getText(?int $pageLimit = null): string
    {
        $texts = [];
        $pages = $this->getPages();

        // Only use the first X number of pages if $pageLimit is set and positive.
        if (null !== $pageLimit && 0 < $pageLimit) {
            $pages = \array_slice($pages, 0, $pageLimit);
        }

        foreach ($pages as $page) {
            /**
             * In some cases, the $page variable may be null.
             */
            if (null === $page) {
                continue;
            }
            if ($text = trim($page->getText())) {
                $texts[] = $text;
            }
        }

        return implode("\n\n", $texts);
    }

    public function getTrailer(): Header
    {
        return $this->trailer;
    }

    public function setTrailer(Header $trailer): void
    {
        $this->trailer = $trailer;
    }

    public function getDetails(): array
    {
        if (null === $this->details) {
            $this->buildDetails();
        }

        return $this->details;
    }
}
