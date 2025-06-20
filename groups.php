<?php
session_start();
require_once 'vendor/autoload.php';
require_once 'vendor/connect.php';
require_once 'vendor/GroupManager.php';
require_once 'vendor/StudentController.php';
require_once 'vendor/ImportExportController.php';

error_reporting(E_ERROR);

if (!isset($_SESSION['id_teacher']['id'])) {
    $_SESSION['error'] = "Необходимо войти как преподаватель.";
    header('Location: index.php');
    exit();
}

$teacher_id = $_SESSION['id_teacher']['id'];
$group_id = isset($_GET['group_id']) ? (int)$_GET['group_id'] : ($_SESSION['group_id'] ?? null);

if (!$group_id) {
    $_SESSION['error'] = "Группа не указана.";
    header('Location: profile.php');
    exit();
}

$_SESSION['group_id'] = $group_id;
// Получение названия предмета
$db = Database::getInstance()->getConnection();
$query = "SELECT s.name AS subject_name FROM `groups` g LEFT JOIN `subjects` s ON g.subjects_id = s.id WHERE g.id = ?";
$stmt = $db->prepare($query);
$stmt->bind_param("i", $group_id);
$stmt->execute();
$subject_result = $stmt->get_result()->fetch_assoc();
$subject_name = $subject_result['subject_name'] ?? 'Не указан';
$stmt->close();
try {
    $groupManager = new GroupManager(Database::getInstance(), $teacher_id, $group_id);
    $group = $groupManager->hasAccess();

    if (!$group) {
        $_SESSION['error'] = "У вас нет доступа к этой группе.";
        header('Location: profile.php');
        exit();
    }

    $name_group = $group['name'] ?? 'Неизвестная группа';
    $_SESSION['name_group'] = $name_group;

    $studentController = new StudentController($teacher_id, $group_id);
    $attributes = $groupManager->getAttributes();
    $students = $groupManager->getStudents();
    $importExportController = new ImportExportController($teacher_id, $group_id, $attributes);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        error_log("POST data: " . print_r($_POST, true)); // Отладка для проверки section
        if (isset($_POST['add_student'])) {
            $fio = $_POST['fio'] ?? '';
            $result = $studentController->addStudent($fio);
            $_SESSION['message'] = is_int($result) ? "Студент успешно добавлен!" : $result;
            header("Location: groups.php?group_id=$group_id");
            exit();
        } elseif (isset($_POST['add_attribute'])) {
            $display_name = $_POST['display_name'] ?? 'Новый столбец';
            $type = $_POST['type'] ?? 'text';
            $section = $_POST['section'] ?? 'after_module3'; // Убедимся, что section передаётся
            if (empty($section)) {
                $_SESSION['message'] = "Позиция столбца не указана.";
                header("Location: groups.php?group_id=$group_id");
                exit();
            }
            error_log("Adding attribute: display_name=$display_name, type=$type, section=$section");
            $result = $groupManager->addAttribute($display_name, $type, $section);
            $_SESSION['message'] = $result === true ? "Атрибут добавлен!" : $result;
            header("Location: groups.php?group_id=$group_id");
            exit();
        } elseif (isset($_POST['rename_attribute'])) {
            $attribute_id = (int)$_POST['attribute_id'];
            $display_name = $_POST['display_name'] ?? '';
            $result = $groupManager->renameAttribute($attribute_id, $display_name);
            $_SESSION['message'] = $result === true ? "Атрибут переименован!" : $result;
            header("Location: groups.php?group_id=$group_id");
            exit();
        } elseif (isset($_POST['delete_attribute'])) {
            $attribute_id = (int)$_POST['attribute_id'];
            $result = $groupManager->deleteAttribute($attribute_id);
            $_SESSION['message'] = $result === true ? "Атрибут удалён!" : $result;
            header("Location: groups.php?group_id=$group_id");
            exit();
        } elseif (isset($_POST['import_excel']) && isset($_FILES['excel_file'])) {
            $create_total_column = isset($_POST['create_total_column']);
            $result = $importExportController->importExcel($_FILES['excel_file'], $create_total_column);
            $_SESSION['message'] = $result;
            header("Location: groups.php?group_id=$group_id");
            exit();
        } elseif (isset($_POST['export_csv'])) {
            $fields = $_POST['export_fields'] ?? [];
            $importExportController->exportCsv($fields, $students, $name_group);
        } elseif (isset($_POST['delete_student'])) {
            $student_id = (int)$_POST['delete_student'];
            error_log("Attempting to delete student ID: $student_id");
            $result = $studentController->deleteStudent($student_id);
            $_SESSION['message'] = $result === true ? "Студент удалён!" : $result;
            header("Location: groups.php?group_id=$group_id");
            exit();
        } elseif (isset($_POST['update_field'])) {
            $student_id = (int)$_POST['student_id'];
            $field = $_POST['field'];
            $value = $_POST['value'];
            $result = $studentController->updateStudentField($student_id, $field, $value);
            header('Content-Type: application/json');
            echo json_encode(['success' => $result === true, 'error' => $result !== true ? $result : null]);
            exit();
        }
    }
} catch (Exception $e) {
    $_SESSION['error'] = "Ошибка: " . $e->getMessage();
    header("Location: profile.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="assets/css/groups.css">
    <title><?= htmlspecialchars($name_group) ?></title>
    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <style>
        .toggle-columns-btn {
            width: 50px;
            height: 50px;
            background: none;
            border: 1px solid #03e9f4;
            color: #03e9f4;
            border-radius: 50%;
            cursor: pointer;
            font-size: 24px;
            transition: all 0.3s ease;
            margin: 5px;
        }
        .toggle-columns-btn:hover {
            background: #03e9f4;
            color: #fff;
            box-shadow: 0 0 10px #03e9f4;
        }
        .hidden-column {
            display: none;
        }
        .toggle-buttons {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin-bottom: 20px;
        }
        /* Стили для модального окна управления столбцами */
        #attributes-modal .modal-content {
            max-height: 80vh; /* Ограничение высоты на 80% от высоты окна */
            overflow-y: auto; /* Вертикальная прокрутка при необходимости */
            padding: 20px;
        }
        #attributes-modal .attributes-table {
            width: 100%;
            border-collapse: collapse;
        }
        #attributes-modal .attributes-table th,
        #attributes-modal .attributes-table td {
            padding: 10px;
            border: 1px solid #ddd;
            text-align: left;
        }
        #attributes-modal .attributes-table th {
            background-color: #f4f4f4;
        }
    </style>
