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

use Smalot\PdfParser\Element\ElementArray;
use Smalot\PdfParser\Element\ElementMissing;
use Smalot\PdfParser\Element\ElementNull;
use Smalot\PdfParser\Element\ElementXRef;

class Page extends PDFObject
{
    /**
     * Operators retained by getDataCommands() for Text Matrix (Tm) extraction.
     *
     * @var array<int, string>
     */
    private const array DATA_COMMANDS = [
        'BT', 'cm', 'ET', 'TL', 'Td', 'TD', 'Tm', 'T*',
        'Tj', "'", '"', 'Tf', 'TF', 'TJ', 'q', 'Q',
    ];

    /**
     * @var Font[]|null
     */
    protected ?array $fonts = null;

    /**
     * @var PDFObject[]|null
     */
    protected ?array $xobjects = null;

    protected ?array $dataTm = null;

    /**
     * Inherited fonts propagated from ancestor /Pages nodes (ISO 32000-1, 7.7.3.4).
     *
     * @var Font[]
     */
    protected array $inheritedFonts = [];

    /**
     * Inherited XObjects propagated from ancestor /Pages nodes (ISO 32000-1, 7.7.3.4).
     *
     * @var PDFObject[]
     */
    protected array $inheritedXObjects = [];

    /**
     * @param array<Font> $fonts
     *
     * @internal
     */
    public function setFonts(array $fonts): void
    {
        if (empty($this->fonts) && [] === $this->inheritedFonts && [] !== $fonts) {
            $this->inheritedFonts = $fonts;
        }
    }

    /**
     * @param array<PDFObject> $xobjects
     *
     * @internal
     */
    public function setXObjects(array $xobjects): void
    {
        if (empty($this->xobjects) && [] === $this->inheritedXObjects && [] !== $xobjects) {
            $this->inheritedXObjects = $xobjects;
        }
    }

    /**
     * @return Font[]
     */
    public function getFonts(): array
    {
        if (null !== $this->fonts) {
            return $this->fonts;
        }

        $resources = $this->get('Resources');

        if (method_exists($resources, 'has') && $resources->has('Font')) {
            $fontRes = $resources->get('Font');
            if ($fontRes instanceof ElementMissing) {
                return $this->inheritedFonts;
            }

            if ($fontRes instanceof Header) {
                $fonts = $fontRes->getElements();
            } else {
                $fonts = $fontRes->getHeader()->getElements();
            }

            $table = $this->inheritedFonts;

            foreach ($fonts as $id => $font) {
                if ($font instanceof Font) {
                    $table[$id] = $font;

                    // Store fallback cleaned id value (only numeric) without overwriting exact keys
                    $cleanId = preg_replace('/[^0-9\.\-_]/', '', (string) $id);
                    if ('' !== $cleanId && null !== $cleanId && !isset($table[$cleanId])) {
                        $table[$cleanId] = $font;
                    }
                }
            }

            return $this->fonts = $table;
        }

        return $this->fonts = $this->inheritedFonts;
    }

    public function getFont(string $id): ?Font
    {
        $fonts = $this->getFonts();

        // According to the PDF specs (https://www.adobe.com/content/dam/acom/en/devnet/pdf/pdfs/PDF32000_2008.pdf, page 238)
        // "The font resource name presented to the Tf operator is arbitrary, as are the names for all kinds of resources"
        // Search for the unfiltered name first and then fall back to the cleaned id.
        if (isset($fonts[$id])) {
            return $fonts[$id];
        }

        $cleanId = (string) preg_replace('/[^0-9\.\-_]/', '', $id);

        return $fonts[$cleanId] ?? null;
    }

