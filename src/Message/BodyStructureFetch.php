<?php

namespace ImapPolyfill\Message;

use ImapPolyfill\Search\ImapSexpParser;

/**
 * Fetches BODYSTRUCTURE directly over the raw socket, bypassing webklex's
 * response tokenizer (see ImapSexpParser for why).
 */
final class BodyStructureFetch
{
    /**
     * @return array<int, mixed>
     */
    public static function fetch(\Webklex\PHPIMAP\Client $client, int $id, bool $byUid): array
    {
        // ->stream only exists on the concrete ImapProtocol, the only
        // protocol this polyfill supports (see README limitations).
        $connection = $client->getConnection();
        assert($connection instanceof \Webklex\PHPIMAP\Connection\Protocols\ImapProtocol);
        $stream = $connection->stream;
        $tag = 'X'.random_int(1000, 9999);

        $command = $byUid ? 'UID FETCH' : 'FETCH';
        fwrite($stream, "{$tag} {$command} {$id} (BODYSTRUCTURE)\r\n");

        $buffer = '';
        while (($line = fgets($stream)) !== false) {
            $buffer .= $line;
            if (str_starts_with($line, $tag.' ')) {
                break;
            }
        }

        if (!str_contains($buffer, "{$tag} OK")) {
            throw new \RuntimeException('FETCH BODYSTRUCTURE failed: '.trim($buffer));
        }

        $markerPos = strpos($buffer, 'BODYSTRUCTURE');
        if ($markerPos === false) {
            throw new \RuntimeException('no BODYSTRUCTURE in FETCH response');
        }

        $openParen = strpos($buffer, '(', $markerPos);
        if ($openParen === false) {
            throw new \RuntimeException('malformed BODYSTRUCTURE in FETCH response');
        }

        return ImapSexpParser::parseAt($buffer, $openParen);
    }
}
