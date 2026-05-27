<?php

class ProTestService
{
    public function viewer($node)
    {
        header("Content-Type: text/html");
        readfile(__DIR__ . '/../test.html');
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

                $params = $this->extractParamsFromCode($instance, $methodName);

                $tree[$className][] = [
                    'path'            => $path,
                    'method'          => $method,
                    'function'        => $methodName,
                    'params'          => $params['query'],
                    'required_params' => $params['required_query'],
                    'body'            => $params['body'],
                    'required_body'   => $params['required_body'],
                    'files'           => $params['files'],
                    'required_files'  => $params['required_files'],
                ];
            }
        }

        return new DataSuccess('Routes fetched', ['tree' => $tree]);
    }

    private function extractParamsFromCode($instance, $methodName)
    {
        $query         = [];
        $requiredQuery = [];
        $body          = [];
        $requiredBody  = [];
        $files         = [];
        $requiredFiles = [];

        try {
            $ref = new ReflectionMethod($instance, $methodName);
            $filename = $ref->getFileName();
            $start_line = $ref->getStartLine() - 1;
            $end_line = $ref->getEndLine();
            $length = $end_line - $start_line;

            if ($filename && file_exists($filename)) {
                $source = file($filename);
                $code = implode("", array_slice($source, $start_line, $length));

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

                // 4. Extract Optional Query Params via array access (e.g. $params['min_price'] or $_GET['min_price'])
                preg_match_all("/(?:\\$[a-zA-Z0-9_]*params?|\\$_GET)\[['\"]([^'\"]+)['\"]\]/i", $code, $extraParams);
                if (!empty($extraParams[1])) {
                    foreach ($extraParams[1] as $k) $query[] = trim($k);
                }

                // 5. Extract Optional Body Params via array access (e.g. $body['userId'] or $_POST['userId'])
                preg_match_all("/(?:\\$[a-zA-Z0-9_]*body|\\$_POST)\[['\"]([^'\"]+)['\"]\]/i", $code, $extraBody);
                if (!empty($extraBody[1])) {
                    foreach ($extraBody[1] as $k) $body[] = trim($k);
                }

                // 6. Extract Optional Files via array access (e.g. $files['avatar'] or $_FILES['avatar'])
                preg_match_all("/(?:\\$[a-zA-Z0-9_]*files?|\\$_FILES)\[['\"]([^'\"]+)['\"]\]/i", $code, $extraFiles);
                if (!empty($extraFiles[1])) {
                    foreach ($extraFiles[1] as $k) $files[] = trim($k);
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
            'files'          => array_values(array_unique($files)),
            'required_files' => array_values(array_unique($requiredFiles)),
        ];
    }
}
