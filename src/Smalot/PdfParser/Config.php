<?php

declare(strict_types=1);

/**
 * @file
 *          This file is part of the PdfParser library.
 *
 * @author  Konrad Abicht <hi@inspirito.de>
 *
 * @date    2020-11-22
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

/**
 * This class contains configurations used in various classes. You can override them
 * manually, in case default values aren't working.
 *
 * @see https://github.com/smalot/pdfparser/issues/305
 */
class Config
{
    private int $fontSpaceLimit = -50;

    private string $horizontalOffset = ' ';

    /**
     * Represents: (NUL, HT, LF, FF, CR, SP)
     */
    private string $pdfWhitespaces = "\0\t\n\f\r ";

    /**
     * Represents: (NUL, HT, LF, FF, CR, SP)
     */
    private string $pdfWhitespacesRegex = '[\0\t\n\f\r ]';

    /**
     * Whether to retain raw image data as content or discard it to save memory
     */
    private bool $retainImageContent = true;

    /**
     * Maximum output size, in bytes, a single stream may be decompressed to. It is
     * the control against decompression amplification and applies to all supported
     * filters; 0 means unlimited. The default is unlimited because legitimate PDFs
     * may contain highly compressible streams (e.g. images) that decode far larger
     * than the file, which cannot be told apart from a decompression bomb by size.
     *
     * @see https://opensource.adobe.com/dc-acrobat-sdk-docs/pdfstandards/PDF32000_2008.pdf#page=657 ISO 32000-1:2008, Annex C (implementation limits)
     */
    private int $decodeMemoryLimit = 0;

    /**
     * Maximum nesting depth of arrays and dictionaries a single object may have.
     * Bounds the recursion of the raw object parser. 0 means unlimited.
     *
     * @see https://opensource.adobe.com/dc-acrobat-sdk-docs/pdfstandards/PDF32000_2008.pdf#page=657 ISO 32000-1:2008, Annex C (implementation limits)
     */
    private int $maxNestingDepth = 5000;

    /**
     * Whether to include font id and size in dataTm array
     */
    private bool $dataTmFontInfoHasToBeIncluded = false;

    /**
     * Whether to attempt to read PDFs even if they are marked as encrypted.
     */
    private bool $ignoreEncryption = false;

    public function getFontSpaceLimit(): int
    {
        return $this->fontSpaceLimit;
    }

    public function setFontSpaceLimit(int $value): void
    {
        $this->fontSpaceLimit = $value;
    }

    public function getHorizontalOffset(): string
    {
        return $this->horizontalOffset;
    }

    public function setHorizontalOffset(string $value): void
    {
        $this->horizontalOffset = $value;
    }

    public function getPdfWhitespaces(): string
    {
        return $this->pdfWhitespaces;
    }

    public function setPdfWhitespaces(string $pdfWhitespaces): void
    {
        $this->pdfWhitespaces = $pdfWhitespaces;
    }

    public function getPdfWhitespacesRegex(): string
    {
        return $this->pdfWhitespacesRegex;
    }

    public function setPdfWhitespacesRegex(string $pdfWhitespacesRegex): void
    {
        $this->pdfWhitespacesRegex = $pdfWhitespacesRegex;
    }

    public function getRetainImageContent(): bool
    {
        return $this->retainImageContent;
    }

    public function setRetainImageContent(bool $retainImageContent): void
    {
        $this->retainImageContent = $retainImageContent;
    }

    public function getDecodeMemoryLimit(): int
    {
        return $this->decodeMemoryLimit;
    }

    public function setDecodeMemoryLimit(int $decodeMemoryLimit): void
    {
        $this->decodeMemoryLimit = $decodeMemoryLimit;
    }

    public function getMaxNestingDepth(): int
    {
        return $this->maxNestingDepth;
    }

    public function setMaxNestingDepth(int $maxNestingDepth): void
    {
        $this->maxNestingDepth = $maxNestingDepth;
    }

    public function getDataTmFontInfoHasToBeIncluded(): bool
    {
        return $this->dataTmFontInfoHasToBeIncluded;
    }

    public function setDataTmFontInfoHasToBeIncluded(bool $dataTmFontInfoHasToBeIncluded): void
    {
        $this->dataTmFontInfoHasToBeIncluded = $dataTmFontInfoHasToBeIncluded;
    }

    public function getIgnoreEncryption(): bool
    {
        return $this->ignoreEncryption;
    }

    /**
     * @deprecated this is a temporary workaround, don't rely on it
     * @see https://github.com/smalot/pdfparser/pull/653
     */
    public function setIgnoreEncryption(bool $ignoreEncryption): void
    {
        $this->ignoreEncryption = $ignoreEncryption;
    }
}
