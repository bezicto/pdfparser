<?php

declare(strict_types=1);

namespace PHPUnitTests\Unit;

use PHPUnitTests\TestCase;
use Smalot\PdfParser\Config;
use Smalot\PdfParser\Document;
use Smalot\PdfParser\Element;
use Smalot\PdfParser\Element\ElementArray;
use Smalot\PdfParser\Element\ElementBoolean;
use Smalot\PdfParser\Element\ElementDate;
use Smalot\PdfParser\Element\ElementMissing;
use Smalot\PdfParser\Element\ElementName;
use Smalot\PdfParser\Element\ElementNull;
use Smalot\PdfParser\Element\ElementNumeric;
use Smalot\PdfParser\Element\ElementXRef;
use Smalot\PdfParser\Encoding;
use Smalot\PdfParser\Encoding\EncodingLocator;
use Smalot\PdfParser\Encoding\PDFDocEncoding;
use Smalot\PdfParser\Encoding\PostScriptGlyphs;
use Smalot\PdfParser\Encoding\WinAnsiEncoding;
use Smalot\PdfParser\Exception\EncodingNotFoundException;
use Smalot\PdfParser\Font;
use Smalot\PdfParser\Font\FontTrueType;
use Smalot\PdfParser\Header;
use Smalot\PdfParser\Page;
use Smalot\PdfParser\Pages;
use Smalot\PdfParser\Parser;
use Smalot\PdfParser\PDFObject;
use Smalot\PdfParser\RawData\FilterHelper;
use Smalot\PdfParser\RawData\LazyStream;
use Smalot\PdfParser\XObject\Form;
use Smalot\PdfParser\XObject\Image;

/**
 * Verifies PHP 8.5+ strict typing, modernized language features, and edge-case fixes.
 */
final class Php85ModernizationTest extends TestCase
{
    public function testStrictConfigTypesAndDefaults(): void
    {
        $config = new Config();

        self::assertSame(-50, $config->getFontSpaceLimit());
        self::assertSame(' ', $config->getHorizontalOffset());
        self::assertSame("\0\t\n\f\r ", $config->getPdfWhitespaces());
        self::assertSame('[\0\t\n\f\r ]', $config->getPdfWhitespacesRegex());
        self::assertTrue($config->getRetainImageContent());
        self::assertSame(0, $config->getDecodeMemoryLimit());
        self::assertSame(5000, $config->getMaxNestingDepth());
        self::assertFalse($config->getDataTmFontInfoHasToBeIncluded());
        self::assertFalse($config->getIgnoreEncryption());

        $config->setFontSpaceLimit(-75);
        $config->setHorizontalOffset("\t");
        $config->setMaxNestingDepth(100);
        $config->setDecodeMemoryLimit(2048);
        $config->setDataTmFontInfoHasToBeIncluded(true);
        $config->setIgnoreEncryption(true);

        self::assertSame(-75, $config->getFontSpaceLimit());
        self::assertSame("\t", $config->getHorizontalOffset());
        self::assertSame(100, $config->getMaxNestingDepth());
        self::assertSame(2048, $config->getDecodeMemoryLimit());
        self::assertTrue($config->getDataTmFontInfoHasToBeIncluded());
        self::assertTrue($config->getIgnoreEncryption());
    }

    public function testElementBooleanAcceptsNativeBoolAndStringsUnderStrictTypes(): void
    {
        $trueBool = new ElementBoolean(true);
        $falseBool = new ElementBoolean(false);
        $trueStr = new ElementBoolean('TRUE');
        $falseStr = new ElementBoolean('false');

        self::assertTrue($trueBool->getContent());
        self::assertSame('true', (string) $trueBool);
        self::assertFalse($falseBool->getContent());
        self::assertSame('false', (string) $falseBool);
        self::assertTrue($trueStr->getContent());
        self::assertFalse($falseStr->getContent());
    }

