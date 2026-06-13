<?php

$projectRoot = getcwd();
$packageRoot = __DIR__;

/** Copy file/directory only if it does not already exist */
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
        copy($src, $dst);
    }
}

/** Copy file/directory and overwrite/update it if it exists */
function publishOrOverwrite(string $src, string $dst): void
{
    if (is_dir($src)) {
        if (!file_exists($dst)) {
            mkdir($dst, 0755, true);
        }
        foreach (scandir($src) as $f) {
            if ($f === '.' || $f === '..') continue;
            publishOrOverwrite("$src/$f", "$dst/$f");
        }
    } else {
        copy($src, $dst);
    }
}

// 1. Force update/overwrite core framework directory and configuration scripts
publishOrOverwrite("$packageRoot/Core", "$projectRoot/Core");
publishOrOverwrite("$packageRoot/.htaccess", "$projectRoot/.htaccess");
publishOrOverwrite("$packageRoot/post-install.php", "$projectRoot/post-install.php");

// 2. Publish config.php, index.php, and controllers ONLY if they are missing (to preserve custom code and setup)
publishIfMissing("$packageRoot/config.php", "$projectRoot/config.php");
publishIfMissing("$packageRoot/index.php", "$projectRoot/index.php");
publishIfMissing("$packageRoot/lib/controller", "$projectRoot/lib/controller");

// 3. Write/update the root CLI runner loader
$cliPath = $projectRoot . '/api-pro';
$cliContent = "#!/usr/bin/env php\n<?php\nrequire_once __DIR__ . '/Core/api-pro-cli.php';\n";
file_put_contents($cliPath, $cliContent);
chmod($cliPath, 0755);

// 4. Clean up markdown files from project root on new install (bypass if in development/framework repository root)
if (realpath($projectRoot) !== realpath($packageRoot)) {
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
}
