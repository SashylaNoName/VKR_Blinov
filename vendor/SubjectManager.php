<?php
require_once 'connect.php';
require_once 'GroupManager.php';

class SubjectManager {
    private $db;

    public function __construct(Database $database) {
        $this->db = $database->getConnection();
    }

    public function addSubject($name, $teacher_id) {
        if (empty($name)) {
            return "Название предмета не может быть пустым.";
        }
        $name = htmlspecialchars($name);
        $teacher_id = (int)$teacher_id;

        $query = "SELECT COUNT(*) FROM subjects WHERE name = ? AND teacher_id = ?";
        $stmt = $this->db->prepare($query);
        if (!$stmt) {
            error_log("[" . date('Y-m-d H:i:s') . "] Ошибка подготовки запроса в addSubject: " . $this->db->error . "\n", 3, "logs/sql_errors.log");
            return "Ошибка подготовки запроса: " . $this->db->error;
        }
        $stmt->bind_param("si", $name, $teacher_id);
        $stmt->execute();
        $stmt->bind_result($count);
        $stmt->fetch();
        $stmt->close();

        if ($count > 0) {
            return "Предмет с таким названием уже существует.";
        }

        $query = "INSERT INTO subjects (name, teacher_id) VALUES (?, ?)";
        $stmt = $this->db->prepare($query);
        if (!$stmt) {
            error_log("[" . date('Y-m-d H:i:s') . "] Ошибка подготовки запроса в addSubject: " . $this->db->error . "\n", 3, "logs/sql_errors.log");
            return "Ошибка подготовки запроса: " . $this->db->error;
        }
        $stmt->bind_param("si", $name, $teacher_id);
        if ($stmt->execute()) {
            $stmt->close();
            return true;
        }
        $stmt->close();
        return "Ошибка добавления предмета: " . $this->db->error;
    }

    public function deleteSubject($subject_id, $teacher_id) {
        $subject_id = (int)$subject_id;
        $teacher_id = (int)$teacher_id;

        $this->db->begin_transaction();
        try {
            // Найти все группы, связанные с предметом
            $query = "SELECT id FROM `groups` WHERE subjects_id = ? AND teacher_id = ?";
            $stmt = $this->db->prepare($query);
            if (!$stmt) throw new Exception("Ошибка подготовки запроса: " . $this->db->error);
            $stmt->bind_param("ii", $subject_id, $teacher_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $group_ids = [];
            while ($row = $result->fetch_assoc()) {
                $group_ids[] = $row['id'];
            }
            $stmt->close();

            // Удалить все связанные группы
            $groupManager = new GroupManager(Database::getInstance(), $teacher_id);
            foreach ($group_ids as $group_id) {
                $result = $groupManager->deleteGroup($group_id);
                if ($result !== true) {
                    throw new Exception("Ошибка удаления группы ID $group_id: $result");
                }
            }

            // Удалить предмет
            $query = "DELETE FROM subjects WHERE id = ? AND teacher_id = ?";
            $stmt = $this->db->prepare($query);
            if (!$stmt) throw new Exception("Ошибка подготовки запроса: " . $this->db->error);
            $stmt->bind_param("ii", $subject_id, $teacher_id);
            if (!$stmt->execute()) {
                throw new Exception("Ошибка удаления предмета: " . $this->db->error);
            }
            $stmt->close();

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollback();
            error_log("[" . date('Y-m-d H:i:s') . "] Ошибка в deleteSubject: " . $e->getMessage() . "\n", 3, "logs/sql_errors.log");
            return "Ошибка удаления: " . $e->getMessage();
        }
    }
}
?>