</head>
<body>
<div class="container">
    <form method="post" action="profile.php" class="exit-form">
        <button type="submit" class="exit">Назад</button>
    </form>
    <h1><?= htmlspecialchars($name_group) ?></h1>
   <!-- Вывод заголовка с названием группы и предмета -->
<h1> <?php echo htmlspecialchars($subject_name); ?></h1>
    <div class="button-group">
        <button type="button" id="add-student-btn">Добавить студента</button>
        <button type="button" id="manage-attributes-btn">Управление столбцами</button>
        <button type="button" id="import-excel-btn">Импорт из Excel</button>
        <button type="button" id="export-csv-btn">Экспорт в CSV</button>
    </div>

    <!-- Кнопки для переключения зон -->
    <div class="toggle-buttons">
        <button type="button" id="toggle-before-module1" class="toggle-columns-btn">+</button>
        <button type="button" id="toggle-after-module1" class="toggle-columns-btn">+</button>
        <button type="button" id="toggle-after-module2" class="toggle-columns-btn">+</button>
        <button type="button" id="toggle-after-module3" class="toggle-columns-btn">+</button>
    </div>
<!-- Import Modal -->
<div id="import-excel-modal" class="modal">
    <div class="modal-content">
        <span class="close">×</span>
        <h2>Импорт данных из Excel</h2>
        <form method="post" enctype="multipart/form-data" id="import-form">
            <div style="display: flex; flex-direction: row;">
                <label>Выберите файл Excel:</label>
                <div class="file-input-wrapper" style="margin-left: auto;">
                    <input type="file" name="excel_file" accept=".xlsx, .xls" required id="excel-file-input">
                    <button type="button" class="add-file-btn">Добавить файл</button>
                </div>
            </div>
            <div class="mb-3">
                <p><strong>Требования к файлу Excel:</strong></p>
                <ul>
                    <li>Обязательные столбцы: <strong>ФИО</strong>, <strong>1 модуль</strong>, <strong>2 модуль</strong>, <strong>3 модуль</strong>, <strong>Итог</strong>.</li>
                    <li>Заголовки должны начинаться с ячейки А1. Дополнительные столбцы будут импортированы, только если в них есть хотя бы одно непустое значение.</li>
                    <li><strong>Правила суммирования:</strong>
                        <ul>
                            <li>Столбец <strong>1 модуль</strong> = сумма значений столбцов в секции до(слева) 1 модуля.</li>
                            <li>Столбец <strong>2 модуль</strong> = сумма значений столбцов в секции между первым и вторым модулем.</li>
                            <li>Столбец <strong>3 модуль</strong> = сумма значений столбцов между вторым и третьим модулем.</li>
                            <li>Столбец <strong>Итог</strong> = сумма значений столбцов <strong>1 модуль</strong>, <strong>2 модуль</strong>, <strong>3 модуль</strong>, <strong>и доп. столбцов после 3 модуля</strong>.</li>
                        </ul>
                    </li>
                </ul>
            </div>
            <button type="submit" name="import_excel">Импортировать</button>
        </form>
    </div>
