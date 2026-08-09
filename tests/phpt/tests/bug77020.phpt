--TEST--
Bug #77020 (null pointer dereference in imap_mail)
--INI--
sendmail_path="echo >/dev/null"
--XFAIL--
null where a string parameter is declared: an internal function coerces it
with a deprecation, a userland one raises TypeError.
--FILE--
<?php
// For Windows, set it to a string of length HOST_NAME_LEN (256) so the mail is not actually sent
ini_set("SMTP", str_repeat("A", 256));

@imap_mail('1', 1, NULL);
echo 'done'
?>
--EXPECTF--
%Adone
