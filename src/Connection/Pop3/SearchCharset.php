<?php

namespace ImapPolyfill\Connection\Pop3;

use ImapPolyfill\Mime\MimeText;

/**
 * The charset a local search reads its terms in. POP3 has no SEARCH, so
 * what a server would check, c-client checks itself (mail_search_default):
 * the name must be in its utf8_csvalid table, and the terms are converted
 * from it to UTF-8 before anything is compared (utf8_searchpgm).
 */
final class SearchCharset
{
    /** utf8_csvalid's names, in its order, which is the order its refusal lists them in. */
    private const KNOWN = [
        'US-ASCII', 'UTF-8', 'UTF-7', 'ISO-8859-1', 'ISO-8859-2', 'ISO-8859-3', 'ISO-8859-4', 'ISO-8859-5',
        'ISO-8859-6', 'ISO-8859-7', 'ISO-8859-8', 'ISO-8859-9', 'ISO-8859-10', 'ISO-8859-11', 'ISO-8859-13',
        'ISO-8859-14', 'ISO-8859-15', 'ISO-8859-16', 'KOI8-R', 'KOI8-U', 'KOI8-RU', 'TIS-620', 'VISCII', 'GBK',
        'GB2312', 'CN-GB', 'ISO-2022-CN', 'CN-GB-12345', 'BIG5', 'CN-BIG5', 'BIG-5', 'ISO-2022-JP', 'EUC-JP',
        'SHIFT_JIS', 'SHIFT-JIS', 'ISO-2022-JP-1', 'ISO-2022-JP-2', 'ISO-2022-KR', 'EUC-KR', 'KSC5601',
        'KSC_5601', 'KS_C_5601-1987', 'KS_C_5601-1989', 'KS_C_5601-1992', 'KS_C_5601-1997', 'WINDOWS-874',
        'CP874', 'WINDOWS-936', 'CP936', 'WINDOWS-949', 'CP949', 'X-WINDOWS-949', 'WINDOWS-1250', 'CP1250',
        'WINDOWS-1251', 'CP1251', 'WINDOWS-1252', 'CP1252', 'WINDOWS-1253', 'CP1253', 'WINDOWS-1254', 'CP1254',
        'WINDOWS-1255', 'CP1255', 'WINDOWS-1256', 'CP1256', 'WINDOWS-1257', 'CP1257', 'WINDOWS-1258', 'CP1258',
        'IBM367', 'IBM437', 'IBM737', 'IBM775', 'IBM850', 'IBM852', 'IBM855', 'IBM857', 'IBM860', 'IBM861',
        'IBM862', 'IBM863', 'IBM864', 'IBM865', 'IBM866', 'IBM869', 'IBM874', 'ANSI_X3.4-1968',
        'UNICODE-1-1-UTF-7', 'UCS-2', 'UCS-4', 'UTF-16',
    ];

    private function __construct(private readonly string $name)
    {
    }

    /**
     * utf8_charset()'s lookup, case-insensitive: an empty name is a name,
     * and not one in the table. The refusal is utf8_badcharset()'s.
     *
     * @throws \RuntimeException naming every charset the table holds
     */
    public static function named(string $name): self
    {
        if (strlen($name) < 128) {
            foreach (self::KNOWN as $known) {
                if (strcasecmp($known, $name) === 0) {
                    return new self($name);
                }
            }
        }

        throw new \RuntimeException('[BADCHARSET ('.implode(' ', self::KNOWN).')] Unknown charset: '.$name);
    }

    public function toUtf8(string $term): string
    {
        return MimeText::toUtf8From($this->name, $term);
    }
}
