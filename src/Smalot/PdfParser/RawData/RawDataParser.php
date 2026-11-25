<?php

/**
 * This file is based on code of tecnickcom/TCPDF PDF library.
 *
 * Original author Nicola Asuni (info@tecnick.com) and
 * contributors (https://github.com/tecnickcom/TCPDF/graphs/contributors).
 *
 * @see https://github.com/tecnickcom/TCPDF
 *
 * Original code was licensed on the terms of the LGPL v3.
 *
 * ------------------------------------------------------------------------------
 *
 * @file This file is part of the PdfParser library.
 *
 * @author  Konrad Abicht <k.abicht@gmail.com>
 *
 * @date    2020-01-06
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

namespace Smalot\PdfParser\RawData;

use Smalot\PdfParser\Config;
use Smalot\PdfParser\Exception\EmptyPdfException;
use Smalot\PdfParser\Exception\MissingPdfHeaderException;

/**
 * Reads the low-level structure of a PDF file: header, cross-reference data,
 * trailer and the raw indirect objects. It is used by Parser only.
 *
 * @internal
 */
class RawDataParser
{
    /**
     * @var Config
     */
    private $config;

    /**
     * Configuration array.
     *
     * @var array<string,bool>
     */
    protected $cfg = [
        // if `true` ignore filter decoding errors
        'ignore_filter_decoding_errors' => true,
        // if `true` ignore missing filter decoding errors
        'ignore_missing_filter_decoders' => true,
    ];

    protected $filterHelper;
    protected $objects;

    /**
     * Whether getIndirectObject() should wrap non-structural streams in LazyStream
     * for on-demand decompression.
     *
     * @var bool
     */
    private $lazyStreams = false;

    /**
     * Object numbers explicitly marked free ('f' / type 0) in a newer xref revision,
     * so older /Prev xref tables cannot resurrect deleted objects.
     *
     * @var array<int, bool>
     */
    protected $freedObjects = [];

    /**
     * @param array $cfg Configuration array, default is []
     */
    public function __construct($cfg = [], ?Config $config = null)
    {
        // merge given array with default values
        $this->cfg = array_merge($this->cfg, $cfg);

        $this->filterHelper = new FilterHelper();
        $this->config = $config ?: new Config();
    }

    /**
     * Decode the specified stream.
     *
     * @param string $pdfData PDF data
     * @param array  $sdic    Stream's dictionary array
     * @param string $stream  Stream to decode
     *
     * @return array containing decoded stream data and remaining filters
     *
     * @throws \Exception
     */
    protected function decodeStream(string $pdfData, array $xref, array $sdic, string $stream): array
    {
        list($stream, $filters, $decodeParmsList) = $this->prepareStreamDecode($pdfData, $xref, $sdic, $stream);
        if ('' === $stream) {
            return ['', []];
        }

        // decode the stream
        $remaining_filters = [];
        $availableFilters = $this->filterHelper->getAvailableFilters();
        foreach ($filters as $idx => $filter) {
            if (\in_array($filter, $availableFilters, true)) {
                $parms = $decodeParmsList[$idx] ?? [];
                try {
                    $stream = $this->filterHelper->decodeFilter($filter, $stream, $this->config->getDecodeMemoryLimit(), $parms);
                } catch (\Exception $e) {
                    $emsg = $e->getMessage();
                    if ((('~' == ($emsg[0] ?? '')) && !$this->cfg['ignore_missing_filter_decoders'])
                        || (('~' != ($emsg[0] ?? '')) && !$this->cfg['ignore_filter_decoding_errors'])
                    ) {
                        throw new \Exception($emsg);
                    }
                }
            } else {
                // add missing filter to array
                $remaining_filters[] = $filter;
            }
        }

        return [$stream, $remaining_filters];
    }

    /**
     * Prepare a lazy stream handle (or return uncompressed bytes directly if no supported filters apply).
     *
     * @return array{0: string|LazyStream, 1: array<int, string>}
     */
    protected function buildLazyStream(string $pdfData, array $xref, array $sdic, string $stream): array
    {
        list($stream, $filters, $decodeParmsList) = $this->prepareStreamDecode($pdfData, $xref, $sdic, $stream);
        if ('' === $stream) {
            return ['', []];
        }

        $availableFilters = $this->filterHelper->getAvailableFilters();
        $hasSupportedFilter = false;
        $remainingFilters = [];
        foreach ($filters as $filter) {
            if (\in_array($filter, $availableFilters, true)) {
                $hasSupportedFilter = true;
            } else {
                $remainingFilters[] = $filter;
            }
        }

        if (!$hasSupportedFilter) {
            return [$stream, $remainingFilters];
        }

        $lazy = new LazyStream(
            $stream,
            $filters,
            $decodeParmsList,
            $this->filterHelper,
            $this->config->getDecodeMemoryLimit(),
            (bool) $this->cfg['ignore_filter_decoding_errors'],
            (bool) $this->cfg['ignore_missing_filter_decoders']
        );

        return [$lazy, $remainingFilters];
    }

