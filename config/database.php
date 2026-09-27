<?php
// Database configuration using SQLite
class Database {
    private static $instance = null;
    private $db;
    private $db_file = __DIR__ . '/../database/appraisal.db';
    
    private function __construct() {
        try {
            // Create database directory if it doesn't exist
            $db_dir = dirname($this->db_file);
            if (!is_dir($db_dir)) {
                mkdir($db_dir, 0777, true);
            }
            
            $this->db = new PDO('sqlite:' . $this->db_file);
            $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $this->db->setAttribute(PDO::ATTR_PERSISTENT, false); // no shared state between workers
            
            // Enable foreign keys
            $this->db->exec('PRAGMA foreign_keys = ON;');
            
            // Concurrency & Performance Hardening for 100+ concurrent users
            $this->db->exec('PRAGMA journal_mode = WAL;');         // concurrent reads + 1 writer
            $this->db->exec('PRAGMA busy_timeout = 30000;');       // wait up to 30s before "locked" error
            $this->db->exec('PRAGMA synchronous = NORMAL;');       // safe + fast (WAL protects)
            $this->db->exec('PRAGMA temp_store = MEMORY;');        // temp tables in RAM
            $this->db->exec('PRAGMA cache_size = -32000;');        // 32 MB page cache
            $this->db->exec('PRAGMA mmap_size = 268435456;');      // 256 MB memory-mapped I/O
            $this->db->exec('PRAGMA wal_autocheckpoint = 1000;'); // checkpoint every 1000 pages
        } catch(PDOException $e) {
            die("Connection failed: " . $e->getMessage());
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->db;
    }
    
    public function prepare($sql) {
        return $this->db->prepare($sql);
    }
    
    public function lastInsertId() {
        return $this->db->lastInsertId();
    }
    
    public function beginTransaction() {
        return $this->db->beginTransaction();
    }
    
    public function commit() {
        return $this->db->commit();
    }
    
    public function rollBack() {
        return $this->db->rollBack();
    }
}
?>
