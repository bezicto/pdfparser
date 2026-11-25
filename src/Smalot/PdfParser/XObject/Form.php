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

namespace Smalot\PdfParser\XObject;

use Smalot\PdfParser\Header;
use Smalot\PdfParser\Page;
use Smalot\PdfParser\PDFObject;

/**
 * Class Form
 */
class Form extends Page
{
    /**
     * Memoized extracted text keyed by resource context.
     *
     * @var array<int|string, string>
     */
    private array $textCache = [];

    #[\Override]
    public function getText(?Page $page = null): string
    {
        $content = $this->getContent();
        if (null === $content || '' === $content) {
            return ' ';
        }

        $uniqueId = $this->getUniqueId();
        if (isset(PDFObject::$recursionStack[$uniqueId])) {
            return ' ';
        }

        $hasOwnResources = $this->has('Resources');
        $resourceOwner = ($hasOwnResources || null === $page) ? $this : $page;
        $cacheKey = $hasOwnResources ? 'self' : (null !== $page ? spl_object_id($page) : 'null');

        if (isset($this->textCache[$cacheKey])) {
            return $this->textCache[$cacheKey];
        }

        PDFObject::$recursionStack[$uniqueId] = true;
        try {
            $header = new Header([], $this->document);
            $contents = new PDFObject($this->document, $header, $content, $this->config);

            return $this->textCache[$cacheKey] = $contents->getText($resourceOwner);
        } finally {
            unset(PDFObject::$recursionStack[$uniqueId]);
        }
    }
}
