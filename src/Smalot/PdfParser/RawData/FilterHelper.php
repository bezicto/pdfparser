<?php

declare(strict_types=1);

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

use Smalot\PdfParser\Exception\NotImplementedException;

class FilterHelper
{
    /**
     * @var array<int, string>
     */
    protected array $availableFilters = ['ASCIIHexDecode', 'ASCII85Decode', 'LZWDecode', 'FlateDecode', 'RunLengthDecode'];

    /**
     * Pre-initialized 256-entry LZW base dictionary (shared via copy-on-write).
     *
     * @var array<int, string>|null
     */
    private static ?array $lzwBaseDictionary = null;

    /**
     * Decode data using the specified filter type.
     *
     * @param string $filter            Filter name
     * @param string $data              Data to decode
     * @param int    $decodeMemoryLimit Maximum decoded size in bytes; 0 for unlimited
     * @param array  $decodeParms       Optional filter decode parameters (e.g. Predictor, Columns, EarlyChange)
     *
     * @return string Decoded data string
     *
     * @throws \Exception
     * @throws NotImplementedException if a certain decode function is not implemented yet
     */
    public function decodeFilter(string $filter, string $data, int $decodeMemoryLimit = 0, array $decodeParms = []): string
    {
        return match ($filter) {
            'ASCIIHexDecode' => $this->decodeFilterASCIIHexDecode($data),
            'ASCII85Decode' => $this->decodeFilterASCII85Decode($data),
            'LZWDecode' => $this->applyDecodeParmsPredictor(
                $this->decodeFilterLZWDecode($data, $decodeMemoryLimit, (int) ($decodeParms['EarlyChange'] ?? 1)),
                $decodeParms
            ),
            'FlateDecode' => $this->applyDecodeParmsPredictor(
                $this->decodeFilterFlateDecode($data, $decodeMemoryLimit),
                $decodeParms
            ),
            'RunLengthDecode' => $this->decodeFilterRunLengthDecode($data, $decodeMemoryLimit),
            'CCITTFaxDecode', 'JBIG2Decode', 'DCTDecode', 'JPXDecode', 'Crypt' => throw new NotImplementedException("Decode {$filter} not implemented yet."),
            default => $data,
        };
    }

    /**
     * Apply PNG or TIFF predictor reversal when specified in /DecodeParms.
     */
    private function applyDecodeParmsPredictor(string $data, array $decodeParms): string
    {
        if (!isset($decodeParms['Predictor'])) {
            return $data;
        }

        $predictor = (int) $decodeParms['Predictor'];
        if ($predictor <= 1) {
            return $data;
        }

        $columns = (int) ($decodeParms['Columns'] ?? 1);
        $colors = (int) ($decodeParms['Colors'] ?? 1);
        $bitsPerComponent = (int) ($decodeParms['BitsPerComponent'] ?? 8);

        return $this->decodePredictor($data, $predictor, $columns, $colors, $bitsPerComponent);
    }

