<?php
session_start();

// Giriş yapılmamışsa login sayfasına yönlendir
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="tr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ana Sayfa - Ders Yönetimi</title>
</head>

<body>
    <h1>Hello World! 👋</h1>
    <p>Hoş geldin,
        <?= htmlspecialchars($_SESSION['username'] ?? 'Kullanıcı') ?>!
    </p>
    <a href="logout.php">Çıkış Yap</a>
</body>

</html>