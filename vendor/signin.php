<?php
session_start();
require_once 'connect.php';

class UserAuthentication {
    private $db;
    private $surname;
    private $password;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    public function validateInput($postData): bool {
        $this->surname = htmlspecialchars($postData['surname'] ?? '');
        $this->password = $postData['password'] ?? '';

        if (empty($this->surname) || empty($this->password)) {
            $_SESSION['message'] = "Все поля обязательны для заполнения";
            return false;
        }
        return true;
    }

    public function authenticate(): bool {
        $query = "SELECT id, role, password FROM `users` WHERE `surname` = ?";
        $stmt = $this->db->prepare($query);
        
        if (!$stmt) {
            $_SESSION['message'] = "Ошибка подготовки запроса";
            return false;
        }

        $stmt->bind_param("s", $this->surname);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $user = $result->fetch_assoc();
            if (password_verify($this->password, $user['password'])) { // Проверяем пароль с помощью password_verify
                $_SESSION['user'] = [
                    'id' => $user['id'],
                    'role' => $user['role']
                ];
                $stmt->close();
                return true;
            }
        }
        
        $_SESSION['message'] = "Неверная фамилия или пароль";
        $stmt->close();
        return false;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $auth = new UserAuthentication();
    
    if ($auth->validateInput($_POST)) {
        if ($auth->authenticate()) {
            header('Location: ../profile.php');
            exit();
        }
    }
    header('Location: ../index.php');
    exit();
}