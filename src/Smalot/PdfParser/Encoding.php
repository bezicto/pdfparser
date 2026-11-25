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

use Smalot\PdfParser\Element\ElementNumeric;
use Smalot\PdfParser\Encoding\EncodingLocator;
use Smalot\PdfParser\Encoding\PostScriptGlyphs;
use Smalot\PdfParser\Exception\EncodingNotFoundException;

/**
 * Class Encoding
 */
class Encoding extends PDFObject
{
    protected array $encoding = [];

    protected array $differences = [];

    protected array $mapping = [];

    #[\Override]
    public function init(): void
    {
        $this->mapping = [];
        $this->differences = [];
        $this->encoding = [];

        if ($this->has('BaseEncoding') || $this->has('Differences')) {
            if ($this->has('BaseEncoding')) {
                $this->encoding = EncodingLocator::getTranslations($this->getEncodingClass());
                $this->mapping = $this->encoding;
            }

            // Build table including differences.
            $differences = $this->get('Differences')->getContent();
            $code = 0;

            if (!\is_array($differences)) {
                return;
            }

            foreach ($differences as $difference) {
                if ($difference instanceof ElementNumeric) {
                    $code = (int) $difference->getContent();
                    continue;
                }

                // ElementName
                $this->differences[$code] = \is_object($difference) ? $difference->getContent() : $difference;

                // For the next char.
                ++$code;
            }

            foreach ($this->differences as $code => $difference) {
                $this->mapping[$code] = $difference;
            }
        }
    }

    #[\Override]
    public function getDetails(bool $deep = true): array
    {
        $details = [];

        $details['BaseEncoding'] = $this->has('BaseEncoding') ? (string) $this->get('BaseEncoding') : 'Ansi';
        $details['Differences'] = $this->has('Differences') ? (string) $this->get('Differences') : '';

        $details += parent::getDetails($deep);

        return $details;
    }

    public function translateChar(int|string $dec): ?int
    {
        if (isset($this->mapping[$dec])) {
            $dec = $this->mapping[$dec];
        }

        return PostScriptGlyphs::getCodePoint($dec);
    }

    /**
     * Returns encoding class name if available.
     *
     * @throws EncodingNotFoundException If encoding class doesn't exist.
     */
    public function __toString(): string
    {
        return $this->getEncodingClass();
    }

    /**
     * @throws EncodingNotFoundException
     */
    protected function getEncodingClass(): string
    {
        // Load reference table charset.
        $baseEncoding = preg_replace('/[^A-Z0-9]/is', '', (string) $this->get('BaseEncoding')->getContent());

        // Check for empty BaseEncoding field value
        if (!\is_string($baseEncoding) || '' === $baseEncoding) {
            $baseEncoding = 'StandardEncoding';
        }

        $className = '\\Smalot\\PdfParser\\Encoding\\'.$baseEncoding;

        if (!class_exists($className)) {
            throw new EncodingNotFoundException('Missing encoding data for: "'.$baseEncoding.'".');
        }

        return $className;
    }
}
