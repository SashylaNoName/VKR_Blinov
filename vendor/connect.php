<?php
class Database {
    private static $instance = null;
    private $connection;
    private $host = 'localhost';
    private $user = 'a1078522_scientific_work';
    private $password = 'Sashyla1359';
    private $database = 'a1078522_scientific_work';

    private function __construct() {
        $this->connection = new mysqli($this->host, $this->user, $this->password, $this->database);
        
        if ($this->connection->connect_error) {
            die("Ошибка подключения к базе данных: " . $this->connection->connect_error);
        }
        
        $this->connection->set_charset("utf8mb4");
    }

    public static function getInstance(): Database {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }

    public function getConnection(): mysqli {
        return $this->connection;
    }

    private function __clone() {}

    public function __wakeup() {
        throw new Exception("Нельзя десериализовать singleton.");
    }

    public function closeConnection() {
        if ($this->connection) {
            $this->connection->close();
        }
    }
}
?>