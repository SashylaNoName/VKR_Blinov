<?php
require_once 'connect.php';

class GroupManager {
    private $db;
    private $userId;
    private $groupId;

    public function __construct(Database $database, $userId, $groupId = null) {
        $this->db = $database->getConnection();
        $this->userId = (int)$userId;
        $this->groupId = $groupId ? (int)$groupId : null;
    }

    public function groupExists($name, $subjectId) {
        $query = "SELECT COUNT(*) FROM `groups` WHERE `name` = ? AND `teacher_id` = ? AND `subjects_id` = ?";
        $stmt = $this->db->prepare($query);
        if (!$stmt) return false;
        $stmt->bind_param("sii", $name, $this->userId, $subjectId);
        $stmt->execute();
        $stmt->bind_result($count);
        $stmt->fetch();
        $stmt->close();
        return $count > 0;
    }

    public function addGroup($name, $subjectId) {
        if (empty($name)) return "Название группы не может быть пустым.";
        $name = htmlspecialchars($name);
        $subjectId = (int)$subjectId;

        if ($this->groupExists($name, $subjectId)) {
            return "Группа с таким названием и предметом уже существует.";
        }

        $this->db->begin_transaction();
        try {
            $query = "INSERT INTO `groups` (`name`, `teacher_id`, `subjects_id`) VALUES (?, ?, ?)";
            $stmt = $this->db->prepare($query);
            if (!$stmt) throw new Exception("Ошибка подготовки запроса: " . $this->db->error);
            $stmt->bind_param("sii", $name, $this->userId, $subjectId);
            if (!$stmt->execute()) throw new Exception("Ошибка добавления группы: " . $this->db->error);
            $group_id = $this->db->insert_id;
            $stmt->close();

            $default_columns = [
                ['display_name' => '1 модуль', 'type' => 'float', 'section' => 'module1', 'is_fixed' => 1],
                ['display_name' => '2 модуль', 'type' => 'float', 'section' => 'module2', 'is_fixed' => 1],
                ['display_name' => '3 модуль', 'type' => 'float', 'section' => 'module3', 'is_fixed' => 1],
                ['display_name' => 'Итог', 'type' => 'float', 'section' => 'after_module3', 'is_fixed' => 1],
            ];
            $query = "INSERT INTO `student_attributes` (display_name, type, group_id, section, is_fixed) VALUES (?, ?, ?, ?, ?)";
            $stmt = $this->db->prepare($query);
            foreach ($default_columns as $column) {
                $stmt->bind_param("ssisi", $column['display_name'], $column['type'], $group_id, $column['section'], $column['is_fixed']);
                if (!$stmt->execute()) throw new Exception("Ошибка добавления столбца {$column['display_name']}: " . $this->db->error);
            }
            $stmt->close();

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollback();
            return $e->getMessage();
        }
    }

    public function deleteGroup($groupId) {
        $groupId = (int)$groupId;
        $this->db->begin_transaction();
        try {
            $query = "DELETE sav FROM `student_attribute_values` sav
                      JOIN `students` s ON sav.student_id = s.id
                      WHERE s.id_group = ? AND s.id_group IN (SELECT id FROM `groups` WHERE teacher_id = ?)";
            $stmt = $this->db->prepare($query);
            if (!$stmt) throw new Exception("Ошибка подготовки запроса: " . $this->db->error);
            $stmt->bind_param("ii", $groupId, $this->userId);
            if (!$stmt->execute()) throw new Exception("Ошибка удаления значений атрибутов: " . $this->db->error);
            $stmt->close();

            $query = "DELETE FROM `students` WHERE id_group = ? AND id_group IN (SELECT id FROM `groups` WHERE teacher_id = ?)";
            $stmt = $this->db->prepare($query);
            if (!$stmt) throw new Exception("Ошибка подготовки запроса: " . $this->db->error);
            $stmt->bind_param("ii", $groupId, $this->userId);
            if (!$stmt->execute()) throw new Exception("Ошибка удаления студентов: " . $this->db->error);
            $stmt->close();

            $query = "DELETE FROM `student_attributes` WHERE group_id = ? AND group_id IN (SELECT id FROM `groups` WHERE teacher_id = ?)";
            $stmt = $this->db->prepare($query);
            if (!$stmt) throw new Exception("Ошибка подготовки запроса: " . $this->db->error);
            $stmt->bind_param("ii", $groupId, $this->userId);
            if (!$stmt->execute()) throw new Exception("Ошибка удаления атрибутов: " . $this->db->error);
            $stmt->close();

            $query = "DELETE FROM `groups` WHERE id = ? AND teacher_id = ?";
            $stmt = $this->db->prepare($query);
            if (!$stmt) throw new Exception("Ошибка подготовки запроса: " . $this->db->error);
            $stmt->bind_param("ii", $groupId, $this->userId);
            if (!$stmt->execute()) throw new Exception("Ошибка удаления группы: " . $this->db->error);
            $stmt->close();

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollback();
            error_log("[" . date('Y-m-d H:i:s') . "] Ошибка в deleteGroup: " . $e->getMessage() . "\n", 3, "logs/sql_errors.log");
            return "Ошибка удаления группы: " . $e->getMessage();
        }
    }