    /**
     * Reverse PNG (10-15) or TIFF (2) predictor encoding on decoded stream bytes.
     *
     * @throws \Exception if an unknown PNG predictor byte is encountered
     *
     * @see https://opensource.adobe.com/dc-acrobat-sdk-docs/pdfstandards/PDF32000_2008.pdf#page=35 ISO 32000-1:2008, 7.4.4.4 (LZW and Flate predictor functions)
     */
    public function decodePredictor(string $data, int $predictor, int $columns = 1, int $colors = 1, int $bitsPerComponent = 8): string
    {
        if ($predictor <= 1 || '' === $data) {
            return $data;
        }

        $columns = max(1, $columns);
        $colors = max(1, $colors);
        $bitsPerComponent = max(1, $bitsPerComponent);

        $bpp = max(1, (int) ceil(($colors * $bitsPerComponent) / 8));
        $rowDataLen = (int) ceil(($columns * $colors * $bitsPerComponent) / 8);

        if ($rowDataLen <= 0) {
            return '';
        }

        // TIFF Predictor 2 (horizontal differencing)
        if (2 === $predictor) {
            if (8 !== $bitsPerComponent) {
                return $data;
            }

            $dataLen = \strlen($data);
            $out = $data;
            for ($rowStart = 0; $rowStart < $dataLen; $rowStart += $rowDataLen) {
                $rowEnd = min($rowStart + $rowDataLen, $dataLen);
                for ($i = $rowStart + $bpp; $i < $rowEnd; ++$i) {
                    $out[$i] = \chr((\ord($out[$i]) + \ord($out[$i - $bpp])) & 0xFF);
                }
            }

            return $out;
        }

        // PNG predictors (10-15): each row has 1 leading filter-type byte + $rowDataLen bytes
        if ($predictor >= 10 && $predictor <= 15) {
            $rowLen = $rowDataLen + 1;
            $dataLen = \strlen($data);
            $prevRow = str_repeat("\x00", $rowDataLen);
            $output = '';

            for ($offset = 0; $offset < $dataLen; $offset += $rowLen) {
                $filterType = \ord($data[$offset]);
                $avail = min($rowDataLen, $dataLen - $offset - 1);
                if ($avail <= 0) {
                    break;
                }

                $curRow = substr($data, $offset + 1, $avail);
                if ($avail < $rowDataLen) {
                    $curRow .= str_repeat("\x00", $rowDataLen - $avail);
                }

                switch ($filterType) {
                    case 0: // PNG None (10)
                        break;

                    case 1: // PNG Sub (11)
                        for ($j = $bpp; $j < $rowDataLen; ++$j) {
                            $curRow[$j] = \chr((\ord($curRow[$j]) + \ord($curRow[$j - $bpp])) & 0xFF);
                        }
                        break;

                    case 2: // PNG Up (12)
                        for ($j = 0; $j < $rowDataLen; ++$j) {
                            $curRow[$j] = \chr((\ord($curRow[$j]) + \ord($prevRow[$j])) & 0xFF);
                        }
                        break;

                    case 3: // PNG Average (13)
                        for ($j = 0; $j < $rowDataLen; ++$j) {
                            $left = $j >= $bpp ? \ord($curRow[$j - $bpp]) : 0;
                            $up = \ord($prevRow[$j]);
                            $curRow[$j] = \chr((\ord($curRow[$j]) + (($left + $up) >> 1)) & 0xFF);
                        }
                        break;

                    case 4: // PNG Paeth (14)
                        for ($j = 0; $j < $rowDataLen; ++$j) {
                            $left = $j >= $bpp ? \ord($curRow[$j - $bpp]) : 0;
                            $up = \ord($prevRow[$j]);
                            $upLeft = $j >= $bpp ? \ord($prevRow[$j - $bpp]) : 0;
                            $p = $left + $up - $upLeft;
                            $pa = abs($p - $left);
                            $pb = abs($p - $up);
                            $pc = abs($p - $upLeft);
                            if ($pa <= $pb && $pa <= $pc) {
                                $pr = $left;
                            } elseif ($pb <= $pc) {
                                $pr = $up;
                            } else {
                                $pr = $upLeft;
                            }
                            $curRow[$j] = \chr((\ord($curRow[$j]) + $pr) & 0xFF);
                        }
                        break;

                    default:
                        throw new \Exception('Unknown PNG predictor: '.(10 + $filterType));
                }

                $output .= $curRow;
                $prevRow = $curRow;
            }

            return $output;
        }

        return $data;
    }

    /**
     * ASCIIHexDecode
     *
     * Decodes data encoded in an ASCII hexadecimal representation, reproducing the original binary data.
     *
     * @param string $data Data to decode
     *
     * @return string data string
     *
     * @throws \Exception
     */
    protected function decodeFilterASCIIHexDecode(string $data): string
    {
        // all white-space characters shall be ignored
        $data = (string) preg_replace('/[\s]/', '', $data);
        // check for EOD character: GREATER-THAN SIGN (3Eh)
        $eod = strpos($data, '>');
        if (false !== $eod) {
            // remove EOD and extra data (if any)
            $data = substr($data, 0, $eod);
            $eod = true;
        }
        // get data length
        $data_length = \strlen($data);
        if (0 !== ($data_length % 2)) {
            // odd number of hexadecimal digits
            if ($eod) {
                // ISO 32000-1:2008, 7.4.2: EOD shall behave as if a 0 followed the last digit
                $data .= '0';
            } else {
                throw new \Exception('decodeFilterASCIIHexDecode: invalid code');
            }
        }
        // check for invalid characters
        if (preg_match('/[^a-fA-F\d]/', $data) > 0) {
            throw new \Exception('decodeFilterASCIIHexDecode: invalid code');
        }
        // get one byte of binary data for each pair of ASCII hexadecimal digits
        return pack('H*', $data);
    }

