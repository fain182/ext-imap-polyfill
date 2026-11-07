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
        ->because('the message cache is the backend\'s, reached from above only as counts and a UidTable');

    $rules[] = Rule::allClasses()
        ->except('ImapPolyfill\Connection\Protocol')
        ->that(new ResideInOneOfTheseNamespaces('ImapPolyfill', 'IMAP'))
        ->andThat(new NotResideInTheseNamespaces('ImapPolyfill\Connection\Imap'))
        ->should(new NotDependsOnTheseNamespaces(['DirectoryTree\ImapEngine']))
        ->because('ImapEngine stops at the wire layer, so swapping the library again stays a change there');

    $rules[] = Rule::allClasses()
        ->that(new ResideInOneOfTheseNamespaces('IMAP\Connection'))
        ->should(new NotDependsOnTheseNamespaces(['ImapPolyfill\Support\ErrorStack']))
        ->because('IMAP\Connection knows nothing about the imap_* error contract');

    $rules[] = Rule::allClasses()
        ->that(new ResideInOneOfTheseNamespaces('ImapPolyfill\Message', 'ImapPolyfill\Mime', 'ImapPolyfill\Address'))
        ->should(new NotDependsOnTheseNamespaces(['ImapPolyfill\Session']))
        ->because('the builders and parsers sit below the imap_* contract layer');

    $rules[] = Rule::allClasses()
        ->that(new ResideInOneOfTheseNamespaces('ImapPolyfill\Message'))
        ->should(new NotDependsOnTheseNamespaces(
            ['ImapPolyfill\Connection'],
            ['ImapPolyfill\Connection\UidMode', 'ImapPolyfill\Connection\UidTable'],
        ))
        ->because('message shapes are built from what the backend answered, not from the backend; the id-space types are shared values');

    $config->add($src, ...$rules);
};
