<?php

declare(strict_types=1);

namespace Smalot\PdfParser\Encoding;

class EncodingLocator
{
    /**
     * @var array<string, AbstractEncoding>
     */
    protected static array $encodings = [];

    /**
     * Cached translation arrays per encoding class to avoid repeated explode() calls.
     *
     * @var array<string, array<int, string>>
     */
    protected static array $translations = [];

    public static function getEncoding(string $encodingClassName): AbstractEncoding
    {
        return self::$encodings[$encodingClassName] ??= new $encodingClassName();
    }

    public static function getTranslations(string $encodingClassName): array
    {
        return self::$translations[$encodingClassName] ??= self::getEncoding($encodingClassName)->getTranslations();
    }
}
