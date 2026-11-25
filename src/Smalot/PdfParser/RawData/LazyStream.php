<?php

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
     * @var string
     */
    private $rawBytes;

    /**
     * @var array<int, string>
     */
    private $filters;

    /**
     * @var array<int, array<string, int>>
     */
    private $decodeParmsList;

    /**
     * @var FilterHelper
     */
    private $filterHelper;

    /**
     * @var int
     */
    private $decodeMemoryLimit;

    /**
     * @var bool
     */
    private $ignoreFilterErrors;

    /**
     * @var bool
     */
    private $ignoreMissingDecoders;

    /**
     * @param array<int, string>             $filters
     * @param array<int, array<string, int>> $decodeParmsList
     */
    public function __construct(
        string $rawBytes,
        array $filters,
        array $decodeParmsList,
        FilterHelper $filterHelper,
        int $decodeMemoryLimit,
        bool $ignoreFilterErrors,
        bool $ignoreMissingDecoders
    ) {
        $this->rawBytes = $rawBytes;
        $this->filters = $filters;
        $this->decodeParmsList = $decodeParmsList;
        $this->filterHelper = $filterHelper;
        $this->decodeMemoryLimit = $decodeMemoryLimit;
        $this->ignoreFilterErrors = $ignoreFilterErrors;
        $this->ignoreMissingDecoders = $ignoreMissingDecoders;
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
                    if ((('~' == ($emsg[0] ?? '')) && !$this->ignoreMissingDecoders)
                        || (('~' != ($emsg[0] ?? '')) && !$this->ignoreFilterErrors)
                    ) {
                        throw new \Exception($emsg);
                    }
                }
            }
        }

        return $stream;
    }
}
