<?php
require_once 'vendor/autoload.php';
require_once 'connect.php';
require_once 'GroupManager.php';
use Shuchkin\SimpleXLSX;

class ImportExportController {
    private $groupManager;
    private $teacher_id;
    private $group_id;
    private $attributes;

    public function __construct($teacher_id, $group_id, $attributes) {
        $this->teacher_id = (int)$teacher_id;
        $this->group_id = (int)$group_id;
        $this->attributes = $attributes;
        $this->groupManager = new GroupManager(Database::getInstance(), $teacher_id, $group_id);
    }

    public function importExcel($file, $create_total_column = false) {
        // Инициализация отладочного лога
        if (!isset($_SESSION['debug_log'])) {
            $_SESSION['debug_log'] = [];
        }

        array_push($_SESSION['debug_log'], "[" . date('Y-m-d H:i:s') . "] Начало импорта Excel, файл: " . print_r($file, true) . ", group_id: {$this->group_id}, teacher_id: {$this->teacher_id}");

        if (!file_exists($file['tmp_name'])) {
            array_push($_SESSION['debug_log'], "[" . date('Y-m-d H:i:s') . "] Ошибка: загруженный файл не найден: " . $file['tmp_name']);
            return "Ошибка: загруженный файл не найден.";
        }

        if ($xlsx = SimpleXLSX::parse($file['tmp_name'])) {
            $header = $xlsx->rows()[0];
            $data = array_slice($xlsx->rows(), 1);

            array_push($_SESSION['debug_log'], "[" . date('Y-m-d H:i:s') . "] Заголовки Excel: " . implode(', ', $header));
            array_push($_SESSION['debug_log'], "[" . date('Y-m-d H:i:s') . "] Количество строк данных: " . count($data));

            $errors = [];

            // Проверка обязательных столбцов
            $requiredColumns = ['ФИО', '1 модуль', '2 модуль', '3 модуль', 'Итог'];
            $headerLower = array_map('mb_strtolower', array_map('trim', $header));
            $requiredIndexes = [];
            foreach ($requiredColumns as $reqCol) {
                $reqColLower = mb_strtolower($reqCol);
                $index = array_search($reqColLower, $headerLower);
                if ($index === false) {
                    $errors[] = "Отсутствует обязательный столбец '$reqCol'.";
                } else {
                    $requiredIndexes[$reqColLower] = $index;
                }
            }
            if (!empty($errors)) {
                array_push($_SESSION['debug_log'], "[" . date('Y-m-d H:i:s') . "] Ошибки проверки заголовков: " . implode('; ', $errors));
                return "Обнаружены ошибки: " . implode('; ', $errors) . ".";
            }

            // Проверка порядка столбцов
            $expectedOrder = array_keys($requiredIndexes);
            $sortedIndexes = array_values($requiredIndexes);
            sort($sortedIndexes);
            if ($sortedIndexes !== array_values($requiredIndexes)) {
                array_push($_SESSION['debug_log'], "[" . date('Y-m-d H:i:s') . "] Ошибка порядка столбцов: " . implode(', ', $header));
                return "Ошибка: столбцы " . implode(', ', $requiredColumns) . " должны быть в правильном порядке слева направо.";
            }

            // Определение секций и маппинг атрибутов
            $sectionMap = [
                'before_module1' => [],
                'after_module1' => [],
                'after_module2' => [],
                'after_module3' => [],
            ];
            $headerToAttrMap = [];
            $existingAttributes = array_column($this->attributes, 'display_name', 'id');
            $columnData = [];
            foreach ($header as $index => $column) {
                $columnData[$index] = array_column($data, $index);
            }

            foreach ($header as $index => $column) {
                $column = trim($column);
                if (empty($column)) continue;

                $columnLower = mb_strtolower($column);
                if ($columnLower === 'фио') {
                    $headerToAttrMap[$index] = 'fio';
                    continue;
                }
                if (in_array($columnLower, ['1 модуль', '2 модуль', '3 модуль', 'итог'])) {
                    continue; // Игнорируем вычисляемые столбцы
                }

                // Определяем секцию
                $section = 'after_module3';
                if ($index < $requiredIndexes['1 модуль']) {
                    $section = 'before_module1';
                } elseif ($index < $requiredIndexes['2 модуль']) {
                    $section = 'after_module1';
                } elseif ($index < $requiredIndexes['3 модуль']) {
                    $section = 'after_module2';
                } elseif ($index <= count($header)) {
                    $section = 'after_module3';
                }

                // Формируем уникальное имя для столбцов с одинаковыми названиями
                $display_name = $column;
                if (in_array($column, ['1', '2', '3'])) {
                    $display_name = $column . '_' . str_replace('_', '', $section);
                }

                // Проверяем, существует ли атрибут
                $attrId = array_search($display_name, $existingAttributes);
                if ($attrId === false) {
                    $type = $this->detectColumnType($columnData[$index]);
                    array_push($_SESSION['debug_log'], "[" . date('Y-m-d H:i:s') . "] Пытаемся добавить атрибут '$display_name' с типом '$type' в секции '$section'");
                    $result = $this->groupManager->addAttribute($display_name, $type, $section);
                    if (is_numeric($result) && $result > 0) {
                        $attrId = $result;
                        $existingAttributes[$attrId] = $display_name;
                        array_push($_SESSION['debug_log'], "[" . date('Y-m-d H:i:s') . "] Атрибут '$display_name' добавлен (ID: $attrId) в секции '$section'");
                    } else {
                        array_push($_SESSION['debug_log'], "[" . date('Y-m-d H:i:s') . "] Ошибка добавления атрибута '$display_name': $result");
                        continue; // Пропускаем ошибку, чтобы продолжить импорт
                    }
                } else {
                    array_push($_SESSION['debug_log'], "[" . date('Y-m-d H:i:s') . "] Атрибут '$display_name' уже существует, ID: $attrId");
                }
                $headerToAttrMap[$index] = 'attr_' . $attrId;
                $sectionMap[$section][] = $attrId;
            }

            // Импорт строк
            $skippedRows = 0;
            $importedRows = 0;
            $addedStudents = [];
            foreach ($data as $rowIndex => $row) {
                $fio = trim($row[$requiredIndexes['фио']] ?? '');
                array_push($_SESSION['debug_log'], "[" . date('Y-m-d H:i:s') . "] Обработка строки $rowIndex, ФИО: '$fio'");

                if (empty($fio)) {
                    array_push($_SESSION['debug_log'], "[" . date('Y-m-d H:i:s') . "] Пропущена строка $rowIndex: пустое ФИО");
                    $skippedRows++;
                    continue;
                }

                // Нормализация ФИО
                $fio = htmlspecialchars($fio);
                $studentKey = mb_strtolower($fio);
                if (isset($addedStudents[$studentKey])) {
                    array_push($_SESSION['debug_log'], "[" . date('Y-m-d H:i:s') . "] Пропущена строка $rowIndex: дубликат ФИО '$fio'");
                    $skippedRows++;
                    continue;
                }

                array_push($_SESSION['debug_log'], "[" . date('Y-m-d H:i:s') . "] Пытаемся добавить студента с ФИО '$fio'");
                $studentId = $this->groupManager->addStudent($fio);
                if (is_numeric($studentId) && $studentId > 0) {
                    $importedRows++;
                    $addedStudents[$studentKey] = $studentId;
                    array_push($_SESSION['debug_log'], "[" . date('Y-m-d H:i:s') . "] Студент с ФИО '$fio' добавлен (ID: $studentId)");

                    // Импорт значений для остальных столбцов
                    foreach ($headerToAttrMap as $index => $field) {
                        if (strpos($field, 'attr_') === 0) {
                            $attrId = (int)substr($field, 5);
                            $value = trim($row[$index] ?? '');
                            array_push($_SESSION['debug_log'], "[" . date('Y-m-d H:i:s') . "] Установка значения '$value' для атрибута ID $attrId, студента ID $studentId");
                            $result = $this->groupManager->setAttributeValue($studentId, $attrId, $value);
                            if ($result !== true) {
                                array_push($_SESSION['debug_log'], "[" . date('Y-m-d H:i:s') . "] Ошибка установки значения для атрибута ID $attrId, студента ID $studentId: $result");
                            }
                        }
                    }
                } else {
                    array_push($_SESSION['debug_log'], "[" . date('Y-m-d H:i:s') . "] Ошибка добавления студента с ФИО '$fio': " . $studentId);
                    $skippedRows++;
                }
            }

            // Пересчет значений модулей и итога
            array_push($_SESSION['debug_log'], "[" . date('Y-m-d H:i:s') . "] Пересчет модулей и итога...");
            $students = $this->groupManager->getStudents();
            array_push($_SESSION['debug_log'], "[" . date('Y-m-d H:i:s') . "] Пересчет завершен, студентов: " . count($students));

            // Очистка временного файла
            unlink($file['tmp_name']);

            $message = "Импорт строк: $importedRows.";
            if ($skippedRows > 0) $message .= " Пропущено строк: $skippedRows.";
            array_push($_SESSION['debug_log'], "[" . date('Y-m-d H:i:s') . "] Результат импорта: $message");
            return $message ?: "Импорт завершён успешно.";
        }

        $parseError = SimpleXLSX::parseError();
        array_push($_SESSION['debug_log'], "[" . date('Y-m-d H:i:s') . "] Ошибка парсинга Excel: $parseError");
        return "Ошибка при обработке файла Excel: $parseError";
    }

