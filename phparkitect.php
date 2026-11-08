<?php

declare(strict_types=1);

use Arkitect\ClassSet;
use Arkitect\CLI\Config;
use Arkitect\Expression\ForClasses\NotDependsOnTheseNamespaces;
use Arkitect\Expression\ForClasses\NotResideInTheseNamespaces;
use Arkitect\Expression\ForClasses\ResideInOneOfTheseNamespaces;
use Arkitect\Rules\Rule;

return static function (Config $config): void {
    $src = ClassSet::fromDir(__DIR__.'/src');

    $rules = [];

    $rules[] = Rule::allClasses()
        ->except(
            'ImapPolyfill\Connection\MessageCache',
            'ImapPolyfill\Connection\Imap\ImapEngineConnection',
            'ImapPolyfill\Connection\Protocol',
            'ImapPolyfill\Connection\Pop3\Pop3Backend',
        )
        ->that(new ResideInOneOfTheseNamespaces('ImapPolyfill', 'IMAP'))
        ->should(new NotDependsOnTheseNamespaces(['ImapPolyfill\Connection\MessageCache']))
        ->because('only the backend sees the responses that change it (untagged EXISTS, RECENT and EXPUNGE over IMAP; STAT and expunge over POP3), and a count or uid read from it anywhere else is a second copy that goes stale');

    $rules[] = Rule::allClasses()
        ->except('ImapPolyfill\Connection\Protocol')
        ->that(new ResideInOneOfTheseNamespaces('ImapPolyfill', 'IMAP'))
        ->andThat(new NotResideInTheseNamespaces('ImapPolyfill\Connection\Imap'))
        ->should(new NotDependsOnTheseNamespaces(['DirectoryTree\ImapEngine']))
        ->because('replacing the IMAP library must stay a change to the wire layer, so the layers above catch and type only the polyfill\'s own classes');

    $rules[] = Rule::allClasses()
        ->that(new ResideInOneOfTheseNamespaces('ImapPolyfill\Session'))
        ->should(new NotDependsOnTheseNamespaces([
            'ImapPolyfill\Connection\Protocol',
            'ImapPolyfill\Connection\Imap',
            'ImapPolyfill\Connection\Pop3',
        ]))
        ->because('the imap_* contract has to hold identically over IMAP and POP3, which it can only if it reaches both through the same ConnectionBackend');

    $rules[] = Rule::allClasses()
        ->that(new ResideInOneOfTheseNamespaces('IMAP\Connection'))
        ->should(new NotDependsOnTheseNamespaces(['ImapPolyfill\Support\ErrorStack']))
        ->because('whether a failure becomes a stack message, a false or nothing at all is each imap_* function\'s decision (imap_ping() logs nothing for a dead stream), so the connection throws and lets the wrapper choose');

    $rules[] = Rule::allClasses()
        ->that(new ResideInOneOfTheseNamespaces('ImapPolyfill\Message', 'ImapPolyfill\Mime', 'ImapPolyfill\Address'))
        ->should(new NotDependsOnTheseNamespaces(['ImapPolyfill\Session']))
        ->because('they also serve the connectionless functions (imap_utf8(), imap_rfc822_parse_adrlist(), imap_rfc822_parse_headers(), imap_mail_compose()), which have no session to reach');

    $rules[] = Rule::allClasses()
        ->that(new ResideInOneOfTheseNamespaces('ImapPolyfill\Message'))
        ->should(new NotDependsOnTheseNamespaces(
            ['ImapPolyfill\Connection', 'IMAP'],
            ['ImapPolyfill\Connection\UidMode', 'ImapPolyfill\Connection\UidTable'],
        ))
        ->because('the builders turn what the server already answered into ext-imap\'s shapes and must work on that data alone, as imap_rfc822_parse_headers() does; UidMode and UidTable are plain values both layers hold, not wire');

    $rules[] = Rule::allClasses()
        ->that(new ResideInOneOfTheseNamespaces('ImapPolyfill\Mime', 'ImapPolyfill\Address'))
        ->should(new NotDependsOnTheseNamespaces(['ImapPolyfill\Connection', 'IMAP']))
        ->because('encoding, decoding and address parsing are whole imap_* functions of their own (imap_utf8(), imap_rfc822_parse_adrlist(), imap_mail_compose()…) that run with no server at all');

    $rules[] = Rule::allClasses()
        ->that(new ResideInOneOfTheseNamespaces('ImapPolyfill\Connection', 'IMAP'))
        ->should(new NotDependsOnTheseNamespaces(['ImapPolyfill\Session']))
        ->because('Session is built per call around a connection that outlives it and serves every imap_* function, so the connection cannot lean on any one function\'s validation or return conventions');

    $rules[] = Rule::allClasses()
        ->that(new ResideInOneOfTheseNamespaces('ImapPolyfill\Connection'))
        ->should(new NotDependsOnTheseNamespaces(['IMAP\Connection']))
        ->because('the stream state (selected folder, read-only bit, half-open, keywords) has IMAP\Connection as its one owner; a backend reading or editing it would be a second one, and every new protocol would have to learn it');

    $config->add($src, ...$rules);
};
