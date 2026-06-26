<?php

if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line.\n");
}

$projectRoot = dirname(__DIR__);

// If this file was called directly or required by api-pro, parse arguments
$args = $argv;
$scriptName = array_shift($args); // remove script name
$command = array_shift($args) ?? 'help';

switch ($command) {
    case 'update':
        $version = array_shift($args) ?? 'latest';
        runUpdate($version, $projectRoot);
        break;
        
    case 'version':
        showVersion($projectRoot);
        break;
        
    case 'help':
    default:
        showHelp();
        break;
}

function showVersion(string $projectRoot)
{
    $autoload = $projectRoot . '/Core/autoload.php';
    $version = 'Unknown';
    if (file_exists($autoload)) {
        $content = file_get_contents($autoload);
        if (preg_match("/define\(\s*['\"]APIPRO_VERSION['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/", $content, $matches)) {
            $version = $matches[1];
        }
    }
    echo "ApiPro CLI version: " . $version . "\n";
}

function showHelp()
{
    echo "ApiPro CLI Tool\n";
    echo "Usage:\n";
    echo "  php api-pro <command> [options]\n\n";
    echo "Commands:\n";
    echo "  update [version]  Update the ApiPro framework to the specified version (defaults to latest)\n";
    echo "  version           Display the current ApiPro framework version\n";
    echo "  help              Display this help message\n";
}

function runUpdate(string $version, string $projectRoot)
{
    echo "Starting ApiPro update to version: $version...\n";
    
    // Create temporary update directory
    $tempDir = $projectRoot . '/.api-pro-update-temp';
    if (file_exists($tempDir)) {
        exec("rm -rf " . escapeshellarg($tempDir));
    }
    mkdir($tempDir, 0755, true);

    $success = downloadAndExtract($version, $tempDir);
    if (!$success) {
        exec("rm -rf " . escapeshellarg($tempDir));
        echo "Update failed.\n";
        exit(1);
    }

    $subdirs = glob($tempDir . '/api-pro-*', GLOB_ONLYDIR);
    if (empty($subdirs)) {
        echo "Error: Could not find extracted project directory in temp folder.\n";
        exec("rm -rf " . escapeshellarg($tempDir));
        exit(1);
    }
    $extractedDir = $subdirs[0];

    echo "Updating files...\n";

    // Helper functions for copying
    $publishOrOverwrite = function (string $src, string $dst) use (&$publishOrOverwrite) {
        if (is_dir($src)) {
            if (!file_exists($dst)) {
                mkdir($dst, 0755, true);
            }
            foreach (scandir($src) as $f) {
                if ($f === '.' || $f === '..') continue;
                $publishOrOverwrite("$src/$f", "$dst/$f");
            }
        } else {
            copy($src, $dst);
        }
    };

    $publishIfMissing = function (string $src, string $dst) use (&$publishIfMissing) {
        if (file_exists($dst)) return;

        if (is_dir($src)) {
            mkdir($dst, 0755, true);
            foreach (scandir($src) as $f) {
                if ($f === '.' || $f === '..') continue;
                $publishIfMissing("$src/$f", "$dst/$f");
            }
        } else {
            copy($src, $dst);
        }
    };

    // 1. Core directory is completely overwritten
    if (file_exists("$extractedDir/Core")) {
        if (file_exists("$projectRoot/Core")) {
            exec("rm -rf " . escapeshellarg("$projectRoot/Core"));
        }
        $publishOrOverwrite("$extractedDir/Core", "$projectRoot/Core");
    }
    
    // 2. Server configurations/scripts (.htaccess, post-install.php, index.php, composer.json) are overwritten
    if (file_exists("$extractedDir/.htaccess")) {
        $publishOrOverwrite("$extractedDir/.htaccess", "$projectRoot/.htaccess");
    }
    if (file_exists("$extractedDir/post-install.php")) {
        $publishOrOverwrite("$extractedDir/post-install.php", "$projectRoot/post-install.php");
    }
    if (file_exists("$extractedDir/index.php")) {
        $publishOrOverwrite("$extractedDir/index.php", "$projectRoot/index.php");
    }
    if (file_exists("$extractedDir/composer.json")) {
        $publishOrOverwrite("$extractedDir/composer.json", "$projectRoot/composer.json");
    }

    // 3. User code and configs (lib/, config.php) are preserved
    if (file_exists("$extractedDir/config.php")) {
        $publishIfMissing("$extractedDir/config.php", "$projectRoot/config.php");
    }
    if (file_exists("$extractedDir/lib/controller")) {
        $publishIfMissing("$extractedDir/lib/controller", "$projectRoot/lib/controller");
    }

    // 4. Update/Restore the root CLI runner script
    $cliPath = $projectRoot . '/api-pro';
    $cliContent = "#!/usr/bin/env php\n<?php\nrequire_once __DIR__ . '/Core/api-pro-cli.php';\n";
    file_put_contents($cliPath, $cliContent);
    chmod($cliPath, 0755);

    // Clean up temporary files
    exec("rm -rf " . escapeshellarg($tempDir));

    echo "ApiPro updated successfully!\n";
    showVersion($projectRoot);
}

function downloadAndExtract(string $version, string $targetDir): bool
{
    $url = "https://github.com/CodeSignificant/api-pro/archive/refs/tags/v{$version}.zip";
    if ($version === 'latest' || empty($version)) {
        $url = "https://github.com/CodeSignificant/api-pro/archive/refs/heads/main.zip";
    }

    echo "Downloading updates from: $url\n";

    $zipPath = $targetDir . '/update.zip';
    $escapedZip = escapeshellarg($zipPath);
    $escapedUrl = escapeshellarg($url);
    $curlSuccess = false;

    // 1. Try downloading via curl
    exec("curl -L -s -f -o $escapedZip $escapedUrl", $output, $returnCode);
    if ($returnCode === 0 && file_exists($zipPath) && filesize($zipPath) > 0) {
        $curlSuccess = true;
    }

    // 2. Fallback to file_get_contents with correct stream context options
    if (!$curlSuccess) {
        $opts = [
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: PHP\r\n",
                'follow_location' => 1,
                'timeout' => 30
            ]
        ];
        $context = stream_context_create($opts);
        $zipData = @file_get_contents($url, false, $context);
        
        if ($zipData === false && $version !== 'latest' && !empty($version)) {
            // Retry without 'v' prefix
            $url = "https://github.com/CodeSignificant/api-pro/archive/refs/tags/{$version}.zip";
            echo "Retrying download from: $url\n";
            $zipData = @file_get_contents($url, false, $context);
        }

        if ($zipData === false) {
            echo "Error: Failed to download the update package from GitHub.\n";
            return false;
        }

        file_put_contents($zipPath, $zipData);
    }

    echo "Extracting package...\n";
    $extracted = false;

    // 1. Try ZipArchive
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) === true) {
            $zip->extractTo($targetDir);
            $zip->close();
            $extracted = true;
        }
    }

    // 2. Fallback to command line unzip
    if (!$extracted) {
        exec("unzip -o $escapedZip -d " . escapeshellarg($targetDir), $output, $returnCode);
        if ($returnCode !== 0) {
            echo "Error: Failed to extract the ZIP archive.\n";
            if (file_exists($zipPath)) {
                unlink($zipPath);
            }
            return false;
        }
    }

    if (file_exists($zipPath)) {
        unlink($zipPath);
    }
    return true;
}