    /**
     * ASCII85Decode
     *
     * Decodes data encoded in an ASCII base-85 representation, reproducing the original binary data.
     *
     * @param string $data Data to decode
     *
     * @return string data string
     *
     * @throws \Exception
     */
    protected function decodeFilterASCII85Decode(string $data): string
    {
        // initialize string to return
        $decoded = '';
        // all white-space characters shall be ignored
        $data = (string) preg_replace('/[\s]/', '', $data);
        // remove start sequence 2-character sequence <~ (3Ch)(7Eh)
        if (str_starts_with($data, '<~')) {
            // remove start sequence
            $data = substr($data, 2);
        }
        // check for EOD: 2-character sequence ~> (7Eh)(3Eh)
        $eod = strpos($data, '~>');
        if (false !== $eod) {
            // remove EOD and extra data (if any)
            $data = substr($data, 0, $eod);
        }
        // data length
        $data_length = \strlen($data);
        // check for invalid characters (allow '!'.. 'u' (0x21..0x75) and 'z' (0x7A))
        if (preg_match('/[^\x21-\x75z]/', $data) > 0) {
            throw new \Exception('decodeFilterASCII85Decode: invalid code');
        }
        // z sequence
        $zseq = "\x00\x00\x00\x00";
        // position inside a group of 5 characters (0-4)
        $group_pos = 0;
        $tuple = 0;
        $pow85 = [52200625, 614125, 7225, 85, 1];

        // for each byte
        for ($i = 0; $i < $data_length; ++$i) {
            // get char value
            $char = \ord($data[$i]);
            if (122 === $char) { // 'z'
                if (0 === $group_pos) {
                    $decoded .= $zseq;
                } else {
                    throw new \Exception('decodeFilterASCII85Decode: invalid code');
                }
            } else {
                // the value represented by a group of 5 characters should never be greater than 2^32 - 1
                $tuple += (($char - 33) * $pow85[$group_pos]);
                if (4 === $group_pos) {
                    if ($tuple > 0xFFFFFFFF) {
                        throw new \Exception('decodeFilterASCII85Decode: invalid code');
                    }
                    $decoded .= pack('N', $tuple);
                    $tuple = 0;
                    $group_pos = 0;
                } else {
                    ++$group_pos;
                }
            }
        }
        if ($group_pos > 1) {
            $tuple += $pow85[$group_pos - 1];
        }
        // last tuple (if any)
        $decoded .= match ($group_pos) {
            4 => substr(pack('N', $tuple), 0, 3),
            3 => substr(pack('N', $tuple), 0, 2),
            2 => \chr(($tuple >> 24) & 0xFF),
            1 => throw new \Exception('decodeFilterASCII85Decode: invalid code'),
            default => '',
        };

        return $decoded;
    }

    /**
     * FlateDecode
     *
     * Decompresses data encoded using the zlib/deflate compression method, reproducing the original text or binary data.
     *
     * @param string $data              Data to decode
     * @param int    $decodeMemoryLimit Memory limit on deflation
     *
     * @return string data string
     *
     * @throws \Exception
     *
     * @see https://opensource.adobe.com/dc-acrobat-sdk-docs/pdfstandards/PDF32000_2008.pdf#page=33 ISO 32000-1:2008, 7.4.4 (LZWDecode and FlateDecode filters)
     */
    protected function decodeFilterFlateDecode(string $data, int $decodeMemoryLimit): string
    {
        if ('' === $data) {
            throw new \Exception('decodeFilterFlateDecode: invalid data');
        }

        // Fast path: C-level gzuncompress() when stream is valid zlib and fits within limit.
        // Request $decodeMemoryLimit + 1 bytes when bounded so we can distinguish a
        // limit breach from a legitimately sized stream of exact length $decodeMemoryLimit.
        $maxLen = $decodeMemoryLimit > 0 ? $decodeMemoryLimit + 1 : 0;
        $decoded = @gzuncompress($data, $maxLen);

        if (\is_string($decoded)) {
            if ($decodeMemoryLimit > 0 && \strlen($decoded) > $decodeMemoryLimit) {
                throw new \Exception('decodeFilterFlateDecode: decode memory limit exceeded');
            }
            if ('' !== $decoded) {
                return $decoded;
            }
        }

        // In-memory streaming fallback via inflate_init()/inflate_add():
        // Handles zlib streams with bad/CRC32 trailing checksums or truncated ends (Issue #592),
        // and enforces $decodeMemoryLimit without creating temp files on disk.
        $decoded = $this->inflateStreaming($data, \ZLIB_ENCODING_DEFLATE, $decodeMemoryLimit, true);
        if (null === $decoded && \strlen($data) > 2) {
            // If a valid 2-byte zlib header is present, strip it and inflate raw deflate
            // (recovers streams whose trailing 4-byte checksum is CRC32 instead of Adler-32, Issue #592).
            $cmf = \ord($data[0]);
            $flg = \ord($data[1]);
            if (8 === ($cmf & 0x0F) && 0 === (($cmf * 256 + $flg) % 31)) {
                $decoded = $this->inflateStreaming(substr($data, 2), \ZLIB_ENCODING_RAW, $decodeMemoryLimit, false);
            }
        }

        if (null === $decoded || '' === $decoded) {
            throw new \Exception('decodeFilterFlateDecode: invalid data');
        }

        return $decoded;
    }