</div>

    <!-- Export Modal -->
    <div id="export-csv-modal" class="modal">
        <div class="modal-content">
            <span class="close">×</span>
            <h2>Экспорт данных в CSV</h2>
            <form method="post" id="export-form">
                
                    <label>Выберите поля для экспорта:</label>
                    <div id="export-field-selection" >
                        <label class="checkbox-label" style="display: flex;flex-direction: row-reverse;justify-content: space-between;"><input type="checkbox" name="export_fields[]" value="fio" checked> ФИО</label><br>
                        <?php foreach ($attributes as $attr): ?>
                            <?php
                            // Удаляем суффикс из display_name для отображения
                            $display_name = preg_replace('/(_beforemodule1|_aftermodule1|_aftermodule2|_aftermodule3)$/', '', $attr['display_name']);
                            ?>
                            <label class="checkbox-label" style="display: flex;flex-direction: row-reverse;justify-content: space-between;"><input type="checkbox" name="export_fields[]" value="attr_<?= $attr['id'] ?>" checked> <?= htmlspecialchars($display_name) ?></label><br>
                        <?php endforeach; ?>
                    </div>
            
                <button type="submit" name="export_csv">Экспортировать</button>
            </form>
        </div>
    </div>

<!-- Table -->
<div class="table-wrapper">
    <table id="edit-table" data-group-id="<?= $group_id ?>">
        <thead>
            <tr>
                <th>ФИО</th>
                <?php
                $sections = ['before_module1', 'module1', 'after_module1', 'module2', 'after_module2', 'module3', 'after_module3'];
                $section_attributes = [];
                $total_attribute = null;
                foreach ($attributes as $attr) {
                    if ($attr['display_name'] === 'Итог' && $attr['section'] === 'after_module3') {
                        $total_attribute = $attr; // Отделяем "Итог"
                    } else {
                        $section_attributes[$attr['section']][] = $attr; // Остальные атрибуты по секциям
                    }
                }
                foreach ($sections as $section): ?>
                    <?php if (isset($section_attributes[$section])): ?>
                        <?php foreach ($section_attributes[$section] as $attr): ?>
                            <?php
                            // Удаляем суффикс из display_name для отображения
                            $display_name = preg_replace('/(_beforemodule1|_aftermodule1|_aftermodule2|_aftermodule3)$/', '', $attr['display_name']);
                            ?>
                            <th data-type="<?= htmlspecialchars($attr['type']) ?>" 
                                data-fixed="<?= $attr['is_fixed'] ?>" 
                                data-section="<?= $section ?>" 
                                class="<?= !in_array($attr['display_name'], ['1 модуль', '2 модуль', '3 модуль']) && !$attr['is_fixed'] ? 'hidden-column' : '' ?>">
                                <span class="attr-name"><?= htmlspecialchars($display_name) ?></span>
                            </th>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php endforeach; ?>
                <?php if ($total_attribute): ?>
                    <th data-type="<?= htmlspecialchars($total_attribute['type']) ?>" 
                        data-fixed="<?= $total_attribute['is_fixed'] ?>" 
                        data-section="total">
                        <span class="attr-name"><?= htmlspecialchars($total_attribute['display_name']) ?></span>
                    </th>
                <?php endif; ?>
                <th>Действие</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($students as $student): ?>
                <tr class="student-row" data-student-id="<?= $student['id'] ?>">
                    <td data-label="ФИО">
                        <input class="input-field" type="text" name="fio_<?= $student['id'] ?>" value="<?= htmlspecialchars($student['fio'] ?? '') ?>" data-original-value="<?= htmlspecialchars($student['fio'] ?? '') ?>">
                    </td>
                    <?php foreach ($sections as $section): ?>
                        <?php if (isset($section_attributes[$section])): ?>
                            <?php foreach ($section_attributes[$section] as $attr): ?>
                                <?php
                                // Удаляем суффикс из display_name для отображения
                                $display_name = preg_replace('/(_beforemodule1|_aftermodule1|_aftermodule2|_aftermodule3)$/', '', $attr['display_name']);
                                ?>
                                <td data-label="<?= htmlspecialchars($display_name) ?>" 
                                    data-section="<?= $section ?>" 
                                    class="<?= !in_array($attr['display_name'], ['1 модуль', '2 модуль', '3 модуль']) && !$attr['is_fixed'] ? 'hidden-column' : '' ?>">
                                    <input class="input-field <?= in_array($attr['type'], ['int', 'float']) ? 'score' : '' ?>" 
                                           type="text" 
                                           name="attr_<?= $attr['id'] ?>_<?= $student['id'] ?>" 
                                           value="<?= htmlspecialchars($student['attributes'][$attr['id']] ?? '') ?>" 
                                           data-original-value="<?= htmlspecialchars($student['attributes'][$attr['id']] ?? '') ?>" 
                                           <?= $attr['is_fixed'] ? 'readonly' : '' ?>>
                                </td>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <?php if ($total_attribute): ?>
                        <td data-label="<?= htmlspecialchars($total_attribute['display_name']) ?>" 
                            data-section="total">
                            <input class="input-field <?= in_array($total_attribute['type'], ['int', 'float']) ? 'score' : '' ?>" 
                                   type="text" 
                                   name="attr_<?= $total_attribute['id'] ?>_<?= $student['id'] ?>" 
                                   value="<?= htmlspecialchars($student['attributes'][$total_attribute['id']] ?? '') ?>" 
                                   data-original-value="<?= htmlspecialchars($student['attributes'][$total_attribute['id']] ?? '') ?>" 
                                   readonly>
                        </td>
                    <?php endif; ?>
                    <td data-label="Действие">
                        <form method="post" style="display: inline;">
                            <input type="hidden" name="delete_student" value="<?= $student['id'] ?>">
                            <button type="submit" class="delete-btn">X</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<button class="save-btn" id="save-btn">Сохранить</button>
