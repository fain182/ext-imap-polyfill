--TEST--
NIL constant is deprecated
--XFAIL--
Deprecating a constant needs #[\Deprecated] on a `const`, which is PHP 8.5
and a parse error on 8.1-8.4 — the floor this package claims. A `define()`d
constant cannot be deprecated on any version.
--FILE--
<?php
var_dump(NIL);
?>
--EXPECTF--
Deprecated: Constant NIL is deprecated in %s on line %d
int(0)