    /**
     * Incrementally inflate a deflate/zlib payload in-memory while bounding output size.
     *
     * @throws \Exception if $decodeMemoryLimit is exceeded
     */
    private function inflateStreaming(string $data, int $encoding, int $decodeMemoryLimit, bool $allowPartialOnError): ?string
    {
        $ctx = @inflate_init($encoding);
        if (false === $ctx) {
            return null;
        }

        $out = '';
        $outLen = 0;
        $dataLen = \strlen($data);
        $chunkSize = 8192;

        for ($offset = 0; $offset < $dataLen; $offset += $chunkSize) {
            $chunk = @inflate_add($ctx, substr($data, $offset, $chunkSize), \ZLIB_NO_FLUSH);
            if (false === $chunk) {
                return ($allowPartialOnError && $outLen > 0) ? $out : null;
            }
            if ('' !== $chunk) {
                $outLen += \strlen($chunk);
                if ($decodeMemoryLimit > 0 && $outLen > $decodeMemoryLimit) {
                    throw new \Exception('decodeFilterFlateDecode: decode memory limit exceeded');
                }
                $out .= $chunk;
            }
            if (\ZLIB_STREAM_END === inflate_get_status($ctx)) {
                return $outLen > 0 ? $out : null;
            }
        }

        $tail = @inflate_add($ctx, '', \ZLIB_FINISH);
        if (false === $tail) {
            return ($allowPartialOnError && $outLen > 0) ? $out : null;
        }
        if ('' !== $tail) {
            $outLen += \strlen($tail);
            if ($decodeMemoryLimit > 0 && $outLen > $decodeMemoryLimit) {
                throw new \Exception('decodeFilterFlateDecode: decode memory limit exceeded');
            }
            $out .= $tail;
        }

        return $outLen > 0 ? $out : null;
    }