    /**
     * Trim raw stream by declared /Length and extract /Filter list and /DecodeParms list.
     *
     * @return array{0: string, 1: array<int, string>, 2: array<int, array<string, int>>}
     */
    private function prepareStreamDecode(string $pdfData, array $xref, array $sdic, string $stream): array
    {
        $slength = \strlen($stream);
        if ($slength <= 0) {
            return ['', [], []];
        }
        $filters = [];
        $decodeParmsList = [];
        foreach ($sdic as $k => $v) {
            if ('/' == $v[0]) {
                if (('Length' == $v[1]) && isset($sdic[$k + 1])) {
                    $lenVal = $this->getObjectVal($pdfData, $xref, $sdic[$k + 1]);
                    if (\is_array($lenVal) && isset($lenVal[0])) {
                        if (\is_array($lenVal[0]) && isset($lenVal[0][0]) && 'numeric' === $lenVal[0][0]) {
                            $lenVal = $lenVal[0];
                        }
                        if ('numeric' === $lenVal[0]) {
                            $declength = (int) $lenVal[1];
                            if ($declength >= 0 && $declength < $slength) {
                                $stream = substr($stream, 0, $declength);
                                $slength = $declength;
                            }
                        }
                    }
                } elseif (('Filter' == $v[1]) && isset($sdic[$k + 1])) {
                    $objval = $this->resolveRawValue($pdfData, $xref, $sdic[$k + 1]);
                    if ('/' == $objval[0]) {
                        $filters[] = $objval[1];
                    } elseif ('[' == $objval[0]) {
                        foreach ($objval[1] as $flt) {
                            $flt = $this->resolveRawValue($pdfData, $xref, $flt);
                            if ('/' == $flt[0]) {
                                $filters[] = $flt[1];
                            }
                        }
                    }
                } elseif (('DecodeParms' == $v[1]) && isset($sdic[$k + 1])) {
                    $dpVal = $this->resolveRawValue($pdfData, $xref, $sdic[$k + 1]);
                    if ('<<' === $dpVal[0] && \is_array($dpVal[1])) {
                        $decodeParmsList = [$this->extractDecodeParms($pdfData, $xref, $dpVal[1])];
                    } elseif ('[' === $dpVal[0] && \is_array($dpVal[1])) {
                        foreach ($dpVal[1] as $item) {
                            $item = $this->resolveRawValue($pdfData, $xref, $item);
                            if ('<<' === $item[0] && \is_array($item[1])) {
                                $decodeParmsList[] = $this->extractDecodeParms($pdfData, $xref, $item[1]);
                            } else {
                                $decodeParmsList[] = [];
                            }
                        }
                    }
                }
            }
        }

        return [$stream, $filters, $decodeParmsList];
    }

    /**
     * Resolve an indirect raw object reference and unwrap single-element indirect wrappers.
     */
    private function resolveRawValue(string $pdfData, array $xref, array $obj): array
    {
        $resolved = $this->getObjectVal($pdfData, $xref, $obj);
        if (isset($resolved[0]) && \is_array($resolved[0])) {
            return $resolved[0];
        }

        return $resolved;
    }

    /**
     * Extract numeric filter decode parameters (Predictor, Columns, Colors, BitsPerComponent, EarlyChange)
     * from a raw dictionary token list.
     *
     * @return array<string, int>
     */
    private function extractDecodeParms(string $pdfData, array $xref, array $rawDict): array
    {
        $parms = [];
        $count = \count($rawDict);
        for ($i = 0; $i + 1 < $count; ++$i) {
            if ('/' !== $rawDict[$i][0]) {
                continue;
            }
            $key = $rawDict[$i][1];
            if ('Predictor' === $key || 'Columns' === $key || 'Colors' === $key || 'BitsPerComponent' === $key || 'EarlyChange' === $key) {
                $val = $this->resolveRawValue($pdfData, $xref, $rawDict[$i + 1]);
                if ('numeric' === ($val[0] ?? null)) {
                    $parms[$key] = (int) $val[1];
                }
            }
        }

        return $parms;
    }

    /**
     * Decode the Cross-Reference section
     *
     * @param string     $pdfData        PDF data
     * @param int        $startxref      Offset at which the xref section starts (position of the 'xref' keyword)
     * @param array      $xref           Previous xref array (if any)
     * @param array<int> $visitedOffsets Array of visited offsets to prevent infinite loops
     *
     * @return array containing xref and trailer data
     *
     * @throws \Exception
     */
    protected function decodeXref(string $pdfData, int $startxref, array $xref = [], array $visitedOffsets = []): array
    {
        $startxref += 4; // 4 is the length of the word 'xref'
        // skip initial white space chars
        $offset = $startxref + strspn($pdfData, $this->config->getPdfWhitespaces(), $startxref);
        // initialize object number
        $obj_num = 0;
        // search for cross-reference entries or subsection
        while (preg_match('/([0-9]+)[\x20]([0-9]+)[\x20]?([nf]?)(\r\n|[\x20]?[\r\n])/', $pdfData, $matches, \PREG_OFFSET_CAPTURE, $offset) > 0) {
            if ($matches[0][1] != $offset) {
                // we are on another section
                break;
            }
            $offset += \strlen($matches[0][0]);
            if ('n' == $matches[3][0]) {
                // create unique object index: [object number]_[generation number]
                $index = $obj_num.'_'.(int) $matches[2][0];
                // check if object already exists or was freed in a newer revision
                if (!isset($xref['xref'][$index]) && !isset($this->freedObjects[$obj_num])) {
                    // store object offset position
                    $xref['xref'][$index] = (int) $matches[1][0];
                }
                ++$obj_num;
            } elseif ('f' == $matches[3][0]) {
                $this->freedObjects[$obj_num] = true;
                ++$obj_num;
            } else {
                // object number (index)
                $obj_num = (int) $matches[1][0];
            }
        }
        // get trailer data (parse via getRawObject to support nested dictionaries, with regex fallback)
        $trailerParsed = false;
        $prevOffset = 0;
        if (preg_match('/trailer[\s]*/i', $pdfData, $tMatch, \PREG_OFFSET_CAPTURE, $offset) > 0) {
            $dictStart = $tMatch[0][1] + \strlen($tMatch[0][0]);
            if (isset($pdfData[$dictStart + 1]) && '<' === $pdfData[$dictStart] && '<' === $pdfData[$dictStart + 1]) {
                $trailerObj = $this->getRawObject($pdfData, $dictStart);
                if ('<<' === $trailerObj[0] && \is_array($trailerObj[1])) {
                    $trailerParsed = true;
                    $fillTrailer = !isset($xref['trailer']) || empty($xref['trailer']);
                    if ($fillTrailer) {
                        $xref['trailer'] = [];
                    }
                    $tArr = $trailerObj[1];
                    $tCount = \count($tArr);
                    for ($ti = 0; $ti + 1 < $tCount; ++$ti) {
                        if ('/' !== $tArr[$ti][0]) {
                            continue;
                        }
                        $tKey = strtolower($tArr[$ti][1]);
                        $tVal = $tArr[$ti + 1];
                        if ('prev' === $tKey && 'numeric' === $tVal[0]) {
                            $prevOffset = (int) $tVal[1];
                        } elseif ($fillTrailer) {
                            if ('size' === $tKey && 'numeric' === $tVal[0]) {
                                $xref['trailer']['size'] = (int) $tVal[1];
                            } elseif ('root' === $tKey && 'objref' === $tVal[0]) {
                                $xref['trailer']['root'] = $tVal[1];
                            } elseif ('encrypt' === $tKey && ('objref' === $tVal[0] || '<<' === $tVal[0])) {
                                $xref['trailer']['encrypt'] = 'objref' === $tVal[0] ? $tVal[1] : 'inline';
                            } elseif ('info' === $tKey && 'objref' === $tVal[0]) {
                                $xref['trailer']['info'] = $tVal[1];
                            } elseif ('id' === $tKey && '[' === $tVal[0] && isset($tVal[1][0][1], $tVal[1][1][1])) {
                                $xref['trailer']['id'] = [$tVal[1][0][1], $tVal[1][1][1]];
                            }
                        }
                    }
                }
            }
        }

        if ($trailerParsed) {
            if (0 !== $prevOffset) {
                $xref = $this->getXrefData($pdfData, $prevOffset, $xref, $visitedOffsets);
            }
        } elseif (preg_match('/trailer[\s]*<<(.*)>>/isU', $pdfData, $matches, \PREG_OFFSET_CAPTURE, $offset) > 0) {
            $trailer_data = $matches[1][0];
            if (!isset($xref['trailer']) || empty($xref['trailer'])) {
                // get only the last updated version
                $xref['trailer'] = [];
                // parse trailer_data
                if (preg_match('/Size[\s]+([0-9]+)/i', $trailer_data, $matches) > 0) {
                    $xref['trailer']['size'] = (int) $matches[1];
                }
                if (preg_match('/Root[\s]+([0-9]+)[\s]+([0-9]+)[\s]+R/i', $trailer_data, $matches) > 0) {
                    $xref['trailer']['root'] = (int) $matches[1].'_'.(int) $matches[2];
                }
                if (preg_match('/Encrypt[\s]+([0-9]+)[\s]+([0-9]+)[\s]+R/i', $trailer_data, $matches) > 0) {
                    $xref['trailer']['encrypt'] = (int) $matches[1].'_'.(int) $matches[2];
                }
                if (preg_match('/Info[\s]+([0-9]+)[\s]+([0-9]+)[\s]+R/i', $trailer_data, $matches) > 0) {
                    $xref['trailer']['info'] = (int) $matches[1].'_'.(int) $matches[2];
                }
                if (preg_match('/ID[\s]*[\[][\s]*[<]([^>]*)[>][\s]*[<]([^>]*)[>]/i', $trailer_data, $matches) > 0) {
                    $xref['trailer']['id'] = [];
                    $xref['trailer']['id'][0] = $matches[1];
                    $xref['trailer']['id'][1] = $matches[2];
                }
            }
            if (preg_match('/Prev[\s]+([0-9]+)/i', $trailer_data, $matches) > 0) {
                $offset = (int) $matches[1];
                if (0 != $offset) {
                    // get previous xref
                    $xref = $this->getXrefData($pdfData, $offset, $xref, $visitedOffsets);
                }
            }
        } else {
            throw new \Exception('Unable to find trailer');
        }

        return $xref;
    }

