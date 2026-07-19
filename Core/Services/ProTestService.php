<?php

class ProTestService
{
    public function viewer($node)
    {
        header("Content-Type: text/html");
        readfile(__DIR__ . '/../tester/index.html');
        exit;
    }

    public function routes($node)
    {
        $allRoutes = ProNode::$routes;
        $tree = [];

        foreach ($allRoutes as $method => $routes) {
            foreach ($routes as $path => $handler) {
                $instance = $handler[0];
                $methodName = $handler[1];
                $className = get_class($instance);

                // Hide internal framework services from the API Tester
                if (in_array($className, ['ProLogService', 'ProTestService']) || str_starts_with($className, 'class@anonymous')) {
                    continue;
                }

                if (!isset($tree[$className])) {
                    $tree[$className] = [];
                }

                $params = $this->extractParamsFromCode($instance, $methodName, $method);

                $tree[$className][] = [
                    'path'            => $path,
                    'method'          => $method,
                    'function'        => $methodName,
                    'params'          => $params['query'],
                    'required_params' => $params['required_query'],
                    'body'            => $params['body'],
                    'required_body'   => $params['required_body'],
                    'raw_body'        => $params['raw_body'] ?? false,
                    'files'           => $params['files'],
                    'required_files'  => $params['required_files'],
                    'deprecated'      => $params['deprecated'] ?? false,
                    'comment'         => $params['comment'] ?? '',
                ];
            }
        }

        return new DataSuccess('Routes fetched', ['tree' => $tree], 200, '');
    }

