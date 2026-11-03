<?php

namespace App\Support;

/** Languages client-facing documents and mails can be written in. */
final class DocumentLocale
{
    public const DEFAULT = 'de';

    public const ALL = ['de', 'en'];

    public static function rule(): string
    {
        return 'in:'.implode(',', self::ALL);
    }
}
