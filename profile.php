<?php
session_start();
require_once 'vendor/connect.php';
require_once 'vendor/GroupManager.php';
require_once 'vendor/SubjectManager.php';

class Profile {
    private $db;
    private $userId;
    private $userRole;

    public function __construct(Database $database, $userId, $userRole) {
        $this->db = $database->getConnection();
        $this->userId = $userId;
        $this->userRole = $userRole;
    }

    public function hasAccess(): bool {
        return in_array($this->userRole, ['teacher', 'admin']);
    }

    public function getUserInfo() {
        $stmt = $this->db->prepare("SELECT name, surname, role FROM users WHERE id = ?");
        $stmt->bind_param("i", $this->userId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $result;
    }

    public function getGroups($subjectId = null, $year = null) {
        $query = "SELECT 
                      `groups`.id AS group_id,
                      `groups`.name AS group_name,
                      `groups`.subjects_id AS subject_id,
                      `subjects`.name AS subject_name,
                      `groups`.created_at 
                  FROM `groups` 
                  LEFT JOIN `subjects` ON `groups`.subjects_id = `subjects`.id 
                  WHERE `groups`.teacher_id = ?";
        $params = [$this->userId];
        $types = "i";

        if ($subjectId) {
            $query .= " AND `groups`.subjects_id = ?";
            $params[] = $subjectId;
            $types .= "i";
        }

        if ($year) {
            $yearSuffix = substr($year, -2);
            $query .= " AND `groups`.name LIKE ?";
            $params[] = "%{$yearSuffix}%";
            $types .= "s";
        }

        $query .= " ORDER BY `groups`.name";

        $stmt = $this->db->prepare($query);
        if (!$stmt) {
            error_log("[" . date('Y-m-d H:i:s') . "] Ошибка подготовки запроса в getGroups: " . $this->db->error . "\n", 3, "logs/sql_errors.log");
            throw new Exception("Ошибка подготовки запроса: " . $this->db->error);
        }

        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $stmt->close();
        return $result;
    }

    public function getSubjects() {
        $query = "SELECT id, name 
                  FROM subjects 
                  WHERE teacher_id = ? 
                  ORDER BY name";
        $stmt = $this->db->prepare($query);
        if (!$stmt) {
            error_log("[" . date('Y-m-d H:i:s') . "] Ошибка подготовки запроса в getSubjects: " . $this->db->error . "\n", 3, "logs/sql_errors.log");
            throw new Exception("Ошибка подготовки запроса: " . $this->db->error);
        }
        $stmt->bind_param("i", $this->userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $stmt->close();
        return $result;
    }
}

try {
    if (!isset($_SESSION['user']['id']) || !isset($_SESSION['user']['role'])) {
        $_SESSION['message'] = "Пожалуйста, войдите в систему";
        header('Location: index.php');
        exit();
    }

    if (!isset($_SESSION['id_teacher'])) {
        $_SESSION['id_teacher'] = ['id' => $_SESSION['user']['id']];
    }

    $profile = new Profile(Database::getInstance(), $_SESSION['user']['id'], $_SESSION['user']['role']);
    
    if (!$profile->hasAccess()) {
        $_SESSION['message'] = "Доступ запрещен: недостаточно прав";
        header('Location: index.php');
        exit();
    }

    $userInfo = $profile->getUserInfo();
    
    if (!$userInfo) {
        $_SESSION['message'] = "Ошибка: Пользователь не найден";
        header('Location: index.php');
        exit();
    }

    $subjectId = isset($_GET['subject_id']) ? (int)$_GET['subject_id'] : null;
    $year = isset($_GET['year']) && is_numeric($_GET['year']) && $_GET['year'] >= 2018 && $_GET['year'] <= 2025 ? (int)$_GET['year'] : null;
    $groups = $profile->getGroups($subjectId, $year);
    $subjects = $profile->getSubjects();

    if ($groups->num_rows === 0) {
        $_SESSION['message'] = "Группы отсутствуют.";
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['add_name_groups'])) {
            $groupManager = new GroupManager(Database::getInstance(), $_SESSION['user']['id']);
            $result = $groupManager->addGroup($_POST['add_name_groups'], $_POST['subject_id']);
            $_SESSION['message'] = $result === true ? "Группа успешно добавлена" : $result;
            header('Location: profile.php');
            exit();
        } elseif (isset($_POST['subject_name'])) {
            $subjectManager = new SubjectManager(Database::getInstance());
            $result = $subjectManager->addSubject($_POST['subject_name'], $_SESSION['user']['id']);
            $_SESSION['message'] = $result === true ? "Предмет успешно добавлен" : $result;
            header('Location: profile.php');
            exit();
        } elseif (isset($_POST['delete_group_id'])) {
            $groupManager = new GroupManager(Database::getInstance(), $_SESSION['user']['id']);
            $groupId = (int)$_POST['delete_group_id'];
            $result = $groupManager->deleteGroup($groupId);
            $_SESSION['message'] = $result === true ? "Группа успешно удалена" : $result;
            header('Location: profile.php');
            exit();
        } elseif (isset($_POST['delete_subject_id'])) {
            $subjectManager = new SubjectManager(Database::getInstance());
            $subjectId = (int)$_POST['delete_subject_id'];
            $result = $subjectManager->deleteSubject($subjectId, $_SESSION['user']['id']);
            $_SESSION['message'] = $result === true ? "Предмет успешно удалён" : $result;
            header('Location: profile.php');
            exit();
        }
    }
} catch (Exception $e) {
    $_SESSION['message'] = "Ошибка: " . $e->getMessage();
    header('Location: index.php');
    exit();
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="assets/css/profile.css">
    <title>Мои группы</title>
</head>
<body>
    <div class="container">
        <form method="post" action="vendor/logout.php" class="logout-form">
            <button type="submit" class="exit">Выйти</button>
        </form>
        <div class="table-across" style="display: flex; flex-direction: column; align-items: center;">
            <h1 class="h1"><?= htmlspecialchars($userInfo['name'] . " " . $userInfo['surname']) ?></h1>
            <?php if (isset($_SESSION['message'])): ?>
                <p class="msg"><?= htmlspecialchars($_SESSION['message']) ?></p>
                <?php unset($_SESSION['message']); ?>
            <?php endif; ?>

            <!-- Фильтры -->
            <div class="filter-group" style="display: flex;">
                <div class="filter" style="min-width: 150px;">
                    <select id="subjectFilter" onchange="filterGroups()">
                        <option value="">Все предметы</option>
                        <?php 
                        $subjects->data_seek(0);
                        while ($subject = $subjects->fetch_assoc()): ?>
                            <option value="<?= htmlspecialchars($subject['id']) ?>" <?= $subjectId == $subject['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($subject['name']) ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="filter" style="min-width: 150px;">
                    <select id="yearFilter" onchange="filterGroups()">
                        <option value="">Все годы</option>
                        <?php for ($y = 2018; $y <= 2025; $y++): ?>
                            <option value="<?= $y ?>" <?= $year == $y ? 'selected' : '' ?>><?= $y ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
            </div>

            <!-- Список предметов -->
            <div class="subjects-list" style="display: flex; flex-direction: column; align-items: center;">
                <h1 class="h1">Мои предметы</h1>
                <table>
                    <thead>
                        <tr>
                            <th>Предмет</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $subjects->data_seek(0);
                        while ($subject = $subjects->fetch_assoc()): ?>
                            <tr>
                                <td class="name_group"><?= htmlspecialchars($subject['name']) ?>
                                    <div class="delete">
                                        <form method="post" action="" style="display: inline;">
                                            <input type="hidden" name="delete_subject_id" value="<?= htmlspecialchars($subject['id']) ?>">
                                            <button type="submit" class="delete1" onclick="return confirm('Удалить предмет? Все связанные группы будут удалены.')">X</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>

            <!-- Таблица групп -->
            <div class="table-wrapper" style="display: flex; flex-direction: column; align-items: center;">
                <h1 class="h1">Мои группы</h1>
                <table>
                    <thead>
                        <tr>
                            <th>Группа / Предмет</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($group = $groups->fetch_assoc()): ?>
                            <tr>
                                <td class="name_group">
                                    <a href="groups.php?group_id=<?= htmlspecialchars($group['group_id']) ?>" class="name_group">
                                        <?= htmlspecialchars($group['group_name'] . " / " . ($group['subject_name'] ?? 'Не указан')) ?>
                                    </a>
                                    <div class="delete" data-id="<?= htmlspecialchars($group['group_id']) ?>">
                                        <form method="post" action="" style="display: inline;">
                                            <input type="hidden" name="delete_group_id" value="<?= htmlspecialchars($group['group_id']) ?>">
                                            <button type="submit" class="delete1" onclick="return confirm('Удалить строку?')">X</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>

            <div class="button-group">
                <button class="sv" type="button" onclick="openModal('add-group-modal')">Добавить группу</button>
                <button class="sv" type="button" onclick="openModal('add-subject-modal')">Добавить предмет</button>
            </div>
        </div>

        <!-- Модальное окно для добавления группы -->
        <div id="add-group-modal" class="modal">
            <div class="modal-content">
                <span class="close">×</span>
                <h2>Введите название группы</h2>
                <form name="add_group" method="post" action="">
                    <label>Название группы:</label>
                    <input type="text" name="add_name_groups" class="inputField" placeholder="Название группы" required>
                    <label>Выберите предмет:</label>
                    <select name="subject_id" class="inputField subject-select" required>
                        <option value="" disabled selected>Выберите предмет</option>
                        <?php 
                        $subjects->data_seek(0);
                        while ($subject = $subjects->fetch_assoc()): ?>
                            <option value="<?= htmlspecialchars($subject['id']) ?>">
                                <?= htmlspecialchars($subject['name']) ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                    <button type="submit">Сохранить</button>
                </form>
            </div>
        </div>
        
        <!-- Модальное окно для добавления предмета -->
        <div id="add-subject-modal" class="modal">
            <div class="modal-content">
                <span class="close">×</span>
                <h2>Введите название предмета</h2>
                <form name="add_subject" method="post" action="">
                    <label>Название предмета:</label>
                    <input type="text" name="subject_name" class="inputField" placeholder="Название предмета" required>
                    <button type="submit">Сохранить</button>
                </form>
            </div>
        </div>
    </div>

    <script>
        function filterGroups() {
            const subjectId = document.getElementById('subjectFilter').value;
            const year = document.getElementById('yearFilter').value;
            let url = 'profile.php';
            let params = [];
            if (subjectId) params.push('subject_id=' + subjectId);
            if (year) params.push('year=' + year);
            if (params.length > 0) url += '?' + params.join('&');
            window.location.href = url;
        }

        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'flex';
        }

        document.querySelectorAll('.close').forEach(button => {
            button.onclick = function() {
                this.closest('.modal').style.display = 'none';
            };
        });

        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        };
    </script>
</body>
</html>