    public function testElementStandaloneNullAndFalseReturnTypes(): void
    {
        $missing = new ElementMissing();
        $null = new ElementNull();

        self::assertFalse($missing->getContent());
        self::assertNull($null->getContent());
        self::assertTrue($null->equals(null));
        self::assertFalse($null->equals(false));
        self::assertFalse($missing->equals(false));
    }

    public function testElementNumericAcceptsStringIntAndFloat(): void
    {
        self::assertSame(42.0, (new ElementNumeric(42))->getContent());
        self::assertSame(3.14, (new ElementNumeric(3.14))->getContent());
        self::assertSame(-7.5, (new ElementNumeric('-7.5'))->getContent());
    }

    public function testElementDateSupportsDateTimeImmutableInEquals(): void
    {
        $dt = new \DateTime('2026-01-15 10:20:30+00:00');
        $immutableSame = new \DateTimeImmutable('2026-01-15 10:20:30+00:00');
        $immutableDiff = new \DateTimeImmutable('2026-01-15 10:20:31+00:00');

        $element = new ElementDate($dt);

        self::assertTrue($element->equals($immutableSame));
        self::assertFalse($element->equals($immutableDiff));
        self::assertFalse($element->equals('not-a-date'));
    }

    public function testElementXRefNullSafeDocumentLookup(): void
    {
        $xrefWithoutDoc = new ElementXRef('1_0');
        self::assertNull($xrefWithoutDoc->getObject());

        $document = new Document();
        $obj = new PDFObject($document);
        $document->setObjects(['1_0' => $obj]);

        $xrefWithDoc = new ElementXRef('1_0', $document);
        self::assertSame($obj, $xrefWithDoc->getObject());
    }

    public function testElementArrayResolvesIntegerIndexedXRefsUnderStrictTypes(): void
    {
        $document = new Document();
        $page = new Page($document);
        $document->setObjects(['5_0' => $page]);

        $array = new ElementArray([new ElementXRef('5_0', $document)], $document);
        $resolved = $array->getContent();

        self::assertCount(1, $resolved);
        self::assertSame($page, $resolved[0]);
    }

    public function testElementParseSupportsNamedArgumentsAndExplicitOnlyValues(): void
    {
        $offset = 0;
        $elements = Element::parse(content: '/Foo /Bar', document: null, position: $offset, only_values: true);

        self::assertIsArray($elements);
        self::assertCount(2, $elements);
        self::assertInstanceOf(ElementName::class, $elements[0]);
        self::assertSame('Foo', $elements[0]->getContent());
        self::assertSame('Bar', $elements[1]->getContent());
    }

    public function testHeaderResolvesNumericKeysAndUsesClassKeyword(): void
    {
        $document = new Document();
        $obj = new PDFObject($document);
        $document->setObjects(['7_0' => $obj]);

        $header = Header::parse('[7 0 R]', $document);
        $elements = $header->getElements();
        $types = $header->getElementTypes();

        self::assertCount(1, $elements);
        self::assertInstanceOf(ElementXRef::class, $elements[0]);
        self::assertSame([0 => ElementXRef::class], $types);
    }

    public function testEncodingToStringAlwaysThrowsOnUnknownEncoding(): void
    {
        $encoding = new Encoding(new Document(), new Header(['BaseEncoding' => new Element('DoesNotExist')]));

        $this->expectException(EncodingNotFoundException::class);
        (string) $encoding;
    }

    public function testEncodingLocatorCachesInstancesAndTranslations(): void
    {
        $enc1 = EncodingLocator::getEncoding(WinAnsiEncoding::class);
        $enc2 = EncodingLocator::getEncoding(WinAnsiEncoding::class);

        self::assertSame($enc1, $enc2);
        self::assertSame(
            EncodingLocator::getTranslations(WinAnsiEncoding::class),
            $enc1->getTranslations()
        );
    }

