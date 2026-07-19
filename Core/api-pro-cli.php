<?php

if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line.\n");
}

$projectRoot = dirname(__DIR__);

// If this file was called directly or required by api-pro, parse arguments
$args = $argv;
$scriptName = array_shift($args); // remove script name
$command = array_shift($args) ?? 'help';

// Auto-default to 'start' if a port flag is passed directly (e.g. php api-pro -p 7080)
if (in_array($command, ['-p', '--port']) || str_starts_with($command, '--port=')) {
    array_unshift($args, $command);
    $command = 'start';
}

switch ($command) {
    case 'update':
        $version = array_shift($args) ?? 'latest';
        runUpdate($version, $projectRoot);
        break;
        
    case 'version':
    case '--version':
    case '--vesion':
    case '-v':
        showVersion($projectRoot);
        break;

    case 'latest':
    case '--latest':
    case '-l':
        checkLatestVersion($projectRoot);
        break;

    case 'changelog':
    case '--changelog':
    case '-c':
        $verArg = array_shift($args);
        if (empty($verArg)) {
            // Retrieve current version
            $autoload = $projectRoot . '/Core/autoload.php';
            $verArg = 'Unknown';
            if (file_exists($autoload)) {
                $content = file_get_contents($autoload);
                if (preg_match("/define\(\s*['\"]APIPRO_VERSION['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/", $content, $matches)) {
                    $verArg = $matches[1];
                }
            }
        }
        if ($verArg !== 'Unknown') {
            showChangelog($verArg, $projectRoot);
        } else {
            echo "Error: Could not determine version to show changelog for.\n";
        }
        break;
        
    case 'start':
    case 'serve':
        runServer($args, $projectRoot);
        break;
        
    case 'help':
    case '--help':
    case '-h':
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
    echo "  start               Start the local PHP development server (e.g. php api-pro start --port=7070)\n";
    echo "  update [version]    Update the ApiPro framework to the specified version (defaults to latest)\n";
    echo "  version             Display the current ApiPro framework version\n";
    echo "  latest              Check the latest available version of ApiPro on GitHub\n";
    echo "  changelog [version] Display the release notes for the specified version (defaults to current)\n";
    echo "  help                Display this help message\n";
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

    // 2.5 Overwrite documentation files (README.md, AI_INSTRUCTIONS.md)
    if (file_exists("$extractedDir/README.md")) {
        $publishOrOverwrite("$extractedDir/README.md", "$projectRoot/README.md");
    }
    if (file_exists("$extractedDir/AI_INSTRUCTIONS.md")) {
        $publishOrOverwrite("$extractedDir/AI_INSTRUCTIONS.md", "$projectRoot/AI_INSTRUCTIONS.md");
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
    
    // Retrieve the newly installed version
    $autoload = $projectRoot . '/Core/autoload.php';
    $updatedVersion = 'Unknown';
    if (file_exists($autoload)) {
        $content = file_get_contents($autoload);
        if (preg_match("/define\(\s*['\"]APIPRO_VERSION['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/", $content, $matches)) {
            $updatedVersion = $matches[1];
        }
    }
    echo "ApiPro CLI version: " . $updatedVersion . "\n";
    if ($updatedVersion !== 'Unknown') {
        showChangelog($updatedVersion, $projectRoot);
    }
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

function showChangelog(string $version, string $projectRoot)
{
    $readme = $projectRoot . '/README.md';
    if (!file_exists($readme)) {
        return;
    }
    $content = file_get_contents($readme);
    $pattern = "/###\s*Version\s*" . preg_quote($version, '/') . "\s*Release\s*Notes(.*?)(?=###|---|#|$)/is";
    if (preg_match($pattern, $content, $matches)) {
        echo "\nRelease Notes for v{$version}:\n";
        echo "========================================\n";
        echo trim($matches[1]) . "\n";
        echo "========================================\n\n";
    }
}

function checkLatestVersion(string $projectRoot)
{
    // Retrieve current version
    $autoload = $projectRoot . '/Core/autoload.php';
    $current = 'Unknown';
    if (file_exists($autoload)) {
        $content = file_get_contents($autoload);
        if (preg_match("/define\(\s*['\"]APIPRO_VERSION['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/", $content, $matches)) {
            $current = $matches[1];
        }
    }
    
    echo "Current ApiPro version: " . $current . "\n";
    echo "Checking for latest available version on GitHub...\n";
    
    $latest = getLatestVersion();
    
    if ($latest === 'Unknown') {
        echo "Could not fetch the latest version details from GitHub. Please check your internet connection.\n";
        return;
    }
    
    echo "Latest available version: " . $latest . "\n";
    
    if ($current !== 'Unknown') {
        if (version_compare($current, $latest, '<')) {
            echo "A new version of ApiPro is available! Run 'php api-pro update' to upgrade to v{$latest}.\n";
        } else {
            echo "You are running the latest version of ApiPro.\n";
        }
    }
}

function getLatestVersion(): string
{
    $url = "https://api.github.com/repos/CodeSignificant/api-pro/releases/latest";
    $opts = [
        'http' => [
            'method' => 'GET',
            'header' => [
                "User-Agent: PHP\r\n",
                "Accept: application/vnd.github.v3+json\r\n"
            ],
            'timeout' => 10
        ]
    ];
    $context = stream_context_create($opts);
    $json = @file_get_contents($url, false, $context);
    if ($json !== false) {
        $data = json_decode($json, true);
        if (isset($data['tag_name'])) {
            return ltrim($data['tag_name'], 'v');
        }
    }
    
    // Fallback: fetch tags list if releases are rate-limited or not configured
    $urlTags = "https://api.github.com/repos/CodeSignificant/api-pro/tags";
    $jsonTags = @file_get_contents($urlTags, false, $context);
    if ($jsonTags !== false) {
        $dataTags = json_decode($jsonTags, true);
        if (is_array($dataTags) && isset($dataTags[0]['name'])) {
            return ltrim($dataTags[0]['name'], 'v');
        }
    }
    
    return 'Unknown';
}

function runServer(array $args, string $projectRoot)
{
    $port = 7070;
    $host = '127.0.0.1';

    // Normalize arguments (remove isolated '=')
    $cleanArgs = [];
    foreach ($args as $a) {
        if ($a !== '=') {
            $cleanArgs[] = ltrim($a, '=');
        }
    }

    for ($i = 0; $i < count($cleanArgs); $i++) {
        $arg = trim($cleanArgs[$i]);
        if (empty($arg)) continue;

        if (preg_match('/^--port=(.+)$/', $arg, $m)) {
            $port = (int)$m[1];
        } elseif (($arg === '--port' || $arg === '-p') && isset($cleanArgs[$i + 1])) {
            $port = (int)$cleanArgs[$i + 1];
            $i++;
        } elseif (preg_match('/^--host=(.+)$/', $arg, $m)) {
            $host = $m[1];
        } elseif ($arg === '--host' && isset($cleanArgs[$i + 1])) {
            $host = $cleanArgs[$i + 1];
            $i++;
        } elseif (is_numeric($arg)) {
            $port = (int)$arg;
        }
    }

    echo "\033[1;32mStarting ApiPro development server...\033[0m\n\n";
    echo "  \033[1;34mApp URL:\033[0m    http://{$host}:{$port}\n";
    echo "  \033[1;34mAPI Tester:\033[0m http://{$host}:{$port}/test.html\n";
    echo "  \033[1;34mLogger:\033[0m     http://{$host}:{$port}/logs.html\n\n";
    echo "Press Ctrl+C to stop.\n\n";

    chdir($projectRoot);
    passthru("php -S {$host}:{$port} index.php");
}
