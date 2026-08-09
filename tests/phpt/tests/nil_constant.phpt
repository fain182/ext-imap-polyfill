--TEST--
NIL constant is deprecated
--XFAIL--
A constant defined with define() cannot be marked deprecated.
--FILE--
<?php
var_dump(NIL);
?>
--EXPECTF--
Deprecated: Constant NIL is deprecated in %s on line %d
int(0)