    public function testPdfDocEncodingAndPostScriptGlyphsConstants(): void
    {
        self::assertSame("\u{20AC}", PDFDocEncoding::convertPDFDoc2UTF8("\xa0"));
        self::assertSame(0x0041, PostScriptGlyphs::getCodePoint('A'));
        self::assertSame(0x0410, PostScriptGlyphs::getCodePoint('uni0410'));
        self::assertSame(0x1F600, PostScriptGlyphs::getCodePoint('u1F600'));
        self::assertNull(PostScriptGlyphs::getCodePoint('u999999'));
        self::assertNull(PostScriptGlyphs::getCodePoint(999999));
    }

    public function testPdfObjectFactoryCoversAllObjectTypesAndImplicitXObjects(): void
    {
        $document = new Document();
        $discardConfig = new Config();
        $discardConfig->setRetainImageContent(false);

        $image = PDFObject::factory(
            $document,
            new Header(['Type' => new ElementName('XObject'), 'Subtype' => new ElementName('Image')]),
            'raw-image-bytes',
            $discardConfig
        );
        self::assertInstanceOf(Image::class, $image);
        self::assertNull($image->getContent());

        $implicitForm = PDFObject::factory(
            $document,
            new Header(['Subtype' => new ElementName('Form')]),
            'BT (Hi) Tj ET'
        );
        self::assertInstanceOf(Form::class, $implicitForm);

        $trueTypeFont = PDFObject::factory(
            $document,
            new Header(['Type' => new ElementName('Font'), 'Subtype' => new ElementName('TrueType')]),
            null
        );
        self::assertInstanceOf(FontTrueType::class, $trueTypeFont);

        $unknownFont = PDFObject::factory(
            $document,
            new Header(['Type' => new ElementName('Font'), 'Subtype' => new ElementName('CustomUnknown')]),
            null
        );
        self::assertSame(Font::class, $unknownFont::class);
    }

    public function testFontOctalMaskPreventsPhp85ChrDeprecationWarning(): void
    {
        set_error_handler(static function (int $severity, string $message): never {
            throw new \ErrorException($message, 0, $severity);
        });

        try {
            // \777 = 511 (> 255); masking & 0xFF prevents PHP 8.5 chr() out-of-range deprecation
            self::assertSame("\xFF", Font::decodeOctal('\\777'));
        } finally {
            restore_error_handler();
        }
    }

    public function testPagesSetupFontsHandlesMissingFontResourceCleanly(): void
    {
        $document = new Document();
        $page = new Page($document);
        $resources = new Header(['Font' => new ElementMissing()]);
        $pages = new Pages($document, new Header([
            'Resources' => $resources,
            'Kids' => new ElementArray([$page]),
        ]));

        $result = $pages->getPages(true);

        self::assertSame([$page], $result);
        self::assertSame([], $page->getFonts());
    }

    public function testParserTrailerIdArrayIsParsedAsElementArray(): void
    {
        $pdf = $this->createPdf(
            [
                1 => '<< /Type /Catalog /Pages 2 0 R >>',
                2 => '<< /Type /Pages /Kids [] /Count 0 >>',
            ],
            '/ID [<AABB> <CCDD>] '
        );

        $document = (new Parser())->parseContent($pdf);
        $idElement = $document->getTrailer()->get('Id');

        self::assertInstanceOf(ElementArray::class, $idElement);
        self::assertCount(2, $idElement->getRawContent());
    }

    public function testLazyStreamDecodesOnceAndReleasesRawBytes(): void
    {
        $compressed = (string) gzcompress('Hello PHP 8.5', 9);
        $lazy = new LazyStream(
            $compressed,
            ['FlateDecode'],
            [],
            new FilterHelper(),
            0,
            false,
            false
        );

        self::assertSame('Hello PHP 8.5', $lazy->decode());
    }

    public function testDocumentGetFirstFontUsesArrayFirst(): void
    {
        $document = new Document();
        $font1 = new Font($document, new Header(['Type' => new ElementName('Font'), 'BaseFont' => new ElementName('F1')]));
        $font2 = new Font($document, new Header(['Type' => new ElementName('Font'), 'BaseFont' => new ElementName('F2')]));

        $document->setObjects(['10_0' => $font1, '20_0' => $font2]);

        self::assertSame($font1, $document->getFirstFont());
    }
}