    public function exportCsv($fields, $students, $name_group) {
        $output = fopen('php://output', 'w');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="export_' . $name_group . '_' . date('Ymd_His') . '.csv');
        header('Cache-Control: max-age=0');

        $header = ['ФИО'];
        $attrMap = [];
        foreach ($this->attributes as $attr) {
            if (in_array('attr_' . $attr['id'], $fields)) {
                // Удаляем суффикс из display_name для экспорта
                $display_name = preg_replace('/(_beforemodule1|_aftermodule1|_aftermodule2|_aftermodule3)$/', '', $attr['display_name']);
                $header[] = $display_name;
                $attrMap[$attr['id']] = true;
            }
        }
        $includeTotal = in_array('total', $fields);
        if ($includeTotal) $header[] = 'Итого';
        fputcsv($output, $header);

        foreach ($students as $student) {
            $row = [$student['fio']];
            $total = 0;
            foreach ($this->attributes as $attr) {
                if (isset($attrMap[$attr['id']])) {
                    $value = $student['attributes'][$attr['id']] ?? '';
                    $row[] = $value;
                    if (in_array($attr['type'], ['int', 'float']) && is_numeric($value)) {
                        $total += (float)$value;
                    }
                }
            }
            if ($includeTotal) $row[] = $total;
            fputcsv($output, $row);
        }
        fclose($output);
        exit();
    }

    private function detectColumnType($values) {
        $isInt = true;
        $isFloat = true;
        foreach ($values as $value) {
            if (empty($value)) continue;
            if (!is_numeric($value)) {
                $isInt = false;
                $isFloat = false;
                break;
            }
            if ($isInt && floor((float)$value) != (float)$value) $isInt = false;
        }
        return $isInt && !$isFloat ? 'int' : ($isFloat ? 'float' : 'text');
    }
}