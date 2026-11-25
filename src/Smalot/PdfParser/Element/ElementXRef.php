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

namespace Smalot\PdfParser\Element;

use Smalot\PdfParser\Document;
use Smalot\PdfParser\Element;
use Smalot\PdfParser\PDFObject;

/**
 * Class ElementXRef
 */
class ElementXRef extends Element
{
    public function getId(): string
    {
        return (string) $this->getContent();
    }

    public function getObject(): PDFObject|Element|null
    {
        return $this->document?->getObjectById($this->getId());
    }

    #[\Override]
    public function equals(mixed $value): bool
    {
        /**
         * In case $value is a number and $this->value is a string like 5_0
         *
         * Without this if-clause code like:
         *
         *      $element = new ElementXRef('5_0');
         *      $this->assertTrue($element->equals(5));
         *
         * would fail (= 5_0 and 5 are not equal in PHP 8.0+).
         */
        $content = $this->getContent();
        if (
            is_numeric($value)
            && \is_string($content)
            && 1 === preg_match('/[0-9]+\_[0-9]+/', $content)
        ) {
            return (float) $content == $value;
        }

        $id = $value instanceof self ? $value->getId() : $value;

        return $this->getId() == $id;
    }

    #[\Override]
    public function __toString(): string
    {
        return '#Obj#'.$this->getId();
    }

    #[\Override]
    public static function parse(string $content, ?Document $document = null, int &$offset = 0, bool $only_values = false): self|false
    {
        if (1 === preg_match('/^\s*(?P<id>[0-9]+\s+[0-9]+\s+R)/s', $content, $match)) {
            $id = $match['id'];
            $offset += (int) strpos($content, $id) + \strlen($id);
            $id = str_replace(' ', '_', rtrim($id, ' R'));

            return new self($id, $document);
        }

        return false;
    }
}