    /**
     * Decode the Cross-Reference Stream section
     *
     * @param string     $pdfData        PDF data
     * @param int        $startxref      Offset at which the xref section starts
     * @param array      $xref           Previous xref array (if any)
     * @param array<int> $visitedOffsets Array of visited offsets to prevent infinite loops
     *
     * @return array containing xref and trailer data
     *
     * @throws \Exception if unknown PNG predictor detected
     */
    protected function decodeXrefStream(string $pdfData, int $startxref, array $xref = [], array $visitedOffsets = []): array
    {
        // try to read Cross-Reference Stream
        $xrefobj = $this->getRawObject($pdfData, $startxref);
        $xrefcrs = $this->getIndirectObject($pdfData, $xref, $xrefobj[1], $startxref, true);
        if (!isset($xref['trailer']) || empty($xref['trailer'])) {
            // get only the last updated version
            $xref['trailer'] = [];
            $filltrailer = true;
        } else {
            $filltrailer = false;
        }
        if (!isset($xref['xref'])) {
            $xref['xref'] = [];
        }
        $valid_crs = false;
        $sarr = isset($xrefcrs[0][1]) && \is_array($xrefcrs[0][1]) ? $xrefcrs[0][1] : [];
        $wb = [0, 0, 0];
        $index_blocks = [];

        foreach ($sarr as $k => $v) {
            if (
                ('/' == $v[0])
                && ('Type' == $v[1])
                && (
                    isset($sarr[$k + 1])
                    && '/' == $sarr[$k + 1][0]
                    && 'XRef' == $sarr[$k + 1][1]
                )
            ) {
                $valid_crs = true;
            } elseif (('/' == $v[0]) && ('Index' == $v[1]) && isset($sarr[$k + 1]) && \is_array($sarr[$k + 1][1])) {
                // initialize list for: first object number in the subsection / number of objects
                $index_blocks = [];
                $idxList = $sarr[$k + 1][1];
                $idxCount = \count($idxList);
                for ($m = 0; $m + 1 < $idxCount; $m += 2) {
                    $index_blocks[] = [(int) $idxList[$m][1], (int) $idxList[$m + 1][1]];
                }
            } elseif (('/' == $v[0]) && ('Prev' == $v[1]) && (isset($sarr[$k + 1]) && ('numeric' == $sarr[$k + 1][0]))) {
                // get previous xref offset
                $prevxref = (int) $sarr[$k + 1][1];
            } elseif (('/' == $v[0]) && ('W' == $v[1]) && isset($sarr[$k + 1][1][0][1], $sarr[$k + 1][1][1][1], $sarr[$k + 1][1][2][1])) {
                // number of bytes (in the decoded stream) of the corresponding field
                $wb[0] = (int) $sarr[$k + 1][1][0][1];
                $wb[1] = (int) $sarr[$k + 1][1][1][1];
                $wb[2] = (int) $sarr[$k + 1][1][2][1];
            } elseif ($filltrailer) {
                if (('/' == $v[0]) && ('Size' == $v[1]) && (isset($sarr[$k + 1]) && ('numeric' == $sarr[$k + 1][0]))) {
                    $xref['trailer']['size'] = $sarr[$k + 1][1];
                } elseif (('/' == $v[0]) && ('Root' == $v[1]) && (isset($sarr[$k + 1]) && ('objref' == $sarr[$k + 1][0]))) {
                    $xref['trailer']['root'] = $sarr[$k + 1][1];
                } elseif (('/' == $v[0]) && ('Info' == $v[1]) && (isset($sarr[$k + 1]) && ('objref' == $sarr[$k + 1][0]))) {
                    $xref['trailer']['info'] = $sarr[$k + 1][1];
                } elseif (('/' == $v[0]) && ('Encrypt' == $v[1]) && isset($sarr[$k + 1]) && ('objref' == $sarr[$k + 1][0] || '<<' == $sarr[$k + 1][0])) {
                    $xref['trailer']['encrypt'] = 'objref' == $sarr[$k + 1][0] ? $sarr[$k + 1][1] : 'inline';
                } elseif (('/' == $v[0]) && ('ID' == $v[1]) && isset($sarr[$k + 1][1][0][1], $sarr[$k + 1][1][1][1])) {
                    $xref['trailer']['id'] = [];
                    $xref['trailer']['id'][0] = $sarr[$k + 1][1][0][1];
                    $xref['trailer']['id'][1] = $sarr[$k + 1][1][1][1];
                }
            }
        }

        // Decode binary xref entries directly from the stream (decodeStream() already applied /Filter and /DecodeParms predictor)
        if ($valid_crs && isset($xrefcrs[1][3][0])) {
            $streamBytes = $xrefcrs[1][3][0];
            $streamLen = \strlen($streamBytes);
            $wb0 = $wb[0];
            $wb1 = $wb[1];
            $wb2 = $wb[2];
            $rowLen = $wb0 + $wb1 + $wb2;

            if ($rowLen > 0 && $streamLen > 0) {
                $blockIdx = 0;
                $blockCount = \count($index_blocks);
                $obj_num = $blockCount > 0 ? $index_blocks[0][0] : 0;
                $blockRemaining = $blockCount > 0 ? $index_blocks[0][1] : -1;

                for ($pos = 0; $pos + $rowLen <= $streamLen; $pos += $rowLen) {
                    // Column 0: entry type (defaults to 1 when W[0] == 0 per ISO 32000-1 Table 18)
                    if (0 === $wb0) {
                        $f0 = 1;
                    } else {
                        $f0 = 0;
                        for ($b = 0; $b < $wb0; ++$b) {
                            $f0 = ($f0 << 8) | \ord($streamBytes[$pos + $b]);
                        }
                    }

                    // Column 1: byte offset or object stream number
                    $f1 = 0;
                    $c1Pos = $pos + $wb0;
                    for ($b = 0; $b < $wb1; ++$b) {
                        $f1 = ($f1 << 8) | \ord($streamBytes[$c1Pos + $b]);
                    }

                    // Column 2: generation number or index within object stream
                    $f2 = 0;
                    $c2Pos = $c1Pos + $wb1;
                    for ($b = 0; $b < $wb2; ++$b) {
                        $f2 = ($f2 << 8) | \ord($streamBytes[$c2Pos + $b]);
                    }

                    switch ($f0) {
                        case 0: // (f) free object
                            $this->freedObjects[$obj_num] = true;
                            break;

                        case 1: // (n) uncompressed object in use
                            $index = $obj_num.'_'.$f2;
                            if (!isset($xref['xref'][$index]) && !isset($this->freedObjects[$obj_num])) {
                                $xref['xref'][$index] = $f1;
                            }
                            break;

                        case 2: // compressed object inside an ObjStm
                            $index = $f1.'_0_'.$f2;
                            $xref['xref'][$index] = -1;
                            break;

                        default:
                            break;
                    }

                    ++$obj_num;
                    if ($blockCount > 0) {
                        --$blockRemaining;
                        if ($blockRemaining <= 0) {
                            ++$blockIdx;
                            if ($blockIdx < $blockCount) {
                                $obj_num = $index_blocks[$blockIdx][0];
                                $blockRemaining = $index_blocks[$blockIdx][1];
                            } else {
                                $blockCount = 0;
                            }
                        }
                    }
                }
            }
        } // end decoding data
        if (isset($prevxref)) {
            // get previous xref
            $xref = $this->getXrefData($pdfData, $prevxref, $xref, $visitedOffsets);
        }

        return $xref;
    }

