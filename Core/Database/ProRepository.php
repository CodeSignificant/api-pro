<?php

class ProRepository
{
    /**
     * Scan and auto-create/update tables defined in child constructors
     * @param array $tables Schema configurations mapping tableName to:
     *                      - A string: interpreted as mode='create' with that SQL schema.
     *                      - An array: ['schema' => SQL, 'mode' => 'create'|'force'|'update', 'update' => [ALTER queries], 'lock' => true]
     * @param array|bool $options Repository level options, e.g. true to lock the entire repository, or ['lock' => true]
     */
    public function __construct(array $tables = [], $options = [])
    {
        if (defined('DB_WRITE') && DB_WRITE !== false && !empty($tables)) {
            $globalMode = is_string(DB_WRITE) ? strtolower(DB_WRITE) : null;

            // 1. Resolve Repository-Level Lock
            $repoLocked = false;
            if ($options === true) {
                $repoLocked = true;
            } elseif (is_array($options)) {
                $repoLocked = (bool)($options['lock'] ?? $options['locked'] ?? false);
            }

            // 2. Resolve Global-Level Lock
            $globalLocked = ($globalMode === 'lock' || $globalMode === 'locked');

            // Force lock all tables if locked at global or repository level
            $forceLockAll = ($repoLocked || $globalLocked);

            $createQueue = [];

            foreach ($tables as $tableName => $config) {
                // Determine clean target table name
                $tableNameSafe = ProSql::Escape(is_numeric($tableName) ? (is_array($config) ? ($config['table'] ?? '') : $config) : $tableName);
                
                if (empty($tableNameSafe) || $tableNameSafe === "NULL") {
                    continue;
                }

                // Default settings
                $schemaSql = '';
                $mode = 'create';
                $updateQueries = [];
                $tableLocked = false;

                if (is_array($config)) {
                    $schemaSql = $config['schema'] ?? '';
                    $mode = strtolower($config['mode'] ?? 'create');
                    $updateQueries = $config['update'] ?? [];
                    $tableLocked = (bool)($config['lock'] ?? $config['locked'] ?? false);
                } else {
                    $schemaSql = $config;
                }

                // Apply global override if defined in config
                if ($globalMode !== null && !$globalLocked) {
                    $mode = $globalMode;
                }

                // Standard check existence
                $checkQuery = "SHOW TABLES LIKE '$tableNameSafe'";
                $existsRes = ProSql::FetchList($checkQuery);
                
                if (!$existsRes->isSuccess() && $existsRes->getMessage() === "Database connection is not available.") {
                    // Database is offline or connection failed. Gracefully abort structural sync checks.
                    break;
                }
                
                $exists = ($existsRes->isSuccess() && !empty($existsRes->getData()));

                if (!$exists) {
                    // Create table if missing - add to queue to handle table/scheme dependencies
                    if (!empty($schemaSql)) {
                        $createQueue[] = [
                            'table' => $tableNameSafe,
                            'schema' => $schemaSql,
                            'update' => $updateQueries
                        ];
                    }
                } else {
                    // Table exists! Bypass drop/alter changes if locked at global, repository, or table level
                    if ($forceLockAll || $tableLocked) {
                        continue;
                    }

                    if ($mode === 'force' || $mode === 'recreate') {
                        // Force drops and recreates - add to creation queue
                        $dropQuery = "DROP TABLE IF EXISTS `$tableNameSafe`";
                        ProSql::Query($dropQuery);
                        if (!empty($schemaSql)) {
                            $createQueue[] = [
                                'table' => $tableNameSafe,
                                'schema' => $schemaSql,
                                'update' => $updateQueries
                            ];
                        }
                    } elseif ($mode === 'update' || $mode === 'alter') {
                        // Queue alter/update queries on every fail/sync so all operations use queue retries
                        if (!empty($updateQueries) && is_array($updateQueries)) {
                            $createQueue[] = [
                                'table' => $tableNameSafe,
                                'schema' => null,
                                'update' => $updateQueries
                            ];
                        }
                    }
                }
            }

            // Process schema queue iteratively: retry up to 3 times, return 500 if still failing after 3 attempts
            if (!empty($createQueue)) {
                $maxPasses = 3;
                $pass = 0;
                $lastErrorMessage = "Schema migration failed";

                while (!empty($createQueue) && $pass < $maxPasses) {
                    $pass++;
                    $progressMade = false;
                    $remainingQueue = [];

                    foreach ($createQueue as $item) {
                        $allSuccess = true;

                        // 1. Run schema creation if defined
                        if (!empty($item['schema'])) {
                            $schemas = is_array($item['schema']) ? $item['schema'] : [$item['schema']];
                            foreach ($schemas as $sql) {
                                if (empty(trim($sql))) continue;
                                $res = ProSql::Query($sql);
                                if (!$res->isSuccess()) {
                                    $allSuccess = false;
                                    $lastErrorMessage = $res->getMessage();
                                    break;
                                }
                            }
                        }

                        // 2. Run alter/update queries if schema succeeded (or if only update queries queued)
                        if ($allSuccess && !empty($item['update']) && is_array($item['update'])) {
                            foreach ($item['update'] as $alterQuery) {
                                if (empty(trim($alterQuery))) continue;
                                $res = ProSql::Query($alterQuery);
                                if (!$res->isSuccess()) {
                                    $allSuccess = false;
                                    $lastErrorMessage = $res->getMessage();
                                    break;
                                }
                            }
                        }

                        if ($allSuccess) {
                            $progressMade = true;
                        } else {
                            // Dependent scheme/table issue, re-queue for next pass
                            $remainingQueue[] = $item;
                        }
                    }

                    $createQueue = $remainingQueue;
                }

                // If schema execution still fails after 3 attempts, return HTTP 500 error envelope
                if (!empty($createQueue)) {
                    $failedTable = $createQueue[0]['table'] ?? 'unknown';
                    $failedMsg = "Schema migration failed for table '$failedTable' after 3 attempts: " . $lastErrorMessage;
                    (new DataFailed($failedMsg, 500))->response();
                }
            }
        }
    }
}

// Convenient global alias for developers
if (!class_exists('Repository')) {
    class Repository extends ProRepository {}
}
