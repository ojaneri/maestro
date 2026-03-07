<?php

class Database {
    private static $instance = null;
    private $db;

    private function __construct() {
        $dbPath = __DIR__ . '/../chat_data.db';
        if (!file_exists($dbPath)) {
            throw new RuntimeException('Database file not found: ' . $dbPath);
        }
        
        $this->db = new SQLite3($dbPath, SQLITE3_OPEN_READWRITE);
        $this->db->exec('PRAGMA foreign_keys = ON;');
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection() {
        return $this->db;
    }

    public function query($sql, $params = []) {
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Failed to prepare statement: ' . $this->db->lastErrorMsg());
        }
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        $result = $stmt->execute();
        if (!$result) {
            throw new RuntimeException('Query failed: ' . $this->db->lastErrorMsg());
        }
        
        return $result;
    }

    public function fetchAll($sql, $params = []) {
        $result = $this->query($sql, $params);
        $rows = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $row;
        }
        $result->finalize();
        return $rows;
    }

    public function fetchOne($sql, $params = []) {
        $result = $this->query($sql, $params);
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $result->finalize();
        return $row;
    }

    public function insert($table, $data) {
        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), ':'));
        
        $sql = "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})";
        $params = [];
        foreach ($data as $key => $value) {
            $params[":{$key}"] = $value;
        }
        
        $this->query($sql, $params);
        return $this->db->lastInsertRowID();
    }

    public function update($table, $data, $where, $whereParams = []) {
        $setClause = [];
        $params = [];
        
        foreach ($data as $key => $value) {
            $setClause[] = "{$key} = :{$key}";
            $params[":{$key}"] = $value;
        }
        
        $setClause = implode(', ', $setClause);
        $params = array_merge($params, $whereParams);
        
        $sql = "UPDATE {$table} SET {$setClause} WHERE {$where}";
        $this->query($sql, $params);
        return $this->db->changes();
    }

    public function delete($table, $where, $params = []) {
        $sql = "DELETE FROM {$table} WHERE {$where}";
        $this->query($sql, $params);
        return $this->db->changes();
    }

    public function tableExists($tableName) {
        $sql = "SELECT name FROM sqlite_master WHERE type='table' AND name=:name LIMIT 1";
        $result = $this->query($sql, [':name' => $tableName]);
        $exists = $result->fetchArray(SQLITE3_ASSOC) !== false;
        $result->finalize();
        return $exists;
    }

    public function beginTransaction() {
        $this->db->exec('BEGIN TRANSACTION');
    }

    public function commit() {
        $this->db->exec('COMMIT');
    }

    public function rollback() {
        $this->db->exec('ROLLBACK');
    }

    public function __destruct() {
        if ($this->db) {
            $this->db->close();
        }
    }
}