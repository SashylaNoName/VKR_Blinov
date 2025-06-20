<?php
session_start();
require_once 'connect.php';

// Включаем отображение ошибок для отладки
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

class UserRegistration {
    private $db;
    private $surname;
    private $name;
    private $password;
    private $password_confirm;
    private $role;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        if (!$this->db) {
            $_SESSION['message'] = "Ошибка подключения к базе данных";
            throw new Exception("Не удалось подключиться к базе данных");
        }
    }

    public function validateInput($postData): bool {
        $this->surname = htmlspecialchars($postData['surname'] ?? '');
        $this->name = htmlspecialchars($postData['name'] ?? '');
        $this->password = $postData['password'] ?? '';
        $this->password_confirm = $postData['password_confirm'] ?? '';
        $this->role = htmlspecialchars($postData['role'] ?? 'student');

        if (empty($this->surname) || empty($this->name) || empty($this->password)) {
            $_SESSION['message'] = "Все поля обязательны для заполнения";
            return false;
        }

        if ($this->password !== $this->password_confirm) {
            $_SESSION['message'] = "Пароли не совпадают";
            return false;
        }

        if (!in_array($this->role, ['student', 'teacher', 'admin'])) {
            $_SESSION['message'] = "Недопустимая роль";
            return false;
        }

        return true;
    }

    public function register(): bool {
        // Проверяем, не существует ли уже пользователь с такой фамилией
        $stmt = $this->db->prepare("SELECT id FROM `users` WHERE `surname` = ?");
        if (!$stmt) {
            $_SESSION['message'] = "Ошибка подготовки запроса на проверку: " . $this->db->error;
            return false;
        }
        $stmt->bind_param("s", $this->surname);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $_SESSION['message'] = "Пользователь с такой фамилией уже существует";
            $stmt->close();
            return false;
        }
        $stmt->close();

        // Хешируем пароль
        $password = password_hash($this->password, PASSWORD_DEFAULT);
        if ($password === false) {
            $_SESSION['message'] = "Ошибка хеширования пароля";
            return false;
        }

        // Регистрируем пользователя
        $query = "INSERT INTO `users` (`name`, `surname`, `password`, `role`) VALUES (?, ?, ?, ?)";
        $stmt = $this->db->prepare($query);
        if (!$stmt) {
            $_SESSION['message'] = "Ошибка подготовки запроса: " . $this->db->error;
            return false;
        }

        $stmt->bind_param("ssss", $this->name, $this->surname, $password, $this->role);
        
        if ($stmt->execute()) {
            $_SESSION['message'] = "Регистрация прошла успешно";
            $stmt->close();
            return true;
        } else {
            $_SESSION['message'] = "Ошибка регистрации: " . $stmt->error;
            $stmt->close();
            return false;
        }
    }
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $registration = new UserRegistration();
        
        if ($registration->validateInput($_POST)) {
            if ($registration->register()) {
                header('Location: ../index.php');
                exit();
            }
        }
        header('Location: ../register.php');
        exit();
    }
} catch (Exception $e) {
    $_SESSION['message'] = "Произошла ошибка: " . $e->getMessage();
    header('Location: ../register.php');
    exit();
}
?>