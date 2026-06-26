<?php

class ProLogService
{
    public function viewer($node)
    {
        header("Content-Type: text/html");
        readfile(__DIR__ . '/../logs/index.html');
        exit;
    }

    public function read($node)
    {
        // Require password
        $body = Node::body(['password']);
        
        $password = defined('LOG_VIEWER_PASSWORD') ? LOG_VIEWER_PASSWORD : '';

        if (empty($password)) {
            return new DataFailed('Log viewer password is not configured in settings.', 500, null, '');
        }

        if ($body['password'] !== $password) {
            return new DataFailed('Unauthorized. Invalid password.', 401, null, '');
        }

        $logPath = getcwd() . '/prolog.log';

        if (!file_exists($logPath)) {
            return new DataSuccess('Log file is empty or does not exist.', ['logs' => ''], 200, '');
        }

        // Read the last 1000 lines of the log file to prevent massive memory consumption
        $lines = $this->tail($logPath, 1000);
        
        return new DataSuccess('Logs retrieved successfully', ['logs' => $lines], 200, '');
    }

    public function clear($node)
    {
        // Require password
        $body = Node::body(['password']);
        
        $password = defined('LOG_VIEWER_PASSWORD') ? LOG_VIEWER_PASSWORD : '';

        if (empty($password)) {
            return new DataFailed('Log viewer password is not configured in settings.', 500, null, '');
        }

        if ($body['password'] !== $password) {
            return new DataFailed('Unauthorized. Invalid password.', 401, null, '');
        }

        $logPath = getcwd() . '/prolog.log';

        if (file_exists($logPath)) {
            file_put_contents($logPath, '');
        }

        return new DataSuccess('Logs cleared successfully', null, 200, '');
    }

    private function tail($filepath, $lines = 100, $adaptive = true) {
        $f = @fopen($filepath, "rb");
        if ($f === false) return "";

        // Sets buffer size, according to the number of lines to retrieve.
        $buffer = ($adaptive ? max(1024, $lines * 100) : 4096);
        
        // Jump to last character
        fseek($f, -1, SEEK_END);
        
        if (fread($f, 1) != "\n") {
            $lines -= 1;
        }

        $output = '';
        $chunk = '';

        while (ftell($f) > 0 && $lines >= 0) {
            $seek = min(ftell($f), $buffer);
            fseek($f, -$seek, SEEK_CUR);
            
            $output = ($chunk = fread($f, $seek)) . $output;
            
            fseek($f, -strlen($chunk), SEEK_CUR);
            $lines -= substr_count($chunk, "\n");
        }

        while ($lines++ < 0) {
            $output = substr($output, strpos($output, "\n") + 1);
        }
        
        fclose($f);
        
        return trim($output);
    }
}
