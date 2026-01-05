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
publishIfMissing("$packageRoot/error.log", "$projectRoot/error.log");
publishIfMissing("$packageRoot/settings.properties.php", "$projectRoot/settings.properties.php");
publishIfMissing("$packageRoot/.htaccess", "$projectRoot/.htaccess");
publishIfMissing("$packageRoot/lib", "$projectRoot/lib");
