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
                $exists = ($existsRes->isSuccess() && !empty($existsRes->getData()));

                if (!$exists) {
                    // Create table if it doesn't exist (locked or not, we must create it first to avoid app crashes)
                    if (!empty($schemaSql)) {
                        ProSql::Query($schemaSql);
                    }
                } else {
                    // Table exists! Bypass drop/alter changes if locked at global, repository, or table level
                    if ($forceLockAll || $tableLocked) {
                        continue;
                    }

                    if ($mode === 'force' || $mode === 'recreate') {
                        // Force drops and recreates
                        $dropQuery = "DROP TABLE IF EXISTS `$tableNameSafe`";
                        ProSql::Query($dropQuery);
                        if (!empty($schemaSql)) {
                            ProSql::Query($schemaSql);
                        }
                    } elseif ($mode === 'update' || $mode === 'alter') {
                        // Run incremental alter queries
                        if (!empty($updateQueries) && is_array($updateQueries)) {
                            foreach ($updateQueries as $alterQuery) {
                                if (!empty($alterQuery)) {
                                    ProSql::Query($alterQuery);
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}

// Convenient global alias for developers
if (!class_exists('Repository')) {
    class Repository extends ProRepository {}
}