</div>

<!-- Add Student Modal -->
<div id="add-student-modal" class="modal">
    <div class="modal-content">
        <span class="close">×</span>
        <h2>Добавить студента</h2>
        <form method="post">
            <label>ФИО:</label>
            <input type="text" name="fio" required>
            <button type="submit" name="add_student">Добавить</button>
        </form>
    </div>
</div>

<!-- Attributes Modal -->
<div id="attributes-modal" class="modal">
    <div class="modal-content">
        <span class="close">×</span>
        <h2>Управление столбцами для <?= htmlspecialchars($name_group) ?></h2>
        <h3>Добавить новый столбец</h3>
        <form method="post">
            <label>Отображаемое название:</label>
            <input type="text" name="display_name" required>
            <label>Тип данных:</label>
            <select name="type">
                <option value="text">Текст</option>
                <option value="int">Число</option>
                <option value="float">Дробное число</option>
            </select>
            <label>Позиция:</label>
            <select name="section">
                <option value="before_module1">До 1 модуля</option>
                <option value="after_module1">После 1 модуля, до 2</option>
                <option value="after_module2">После 2 модуля, до 3</option>
                <option value="after_module3">После 3 модуля, до Итога</option>
            </select>
            <button type="submit" name="add_attribute">Добавить</button>
        </form>
        <h3>Существующие столбцы</h3>
        <table class="attributes-table">
            <thead style="z-index:1000">
                <tr>
                    <th style="background-color: #081b29; color:white;">Название</th>
                    <th style="background-color: #081b29; color:white;">Тип</th>
                    <th style="background-color: #081b29; color:white;">Позиция</th>
                    <th style="background-color: #081b29; color:white;">Фиксировано</th>
                    <th style="background-color: #081b29; color:white;">Действие</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($attributes as $attr): ?>
                    <?php
                    // Удаляем суффикс из display_name для отображения
                    $display_name = preg_replace('/(_beforemodule1|_aftermodule1|_aftermodule2|_aftermodule3)$/', '', $attr['display_name']);
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($display_name) ?></td>
                        <td><?= htmlspecialchars($attr['type']) ?></td>
                        <td>
                            <?php
                            switch ($attr['section']) {
                                case 'before_module1': echo 'До 1 модуля'; break;
                                case 'after_module1': echo 'После 1 модуля'; break;
                                case 'after_module2': echo 'После 2 модуля'; break;
                                case 'after_module3': echo 'После 3 модуля'; break;
                            }
                            ?>
                        </td>
                        <td><?= $attr['is_fixed'] ? 'Да' : 'Нет' ?></td>
                        <td>
                            <?php if (!$attr['is_fixed']): ?>
                                <form method="post" style="display:inline;">
                                    <input type="hidden" name="attribute_id" value="<?= $attr['id'] ?>">
                                    <button type="submit" name="delete_attribute" onclick="return confirm('Удалить столбец? Все данные будут потеряны!')">Удалить</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Rename Column Modal -->
