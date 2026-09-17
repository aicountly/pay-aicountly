<?php

/**
 * Serves the built app locally, with the history fallback a SPA needs.
 *
 * Only for `server-php/tests/devstack.sh`. On a real host Apache does this —
 * see web/public/.htaccess — and this file is never deployed.
 *
 *     php -S 127.0.0.1:5199 -t dist spa.php
 */
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// `return false` hands the request back to the built-in server, which serves
// the file from the document root with the right content type.
if ($path !== '/' && is_file(__DIR__ . '/dist' . $path)) {
    return false;
}

header('Content-Type: text/html; charset=utf-8');
readfile(__DIR__ . '/dist/index.html');
