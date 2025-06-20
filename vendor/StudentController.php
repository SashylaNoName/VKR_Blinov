<?php
require_once 'connect.php';
require_once 'GroupManager.php';

class StudentController {
    private $groupManager;
    private $teacher_id;
    private $group_id;

    public function __construct($teacher_id, $group_id) {
        $this->teacher_id = (int)$teacher_id;
        $this->group_id = (int)$group_id;
        $this->groupManager = new GroupManager(Database::getInstance(), $teacher_id, $group_id);
    }

    public function addStudent($fio) {
        if (empty($fio)) return "ФИО не может быть пустым.";
        return $this->groupManager->addStudent($fio);
    }

   public function deleteStudent($student_id) {
    $student_id = (int)$student_id;
    if ($student_id <= 0) {
        error_log("[" . date('Y-m-d H:i:s') . "] Неверный student_id: $student_id\n", 3, "logs/sql_errors.log");
        return "Неверный ID студента.";
    }

    $db = Database::getInstance()->getConnection();
    if (!$db) {
        error_log("[" . date('Y-m-d H:i:s') . "] Ошибка подключения к базе данных\n", 3, "logs/sql_errors.log");
        return "Ошибка подключения к базе данных.";
    }

    $db->begin_transaction();
    try {
        // Удаление значений атрибутов студента
        $query = "DELETE FROM `student_attribute_values` WHERE student_id = ?";
        $stmt = $db->prepare($query);
        if (!$stmt) {
            throw new Exception("Ошибка подготовки запроса (attribute_values): " . $db->error);
        }
        $stmt->bind_param("i", $student_id);
        if (!$stmt->execute()) {
            throw new Exception("Ошибка выполнения запроса (attribute_values): " . $stmt->error);
        }
        $stmt->close();

        // Удаление студента
        $query = "DELETE FROM `students` WHERE id = ?";
        $stmt = $db->prepare($query);
        if (!$stmt) {
            throw new Exception("Ошибка подготовки запроса (students): " . $db->error);
        }
        $stmt->bind_param("i", $student_id);
        if (!$stmt->execute()) {
            throw new Exception("Ошибка выполнения запроса (students): " . $stmt->error);
        }
        $stmt->close();

        $db->commit();
        error_log("[" . date('Y-m-d H:i:s') . "] Студент ID $student_id успешно удалён\n", 3, "logs/sql_errors.log");
        return true;
    } catch (Exception $e) {
        $db->rollback();
        $error_message = "[" . date('Y-m-d H:i:s') . "] Ошибка в deleteStudent: " . $e->getMessage() . "\n";
        error_log($error_message, 3, "logs/sql_errors.log");
        return "Ошибка удаления студента: " . $e->getMessage();
    }
}
    public function updateStudentField($student_id, $field, $value) {
        $db = Database::getInstance();
        $connect = $db->getConnection();
        $student_id = (int)$student_id;
        $id_group = (int)$this->group_id;

        if ($field === 'fio') {
            $stmt = $connect->prepare("UPDATE `students` SET `fio` = ? WHERE `id` = ? AND `id_group` = ?");
            $stmt->bind_param("sii", $value, $student_id, $id_group);
        } elseif (strpos($field, 'attr_') === 0) {
            $attribute_id = (int)str_replace('attr_', '', $field);
            $stmt = $connect->prepare("SELECT `type` FROM `student_attributes` WHERE `id` = ? AND `group_id` = ?");
            $stmt->bind_param("ii", $attribute_id, $id_group);
            $stmt->execute();
            $attr = $stmt->get_result()->fetch_assoc();

            if (!$attr) return "Атрибут не найден.";
            if ($attr['type'] === 'int') $value = (int)$value;
            elseif ($attr['type'] === 'float') $value = (float)$value;
            else $value = htmlspecialchars($value);

            $stmt = $connect->prepare("SELECT `id` FROM `student_attribute_values` WHERE `student_id` = ? AND `attribute_id` = ?");
            $stmt->bind_param("ii", $student_id, $attribute_id);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                $stmt = $connect->prepare("UPDATE `student_attribute_values` SET `value` = ? WHERE `student_id` = ? AND `attribute_id` = ?");
                $stmt->bind_param("sii", $value, $student_id, $attribute_id);
            } else {
                $stmt = $connect->prepare("INSERT INTO `student_attribute_values` (`student_id`, `attribute_id`, `value`) VALUES (?, ?, ?)");
                $stmt->bind_param("iis", $student_id, $attribute_id, $value);
            }
        } else {
            return "Недопустимое поле.";
        }

        return $stmt->execute() ? true : "Ошибка выполнения запроса: " . $connect->error;
    }
}
?>