    protected function getObjectHeaderPattern(array $objRefs): string
    {
        // consider all whitespace characters (1 or more per PDF spec)
        $ws = $this->config->getPdfWhitespacesRegex();

        return '/\G'.$objRefs[0].$ws.'+'.$objRefs[1].$ws.'+obj/';
    }

    protected function getObjectHeaderLen(array $objRefs): int
    {
        // "4 0 obj"
        // 2 whitespaces + strlen("obj") = 5
        return 5 + \strlen($objRefs[0]) + \strlen($objRefs[1]);
    }

    /**
     * Get content of indirect object.
     *
     * @param string $pdfData  PDF data
     * @param string $objRef   Object number and generation number separated by underscore character
     * @param int    $offset   Object offset
     * @param bool   $decoding If true decode streams
     *
     * @return array containing object data
     *
     * @throws \Exception if invalid object reference found
     */
    protected function getIndirectObject(string $pdfData, array $xref, string $objRef, int $offset = 0, bool $decoding = true): array
    {
        /*
         * build indirect object header
         */
        // $objHeader = "[object number] [generation number] obj"
        $objRefArr = explode('_', $objRef);
        if (2 !== \count($objRefArr)) {
            throw new \Exception('Invalid object reference for $obj.');
        }

        /*
         * check if we are in position
         */
        // ignore whitespace characters at offset
        $offset += strspn($pdfData, $this->config->getPdfWhitespaces(), $offset);
        // ignore leading zeros for non-zero object number
        if ('0' !== $objRefArr[0]) {
            $offset += strspn($pdfData, '0', $offset);
        }
        if (1 !== preg_match($this->getObjectHeaderPattern($objRefArr), $pdfData, $hdrMatch, 0, $offset)) {
            // an indirect reference to an undefined object shall be considered a reference to the null object
            return ['null', 'null', $offset];
        }

        /*
         * get content
         */
        // starting position of object content
        $offset += \strlen($hdrMatch[0]);
        $objContentArr = [];
        $i = 0; // object main index
        $header = null;
        do {
            $oldOffset = $offset;
            // get element
            $element = $this->getRawObject($pdfData, $offset, null != $header ? $header[1] : null);
            $offset = $element[2];
            // decode stream using stream's dictionary information
            if ($decoding && ('stream' === $element[0]) && null != $header) {
                $objType = $this->getHeaderValue($header[1], 'Type', '/');
                if ($this->lazyStreams && 'ObjStm' !== $objType && 'XRef' !== $objType) {
                    $element[3] = $this->buildLazyStream($pdfData, $xref, $header[1], $element[1]);
                    // Release raw stream slot so compressed bytes aren't duplicated in the raw structure
                    $element[1] = '';
                } else {
                    $element[3] = $this->decodeStream($pdfData, $xref, $header[1], $element[1]);
                }
            }
            $objContentArr[$i] = $element;
            $header = isset($element[0]) && '<<' === $element[0] ? $element : null;
            ++$i;
        } while (('endobj' !== $element[0]) && ($offset !== $oldOffset));
        // remove closing delimiter
        array_pop($objContentArr);

        /*
         * return raw object content
         */
        return $objContentArr;
    }

