<?php

namespace Smalot\PdfParser\Encoding;

class EncodingLocator
{
    protected static $encodings = [];

    /**
     * Cached translation arrays per encoding class to avoid repeated explode() calls.
     *
     * @var array<string, array<int, string>>
     */
    protected static $translations = [];

    public static function getEncoding(string $encodingClassName): AbstractEncoding
    {
        if (!isset(self::$encodings[$encodingClassName])) {
            self::$encodings[$encodingClassName] = new $encodingClassName();
        }

        return self::$encodings[$encodingClassName];
    }

    public static function getTranslations(string $encodingClassName): array
    {
        if (!isset(self::$translations[$encodingClassName])) {
            self::$translations[$encodingClassName] = self::getEncoding($encodingClassName)->getTranslations();
        }

        return self::$translations[$encodingClassName];
    }
}
