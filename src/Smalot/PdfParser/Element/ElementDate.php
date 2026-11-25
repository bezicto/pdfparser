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

/**
 * Class ElementDate
 */
class ElementDate extends ElementString
{
    /**
     * @var array<int, string>
     */
    protected static array $formats = [
        4 => 'Y',
        6 => 'Ym',
        8 => 'Ymd',
        10 => 'YmdH',
        12 => 'YmdHi',
        14 => 'YmdHis',
        15 => 'YmdHise',
        17 => 'YmdHisO',
        18 => 'YmdHisO',
        19 => 'YmdHisO',
    ];

    protected string $format = 'c';

    public function __construct(mixed $value)
    {
        if (!($value instanceof \DateTime)) {
            throw new \Exception('DateTime required.');
        }

        parent::__construct($value);
    }

    public function setFormat(string $format): void
    {
        $this->format = $format;
    }

    #[\Override]
    public function equals(mixed $value): bool
    {
        if ($value instanceof \DateTimeInterface) {
            $timestamp = $value->getTimestamp();
        } else {
            $timestamp = strtotime((string) $value);
        }

        return false !== $timestamp && $timestamp === $this->value->getTimestamp();
    }

    #[\Override]
    public function __toString(): string
    {
        return $this->value->format($this->format);
    }

    #[\Override]
    public static function parse(string $content, ?Document $document = null, int &$offset = 0, bool $only_values = false): self|false
    {
        if (1 === preg_match('/^\s*\(D\:(?P<name>.*?)\)/s', $content, $match)) {
            $name = str_replace("'", '', $match['name']);
            $date = false;

            // Smallest format : Y
            // Full format     : YmdHisP
            if (1 === preg_match('/^\d{4}(\d{2}(\d{2}(\d{2}(\d{2}(\d{2}(Z(\d{2,4})?|[\+-]?\d{2}(\d{2})?)?)?)?)?)?)?$/', $name)) {
                if (false !== ($pos = strpos($name, 'Z'))) {
                    $name = substr($name, 0, $pos + 1);
                } elseif (18 === \strlen($name) && 1 === preg_match('/[^\+-]0000$/', $name)) {
                    $name = substr($name, 0, -4).'+0000';
                }

                $nameLen = \strlen($name);
                if (!isset(self::$formats[$nameLen])) {
                    return false;
                }
                $format = self::$formats[$nameLen];
                $date = \DateTime::createFromFormat($format, $name, new \DateTimeZone('UTC'));
            } elseif (1 === preg_match('/^\d{1,2}-\d{1,2}-\d{4},?\s+\d{2}:\d{2}:\d{2}[\+-]\d{4}$/', $name)) {
                // special cases
                $name = str_replace(',', '', $name);
                $date = \DateTime::createFromFormat('n-j-Y H:i:sO', $name, new \DateTimeZone('UTC'));
            }

            if (false === $date) {
                return false;
            }

            $offset += (int) strpos($content, '(D:') + \strlen($match['name']) + 4; // 1 for '(D:' and ')'

            return new self($date);
        }

        return false;
    }
}