    /**
     * Get the content of object, resolving indirect object reference if necessary.
     *
     * @param string $pdfData PDF data
     * @param array  $obj     Object value
     *
     * @return array containing object data
     *
     * @throws \Exception
     */
    protected function getObjectVal(string $pdfData, $xref, array $obj): array
    {
        if ('objref' == $obj[0]) {
            // reference to indirect object
            if (isset($this->objects[$obj[1]])) {
                // this object has been already parsed
                return $this->objects[$obj[1]];
            } elseif (isset($xref['xref'][$obj[1]]) && $xref['xref'][$obj[1]] > 0) {
                // parse new object
                $this->objects[$obj[1]] = $this->getIndirectObject($pdfData, $xref, $obj[1], $xref['xref'][$obj[1]], false);

                return $this->objects[$obj[1]];
            }
        }

        return $obj;
    }

    /**
     * Skip a balanced pair of delimiters ('[' … ']' or '<<' … '>>') that starts at
     * $offset and return the offset just after the matching closing delimiter. Used
     * to discard a container whose nesting exceeds the configured limit, keeping the
     * surrounding structure balanced.
     */
    private function skipBalancedDelimiters(string $pdfData, int $offset, string $open, string $close): int
    {
        $length = \strlen($pdfData);
        $openLen = \strlen($open);
        $closeLen = \strlen($close);
        $level = 0;

        while ($offset < $length) {
            if (substr($pdfData, $offset, $closeLen) === $close) {
                --$level;
                $offset += $closeLen;
                if ($level <= 0) {
                    return $offset;
                }

                continue;
            }

            if (substr($pdfData, $offset, $openLen) === $open) {
                ++$level;
                $offset += $openLen;

                continue;
            }

            ++$offset;
        }

        return $offset;
    }