    public function hasAccess() {
        if (!$this->groupId) return false;
        $query = "SELECT id, name FROM `groups` WHERE id = ? AND teacher_id = ?";
        $stmt = $this->db->prepare($query);
        if (!$stmt) return false;
        $stmt->bind_param("ii", $this->groupId, $this->userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $group = $result->fetch_assoc();
        $stmt->close();
        return $group ?: false;
    }

    public function getAttributes() {
        if (!$this->groupId) return [];
        $query = "SELECT id, display_name, type, section, is_fixed FROM `student_attributes` WHERE group_id = ? ORDER BY FIELD(section, 'before_module1', 'module1', 'after_module1', 'module2', 'after_module2', 'module3', 'after_module3'), id";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param("i", $this->groupId);
        $stmt->execute();
        $result = $stmt->get_result();
        $attributes = [];
        while ($row = $result->fetch_assoc()) {
            $attributes[$row['id']] = $row;
        }
        $stmt->close();
        return $attributes;
    }

    public function getStudents() {
        if (!$this->groupId) return [];
        $query = "SELECT id, fio FROM `students` WHERE id_group = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param("i", $this->groupId);
        $stmt->execute();
        $result = $stmt->get_result();
        $students = [];
        while ($row = $result->fetch_assoc()) {
            $students[$row['id']] = $row;
            $students[$row['id']]['attributes'] = $this->getStudentAttributes($row['id']);
        }
        $stmt->close();

        // Calculate totals for each student
        foreach ($students as &$student) {
            $this->calculateStudentTotals($student);
        }
        return $students;
    }

    private function getStudentAttributes($student_id) {
        $query = "SELECT attribute_id, value FROM `student_attribute_values` WHERE student_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param("i", $student_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $attributes = [];
        while ($row = $result->fetch_assoc()) {
            $attributes[$row['attribute_id']] = $row['value'];
        }
        $stmt->close();
        return $attributes;
    }

    private function calculateStudentTotals(&$student) {
        $attributes = $this->getAttributes();
        $sectionTotals = [
            'module1' => 0,
            'module2' => 0,
            'module3' => 0,
            'total' => 0
        ];
        $sectionOrder = ['before_module1', 'module1', 'after_module1', 'module2', 'after_module2', 'module3', 'after_module3'];

        foreach ($sectionOrder as $section) {
            foreach ($attributes as $attr) {
                if ($attr['section'] !== $section) continue;
                $value = isset($student['attributes'][$attr['id']]) ? (float)$student['attributes'][$attr['id']] : 0;
                if (in_array($attr['type'], ['int', 'float'])) {
                    if ($section === 'before_module1') {
                        $sectionTotals['module1'] += $value;
                    } elseif ($section === 'after_module1') {
                        $sectionTotals['module2'] += $value;
                    } elseif ($section === 'after_module2') {
                        $sectionTotals['module3'] += $value;
                    } elseif ($section === 'after_module3' && $attr['display_name'] !== 'Итог') {
                        $sectionTotals['total'] += $value;
                    }
                }
            }
        }

        foreach ($attributes as $attr) {
            if ($attr['display_name'] === '1 модуль' && $attr['section'] === 'module1') {
                $this->setAttributeValue($student['id'], $attr['id'], $sectionTotals['module1']);
                $student['attributes'][$attr['id']] = $sectionTotals['module1'];
            } elseif ($attr['display_name'] === '2 модуль' && $attr['section'] === 'module2') {
                $this->setAttributeValue($student['id'], $attr['id'], $sectionTotals['module2']);
                $student['attributes'][$attr['id']] = $sectionTotals['module2'];
            } elseif ($attr['display_name'] === '3 модуль' && $attr['section'] === 'module3') {
                $this->setAttributeValue($student['id'], $attr['id'], $sectionTotals['module3']);
                $student['attributes'][$attr['id']] = $sectionTotals['module3'];
            } elseif ($attr['display_name'] === 'Итог' && $attr['section'] === 'after_module3') {
                $total = $sectionTotals['module1'] + $sectionTotals['module2'] + $sectionTotals['module3'] + $sectionTotals['total'];
                $this->setAttributeValue($student['id'], $attr['id'], $total);
                $student['attributes'][$attr['id']] = $total;
            }
        }
    }

    public function addStudent($fio) {
    // Инициализация отладочного лога в сессии
    if (!isset($_SESSION['debug_log'])) {
        $_SESSION['debug_log'] = [];
    }

    array_push($_SESSION['debug_log'], "[" . date('Y-m-d H:i:s') . "] Вызов addStudent с ФИО: '$fio', group_id: " . ($this->groupId ?? 'null'));

    if (empty($fio)) {
        array_push($_SESSION['debug_log'], "[" . date('Y-m-d H:i:s') . "] Ошибка: ФИО пустое");
        return "ФИО не может быть пустым.";
    }

    if (!$this->groupId) {
        array_push($_SESSION['debug_log'], "[" . date('Y-m-d H:i:s') . "] Ошибка: groupId не задан");
        return "Ошибка: ID группы не задан.";
    }

    array_push($_SESSION['debug_log'], "[" . date('Y-m-d H:i:s') . "] Подготовка SQL-запроса для добавления студента");
    $query = "INSERT INTO `students` (fio, id_group) VALUES (?, ?)";
    $stmt = $this->db->prepare($query);
    if (!$stmt) {
        $error = "Ошибка подготовки запроса: " . $this->db->error;
        array_push($_SESSION['debug_log'], "[" . date('Y-m-d H:i:s') . "] $error");
        return $error;
    }

    array_push($_SESSION['debug_log'], "[" . date('Y-m-d H:i:s') . "] Привязка параметров: fio='$fio', id_group={$this->groupId}");
    $stmt->bind_param("si", $fio, $this->groupId);
    
    array_push($_SESSION['debug_log'], "[" . date('Y-m-d H:i:s') . "] Выполнение SQL-запроса");
    if ($stmt->execute()) {
        $student_id = $this->db->insert_id;
        array_push($_SESSION['debug_log'], "[" . date('Y-m-d H:i:s') . "] Студент успешно добавлен, ID: $student_id");
        $stmt->close();
        return $student_id;
    }

    $error = "Ошибка добавления студента: " . $this->db->error;
    array_push($_SESSION['debug_log'], "[" . date('Y-m-d H:i:s') . "] $error");
    $stmt->close();
    return $error;
}

  public function addAttribute($display_name, $type, $section = 'after_module3') {
    // Инициализация логирования
    error_log("Начало addAttribute: display_name='$display_name', type='$type', section='$section', group_id=" . ($this->groupId ?? 'null'));

    // Проверка входных данных
    if (empty($display_name)) {
        error_log("Ошибка: display_name пустое");
        return "Название атрибута не может быть пустым.";
    }
    if (!in_array($type, ['text', 'int', 'float'])) {
        error_log("Ошибка: недопустимый тип данных '$type'");
        return "Недопустимый тип данных.";
    }
    if (!in_array($section, ['before_module1', 'module1', 'after_module1', 'module2', 'after_module2', 'module3', 'after_module3'])) {
        error_log("Ошибка: недопустимая секция '$section'");
        return "Недопустимая позиция столбца: $section";
    }
    if (!$this->groupId) {
        error_log("Ошибка: groupId не задан");
        return "ID группы не задан.";
    }

    // Проверка подключения к базе данных
    if ($this->db->connect_error) {
        $error = "Ошибка подключения к базе данных: " . $this->db->connect_error . " (errno: {$this->db->connect_errno})";
        error_log($error);
        return $error;
    }

    try {
        // Проверка существования группы
        $query = "SELECT id FROM `groups` WHERE id = ?";
        $stmt = $this->db->prepare($query);
        if (!$stmt) {
            $error = "Ошибка подготовки запроса проверки группы: " . $this->db->error . " (errno: {$this->db->errno})";
            error_log($error);
            return $error;
        }
        $stmt->bind_param("i", $this->groupId);
        if (!$stmt->execute()) {
            $error = "Ошибка выполнения запроса проверки группы: " . $stmt->error . " (errno: {$stmt->errno})";
            error_log($error);
            $stmt->close();
            return $error;
        }
        $result = $stmt->get_result();
        if ($result->num_rows === 0) {
            $error = "Группа с ID {$this->groupId} не существует";
            error_log($error);
            $stmt->close();
            return $error;
        }
        $stmt->close();

        // Проверка существования атрибута
        $query = "SELECT id FROM student_attributes WHERE display_name = ? AND group_id = ?";
        $stmt = $this->db->prepare($query);
        if (!$stmt) {
            $error = "Ошибка подготовки запроса проверки атрибута: " . $this->db->error . " (errno: {$this->db->errno})";
            error_log($error);
            return $error;
        }
        $stmt->bind_param("si", $display_name, $this->groupId);
        if (!$stmt->execute()) {
            $error = "Ошибка выполнения запроса проверки атрибута: " . $stmt->error . " (errno: {$stmt->errno})";
            error_log($error);
            $stmt->close();
            return $error;
        }
        $result = $stmt->get_result();
     

        // Добавление нового атрибута
        $query = "INSERT INTO student_attributes (display_name, type, group_id, section) VALUES (?, ?, ?, ?)";
        $stmt = $this->db->prepare($query);
        if (!$stmt) {
            $error = "Ошибка подготовки запроса вставки атрибута: " . $this->db->error . " (errno: {$this->db->errno})";
            error_log($error);
            return $error;
        }
        $stmt->bind_param("ssis", $display_name, $type, $this->groupId, $section);
        if ($stmt->execute()) {
            $attribute_id = $this->db->insert_id;
            error_log("Атрибут '$display_name' успешно добавлен, ID: $attribute_id, section: $section");
            $stmt->close();
            return $attribute_id;
        } else {
            $error = "Ошибка выполнения запроса вставки атрибута: " . $stmt->error . " (errno: {$stmt->errno})";
            error_log($error);
            $stmt->close();
            return $error;
        }
    } catch (Exception $e) {
        $error = "Исключение в addAttribute: " . $e->getMessage();
        error_log($error);
        return $error;
    }
}
    public function renameAttribute($attribute_id, $display_name) {
        if (empty($display_name)) return "Название атрибута не может быть пустым.";
        $query = "UPDATE `student_attributes` SET display_name = ? WHERE id = ? AND group_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param("sii", $display_name, $attribute_id, $this->groupId);
        if ($stmt->execute()) {
            $stmt->close();
            return true;
        }
        $stmt->close();
        return "Ошибка переименования атрибута: " . $this->db->error;
    }

    public function deleteAttribute($attribute_id) {
        $query = "DELETE FROM `student_attribute_values` WHERE attribute_id = ? AND EXISTS (SELECT 1 FROM `student_attributes` WHERE id = ? AND group_id = ?)";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param("iii", $attribute_id, $attribute_id, $this->groupId);
        if (!$stmt->execute()) {
            $stmt->close();
            return "Ошибка удаления значений атрибута: " . $this->db->error;
        }
        $stmt->close();

        $query = "DELETE FROM `student_attributes` WHERE id = ? AND group_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param("ii", $attribute_id, $this->groupId);
        if ($stmt->execute()) {
            $stmt->close();
            return true;
        }
        $stmt->close();
        return "Ошибка удаления атрибута: " . $this->db->error;
    }

    public function setAttributeValue($student_id, $attribute_id, $value) {
        $query = "SELECT `type` FROM `student_attributes` WHERE id = ? AND group_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param("ii", $attribute_id, $this->groupId);
        $stmt->execute();
        $result = $stmt->get_result();
        $attr = $result->fetch_assoc();
        $stmt->close();

        if (!$attr) return "Атрибут не найден.";
        if ($attr['type'] === 'int') $value = (int)$value;
        elseif ($attr['type'] === 'float') $value = (float)$value;
        else $value = htmlspecialchars($value);

        $query = "SELECT id FROM `student_attribute_values` WHERE student_id = ? AND attribute_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param("ii", $student_id, $attribute_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $query = "UPDATE `student_attribute_values` SET value = ? WHERE student_id = ? AND attribute_id = ?";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param("sii", $value, $student_id, $attribute_id);
        } else {
            $query = "INSERT INTO `student_attribute_values` (student_id, attribute_id, value) VALUES (?, ?, ?)";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param("iis", $student_id, $attribute_id, $value);
        }

        if ($stmt->execute()) {
            $stmt->close();
            return true;
        }
        $stmt->close();
        return "Ошибка установки значения атрибута: " . $this->db->error;
    }
}
?>