<?php

declare(strict_types=1);

/**
 * @file
 *          This file is part of the PdfParser library.
 *
 * @license LGPLv3
 *
 * @url     <https://github.com/bezicto/pdfparser>
 */

namespace Smalot\PdfParser\RawData;

/**
 * Holds compressed stream bytes and filter metadata so streams are decompressed
 * on-demand on first PDFObject::getContent() access instead of eagerly during parseFile().
 *
 * @internal
 */
class LazyStream
{
    /**
     * @param array<int, string>             $filters
     * @param array<int, array<string, int>> $decodeParmsList
     */
    public function __construct(
        private string $rawBytes,
        private readonly array $filters,
        private readonly array $decodeParmsList,
        private readonly FilterHelper $filterHelper,
        private readonly int $decodeMemoryLimit,
        private readonly bool $ignoreFilterErrors,
        private readonly bool $ignoreMissingDecoders,
    ) {
    }

    /**
     * Decompress the stream and release the raw compressed bytes immediately.
     *
     * @throws \Exception
     */
    public function decode(): string
    {
        $stream = $this->rawBytes;
        $this->rawBytes = '';

        $availableFilters = $this->filterHelper->getAvailableFilters();
        foreach ($this->filters as $idx => $filter) {
            if (\in_array($filter, $availableFilters, true)) {
                $parms = $this->decodeParmsList[$idx] ?? [];
                try {
                    $stream = $this->filterHelper->decodeFilter($filter, $stream, $this->decodeMemoryLimit, $parms);
                } catch (\Exception $e) {
                    $emsg = $e->getMessage();
                    $isMissingDecoder = str_starts_with($emsg, '~');
                    if (($isMissingDecoder && !$this->ignoreMissingDecoders)
                        || (!$isMissingDecoder && !$this->ignoreFilterErrors)
                    ) {
                        throw new \Exception($emsg);
                    }
                }
            }
        }

        return $stream;
    }
}