    /**
     * Get object type, raw value and offset to next object
     *
     * @param int        $offset    Object offset
     * @param array|null $headerDic obj header's dictionary, parsed by getRawObject. Used for stream parsing optimization
     *
     * @return array containing object type, raw value and offset to next object
     *
     * @see https://opensource.adobe.com/dc-acrobat-sdk-docs/pdfstandards/PDF32000_2008.pdf#page=26 ISO 32000-1:2008, 7.3.6 (array objects) and 7.3.7 (dictionary objects)
     */
    protected function getRawObject(string $pdfData, int $offset = 0, ?array $headerDic = null, int $depth = 0): array
    {
        $objtype = ''; // object type to be returned
        $objval = ''; // object value to be returned

        // skip initial white space chars
        $offset += strspn($pdfData, $this->config->getPdfWhitespaces(), $offset);

        if (!isset($pdfData[$offset])) {
            return ['', '', $offset];
        }

        // get first char
        $char = $pdfData[$offset];

        // Bound the structural nesting depth. Arrays and dictionaries recurse into
        // getRawObject() once per level; past the limit such a container is skipped
        // as a whole and returned empty instead of being descended into, so a
        // pathologically nested object cannot exhaust the call stack or memory
        // while the surrounding structure stays balanced.
        $maxNestingDepth = $this->config->getMaxNestingDepth();
        if (0 < $maxNestingDepth && $depth >= $maxNestingDepth) {
            if ('[' === $char) {
                return ['[', [], $this->skipBalancedDelimiters($pdfData, $offset, '[', ']')];
            }
            if ('<' === $char && isset($pdfData[$offset + 1]) && '<' === $pdfData[$offset + 1]) {
                return ['<<', [], $this->skipBalancedDelimiters($pdfData, $offset, '<<', '>>')];
            }
        }

        // get object type
        switch ($char) {
            case '%':  // \x25 PERCENT SIGN
                // skip comment and search for next token
                $next = strcspn($pdfData, "\r\n", $offset);
                if ($next > 0) {
                    $offset += $next;

                    // A comment is not a nesting level, so $depth is passed on unchanged.
                    return $this->getRawObject($pdfData, $offset, null, $depth);
                }
                break;

            case '/':  // \x2F SOLIDUS
                // name object
                $objtype = $char;
                ++$offset;
                $span = strcspn($pdfData, "\x00\x09\x0a\x0c\x0d\x20\n\t\r\v\f\x28\x29\x3c\x3e\x5b\x5d\x7b\x7d\x2f\x25", $offset, 256);
                if ($span > 0) {
                    $objval = substr($pdfData, $offset, $span); // unescaped value
                    $offset += $span;
                }
                break;

            case '(':   // \x28 LEFT PARENTHESIS
            case ')':  // \x29 RIGHT PARENTHESIS
                // literal string object
                $objtype = $char;
                ++$offset;
                $strpos = $offset;
                if ('(' == $char) {
                    $open_bracket = 1;
                    while ($open_bracket > 0) {
                        if (!isset($pdfData[$strpos])) {
                            break;
                        }
                        $ch = $pdfData[$strpos];
                        switch ($ch) {
                            case '\\':  // REVERSE SOLIDUS (5Ch) (Backslash)
                                // skip next character
                                ++$strpos;
                                break;

                            case '(':  // LEFT PARENHESIS (28h)
                                ++$open_bracket;
                                break;

                            case ')':  // RIGHT PARENTHESIS (29h)
                                --$open_bracket;
                                break;
                        }
                        ++$strpos;
                    }
                    $objval = substr($pdfData, $offset, $strpos - $offset - 1);
                    $offset = $strpos;
                }
                break;

            case '[':   // \x5B LEFT SQUARE BRACKET
            case ']':  // \x5D RIGHT SQUARE BRACKET
                // array object
                $objtype = $char;
                ++$offset;
                if ('[' == $char) {
                    // get array content
                    $objval = [];
                    do {
                        $oldOffset = $offset;
                        // get element
                        $element = $this->getRawObject($pdfData, $offset, null, $depth + 1);
                        $offset = $element[2];
                        $objval[] = $element;
                    } while ((']' != $element[0]) && ($offset != $oldOffset));
                    // remove closing delimiter
                    array_pop($objval);
                }
                break;

            case '<':  // \x3C LESS-THAN SIGN
            case '>':  // \x3E GREATER-THAN SIGN
                if (isset($pdfData[$offset + 1]) && ($pdfData[$offset + 1] == $char)) {
                    // dictionary object
                    $objtype = $char.$char;
                    $offset += 2;
                    if ('<' == $char) {
                        // get array content
                        $objval = [];
                        do {
                            $oldOffset = $offset;
                            // get element
                            $element = $this->getRawObject($pdfData, $offset, null, $depth + 1);
                            $offset = $element[2];
                            $objval[] = $element;
                        } while (('>>' != $element[0]) && ($offset != $oldOffset));
                        // remove closing delimiter
                        array_pop($objval);
                    }
                } else {
                    // hexadecimal string object
                    $objtype = $char;
                    ++$offset;

                    $span = strspn($pdfData, "0123456789abcdefABCDEF\x09\x0a\x0c\x0d\x20", $offset);
                    $dataToCheck = $pdfData[$offset + $span] ?? null;
                    if ('<' == $char && $span > 0 && '>' == $dataToCheck) {
                        // remove white space characters
                        $objval = strtr(substr($pdfData, $offset, $span), $this->config->getPdfWhitespaces(), '');
                        $offset += $span + 1;
                    } elseif (false !== ($endpos = strpos($pdfData, '>', $offset))) {
                        $offset = $endpos + 1;
                    }
                }
                break;

            default:
                if ('endobj' == substr($pdfData, $offset, 6)) {
                    // indirect object
                    $objtype = 'endobj';
                    $offset += 6;
                } elseif ('null' == substr($pdfData, $offset, 4)) {
                    // null object
                    $objtype = 'null';
                    $offset += 4;
                    $objval = 'null';
                } elseif ('true' == substr($pdfData, $offset, 4)) {
                    // boolean true object
                    $objtype = 'boolean';
                    $offset += 4;
                    $objval = 'true';
                } elseif ('false' == substr($pdfData, $offset, 5)) {
                    // boolean false object
                    $objtype = 'boolean';
                    $offset += 5;
                    $objval = 'false';
                } elseif ('stream' == substr($pdfData, $offset, 6)) {
                    // start stream object
                    $objtype = 'stream';
                    $offset += 6;
                    if (1 == preg_match('/^( *(?:\r\n|\n|\r))/', substr($pdfData, $offset, 16), $matches)) {
                        $offset += \strlen($matches[0]);

                        // Use declared stream length when positive and within bounds to skip binary payload fast
                        $pdfLen = \strlen($pdfData);
                        $streamLen = (int) $this->getHeaderValue($headerDic, 'Length', 'numeric', 0);
                        $skip = false === $this->config->getRetainImageContent()
                            && 'Image' == $this->getHeaderValue($headerDic, 'Subtype', '/');

                        $searchStart = ($streamLen > 0 && ($offset + $streamLen) <= $pdfLen)
                            ? ($offset + $streamLen)
                            : $offset;

                        $endPos = $this->findEndstreamPos($pdfData, $searchStart, $pdfLen);
                        if (false === $endPos && $searchStart > $offset) {
                            // Declared /Length was oversized or inaccurate; retry from actual stream start
                            $endPos = $this->findEndstreamPos($pdfData, $offset, $pdfLen);
                        }

                        if (false !== $endPos) {
                            $objval = $skip ? '' : substr($pdfData, $offset, $endPos - $offset);
                            $offset = $endPos;
                        }
                    }
                } elseif ('endstream' == substr($pdfData, $offset, 9)) {
                    // end stream object
                    $objtype = 'endstream';
                    $offset += 9;
                } elseif (1 == preg_match('/^([0-9]+)[\s]+([0-9]+)[\s]+R/iU', substr($pdfData, $offset, 33), $matches)) {
                    // indirect object reference
                    $objtype = 'objref';
                    $offset += \strlen($matches[0]);
                    $objval = (int) $matches[1].'_'.(int) $matches[2];
                } elseif (1 == preg_match('/^([0-9]+)[\s]+([0-9]+)[\s]+obj/iU', substr($pdfData, $offset, 33), $matches)) {
                    // object start
                    $objtype = 'obj';
                    $objval = (int) $matches[1].'_'.(int) $matches[2];
                    $offset += \strlen($matches[0]);
                } elseif (($numlen = strspn($pdfData, '+-.0123456789', $offset)) > 0) {
                    // numeric object
                    $objtype = 'numeric';
                    $objval = substr($pdfData, $offset, $numlen);
                    $offset += $numlen;
                }
                break;
        }

        return [$objtype, $objval, $offset];
    }