    private function extractParamsFromCode($instance, $methodName, $routeMethod)
    {
        $query         = [];
        $requiredQuery = [];
        $body          = [];
        $requiredBody  = [];
        $files         = [];
        $requiredFiles = [];
        $rawBody       = false;
        $deprecated    = false;
        $comment       = '';

        try {
            $ref = new ReflectionMethod($instance, $methodName);
            $filename = $ref->getFileName();
            $start_line = $ref->getStartLine() - 1;
            $end_line = $ref->getEndLine();
            $length = $end_line - $start_line;

            if ($filename && file_exists($filename)) {
                $source = file($filename);
                $code = implode("", array_slice($source, $start_line, $length));

                // Check for Node deprecation
                if (strpos($code, 'Node::') !== false) {
                    $deprecated = true;
                }

                // Extract comments added via ->addComment("...") or addComment('...')
                preg_match_all('/->addComment\(\s*(["\'])(.*?)\1\s*\)/s', $code, $commentMatches, PREG_SET_ORDER);
                $comments = [];
                foreach ($commentMatches as $m) {
                    $comments[] = stripslashes($m[2]);
                }
                $comment = implode("\n\n", $comments);

                // --- LEGACY NODE SYNTAX PARSING ---
                // 1. Required Query Params — declared in Node::params([...])
                preg_match_all("/Node::params\(\s*\[([^\]]+)\]\s*\)/s", $code, $paramMatches);
                if (!empty($paramMatches[1])) {
                    foreach ($paramMatches[1] as $match) {
                        $keys = explode(',', $match);
                        foreach ($keys as $k) {
                            $clean = trim(str_replace(["'", '"', ' '], "", $k));
                            if (!empty($clean)) { $query[] = $clean; $requiredQuery[] = $clean; }
                        }
                    }
                }

                // 2. Required Body Params — declared in Node::body([...])
                preg_match_all("/Node::body\(\s*\[([^\]]+)\]\s*\)/s", $code, $bodyMatches);
                if (!empty($bodyMatches[1])) {
                    foreach ($bodyMatches[1] as $match) {
                        $keys = explode(',', $match);
                        foreach ($keys as $k) {
                            $clean = trim(str_replace(["'", '"', ' '], "", $k));
                            if (!empty($clean)) { $body[] = $clean; $requiredBody[] = $clean; }
                        }
                    }
                }

                // 3. Required Files — declared in Node::files([...])
                preg_match_all("/Node::files\(\s*\[([^\]]+)\]\s*\)/s", $code, $fileMatches);
                if (!empty($fileMatches[1])) {
                    foreach ($fileMatches[1] as $match) {
                        $keys = explode(',', $match);
                        foreach ($keys as $k) {
                            $clean = trim(str_replace(["'", '"', ' '], "", $k));
                            if (!empty($clean)) { $files[] = $clean; $requiredFiles[] = $clean; }
                        }
                    }
                }

                // --- MODERN REQUEST SYNTAX PARSING ---
                // Extract parameters from calls like $request->body->getString("key") or $request->query->getString("key")
                $requestRegex = '/\$[a-zA-Z0-9_]+->(body|params|query|files)->(getString|getInt|getFloat|getBool|getArray|getObject|getFile|getFiles|get)\(\s*(["\'])(.*?)\3\s*(?:,\s*([^)]+))?\)/s';
                preg_match_all($requestRegex, $code, $requestMatches, PREG_SET_ORDER);

                foreach ($requestMatches as $match) {
                    $prop = $match[1];
                    $method = $match[2];
                    $key = trim($match[4]);
                    $hasDefault = isset($match[5]) && trim($match[5]) !== '';

                    $category = '';
                    if ($prop === 'params' || $prop === 'query') {
                        $category = 'query';
                    } elseif ($prop === 'body') {
                        $category = 'body';
                    } elseif ($prop === 'files' || $method === 'getFile' || $method === 'getFiles') {
                        $category = 'files';
                    }

                    if ($category === 'query') {
                        $query[] = $key;
                        if (!$hasDefault) {
                            $requiredQuery[] = $key;
                        }
                    } elseif ($category === 'body') {
                        $body[] = $key;
                        if (!$hasDefault) {
                            $requiredBody[] = $key;
                        }
                    } elseif ($category === 'files') {
                        $files[] = $key;
                        if (!$hasDefault) {
                            $requiredFiles[] = $key;
                        }
                    }
                }

                // 4. Extract Optional Query Params via array access (e.g. $params['min_price'] or $_GET['min_price'])
                preg_match_all('/(?:\$[a-zA-Z0-9_]*params?|\$_GET)\[[\'\"]([^\'\"]+)[\'\"]\]/i', $code, $extraParams);
                if (!empty($extraParams[1])) {
                    foreach ($extraParams[1] as $k) $query[] = trim($k);
                }

                // 5. Extract Optional Body Params via array access (e.g. $body['userId'] or $_POST['userId'])
                preg_match_all('/(?:\$[a-zA-Z0-9_]*body|\$_POST)\[[\'\"]([^\'\"]+)[\'\"]\]/i', $code, $extraBody);
                if (!empty($extraBody[1])) {
                    foreach ($extraBody[1] as $k) $body[] = trim($k);
                }

                // 6. Extract Optional Files via array access (e.g. $files['avatar'] or $_FILES['avatar'])
                preg_match_all('/(?:\$[a-zA-Z0-9_]*files?|\$_FILES)\[[\'\"]([^\'\"]+)[\'\"]\]/i', $code, $extraFiles);
                if (!empty($extraFiles[1])) {
                    foreach ($extraFiles[1] as $k) $files[] = trim($k);
                }

                // 7. Check if raw body is needed (Node::body() called, but no specific body fields found)
                if (preg_match("/Node::body\s*\(/i", $code) || preg_match("/->body->all\s*\(/i", $code)) {
                    if (empty($body)) {
                        $rawBody = true;
                    }
                }
            }
        } catch (Exception $e) {
            // Ignore reflection errors for internal classes
        }

        return [
            'query'          => array_values(array_unique($query)),
            'required_query' => array_values(array_unique($requiredQuery)),
            'body'           => array_values(array_unique($body)),
            'required_body'  => array_values(array_unique($requiredBody)),
            'raw_body'       => $rawBody,
            'files'          => array_values(array_unique($files)),
            'required_files' => array_values(array_unique($requiredFiles)),
            'deprecated'     => $deprecated,
            'comment'        => $comment,
        ];
    }
}