    /**
     * Support for XObject
     *
     * @return PDFObject[]
     */
    public function getXObjects(): array
    {
        if (null !== $this->xobjects) {
            return $this->xobjects;
        }

        $resources = $this->get('Resources');

        if (method_exists($resources, 'has') && $resources->has('XObject')) {
            $xobjRes = $resources->get('XObject');
            if ($xobjRes instanceof Header) {
                $xobjects = $xobjRes->getElements();
            } else {
                $xobjects = $xobjRes->getHeader()->getElements();
            }

            $table = $this->inheritedXObjects;

            foreach ($xobjects as $id => $xobject) {
                $table[$id] = $xobject;

                // Store fallback cleaned id value (only numeric) without overwriting exact keys
                $cleanId = preg_replace('/[^0-9\.\-_]/', '', (string) $id);
                if ('' !== $cleanId && null !== $cleanId && !isset($table[$cleanId])) {
                    $table[$cleanId] = $xobject;
                }
            }

            return $this->xobjects = $table;
        }

        return $this->xobjects = $this->inheritedXObjects;
    }

    public function getXObject(string $id): ?PDFObject
    {
        $xobjects = $this->getXObjects();

        return $xobjects[$id] ?? null;
    }

    #[\Override]
    public function getText(?self $page = null): string
    {
        $contents = $this->get('Contents');
        if ($contents instanceof ElementMissing || $contents instanceof ElementNull) {
            return '';
        }

        if ($contents instanceof PDFObject) {
            $elements = $contents->getHeader()->getElements();

            if (is_numeric(key($elements))) {
                $new_content = '';

                foreach ($elements as $element) {
                    if ($element instanceof ElementXRef) {
                        $obj = $element->getObject();
                        if (null !== $obj) {
                            $new_content .= $obj->getContent();
                        }
                    } elseif (null !== $element) {
                        $new_content .= $element->getContent();
                    }
                }

                $header = new Header([], $this->document);
                $contents = new PDFObject($this->document, $header, $new_content, $this->config);
            }
        } elseif ($contents instanceof ElementArray) {
            // Create a virtual global content.
            $new_content = '';

            foreach ($contents->getContent() as $content) {
                if (null !== $content) {
                    $new_content .= $content->getContent()."\n";
                }
            }

            $header = new Header([], $this->document);
            $contents = new PDFObject($this->document, $header, $new_content, $this->config);
        }

        /*
         * Elements referencing each other on the same page can cause endless loops during text parsing.
         * To combat this we keep a recursionStack containing already parsed elements on the page.
         * The stack is only emptied here after getting text from a page.
         */
        $contentsText = $contents->getText($this);
        PDFObject::$recursionStack = [];

        return $contentsText;
    }

    /**
     * Return true if the current page is a (setasign\Fpdi\Fpdi) FPDI/FPDF document
     *
     * The metadata 'Producer' should have the value of "FPDF" . FPDF_VERSION if the
     * pdf file was generated by FPDF/Fpfi.
     *
     * @return bool true if the current page is a FPDI/FPDF document
     */
    public function isFpdf(): bool
    {
        $details = $this->document->getDetails();

        return \array_key_exists('Producer', $details)
            && \is_string($details['Producer'])
            && str_starts_with($details['Producer'], 'FPDF');
    }

    /**
     * Return the page number of the PDF document of the page object
     *
     * @return int the page number
     */
    public function getPageNumber(): int
    {
        $pages = $this->document->getPages();
        $numOfPages = \count($pages);
        for ($pageNum = 0; $pageNum < $numOfPages; ++$pageNum) {
            if ($pages[$pageNum] === $this) {
                break;
            }
        }

        return $pageNum;
    }

    /**
     * Return the Object of the page if the document is a FPDF/FPDI document
     *
     * If the document was generated by FPDF/FPDI it returns the
     * PDFObject of the given page
     *
     * @return PDFObject The PDFObject for the page
     */
    public function getPDFObjectForFpdf(): PDFObject
    {
        $pageNum = $this->getPageNumber();
        $xObjects = $this->getXObjects();

        return $xObjects[$pageNum];
    }

