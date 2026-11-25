# PDF Parser for PHP

`bezicto/pdfparser` is a pure-PHP library that reads PDF files and extracts text, page content, and document metadata — **without needing any external server tools** like `pdftotext`, Poppler, or Ghostscript.

This repository is an enhanced fork of [`smalot/pdfparser`](https://github.com/smalot/pdfparser) (originally created by Sébastien Malot). It keeps the `Smalot\PdfParser` PHP namespace for **100% drop-in compatibility** while adding memory optimizations such as **on-demand (lazy) CMap font range decoding**, preventing out-of-memory crashes when parsing academic journals, scientific papers, and complex Unicode/CID fonts.

---

## Features

- **Extract plain text** from an entire PDF or individual pages in a few lines of code
- **Read PDF metadata** such as title, author, creation date, producer, page count, and XMP data
- **Parse from anywhere** — local file paths, uploaded files, raw binary strings, or Base64 strings
- **Inspect layout details** including text coordinates (`x`, `y` positions), page dimensions, and font widths
- **Memory-friendly** — handles compressed PDFs, discards raw image streams when not needed, and lazily translates wide Unicode/math font maps (`beginbfrange`) on demand
- **Zero external binaries** — runs anywhere PHP runs (shared hosting, Docker, serverless, or local dev)

---

## Requirements

- **PHP 8.5 or newer**
- PHP `zlib` and `iconv` extensions (enabled by default in standard PHP installations)

---

## Installation

### Option 1: Via Composer (Packagist)

Run this command inside your project folder:

```bash
composer require bezicto/pdfparser
```

### Option 2: Directly from GitHub (Composer VCS)

If you want Composer to pull directly from this GitHub repository, add the `repositories` block to your project's `composer.json`:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/bezicto/pdfparser"
        }
    ],
    "require": {
        "bezicto/pdfparser": "dev-master"
    }
}
```

Then run:

```bash
composer update bezicto/pdfparser
```

### Option 3: Without Composer

If you are working on a standalone script without Composer, download or clone this repository and require `alt_autoload.php-dist` at the top of your PHP file:

```php
<?php

require_once '/path/to/pdfparser/alt_autoload.php-dist';
```

> **Migrating from `smalot/pdfparser`?**
> This package keeps the `Smalot\PdfParser` namespace and declares `"replace": { "smalot/pdfparser": "*" }` in Composer, so your existing `use Smalot\PdfParser\Parser;` code works without any changes.

---

## Quick Start

Create a PHP file (for example, `read-pdf.php`) and run it:

```php
<?php

require 'vendor/autoload.php';

use Smalot\PdfParser\Parser;

$parser = new Parser();
$pdf = $parser->parseFile('sample.pdf');

// Print all text from the PDF
echo $pdf->getText();
```

---

## Common Examples

### 1. Extract Text Page by Page

If you want to process pages individually instead of combining everything into one string:

```php
<?php

require 'vendor/autoload.php';

use Smalot\PdfParser\Parser;

$parser = new Parser();
$pdf = $parser->parseFile('sample.pdf');

$pages = $pdf->getPages();

foreach ($pages as $index => $page) {
    $pageNumber = $index + 1;
    echo "--- Page {$pageNumber} ---\n";
    echo $page->getText() . "\n\n";
}
```

You can also grab just the first page, or limit extraction to the first few pages:

```php
// Only the first page (index starts at 0)
$firstPageText = $pdf->getPages()[0]->getText();

// Only the first 3 pages combined
$firstThreePages = $pdf->getText(3);
```

### 2. Read Document Metadata (Title, Author, Page Count)

PDF files often store information about who created the file and when. Available keys vary depending on how the PDF was generated:

```php
<?php

require 'vendor/autoload.php';

use Smalot\PdfParser\Parser;

$parser = new Parser();
$pdf = $parser->parseFile('sample.pdf');

$details = $pdf->getDetails();

echo 'Title: '  . ($details['Title'] ?? 'Untitled') . "\n";
echo 'Author: ' . ($details['Author'] ?? 'Unknown') . "\n";
echo 'Pages: '  . ($details['Pages'] ?? 0) . "\n";

// See all available metadata fields
print_r($details);
```

### 3. Parse Uploaded Files or Base64 Strings (No Disk Save Required)

When handling a form upload or an API response, you do not need to permanently save the PDF first:

```php
<?php

require 'vendor/autoload.php';

use Smalot\PdfParser\Parser;

$parser = new Parser();

// From a standard PHP file upload:
$pdf = $parser->parseFile($_FILES['pdf_file']['tmp_name']);

// Or from raw PDF bytes / Base64 string in memory:
$rawBytes = base64_decode($base64EncodedPdf);
$pdf = $parser->parseContent($rawBytes);

echo $pdf->getText();
```

### 4. Recommended Setup for Large or User-Uploaded PDFs

When accepting PDFs from users or parsing heavy documents with lots of images, pass a `Config` object to keep memory usage low and safe:

```php
<?php

require 'vendor/autoload.php';

use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser;

$config = new Config();

// Skip storing raw image bytes in memory (greatly reduces RAM usage when you only need text)
$config->setRetainImageContent(false);

// Cap how large a single compressed stream can expand (e.g. 100 MB) to protect against malformed files
$config->setDecodeMemoryLimit(100 * 1024 * 1024);

$parser = new Parser([], $config);
$pdf = $parser->parseFile('uploaded-document.pdf');

echo $pdf->getText();
```

---

## Troubleshooting & Limitations

- **Getting empty text on a scanned PDF?**
  Some PDFs are simply scanned photos of paper pages and contain no selectable text layer. Because this library parses embedded PDF text rather than performing image recognition, scanned image-only PDFs require an OCR tool (such as Tesseract OCR).
- **Words stuck together or split with extra spaces?**
  PDFs position characters using coordinates rather than always storing literal space characters. You can tune spacing with `Config`:
  ```php
  $config = new \Smalot\PdfParser\Config();
  $config->setFontSpaceLimit(-60); // Default is -50; adjust if text has too many or too few spaces
  ```
- **Password-protected / encrypted PDFs:**
  Encrypted PDFs that require a password cannot currently be read and will throw `Exception: Secured pdf file are currently not supported.` (If a PDF is mistakenly flagged as encrypted without actually being locked, see `setIgnoreEncryption(true)` in [CustomConfig.md](./doc/CustomConfig.md).)
- **Interactive PDF forms:**
  Extracting user-filled interactive form field values is not currently supported.

---

## More Documentation

For advanced features such as text coordinates (`getDataTm()`), page dimensions (`MediaBox`), text width calculation, and all configuration options, check the guides in [`doc/`](./doc):

- [Usage Guide](./doc/Usage.md) — text positions, XMP metadata, page width/height, and font widths
- [Custom Configuration](./doc/CustomConfig.md) — full reference of `Config` options
- [Developer Guide](./doc/Developer.md) — running tests and code quality tools locally

---

## Credits & Acknowledgments

- **Original Author:** [Sébastien Malot](https://github.com/smalot) and all contributors to the original [`smalot/pdfparser`](https://github.com/smalot/pdfparser) project.
- **Fork Maintainer:** [Khairul Asyrani bin Sulaiman (`@bezicto`)](https://github.com/bezicto).

---

## License

This library is open-source software licensed under the [GNU Lesser General Public License v3.0 (LGPL-3.0)](./LICENSE.txt).
