<?php session_start(); ?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Регистрация</title>
    <link rel="stylesheet" href="assets/css/main.css">
</head>
<body>
<div class="login-box">
    <h2>Регистрация</h2>
    <form action="vendor/signup.php" method="post">
        <div class="user-box">
            <input type="text" name="surname" required>
            <label>Фамилия</label>
        </div>
        <div class="user-box">
            <input type="text" name="name" required>
            <label>Имя</label>
        </div>
        <div class="user-box">
            <select name="role" required>
                <option value="student">Студент</option>
                <option value="teacher">Преподаватель</option>
                <option value="admin">Администратор</option>
            </select>
            <label>Роль</label>
        </div>
        <div class="user-box">
            <input type="password" name="password" required>
            <label>Пароль</label>
        </div>
        <div class="user-box">
            <input type="password" name="password_confirm" required>
            <label>Подтверждение пароля</label>
        </div>

        <?php if (isset($_SESSION['message'])): ?>
            <p class="msg"><?php echo $_SESSION['message']; ?></p>
            <?php unset($_SESSION['message']); ?>
        <?php endif; ?>

        <button class="sv" type="submit">
            <span></span>
            <span></span>
            <span></span>
            <span></span>
            Зарегистрироваться
        </button>
        
        <div class="user-box">
            <h4>
                <a class="sv" href="index.php">У вас уже есть аккаунт? Авторизируйтесь!</a>
            </h4>
        </div>
    </form>
</div>
</body>
</html>