<?php

class ProSql
{
    private static ?mysqli $con = null;

    /** Establish and return database connection */
    private static function connect(): ?mysqli
    {
        if (self::$con === null) {
            try {
                // Disable strict exception throwing momentarily if we want to check connect_error safely
                mysqli_report(MYSQLI_REPORT_OFF);
                
                // Persistent connection (note the "p:")
                self::$con = new mysqli('p:' . DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
        
                if (self::$con->connect_error) {
                    error_log("Database Connection Failed: " . self::$con->connect_error);
                    self::$con = null;
                    return null;
                }
        
                self::$con->set_charset("utf8mb4");
            } catch (Throwable $e) {
                error_log("Database Connection Failed Exception: " . $e->getMessage());
                self::$con = null;
                return null;
            } finally {
                // Restore default error reporting
                mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            }
        }
    
        return self::$con;
    }

    /** Close the DB connection */
    public static function disconnect(): void
    {
        if (self::$con !== null) {
            self::$con->close();
            self::$con = null;
        }
    }
    
    static function UUID() {
        // Generate a random 16-byte string
        $randomBytes = random_bytes(16);
        
        // Set the version (7) and variant bits (10)
        $randomBytes[6] = chr(ord($randomBytes[6]) & 0x0f | 0x70); // Set version to 7
        $randomBytes[8] = chr(ord($randomBytes[8]) & 0x3f | 0x80); // Set variant to 10
        
        // Format the bytes as a hexadecimal string
        $uuidString = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($randomBytes), 4));
        