    /**
     * LZWDecode
     *
     * Decompresses data encoded using the LZW (Lempel-Ziv-Welch) adaptive compression method, reproducing the original text or binary data.
     *
     * @param string $data              Data to decode
     * @param int    $decodeMemoryLimit Maximum decoded size in bytes; 0 for no limit
     * @param int    $earlyChange       1 (PDF default) or 0
     *
     * @return string Data string
     *
     * @throws \Exception
     *
     * @see https://opensource.adobe.com/dc-acrobat-sdk-docs/pdfstandards/PDF32000_2008.pdf#page=33 ISO 32000-1:2008, 7.4.4 (LZWDecode and FlateDecode filters)
     */
    protected function decodeFilterLZWDecode(string $data, int $decodeMemoryLimit = 0, int $earlyChange = 1): string
    {
        if (null === self::$lzwBaseDictionary) {
            self::$lzwBaseDictionary = [];
            for ($i = 0; $i < 256; ++$i) {
                self::$lzwBaseDictionary[$i] = \chr($i);
            }
        }

        $dataLen = \strlen($data);
        $bytePos = 0;
        $bitBuf = 0;
        $bitCount = 0;

        $bitlen = 9;
        $dix = 258;
        $dictionary = self::$lzwBaseDictionary;
        $prevIndex = -1;
        $earlyOffset = 0 === $earlyChange ? 0 : 1;

        $decoded = '';
        $decodedLen = 0;

        while (true) {
            while ($bitCount < $bitlen && $bytePos < $dataLen) {
                $bitBuf = ($bitBuf << 8) | \ord($data[$bytePos++]);
                $bitCount += 8;
            }
            if ($bitCount < $bitlen) {
                break;
            }

            $bitCount -= $bitlen;
            $code = ($bitBuf >> $bitCount) & ((1 << $bitlen) - 1);
            // Keep accumulator bounded to avoid integer overflow on 32/64-bit PHP
            $bitBuf &= (1 << $bitCount) - 1;

            if (257 === $code) { // EOD marker
                break;
            }

            if (256 === $code) { // Clear-table marker
                $bitlen = 9;
                $dix = 258;
                $prevIndex = -1;
                $dictionary = self::$lzwBaseDictionary;
                continue;
            }

            if (-1 === $prevIndex) {
                if (!isset($dictionary[$code])) {
                    break;
                }
                $entry = $dictionary[$code];
                $decoded .= $entry;
                $decodedLen += \strlen($entry);
                $prevIndex = $code;
            } else {
                if ($code < $dix) {
                    $entry = $dictionary[$code];
                    $newEntry = $dictionary[$prevIndex].$entry[0];
                } elseif ($code === $dix) {
                    $prevStr = $dictionary[$prevIndex];
                    $entry = $prevStr.$prevStr[0];
                    $newEntry = $entry;
                } else {
                    // Corrupt code out of dictionary bounds
                    break;
                }

                $decoded .= $entry;
                $decodedLen += \strlen($entry);

                if ($dix < 4096) {
                    $dictionary[$dix] = $newEntry;
                    ++$dix;
                    if ($dix === (512 - $earlyOffset)) {
                        $bitlen = 10;
                    } elseif ($dix === (1024 - $earlyOffset)) {
                        $bitlen = 11;
                    } elseif ($dix === (2048 - $earlyOffset)) {
                        $bitlen = 12;
                    }
                }
                $prevIndex = $code;
            }

            if (0 < $decodeMemoryLimit && $decodedLen > $decodeMemoryLimit) {
                throw new \Exception('decodeFilterLZWDecode: decode memory limit exceeded');
            }
        }

        return $decoded;
    }

    /**
     * RunLengthDecode
     *
     * Decompresses data encoded using a byte-oriented run-length encoding algorithm.
     *
     * @param string $data              Data to decode
     * @param int    $decodeMemoryLimit Maximum decoded size in bytes; 0 for no limit
     *
     * @return string Data string
     *
     * @throws \Exception
     *
     * @see https://opensource.adobe.com/dc-acrobat-sdk-docs/pdfstandards/PDF32000_2008.pdf#page=37 ISO 32000-1:2008, 7.4.5 (RunLengthDecode filter)
     */
    protected function decodeFilterRunLengthDecode(string $data, int $decodeMemoryLimit = 0): string
    {
        $decoded = '';
        $decodedLen = 0;
        $data_length = \strlen($data);
        $i = 0;
        while ($i < $data_length) {
            $byte = \ord($data[$i]);
            if (128 === $byte) {
                break;
            } elseif ($byte < 128) {
                $chunkLen = $byte + 1;
                if (0 < $decodeMemoryLimit && ($decodedLen + $chunkLen) > $decodeMemoryLimit) {
                    throw new \Exception('decodeFilterRunLengthDecode: decode memory limit exceeded');
                }
                $chunk = substr($data, $i + 1, $chunkLen);
                $decoded .= $chunk;
                $decodedLen += \strlen($chunk);
                $i += ($byte + 2);
            } else {
                if ($i + 1 >= $data_length) {
                    break;
                }
                $repeatLen = 257 - $byte;
                if (0 < $decodeMemoryLimit && ($decodedLen + $repeatLen) > $decodeMemoryLimit) {
                    throw new \Exception('decodeFilterRunLengthDecode: decode memory limit exceeded');
                }
                $decoded .= str_repeat($data[$i + 1], $repeatLen);
                $decodedLen += $repeatLen;
                $i += 2;
            }
        }

        return $decoded;
    }

    /**
     * @return array<int, string> list of available filters
     */
    public function getAvailableFilters(): array
    {
        return $this->availableFilters;
    }
}