<div id="rename-column-modal" class="modal">
    <div class="modal-content">
        <span class="close">×</span>
        <h2>Переименовать столбец</h2>
        <form method="post" id="rename-column-form">
            <input type="hidden" name="attribute_id" id="rename-attr-id">
            <label>Новое название:</label>
            <input type="text" name="display_name" id="rename-display-name" required>
            <button type="submit" name="rename_attribute">Сохранить</button>
        </form>
    </div>
</div>

<script>
$(document).ready(function() {
    const changes = {};
    const groupId = $('#edit-table').data('group-id');

    // Modal triggers
    $('#add-student-btn').on('click', () => $('#add-student-modal').show());
    $('#manage-attributes-btn').on('click', () => $('#attributes-modal').show());
    $('#import-excel-btn').on('click', () => $('#import-excel-modal').show());
    $('#export-csv-btn').on('click', () => $('#export-csv-modal').show());

    // Close modals
    $('.close').on('click', function() { $(this).closest('.modal').hide(); });
    $(window).on('click', function(event) {
        if ($(event.target).hasClass('modal')) $(event.target).hide();
    });

    // Toggle columns visibility by section
    $('#toggle-before-module1').on('click', function() {
        console.log('Toggling before_module1');
        toggleSection('before_module1');
        updateButtonText(this, 'before_module1');
    });
    $('#toggle-after-module1').on('click', function() {
        console.log('Toggling after_module1');
        toggleSection('after_module1');
        updateButtonText(this, 'after_module1');
    });
    $('#toggle-after-module2').on('click', function() {
        console.log('Toggling after_module2');
        toggleSection('after_module2');
        updateButtonText(this, 'after_module2');
    });
    $('#toggle-after-module3').on('click', function() {
        console.log('Toggling after_module3');
        toggleSection('after_module3', true); // Передаём true, чтобы исключить "Итог"
        updateButtonText(this, 'after_module3');
    });

    function toggleSection(section, excludeTotal = false) {
        let selector = `th[data-section="${section}"], td[data-section="${section}"]`;
        if (excludeTotal) {
            // Исключаем столбец "Итог" из переключения
            selector += ':not(.total-column)';
        }
        const $columns = $(selector);
        if ($columns.length === 0) {
            console.log(`No columns found for section ${section}`);
            return;
        }
        if ($columns.filter('.hidden-column').length > 0) {
            $columns.removeClass('hidden-column');
        } else {
            $columns.addClass('hidden-column');
        }
    }

    function updateButtonText(button, section) {
        let selector = `th[data-section="${section}"], td[data-section="${section}"]`;
        if (section === 'after_module3') {
            selector += ':not(.total-column)'; // Исключаем "Итог"
        }
        const $columns = $(selector);
        const isHidden = $columns.filter('.hidden-column').length === $columns.length;
        $(button).text(isHidden ? '+' : '-');
    }

    // Calculate total score
    function calculateTotalScore(row) {
        const sections = ['before_module1', 'module1', 'after_module1', 'module2', 'after_module2', 'module3', 'after_module3'];
        const sectionTotals = {
            'module1': 0,
            'module2': 0,
            'module3': 0,
            'total': 0
        };
        let currentSection = 'before_module1';

        row.find('input.score:not([readonly])').each(function() {
            const $th = $(this).closest('td').siblings('th').filter(`[data-section]`).last();
            const attrId = $(this).attr('name').split('_')[1];
            const type = $th.data('type');
            const value = parseFloat($(this).val()) || 0;
            const section = $th.data('section') || currentSection;
            if (sections.includes(section)) currentSection = section;

            if (type === 'int' || type === 'float') {
                if (currentSection === 'before_module1') {
                    sectionTotals['module1'] += value;
                } else if (currentSection === 'after_module1') {
                    sectionTotals['module2'] += value;
                } else if (currentSection === 'after_module2') {
                    sectionTotals['module3'] += value;
                } else if (currentSection === 'after_module3' && $th.find('.attr-name').text() !== 'Итог') {
                    sectionTotals['total'] += value;
                }
            }
        });

        // Update module columns
        row.find('input[name*="attr_"][name$="_1 модуль"]').each(function() {
            const studentId = row.data('student-id');
            const field = $(this).attr('name').split('_')[1];
            $(this).val(sectionTotals['module1'].toFixed(2));
            saveField(studentId, `attr_${field}`, sectionTotals['module1'].toFixed(2));
        });
        row.find('input[name*="attr_"][name$="_2 модуль"]').each(function() {
            const studentId = row.data('student-id');
            const field = $(this).attr('name').split('_')[1];
            $(this).val(sectionTotals['module2'].toFixed(2));
            saveField(studentId, `attr_${field}`, sectionTotals['module2'].toFixed(2));
        });
        row.find('input[name*="attr_"][name$="_3 модуль"]').each(function() {
            const studentId = row.data('student-id');
            const field = $(this).attr('name').split('_')[1];
            $(this).val(sectionTotals['module3'].toFixed(2));
            saveField(studentId, `attr_${field}`, sectionTotals['module3'].toFixed(2));
        });

        // Update total column
        const totalScore = sectionTotals['module1'] + sectionTotals['module2'] + sectionTotals['module3'] + sectionTotals['total'];
        row.find('input[name*="attr_"][name$="_Итог"]').each(function() {
            const studentId = row.data('student-id');
            const field = $(this).attr('name').split('_')[1];
            $(this).val(totalScore.toFixed(2));
            saveField(studentId, `attr_${field}`, totalScore.toFixed(2));
        });
    }

    // Track changes for editable fields only
    $('input.input-field:not([readonly])').on('input', function() {
        const $input = $(this);
        const name = $input.attr('name');
        const value = $input.val();
        const originalValue = $input.data('original-value');
        const parts = name.split('_');
        if (parts.length < 2) {
            alert('Ошибка: некорректное имя поля');
            return;
        }
        const field = parts[0] === 'attr' ? 'attr_' + parts[1] : parts[0];
        const studentId = parts[parts.length - 1];

        if (value !== originalValue) {
            if (!changes[studentId]) changes[studentId] = {};
            changes[studentId][field] = value;
        } else if (changes[studentId] && changes[studentId][field]) {
            delete changes[studentId][field];
            if (Object.keys(changes[studentId]).length === 0) delete changes[studentId];
        }

        if ($input.hasClass('score')) calculateTotalScore($input.closest('.student-row'));
    });

    // Save on Enter for editable fields
    $('input.input-field:not([readonly])').on('keypress', function(e) {
        if (e.which === 13) {
            e.preventDefault();
            const $input = $(this);
            const name = $input.attr('name');
            const value = $input.val();
            const parts = name.split('_');
            if (parts.length < 2) {
                alert('Ошибка: некорректное имя поля');
                return;
            }
            const field = parts[0] === 'attr' ? 'attr_' + parts[1] : parts[0];
            const studentId = parts[parts.length - 1];
            saveField(studentId, field, value);
        }
    });

    // Save field via AJAX
    function saveField(studentId, field, value) {
        $.ajax({
            url: 'groups.php',
            type: 'POST',
            data: { student_id: studentId, field: field, value: value, update_field: true },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $(`input[name="${field}_${studentId}"]`).data('original-value', value);
                    if (changes[studentId] && changes[studentId][field]) {
                        delete changes[studentId][field];
                        if (Object.keys(changes[studentId]).length === 0) delete changes[studentId];
                    }
                    alert('Поле успешно сохранено!');
                    const row = $(`tr[data-student-id="${studentId}"]`);
                    calculateTotalScore(row);
                } else {
                    alert(`Ошибка сохранения: ${response.error || 'Неизвестная ошибка'}`);
                }
            },
            error: function(xhr, status, error) {
                alert(`Ошибка AJAX: ${error}`);
            }
        });
    }

    // Save all changes
    $('#save-btn').on('click', function() {
        if (Object.keys(changes).length === 0) {
            alert('Нет изменений для сохранения.');
            return;
        }

        const promises = [];
        for (const studentId in changes) {
            for (const field in changes[studentId]) {
                const value = changes[studentId][field];
                promises.push(new Promise((resolve, reject) => {
                    $.ajax({
                        url: 'groups.php',
                        type: 'POST',
                        data: { student_id: studentId, field: field, value: value, update_field: true },
                        dataType: 'json',
                        success: function(response) {
                            if (response.success) {
                                $(`input[name="${field}_${studentId}"]`).data('original-value', value);
                                delete changes[studentId][field];
                                if (Object.keys(changes[studentId]).length === 0) delete changes[studentId];
                                resolve();
                            } else {
                                reject(new Error(`Ошибка сохранения поля ${field}: ${response.error || 'Неизвестная ошибка'}`));
                            }
                        },
                        error: function(xhr, status, error) {
                            reject(new Error(`Ошибка AJAX для поля ${field}: ${error}`));
                        }
                    });
                }));
            }
        }

        Promise.allSettled(promises).then(results => {
            const errors = results.filter(r => r.status === 'rejected').map(r => r.reason.message);
            if (errors.length === 0) {
                alert('Все данные успешно сохранены!');
                $('.student-row').each(function() {
                    calculateTotalScore($(this));
                });
            } else {
                alert('Некоторые данные не удалось сохранить:\n' + errors.join('\n'));
            }
        });
    });

    // Restrict input for numeric fields
    $('.score:not([readonly])').on('input', function() {
        const type = $(this).closest('td').siblings('th').data('type');
        if (type === 'int') {
            this.value = this.value.replace(/[^0-9]/g, '');
            if (this.value.length > 3) this.value = this.value.slice(0, 3);
        } else if (type === 'float') {
            this.value = this.value.replace(/[^0-9.]/g, '');
            const parts = this.value.split('.');
            if (parts.length > 2) this.value = parts[0] + '.' + parts[1];
            if (parts[0].length > 3) parts[0] = parts[0].slice(0, 3);
            this.value = parts[0] + (parts[1] ? '.' + parts[1].slice(0, 2) : '');
        }
    });

    // Drag functionality for modals
    $('.modal-content').each(function() {
        const modalContent = $(this);
        let isDragging = false;
        let currentX, currentY, initialX, initialY;

        modalContent.on('mousedown', function(e) {
            if ($(e.target).is('button, input, select, .close') || $(e.target).parents('button, input, select, .close').length) return;
            isDragging = true;
            initialX = e.clientX - currentX;
            initialY = e.clientY - currentY;
            $(document).on('mousemove', drag);
            $(document).on('mouseup', stopDragging);
        });

        function drag(e) {
            if (isDragging) {
                e.preventDefault();
                currentX = e.clientX - initialX;
                currentY = e.clientY - initialY;
                modalContent.css({ left: currentX + 'px', top: currentY + 'px', transform: 'none' });
            }
        }

        function stopDragging() {
            isDragging = false;
            $(document).off('mousemove', drag);
            $(document).off('mouseup', stopDragging);
        }

        modalContent.css({ position: 'absolute', left: '0', top: '0' });
    });
});
</script>
</body>
</html>