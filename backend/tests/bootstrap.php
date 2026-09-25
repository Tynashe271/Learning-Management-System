<?php

// Real environment variables (for example the container's env_file) take precedence over the
// <env> values in phpunit.xml. Apply them to every source Laravel reads so tests never run
// against the development database, cache or queue.
foreach (simplexml_load_file(__DIR__.'/../phpunit.xml')->php->env ?? [] as $env) {
    $name = (string) $env['name'];
    $value = (string) $env['value'];

    putenv("{$name}={$value}");
    $_ENV[$name] = $_SERVER[$name] = $value;
}

require __DIR__.'/../vendor/autoload.php';