        return $uuidString;
    }
    
    public static function Escape($value) {
        $con = self::connect();
    
        if (is_null($value)) return "NULL";
    
        // Convert to string if array/object
        if (is_array($value) || is_object($value)) {
            $value = json_encode($value);
        }
    
        // Remove slashes if magic quotes enabled (older servers)
        if (function_exists('get_magic_quotes_gpc') && get_magic_quotes_gpc()) {
            $value = stripslashes($value);
        }
    
        if ($con) {
            return $con->real_escape_string($value);
        }
        return addslashes($value);
    }


    // ------------------- BASIC QUERIES -------------------

    public static function Query($query)
    {
        $con = self::connect();
        if (!$con) {
            return new DataFailed("Database connection is not available.");
        }
        $result = $con->query($query);

        if (!$result) {
            return new DataFailed("Query failed: " . $con->error);
        }

        return new DataSuccess("Query executed successfully", $result);
    }

    public static function FetchListed($query)
    {
        $con = self::connect();
        if (!$con) {
            return new DataFailed("Database connection is not available.");
        }
        $result = $con->query($query);

        if (!$result) {
            return new DataFailed("Query failed: " . $con->error);
        }

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }

        if (empty($data)) {
            return new DataFailed("No records found.");
        }

        return new DataSuccess("Fetch successful", $data);
    }
    
    
    public static function FetchList($query)
    {
        $con = self::connect();
        if (!$con) {
            return new DataFailed("Database connection is not available.");
        }
        $result = $con->query($query);

        if (!$result) {
            return new DataFailed("Query failed: " . $con->error);
        }

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }

        if (empty($data)) {
            return new DataSuccess("No records found.", []);
        }

        return new DataSuccess("Fetch successful", $data);
    }

    public static function Fetch($query)
    {
        $con = self::connect();
        if (!$con) {
            return new DataFailed("Database connection is not available.");
        }
        $result = $con->query($query);

        if (!$result) {
            return new DataFailed("Query failed: " . $con->error);
        }

        if ($result->num_rows == 0) {
            return new DataSuccess("Fetch successful", []);
        }

        $item = $result->fetch_assoc();
        return new DataSuccess("Fetch successful", $item);
    }
    
    
    public static function FetchItem($query)
    {
        $con = self::connect();
        if (!$con) {
            return new DataFailed("Database connection is not available.");
        }
        $result = $con->query($query);

        if (!$result) {
            return new DataFailed("Query failed: " . $con->error);
        }

        if ($result->num_rows == 0) {
            return new DataFailed("No record found.", 404);
        }

        $item = $result->fetch_assoc();
        return new DataSuccess("Fetch successful", $item);
    }

    public static function Updated($query)
    {
        $con = self::connect();
        if (!$con) {
            return new DataFailed("Database connection is not available.");
        }
        $result = $con->query($query);

        if (!$result) {
            return new DataFailed("Update failed: " . $con->error);
        }

        $affected = $con->affected_rows;
        if ($affected === 0) {
            return new DataFailed("Update executed, but no rows were changed.");
        }

        return new DataSuccess("Update successful", $affected);
    }

    public static function Update($query)
    {
        $con = self::connect();
        if (!$con) {
            return new DataFailed("Database connection is not available.");
        }
        $result = $con->query($query);

        if (!$result) {
            return new DataFailed("Update failed: " . $con->error);
        }

        $affected = $con->affected_rows;
        if ($affected === 0) {
            return new DataSuccess("Update executed, but no rows were changed.", []);
        }

        return new DataSuccess("Update successful", $affected);
    }

    // ------------------- PAGINATION SUPPORT -------------------

    public static function FetchPaginated($table, $params, $condition = "1=1", $page = 1, $orderBy = [], $pageSize = 10)
    {
        $con = self::connect();
        if (!$con) {
            return new DataFailed("Database connection is not available.");
        }
        $page = max(1, (int)$page);
        $pageSize = max(1, (int)$pageSize);
        $offset = ($page - 1) * $pageSize;
        if (empty($condition)) $condition = "1=1";

        // Handle order by
        $orderSql = "";

        if (!empty($orderBy)) {
            if (!is_array($orderBy)) {
                $orderBy = [$orderBy];
            }
            $orderParts = [];
            foreach ($orderBy as $order) {
                // Skip invalid input
                if (!is_string($order) || $order === '') {
                    continue;
                }
                $direction = "ASC";
                $field = $order;
                if ($order[0] === '+') {
                    $field = substr($order, 1);
                    $direction = "ASC";
                } elseif ($order[0] === '-') {
                    $field = substr($order, 1);
                    $direction = "DESC";
                }
                $field = preg_replace('/[^a-zA-Z0-9_]/', '', $field);
                if ($field !== '') {
                    $orderParts[] = "$field $direction";
                }
            }
            if (!empty($orderParts)) {
                $orderSql = " ORDER BY " . implode(", ", $orderParts);
            }
        }

        $query = "SELECT $params FROM $table WHERE $condition $orderSql LIMIT $offset, $pageSize";
        $result = $con->query($query);

        if (!$result) {
            return new DataFailed("Query failed: " . $con->error);
        }

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }

        // Count query
        $countQuery = "SELECT COUNT(*) as total_count FROM $table WHERE $condition";
        $countResult = $con->query($countQuery);

        if (!$countResult) {
            return new DataFailed("Count query failed: " . $con->error);
        }

        $totalCountRow = $countResult->fetch_assoc();
        $totalCount = (int)$totalCountRow['total_count'];
        $totalPages = ceil($totalCount / $pageSize);

        return new DataSuccess("Fetch successful", [
            'current_page' => $page,
            'page_size' => $pageSize,
            'total_records' => $totalCount,
            'total_pages' => $totalPages,
            'data' => $data
        ]);
    }


    // DEV
    public static function FetchPaginatedDebug($table, $params, $condition = "1=1", $page = 1, $orderBy = [], $pageSize = 10)
    {
        $con = self::connect();
        if (!$con) {
            return new DataFailed("Database connection is not available.");
        }
        $page = max(1, (int)$page);
        $pageSize = max(1, (int)$pageSize);
        $offset = ($page - 1) * $pageSize;
        if (empty($condition)) $condition = "1=1";

        // Handle order by
        $orderSql = "";
        if (!empty($orderBy)) {
            if (!is_array($orderBy)) {
                $orderBy = [$orderBy];
            }
            $orderParts = [];
            foreach ($orderBy as $order) {
                $direction = "ASC";
                $field = $order;
                if (strpos($order, "+") === 0) {
                    $field = substr($order, 1);
                    $direction = "ASC";
                } elseif (strpos($order, "-") === 0) {
                    $field = substr($order, 1);
                    $direction = "DESC";
                }
                $field = preg_replace('/[^a-zA-Z0-9_\.]/', '', $field);
                if (!empty($field)) {
                    $orderParts[] = "$field $direction";
                }
            }
            if (!empty($orderParts)) {
                $orderSql = " ORDER BY " . implode(", ", $orderParts);
            }
        }

        $query = "SELECT $params FROM $table WHERE $condition $orderSql LIMIT $offset, $pageSize";
        echo($query);
        $result = $con->query($query);

        if (!$result) {
            return new DataFailed("Query failed: " . $con->error);
        }

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }

        // Count query
        $countQuery = "SELECT COUNT(*) as total_count FROM $table WHERE $condition";
        $countResult = $con->query($countQuery);

        if (!$countResult) {
            return new DataFailed("Count query failed: " . $con->error);
        }

        $totalCountRow = $countResult->fetch_assoc();
        $totalCount = (int)$totalCountRow['total_count'];
        $totalPages = ceil($totalCount / $pageSize);

        return new DataSuccess("Fetch successful", [
            'current_page' => $page,
            'page_size' => $pageSize,
            'total_records' => $totalCount,
            'total_pages' => $totalPages,
            'data' => $data
        ]);
    }
}
