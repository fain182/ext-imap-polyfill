<?php

require __DIR__.'/../vendor/autoload.php';

// A .env, when there is one, points the suite at a server other than the
// fixtures — see .env.example. Unsafe only in the sense of putenv(), which
// is what getenv() in the test base classes reads; and it never overwrites
// a variable already exported, so `FOO=bar make test` still wins.
if (file_exists(__DIR__.'/../.env')) {
    Dotenv\Dotenv::createUnsafeImmutable(dirname(__DIR__))->load();
}