    /**
     * Return a new PDFObject of the document created with FPDF/FPDI
     *
     * For a document generated by FPDF/FPDI, it generates a
     * new PDFObject for that document
     *
     * @return PDFObject The PDFObject
     */
    public function createPDFObjectForFpdf(): PDFObject
    {
        $pdfObject = $this->getPDFObjectForFpdf();

        return new PDFObject(
            $pdfObject->document,
            $pdfObject->getHeader(),
            $pdfObject->getContent(),
            $pdfObject->config
        );
    }

    /**
     * Return page if document is a FPDF/FPDI document
     *
     * @return Page The page
     */
    public function createPageForFpdf(): self
    {
        $pdfObject = $this->getPDFObjectForFpdf();

        return new self(
            $pdfObject->document,
            $pdfObject->getHeader(),
            $pdfObject->getContent(),
            $pdfObject->config
        );
    }

    /**
     * Whether this FPDF/FPDI page actually has a matching XObject template for its page number.
     */
    private function hasFpdfTemplate(): bool
    {
        if (!$this->isFpdf()) {
            return false;
        }

        $pageNum = $this->getPageNumber();
        $xObjects = $this->getXObjects();

        return isset($xObjects[$pageNum]) && $xObjects[$pageNum] instanceof PDFObject;
    }

    #[\Override]
    public function getTextArray(?self $page = null): array
    {
        if ($this->hasFpdfTemplate()) {
            $pdfObject = $this->getPDFObjectForFpdf();
            $newPdfObject = $this->createPDFObjectForFpdf();

            return $newPdfObject->getTextArray($pdfObject instanceof self ? $pdfObject : $this);
        }

        $contents = $this->get('Contents');
        if ($contents instanceof ElementMissing || $contents instanceof ElementNull) {
            return [];
        }

        if ($contents instanceof PDFObject) {
            $elements = $contents->getHeader()->getElements();

            if (is_numeric(key($elements))) {
                $new_content = '';

                foreach ($elements as $element) {
                    if ($element instanceof ElementXRef) {
                        $obj = $element->getObject();
                        if (null !== $obj) {
                            $new_content .= $obj->getContent();
                        }
                    } elseif (null !== $element) {
                        $new_content .= $element->getContent();
                    }
                }

                $header = new Header([], $this->document);
                $contents = new PDFObject($this->document, $header, $new_content, $this->config);
            } else {
                try {
                    return $contents->getTextArray($this);
                } catch (\Throwable) {
                    return $contents->getTextArray();
                }
            }
        } elseif ($contents instanceof ElementArray) {
            // Create a virtual global content.
            $new_content = '';

            foreach ($contents->getContent() as $content) {
                if (null !== $content) {
                    $new_content .= $content->getContent()."\n";
                }
            }

            $header = new Header([], $this->document);
            $contents = new PDFObject($this->document, $header, $new_content, $this->config);
        }

        return $contents->getTextArray($this);
    }

    /**
     * Gets all the text data with its internal representation of the page.
     *
     * Returns an array with the data and the internal representation
     */
    public function extractRawData(): array
    {
        /*
         * Now you can get the complete content of the object with the text on it
         */
        $extractedData = [];
        $content = $this->get('Contents');
        $values = $content->getContent();
        if (\is_array($values)) {
            $text = '';
            foreach ($values as $section) {
                if (null !== $section) {
                    $text .= $section->getContent();
                }
            }
            $sectionsText = $this->getSectionsText($text);
            foreach ($sectionsText as $sectionText) {
                $commandsText = $this->getCommandsText($sectionText);
                foreach ($commandsText as $command) {
                    $extractedData[] = $command;
                }
            }
        } else {
            if ($this->hasFpdfTemplate()) {
                $content = $this->getPDFObjectForFpdf();
            }
            $sectionsText = $content instanceof PDFObject ? $content->getSectionsText($content->getContent()) : [];
            foreach ($sectionsText as $sectionText) {
                $commandsText = $content->getCommandsText($sectionText);
                foreach ($commandsText as $command) {
                    $extractedData[] = $command;
                }
            }
        }

        return $extractedData;
    }

