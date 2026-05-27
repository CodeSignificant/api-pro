<?php

// Serve static files transparently when using built-in PHP dev server
if (php_sapi_name() === 'cli-server') {
    $file = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (is_file($file)) return false;
}

require_once __DIR__ . '/Core/autoload.php';
// require_once __DIR__ . '/vendor/autoload.php'; Uncomment when other packages used

ProNode::scan(__DIR__ . '/lib/controllers');

ProNode::start();
