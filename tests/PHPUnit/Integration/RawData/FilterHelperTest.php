<?php

/**
 * @file This file is part of the PdfParser library.
 *
 * @author  Konrad Abicht <k.abicht@gmail.com>
 *
 * @date    2020-06-01
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

namespace PHPUnitTests\Integration\RawData;

use PHPUnitTests\TestCase;
use Smalot\PdfParser\Parser;
use Smalot\PdfParser\RawData\FilterHelper;

class FilterHelperTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->fixture = new FilterHelper();
    }

    /*
     * Tests for filter ASCII85Decode
     */

    public function testDecodeFilterASCII85Decode(): void
    {
        $compressed = '6Z6g\Eb0<5ARlp)FE2)5B)'; // = Compressed string
        $result = $this->fixture->decodeFilter('ASCII85Decode', $compressed);

        $this->assertEquals('Compressed string', $result);
    }

    public function testDecodeFilterASCII85DecodeInitSequence(): void
    {
        $compressed = '<~6Z6g\Eb0<5ARlp)FE2)5B)'; // = Compressed string
        $result = $this->fixture->decodeFilter('ASCII85Decode', $compressed);

        $this->assertEquals('Compressed string', $result);
    }

    public function testDecodeFilterASCII85DecodeEndSequence(): void
    {
        $compressed = '6Z6g\Eb0<5ARlp)FE2)5B)~>'; // = Compressed string
        $result = $this->fixture->decodeFilter('ASCII85Decode', $compressed);

        $this->assertEquals('Compressed string', $result);
    }

    public function testDecodeFilterASCII85DecodeSpecificEndSequence(): void
    {
        $compressed = '+^6b<~>'; // = 0x215B33C0 = "![3\xC0"
        $result = $this->fixture->decodeFilter('ASCII85Decode', $compressed);

        $this->assertEquals("\x21\x5B\x33\xC0", $result);
    }

    /*
     * Tests for filter ASCIIHexDecode
     */

    public function testDecodeFilterASCIIHexDecode(): void
    {
        $compressed = '43 6f 6d 70 72 65 73 73 65 64 20 73 74 72 69 6e 67'; // = Compressed string
        $result = $this->fixture->decodeFilter('ASCIIHexDecode', $compressed);

        $this->assertEquals('Compressed string', $result);
    }

    /*
     * Tests for filter FlateDecode
     */

    public function testDecodeFilterFlateDecode(): void
    {
        $compressed = gzcompress('Compress me', 9);
        $result = $this->fixture->decodeFilter('FlateDecode', $compressed);

        $this->assertEquals('Compress me', $result);
    }

    /**
     * How does function behave if an empty string was given.
     */
    public function testDecodeFilterFlateDecodeEmptyString(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('decodeFilterFlateDecode: invalid data');

        $this->fixture->decodeFilter('FlateDecode', '');
    }

    /**
     * How does function behave if an uncompressed string was given.
     */
    public function testDecodeFilterFlateDecodeUncompressedString(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('decodeFilterFlateDecode: invalid data');

        $this->fixture->decodeFilter('FlateDecode', 'something');
    }

    /**
     * How does function behave if compression checksum is CRC32 instead of Adler-32.
     * See: https://github.com/smalot/pdfparser/issues/592
     */
    public function testDecodeFilterFlateDecodeCRC32Checksum(): void
    {
        $document = (new Parser())->parseFile($this->rootDir.'/samples/bugs/Issue592.pdf');

        self::assertStringContainsString('Two Westbrook Corporate Center Suite 500', $document->getText());
    }

    /**
     * How does function behave if an unknown filter name was given.
     */
    public function testDecodeFilterUnknownFilter(): void
    {
        $result = $this->fixture->decodeFilter('a string '.rand(), 'something');
        $this->assertEquals('something', $result);
    }

    /*
     * Test for filters not being implemented yet.
     */

    /**
     * CCITTFaxDecode
     */
    public function testDecodeFilterCCITTFaxDecode(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Decode CCITTFaxDecode not implemented yet.');

        $this->fixture->decodeFilter('CCITTFaxDecode', '');
    }

    /**
     * Crypt
     */
    public function testDecodeFilterCrypt(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Decode Crypt not implemented yet.');

        $this->fixture->decodeFilter('Crypt', '');
    }

    /**
     * DCTDecode
     */
    public function testDecodeFilterDCTDecode(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Decode DCTDecode not implemented yet.');

        $this->fixture->decodeFilter('DCTDecode', '');
    }

    /**
     * JBIG2Decode
     */
    public function testDecodeFilterJBIG2Decode(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Decode JBIG2Decode not implemented yet.');

        $this->fixture->decodeFilter('JBIG2Decode', '');
    }

    /**
     * JPXDecode
     */
    public function testDecodeFilterJPXDecode(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Decode JPXDecode not implemented yet.');

        $this->fixture->decodeFilter('JPXDecode', '');
    }

    /*
     * Tests for filter RunLengthDecode
     */

    /**
     * A valid RunLength stream decodes to the same bytes independently of any
     * decode memory limit: "\x02ABC" copies 3 literal bytes, "\xFEX" repeats 'X'
     * three times and "\x80" is the EOD marker. An output-size bound added to this
     * filter must leave small, valid streams like this unchanged.
     */
    public function testDecodeFilterRunLengthDecode(): void
    {
        $result = $this->fixture->decodeFilter('RunLengthDecode', "\x02ABC\xFEX\x80");

        $this->assertSame('ABCXXX', $result);
    }

    public function testDecodeFilterRunLengthDecodeTruncatedTrailingByte(): void
    {
        // Trailing repeat marker 0xFE without a following byte must not fail
        $result = $this->fixture->decodeFilter('RunLengthDecode', "\x02ABC\xFE");

        $this->assertSame('ABC', $result);
    }

    public function testDecodeFilterASCII85DecodeZeroShortcutAndTrailingData(): void
    {
        $this->assertSame("\x00\x00\x00\x00", $this->fixture->decodeFilter('ASCII85Decode', 'z~>'));
        $this->assertSame("\x00\x00\x00\x00Hello", $this->fixture->decodeFilter('ASCII85Decode', 'z87cURDZ~>trailing'));
    }

    public function testDecodeFilterASCIIHexDecodeOddDigitsPerPdfSpec(): void
    {
        // ISO 32000-1:2008, 7.4.2: odd trailing hex digit behaves as if followed by 0
        $this->assertSame("\x41\x40", $this->fixture->decodeFilter('ASCIIHexDecode', '414>'));
    }

    public function testDecodeFilterLZWDecodeKwKwKAndMemoryLimit(): void
    {
        // Encode "ABABABA" with 9-bit LZW codes (Clear=256, A=65, B=66, AB=258, BA=259, ABA=260, EOD=257)
        // Includes KwKwK case (code 260 emitted immediately as it is entered into dictionary)
        $codes = [256, 65, 66, 258, 260, 257];
        $bits = '';
        foreach ($codes as $c) {
            $bits .= str_pad(decbin($c), 9, '0', \STR_PAD_LEFT);
        }
        $bits = str_pad($bits, (int) (ceil(\strlen($bits) / 8) * 8), '0', \STR_PAD_RIGHT);
        $bytes = '';
        for ($i = 0; $i < \strlen($bits); $i += 8) {
            $bytes .= \chr((int) bindec(substr($bits, $i, 8)));
        }

        $this->assertSame('ABABABA', $this->fixture->decodeFilter('LZWDecode', $bytes));

        // Verify decodeMemoryLimit is enforced
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('decode memory limit exceeded');
        $this->fixture->decodeFilter('LZWDecode', $bytes, 3);
    }

    public function testDecodePredictorPngAndTiff(): void
    {
        // 3 columns, 2 rows of raw bytes: row0 = [10, 20, 30], row1 = [15, 25, 35]
        $expected = "\x0A\x14\x1E\x0F\x19\x23";

        // Predictor 1 (no prediction) returns data unchanged
        $this->assertSame($expected, $this->fixture->decodePredictor($expected, 1, 3));

        // PNG Sub (filter=1): diffs against reconstructed left byte
        $pngSub = "\x01\x0A\x0A\x0A\x01\x0F\x0A\x0A";
        $this->assertSame($expected, $this->fixture->decodePredictor($pngSub, 11, 3));

        // PNG Up (filter=2)
        $pngUp = "\x02\x0A\x14\x1E\x02\x05\x05\x05";
        $this->assertSame($expected, $this->fixture->decodePredictor($pngUp, 12, 3));

        // PNG Average (filter=3)
        // row0: [10, 20-(10>>1)=15, 30-(20>>1)=20], row1: [15-(10>>1)=10, 25-((15+20)>>1)=8, 35-((25+30)>>1)=8]
        $pngAvg = "\x03\x0A\x0F\x14\x03\x0A\x08\x08";
        $this->assertSame($expected, $this->fixture->decodePredictor($pngAvg, 13, 3));

        // PNG Paeth (filter=4)
        // row0: left=[0,10,20], up=[0,0,0], upleft=[0,0,0] -> pr=[0,10,20] -> enc=[10,10,10]
        // row1: [15-10=5, paeth(15,20,10)=20 -> 25-20=5, paeth(25,30,20)=30 -> 35-30=5]
        $pngPaeth = "\x04\x0A\x0A\x0A\x04\x05\x05\x05";
        $this->assertSame($expected, $this->fixture->decodePredictor($pngPaeth, 14, 3));

        // TIFF Predictor 2 (horizontal differencing, bpp=1)
        $tiff = "\x0A\x0A\x0A\x0F\x0A\x0A";
        $this->assertSame($expected, $this->fixture->decodePredictor($tiff, 2, 3));
    }
}
