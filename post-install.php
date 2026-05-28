<?php


$projectRoot = getcwd();
$packageRoot = __DIR__;

function publishIfMissing(string $src, string $dst): void
{
    if (file_exists($dst)) return;

    if (is_dir($src)) {
        mkdir($dst, 0755, true);
        foreach (scandir($src) as $f) {
            if ($f === '.' || $f === '..') continue;
            publishIfMissing("$src/$f", "$dst/$f");
        }
    } else {
        echo $src;
        copy($src, $dst);
    }
}

publishIfMissing("$packageRoot/index.php", "$projectRoot/index.php");
publishIfMissing("$packageRoot/config.php", "$projectRoot/config.php");
publishIfMissing("$packageRoot/.htaccess", "$projectRoot/.htaccess");
publishIfMissing("$packageRoot/lib/controller", "$projectRoot/lib/controller");

// Clean up all markdown (.md) files from the project root to ensure a clean install
$rootFiles = scandir($projectRoot);
if ($rootFiles !== false) {
    foreach ($rootFiles as $file) {
        if (pathinfo($file, PATHINFO_EXTENSION) === 'md') {
            $filePath = "$projectRoot/$file";
            if (is_file($filePath)) {
                unlink($filePath);
            }
        }
    }
}