    /**
     * Gets all the decoded text data with its internal representation from a page.
     *
     * @param array|null $extractedRawData the extracted data returned by extractRawData or
     *                                     null if extractRawData should be called
     *
     * @return array An array with the data and the internal representation
     */
    public function extractDecodedRawData(?array $extractedRawData = null): array
    {
        if (null === $extractedRawData || [] === $extractedRawData) {
            $extractedRawData = $this->extractRawData();
        }
        $currentFont = null;
        $clippedFont = null;
        $fpdfPage = $this->hasFpdfTemplate() ? $this->createPageForFpdf() : null;

        foreach ($extractedRawData as &$command) {
            if ('Tj' === $command['o'] || 'TJ' === $command['o']) {
                $data = $command['c'];
                if (!\is_array($data)) {
                    $tmpText = null !== $currentFont ? $currentFont->decodeOctal($data) : '';
                    $tmpText = str_replace(
                        ['\\\\', '\(', '\)', '\n', '\r', '\t', '\ '],
                        ['\\', '(', ')', "\n", "\r", "\t", ' '],
                        $tmpText
                    );
                    $tmpText = (string) mb_convert_encoding($tmpText, 'UTF-8', 'ISO-8859-1');
                    if (null !== $currentFont) {
                        $tmpText = $currentFont->decodeContent($tmpText);
                    }
                    $command['c'] = $tmpText;
                    continue;
                }
                $numText = \count($data);
                for ($i = 0; $i < $numText; ++$i) {
                    if (0 !== ($i % 2)) {
                        continue;
                    }
                    $tmpText = $data[$i]['c'];
                    $decodedText = null !== $currentFont ? $currentFont->decodeOctal($tmpText) : $tmpText;
                    $decodedText = str_replace(
                        ['\\\\', '\(', '\)', '\n', '\r', '\t', '\ '],
                        ['\\', '(', ')', "\n", "\r", "\t", ' '],
                        $decodedText
                    );

                    $decodedText = (string) mb_convert_encoding($decodedText, 'UTF-8', 'ISO-8859-1');

                    if (null !== $currentFont) {
                        $decodedText = $currentFont->decodeContent($decodedText);
                    }
                    $command['c'][$i]['c'] = $decodedText;
                }
            } elseif ('Tf' === $command['o'] || 'TF' === $command['o']) {
                $fontId = explode(' ', $command['c'])[0];
                // If document is a FPDI/FPDF the $page has the correct font
                $currentFont = null !== $fpdfPage ? $fpdfPage->getFont($fontId) : $this->getFont($fontId);
            } elseif ('Q' === $command['o']) {
                $currentFont = $clippedFont;
            } elseif ('q' === $command['o']) {
                $clippedFont = $currentFont;
            }
        }
        unset($command);

        return $extractedRawData;
    }

    /**
     * Gets just the Text commands that are involved in text positions and
     * Text Matrix (Tm)
     *
     * It extracts just the PDF commands that are involved with text positions, and
     * the Text Matrix (Tm). These are: BT, cm, ET, TL, Td, TD, Tm, T*, Tj, ', ", Tf, TF, TJ, q, and Q.
     *
     * @param array|null $extractedDecodedRawData The data extracted by extractDecodedRawData.
     *                                            If it is null, the method extractDecodedRawData is called.
     *
     * @return array An array with the text commands of the page
     */
    public function getDataCommands(?array $extractedDecodedRawData = null): array
    {
        if (null === $extractedDecodedRawData || [] === $extractedDecodedRawData) {
            $extractedDecodedRawData = $this->extractDecodedRawData();
        }

        $extractedData = [];
        foreach ($extractedDecodedRawData as $command) {
            if (\in_array($command['o'], self::DATA_COMMANDS, true)) {
                $extractedData[] = $command;
            }
        }

        return $extractedData;
    }

