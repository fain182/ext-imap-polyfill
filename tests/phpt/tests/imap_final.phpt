--TEST--
Check that IMAP\Connection is declared final
--FILE--
<?php

class T extends IMAP\Connection {}
?>
--EXPECTF--
Fatal error: Class T cannot extend final class IMAP\Connection in %s on line %d
%A
