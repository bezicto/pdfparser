<?php

/**
 * @file This file is part of the PdfParser library.
 *
 * @license LGPLv3
 *
 * @url     <https://github.com/bezicto/pdfparser>
 */

namespace PerformanceTests\Test;

use PerformanceTests\AbstractPerformanceTest;
use Smalot\PdfParser\Parser;

/**
 * Verifies that graphics-heavy PDFs with multi-megabyte vector Form XObjects
 * (e.g. Issue356.pdf with >21 MB of path commands and only a handful of BT blocks)
 * parse and extract text quickly via ContentStreamLexer and lazy stream decoding.
 */
final class GraphicsHeavyContentStreamTest extends AbstractPerformanceTest
{
    /**
     * @var string
     */
    private $file;

    public function init(): void
    {
        $this->file = __DIR__.'/../../../samples/bugs/Issue356.pdf';
    }

    public function run(): void
    {
        $document = (new Parser())->parseFile($this->file);
        $text = $document->getText();

        if (false === strpos($text, 'Ημερήσια έκθεση επιδημιολογικής')) {
            throw new \RuntimeException('Expected text not extracted from Issue356.pdf.');
        }
    }

    public function getMaxEstimatedTime(): int
    {
        return 2;
    }
}