    /**
     * Gets the Text Matrix of the text in the page
     *
     * Return an array where every item is an array where the first item is the
     * Text Matrix (Tm) and the second is a string with the text data. The Text matrix
     * is an array of 6 numbers. The last 2 numbers are the coordinates X and Y of the
     * text. The first 4 numbers have to do with Scaling, Rotation and Skew of the text.
     *
     * @param array|null $dataCommands the data extracted by getDataCommands
     *                                 if null getDataCommands is called
     *
     * @return array an array with the data of the page including the Tm information
     *               of any text in the page
     */
    public function getDataTm(?array $dataCommands = null): array
    {
        // Fast single-pass path when caller doesn't supply pre-extracted $dataCommands
        if (null === $dataCommands) {
            if ($this->hasFpdfTemplate()) {
                $pdfObject = $this->getPDFObjectForFpdf();
                $newPdfObject = $this->createPDFObjectForFpdf();
                $resourceOwner = $pdfObject instanceof self ? $pdfObject : $this;
                $this->dataTm = $newPdfObject->extractDataTm($resourceOwner);

                return $this->dataTm;
            }

            $contents = $this->get('Contents');
            if ($contents instanceof ElementMissing || $contents instanceof ElementNull) {
                $this->dataTm = [];

                return [];
            }

            if ($contents instanceof PDFObject) {
                $elements = $contents->getHeader()->getElements();
                if (is_numeric(key($elements))) {
                    $new_content = '';
                    foreach ($elements as $element) {
                        if ($element instanceof ElementXRef) {
                            $obj = $element->getObject();
                            if (null !== $obj) {
                                $new_content .= $obj->getContent();
                            }
                        } elseif (null !== $element) {
                            $new_content .= $element->getContent();
                        }
                    }
                    $contents = new PDFObject($this->document, new Header([], $this->document), $new_content, $this->config);
                }
                $this->dataTm = $contents->extractDataTm($this);

                return $this->dataTm;
            }

            if ($contents instanceof ElementArray) {
                $new_content = '';
                foreach ($contents->getContent() as $part) {
                    if (null !== $part) {
                        $new_content .= $part->getContent()."\n";
                    }
                }
                $virtual = new PDFObject($this->document, new Header([], $this->document), $new_content, $this->config);
                $this->dataTm = $virtual->extractDataTm($this);

                return $this->dataTm;
            }

            $this->dataTm = [];

            return [];
        }

        if ([] === $dataCommands) {
            $dataCommands = $this->getDataCommands();
        }

        /*
         * At the beginning of a text object Tm is the identity matrix
         */
        $defaultTm = ['1', '0', '0', '1', '0', '0'];
        $concatTm = ['1', '0', '0', '1', '0', '0'];
        $graphicsStatesStack = [];
        /*
         *  Set the text leading used by T*, ' and " operators
         */
        $defaultTl = 0.0;

        /*
         *  Set default values for font data
         */
        $defaultFontId = -1;
        $defaultFontSize = 1;

        /*
         * Indexes of horizontal/vertical scaling and X,Y-coordinates in the matrix (Tm)
         */
        $hSc = 0; // horizontal scaling
        /**
         * index of vertical scaling in the array that encodes the text matrix.
         * for more information: https://github.com/smalot/pdfparser/pull/559#discussion_r1053415500
         */
        $vSc = 3;
        $x = 4;
        $y = 5;

        /*
         * x,y-coordinates of text space origin in user units
         *
         * These will be assigned the value of the currently printed string
         */
        $Tx = 0.0;
        $Ty = 0.0;

        $Tm = $defaultTm;
        $Tl = $defaultTl;
        $fontId = $defaultFontId;
        $fontSize = $defaultFontSize; // reflects fontSize set by Tf or Tfs
        $includeFontInfo = $this->config?->getDataTmFontInfoHasToBeIncluded() ?? false;

        $extractedTexts = $this->getTextArray();
        $extractedData = [];
        foreach ($dataCommands as $command) {
            // If we've used up all the texts from getTextArray(), exit
            // so we aren't accessing non-existent array indices
            // Fixes 'undefined array key' errors in Issues #575, #576
            if (\count($extractedTexts) <= \count($extractedData)) {
                break;
            }
            $currentText = $extractedTexts[\count($extractedData)];
            switch ($command['o']) {
                /*
                 * BT
                 * Begin a text object, initializing the Tm and Tlm to identity matrix
                 */
                case 'BT':
                    $Tm = $defaultTm;
                    $Tl = $defaultTl;
                    $Tx = 0.0;
                    $Ty = 0.0;
                    break;

                case 'cm':
                    $newConcatTm = explode(' ', $command['c']);
                    $TempMatrix = [];
                    // Multiply with previous concatTm
                    $TempMatrix[0] = (float) $concatTm[0] * (float) $newConcatTm[0] + (float) $concatTm[1] * (float) $newConcatTm[2];
                    $TempMatrix[1] = (float) $concatTm[0] * (float) $newConcatTm[1] + (float) $concatTm[1] * (float) $newConcatTm[3];
                    $TempMatrix[2] = (float) $concatTm[2] * (float) $newConcatTm[0] + (float) $concatTm[3] * (float) $newConcatTm[2];
                    $TempMatrix[3] = (float) $concatTm[2] * (float) $newConcatTm[1] + (float) $concatTm[3] * (float) $newConcatTm[3];
                    $TempMatrix[4] = (float) $concatTm[4] * (float) $newConcatTm[0] + (float) $concatTm[5] * (float) $newConcatTm[2] + (float) $newConcatTm[4];
                    $TempMatrix[5] = (float) $concatTm[4] * (float) $newConcatTm[1] + (float) $concatTm[5] * (float) $newConcatTm[3] + (float) $newConcatTm[5];
                    $concatTm = $TempMatrix;
                    break;

                case 'ET':
                    break;

                case 'TL':
                    // scaled text leading
                    $Tl = (float) $command['c'] * (float) $Tm[$vSc];
                    break;

                case 'Td':
                    $coord = explode(' ', $command['c']);
                    $Tx += (float) $coord[0] * (float) $Tm[$hSc];
                    $Ty += (float) $coord[1] * (float) $Tm[$vSc];
                    $Tm[$x] = (string) $Tx;
                    $Tm[$y] = (string) $Ty;
                    break;

                case 'TD':
                    $coord = explode(' ', $command['c']);
                    $Tl = -((float) $coord[1] * (float) $Tm[$vSc]);
                    $Tx += (float) $coord[0] * (float) $Tm[$hSc];
                    $Ty += (float) $coord[1] * (float) $Tm[$vSc];
                    $Tm[$x] = (string) $Tx;
                    $Tm[$y] = (string) $Ty;
                    break;

                case 'Tm':
                    $Tm = explode(' ', $command['c']);
                    $TempMatrix = [];
                    $TempMatrix[0] = (float) $Tm[0] * (float) $concatTm[0] + (float) $Tm[1] * (float) $concatTm[2];
                    $TempMatrix[1] = (float) $Tm[0] * (float) $concatTm[1] + (float) $Tm[1] * (float) $concatTm[3];
                    $TempMatrix[2] = (float) $Tm[2] * (float) $concatTm[0] + (float) $Tm[3] * (float) $concatTm[2];
                    $TempMatrix[3] = (float) $Tm[2] * (float) $concatTm[1] + (float) $Tm[3] * (float) $concatTm[3];
                    $TempMatrix[4] = (float) $Tm[4] * (float) $concatTm[0] + (float) $Tm[5] * (float) $concatTm[2] + (float) $concatTm[4];
                    $TempMatrix[5] = (float) $Tm[4] * (float) $concatTm[1] + (float) $Tm[5] * (float) $concatTm[3] + (float) $concatTm[5];
                    $Tm = $TempMatrix;
                    $Tx = (float) $Tm[$x];
                    $Ty = (float) $Tm[$y];
                    break;

                case 'T*':
                    $Ty -= $Tl;
                    $Tm[$y] = (string) $Ty;
                    break;

                case 'Tj':
                case 'TJ':
                    $data = [$Tm, $currentText];
                    if ($includeFontInfo) {
                        $data[] = $fontId;
                        $data[] = $fontSize;
                    }
                    $extractedData[] = $data;
                    break;

                case "'":
                    $Ty -= $Tl;
                    $Tm[$y] = (string) $Ty;
                    $extractedData[] = [$Tm, $currentText];
                    break;

                case '"':
                    $data = explode(' ', $currentText);
                    $Ty -= $Tl;
                    $Tm[$y] = (string) $Ty;
                    $extractedData[] = [$Tm, $data[2]]; // Verify
                    break;

                case 'Tf':
                    /*
                     * From PDF 1.0 specification, page 106:
                     *     fontname size Tf Set font and size
                     *     Sets the text font and text size in the graphics state. There is no default value for
                     *     either fontname or size; they must be selected using Tf before drawing any text.
                     *     fontname is a resource name. size is a number expressed in text space units.
                     *
                     * Source: https://ia902503.us.archive.org/10/items/pdfy-0vt8s-egqFwDl7L2/PDF%20Reference%201.0.pdf
                     * Introduced with https://github.com/smalot/pdfparser/pull/516
                     */
                    [$fontId, $fontSize] = explode(' ', $command['c'], 2);
                    break;

                case 'q':
                    $graphicsStatesStack[] = $concatTm;
                    break;

                case 'Q':
                    if ([] !== $graphicsStatesStack) {
                        $concatTm = array_pop($graphicsStatesStack);
                    }
                    break;

                default:
            }
        }
        $this->dataTm = $extractedData;

        return $extractedData;
    }