    /**
     * Locate the byte offset of the 'endstream' delimiter at or after $searchStart using fast strpos().
     *
     * @return int|false
     */
    private function findEndstreamPos(string $pdfData, int $searchStart, int $pdfLen)
    {
        $pos = $searchStart;
        while (false !== ($found = strpos($pdfData, 'endstream', $pos))) {
            $after = $found + 9;
            if (
                $after >= $pdfLen
                || false !== strpos("\x00\x09\x0a\x0c\x0d\x20", $pdfData[$after])
                || 'endobj' === substr($pdfData, $after, 6)
            ) {
                return $found;
            }
            $pos = $found + 9;
        }

        return false;
    }

    /**
     * Get value of an object header's section (obj << YYY >> part ).
     *
     * It is similar to Header::get('...')->getContent(), the only difference is it can be used during the parsing process,
     * when no Smalot\PdfParser\Header objects are created yet.
     *
     * @param string            $key     header's section name
     * @param string            $type    type of the section (i.e. 'numeric', '/', '<<', etc.)
     * @param string|array|null $default default value for header's section
     *
     * @return string|array|null value of obj header's section, or default value if none found, or its type doesn't match $type param
     */
    private function getHeaderValue(?array $headerDic, string $key, string $type, $default = '')
    {
        if (false === \is_array($headerDic)) {
            return $default;
        }

        /*
         * It recieves dictionary of header fields, as it is returned by RawDataParser::getRawObject,
         * iterates over it, searching for section of type '/' whith requested key.
         * If such a section is found, it tries to receive it's value (next object in dictionary),
         * returning it, if it matches requested type, or default value otherwise.
         */
        foreach ($headerDic as $i => $val) {
            $isSectionName = \is_array($val) && 3 == \count($val) && '/' == $val[0];
            if (
                $isSectionName
                && $val[1] == $key
                && isset($headerDic[$i + 1])
            ) {
                $isSectionValue = \is_array($headerDic[$i + 1]) && 1 < \count($headerDic[$i + 1]);

                return $isSectionValue && $type == $headerDic[$i + 1][0]
                    ? $headerDic[$i + 1][1]
                    : $default;
            }
        }

        return $default;
    }

    /**
     * Get Cross-Reference (xref) table and trailer data from PDF document data.
     *
     * @param int        $offset        xref offset (if known)
     * @param array      $xref          previous xref array (if any)
     * @param array<int> $visitedOffsets array of visited offsets to prevent infinite loops
     *
     * @return array containing xref and trailer data
     *
     * @throws \Exception if it was unable to find startxref
     * @throws \Exception if it was unable to find xref
     */
    protected function getXrefData(string $pdfData, int $offset = 0, array $xref = [], array $visitedOffsets = []): array
    {
        // Check for circular references to prevent infinite loops
        if (\in_array($offset, $visitedOffsets, true)) {
            // We've already processed this offset, skip to avoid infinite loop
            return $xref;
        }

        // Track this offset as visited
        $visitedOffsets[] = $offset;
        // If the $offset is currently pointed at whitespace, bump it
        // forward until it isn't; affects loosely targetted offsets
        // for the 'xref' keyword
        // See: https://github.com/smalot/pdfparser/issues/673
        $pdfLen = \strlen($pdfData);
        $bumpOffset = $offset + strspn($pdfData, "\x09\x0a\x0b\x0c\x0d\x20", $offset);

        // Locate startxref: when starting from 0, inspect the file tail first (O(1) on large PDFs)
        $startxrefMatches = [];
        $startxrefPreg = 0;
        if (0 === $offset) {
            $tailOffset = max(0, $pdfLen - 4096);
            $startxrefPreg = preg_match_all(
                '/(?<=[\r\n])startxref[\s]*[\r\n]+([0-9]+)[\s]*[\r\n]+%%EOF/i',
                $pdfData,
                $startxrefMatches,
                \PREG_SET_ORDER,
                $tailOffset
            );
            if (0 === $startxrefPreg && $tailOffset > 0) {
                $startxrefPreg = preg_match_all(
                    '/(?<=[\r\n])startxref[\s]*[\r\n]+([0-9]+)[\s]*[\r\n]+%%EOF/i',
                    $pdfData,
                    $startxrefMatches,
                    \PREG_SET_ORDER,
                    0
                );
            }
        } else {
            $startxrefPreg = preg_match_all(
                '/(?<=[\r\n])startxref[\s]*[\r\n]+([0-9]+)[\s]*[\r\n]+%%EOF/i',
                $pdfData,
                $startxrefMatches,
                \PREG_SET_ORDER,
                $offset
            );
        }

        if (0 == $startxrefPreg) {
            // No startxref tables were found
            throw new \Exception('Unable to find startxref');
        } elseif (0 == $offset) {
            // Use the last startxref in the document
            $startxref = (int) $startxrefMatches[\count($startxrefMatches) - 1][1];
        } elseif ('xref' === substr($pdfData, $bumpOffset, 4)) {
            // Already pointing at the xref table
            $startxref = $bumpOffset;
        } elseif (1 === preg_match('/\G([0-9]+[\s]+[0-9]+[\s]+obj)/i', $pdfData, $matches, 0, $bumpOffset)) {
            // Cross-Reference Stream object
            $startxref = $bumpOffset;
        } else {
            // Use the next startxref from this $offset
            $startxref = (int) $startxrefMatches[0][1];
        }

        if ($startxref > $pdfLen) {
            throw new \Exception('Unable to find xref (PDF corrupted?)');
        }

        // check xref position
        if ('xref' === substr($pdfData, $startxref, 4)) {
            // Cross-Reference
            $xref = $this->decodeXref($pdfData, $startxref, $xref, $visitedOffsets);
        } elseif (1 === preg_match('/\G[\0\t\n\f\r ]*[0-9]+[\0\t\n\f\r ]+[0-9]+[\0\t\n\f\r ]+obj\b/', $pdfData, $objMatch, 0, $startxref)) {
            // Cross-Reference Stream (avoids allocating a full-file str_replace copy on PDF 1.5+ files)
            $xref = $this->decodeXrefStream($pdfData, $startxref, $xref, $visitedOffsets);
        } else {
            // Check if the $pdfData might have the wrong line-endings (\r\n instead of \n)
            if (false !== strpos($pdfData, "\r\n")) {
                $pdfDataUnix = str_replace("\r\n", "\n", $pdfData);
                if ($startxref < \strlen($pdfDataUnix) && 'xref' === substr($pdfDataUnix, $startxref, 4)) {
                    // Return Unix-line-ending flag
                    return ['Unix' => true];
                }
                unset($pdfDataUnix);
            }
            // Fallback to Cross-Reference Stream
            $xref = $this->decodeXrefStream($pdfData, $startxref, $xref, $visitedOffsets);
        }
        if (empty($xref)) {
            throw new \Exception('Unable to find xref');
        }

        return $xref;
    }

