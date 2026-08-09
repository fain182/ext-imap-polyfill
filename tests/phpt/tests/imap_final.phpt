--TEST--
Check that IMAP\Connection is declared final
--XFAIL--
IMAP\Connection is final, and this reports it. The parent is loaded by the
autoloader, so the engine raises the refusal at runtime and it carries a
stack trace the compile-time one does not.
--FILE--
<?php

class T extends IMAP\Connection {}
?>
--EXPECTF--
Fatal error: Class T cannot extend final class IMAP\Connection in %s on line %d