    /**
     * Gets text data that are around the given coordinates (X,Y)
     *
     * If the text is near the given coordinates (X,Y) (or the TM info),
     * the text is returned. The extractedData returned by getDataTm can be used to see
     * the coordinates of a given text, using the TM info for it.
     *
     * @param float|null $x      The X value of the coordinate to search for. If null
     *                           just the Y value is considered (same Row)
     * @param float|null $y      The Y value of the coordinate to search for. If null
     *                           just the X value is considered (same column)
     * @param float      $xError The value less or more to consider an X to be "near"
     * @param float      $yError The value less or more to consider a Y to be "near"
     *
     * @return array An array of text that are near the given coordinates. If no text
     *               is "near" the x,y coordinate, or both x and y are null, an empty array is returned.
     */
    public function getTextXY(?float $x = null, ?float $y = null, float $xError = 0, float $yError = 0): array
    {
        if (null === $this->dataTm || [] === $this->dataTm) {
            $this->getDataTm();
        }

        if (null === $x && null === $y) {
            return [];
        }

        $extractedData = [];
        foreach ($this->dataTm as $item) {
            $tm = $item[0];
            $xTm = (float) $tm[4];
            $yTm = (float) $tm[5];
            $text = $item[1];
            if (null === $y) {
                if (($xTm >= ($x - $xError)) && ($xTm <= ($x + $xError))) {
                    $extractedData[] = [$tm, $text];
                }
                continue;
            }
            if (null === $x) {
                if (($yTm >= ($y - $yError)) && ($yTm <= ($y + $yError))) {
                    $extractedData[] = [$tm, $text];
                }
                continue;
            }
            if (($xTm >= ($x - $xError))
                && ($xTm <= ($x + $xError))
                && ($yTm >= ($y - $yError))
                && ($yTm <= ($y + $yError))) {
                $extractedData[] = [$tm, $text];
            }
        }

        return $extractedData;
    }
}
