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

/**
 * Class ElementBoolean
 */
class ElementBoolean extends Element
{
    public function __construct(string|bool $value)
    {
        parent::__construct(\is_bool($value) ? $value : 'true' === strtolower($value), null);
    }

    #[\Override]
    public function getContent(): bool
    {
        return (bool) $this->value;
    }

    #[\Override]
    public function __toString(): string
    {
        return $this->value ? 'true' : 'false';
    }

    #[\Override]
    public function equals(mixed $value): bool
    {
        return $this->getContent() === $value;
    }

    #[\Override]
    public static function parse(string $content, ?Document $document = null, int &$offset = 0, bool $only_values = false): self|false
    {
        if (1 === preg_match('/^\s*(?P<value>true|false)/is', $content, $match)) {
            $value = $match['value'];
            $offset += (int) strpos($content, $value) + \strlen($value);

            return new self($value);
        }

        return false;
    }
}
