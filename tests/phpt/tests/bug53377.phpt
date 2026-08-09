--TEST--
Bug #53377 (imap_mime_header_decode() doesn't ignore \t during long MIME header unfolding)
--FILE--
<?php
$s = "=?UTF-8?Q?=E2=82=AC?=";
$header = "$s\n $s\n\t$s";

var_dump(imap_mime_header_decode($header));
?>
--EXPECTF--
array(3) {
  [0]=>
  object(stdClass)#%d (2) {
    ["charset"]=>
    string(5) "UTF-8"
    ["text"]=>
    string(3) "€"
  }
  [1]=>
  object(stdClass)#%d (2) {
    ["charset"]=>
    string(5) "UTF-8"
    ["text"]=>
    string(3) "€"
  }
  [2]=>
  object(stdClass)#%d (2) {
    ["charset"]=>
    string(5) "UTF-8"
    ["text"]=>
    string(3) "€"
  }
}