    /**
     * Parses PDF data and returns extracted data as array.
     *
     * All indirect objects are held in memory at once. Parser uses
     * parseHeaderAndXref() and iterateIndirectObjects() to get them one at a
     * time.
     *
     * @param string $data PDF data to parse
     *
     * @return array{0: array, 1: array<string, array>} [$xref, $objects]; objects are keyed by object reference
     *
     * @throws EmptyPdfException if empty PDF data given
     * @throws MissingPdfHeaderException if PDF data missing `%PDF-` header
     */
    public function parseData(string $data): array
    {
        list($xref, $pdfData) = $this->parseHeaderAndXref($data);

        return [$xref, iterator_to_array($this->iterateIndirectObjects($pdfData, $xref))];
    }

    /**
     * Normalizes the raw PDF data and decodes the cross-reference/trailer data.
     *
     * Normalized PDF data starts with the header. If the offsets of the
     * cross-reference table only fit after replacing \r\n by \n, its line
     * endings are replaced accordingly.
     *
     * The trailer is available before any object gets decoded, which allows
     * callers (e.g. Parser) to inspect it (for instance to detect encryption)
     * and to get the objects via iterateIndirectObjects() afterwards.
     *
     * @param string $data PDF data to parse
     *
     * @return array{0: array, 1: string} [$xref, $pdfData]
     *
     * @see https://opensource.adobe.com/dc-acrobat-sdk-docs/pdfstandards/PDF32000_2008.pdf#page=47 ISO 32000-1:2008, 7.5.2 (file header)
     * @see https://opensource.adobe.com/dc-acrobat-sdk-docs/pdfstandards/PDF32000_2008.pdf#page=48 ISO 32000-1:2008, 7.5.4 (cross-reference table)
     * @see https://opensource.adobe.com/dc-acrobat-sdk-docs/pdfstandards/PDF32000_2008.pdf#page=50 ISO 32000-1:2008, 7.5.5 (file trailer)
     * @see https://opensource.adobe.com/dc-acrobat-sdk-docs/pdfstandards/PDF32000_2008.pdf#page=57 ISO 32000-1:2008, 7.5.8 (cross-reference streams)
     *
     * @throws EmptyPdfException if empty PDF data given
     * @throws MissingPdfHeaderException if PDF data missing `%PDF-` header
     */
    public function parseHeaderAndXref(string $data): array
    {
        if (empty($data)) {
            throw new EmptyPdfException('Empty PDF data given.');
        }

        $this->objects = [];
        $this->freedObjects = [];

        // find the pdf header starting position
        if (false === ($trimpos = strpos($data, '%PDF-'))) {
            throw new MissingPdfHeaderException('Invalid PDF data: Missing `%PDF-` header.');
        }

        // get PDF content string
        $pdfData = $trimpos > 0 ? substr($data, $trimpos) : $data;

        // get xref and trailer data
        $xref = $this->getXrefData($pdfData);

        // If we found Unix line-endings
        if (isset($xref['Unix'])) {
            $this->objects = [];
            $this->freedObjects = [];
            $pdfData = str_replace("\r\n", "\n", $pdfData);
            $xref = $this->getXrefData($pdfData);
        }

        return [$xref, $pdfData];
    }

    /**
     * Yields the raw structure of each indirect object, one at a time.
     *
     * An object gets decoded when the consumer asks for it. A consumer, which
     * processes and discards a structure before it asks for the next one,
     * holds only one raw structure in memory at a time. The raw structures
     * are by far the largest transient data when parsing a document.
     *
     *
     * @return \Generator<string, array> raw object structure keyed by object reference
     *
     * @see https://opensource.adobe.com/dc-acrobat-sdk-docs/pdfstandards/PDF32000_2008.pdf#page=29 ISO 32000-1:2008, 7.3.10 (indirect objects)
     * @see https://opensource.adobe.com/dc-acrobat-sdk-docs/pdfstandards/PDF32000_2008.pdf#page=53 ISO 32000-1:2008, 7.5.7 (object streams)
     * @see https://opensource.adobe.com/dc-acrobat-sdk-docs/pdfstandards/PDF32000_2008.pdf#page=59 ISO 32000-1:2008, 7.5.8.3, Table 18 (types of cross-reference stream entries)
     */
    /**
     * Parse a single PDF object directly from a string slice (e.g. an object inside an /ObjStm).
     *
     * @return array{0: string, 1: mixed, 2: int}
     */
    public function parseObjectFromString(string $content, int $offset = 0): array
    {
        return $this->getRawObject($content, $offset);
    }

    public function iterateIndirectObjects(string $pdfData, array $xref, bool $lazyStreams = false): \Generator
    {
        $prevLazy = $this->lazyStreams;
        $this->lazyStreams = $lazyStreams;
        try {
            // $xref['xref'] is missing, if the cross-reference data contains no
            // object which is in use.
            foreach ($xref['xref'] ?? [] as $obj => $offset) {
                // Only objects with a positive offset are decoded. Objects which
                // are located in an object stream have an offset of -1, they get
                // extracted from their object stream by Parser::parseObject().
                // $xref['xref'] is keyed by object reference, so each object is
                // decoded exactly once.
                if ($offset > 0) {
                    yield $obj => $this->getIndirectObject($pdfData, $xref, $obj, $offset, true);
                }
            }
        } finally {
            $this->lazyStreams = $prevLazy;
        }
    }
}
