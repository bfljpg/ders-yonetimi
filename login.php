<?php
session_start();

// Zaten giriş yapılmışsa yönlendir
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    header('Location: index.php');
    exit;
}

$error = '';

// Form gönderildiğinde
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = 'Kullanıcı adı ve şifre gereklidir.';
    } else {
        // Veritabanı bağlantısı
        $env = parse_ini_file(__DIR__ . '/.env');

        if (!$env) {
            $error = '.env dosyası bulunamadı!';
        } else {
            try {
                $dsn = "pgsql:host={$env['DB_HOST']};port={$env['DB_PORT']};dbname={$env['DB_NAME']}";
                $pdo = new PDO($dsn, $env['DB_USER'], $env['DB_PASS']);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

                // Kullanıcıyı kontrol et
                $stmt = $pdo->prepare('SELECT * FROM "LoginCredentials" WHERE "Username" = :username');
                $stmt->execute(['username' => $username]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($user && password_verify($password, $user['Password'])) {
                    // Giriş başarılı
                    $_SESSION['logged_in'] = true;
                    $_SESSION['username'] = $username;
                    header('Location: index.php');
                    exit;
                } else {
                    $error = 'Geçersiz kullanıcı adı veya şifre.';
                }
            } catch (PDOException $e) {
                $error = 'Veritabanı hatası: ' . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="tr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Giriş Yap - Ders Yönetimi</title>
    <link rel="stylesheet" href="styles.css">
</head>

<body class="login-page">
    <div class="login-container">
        <div class="login-header">
            <div class="emoji">📚</div>
            <h1>Ders Yönetimi</h1>
            <p>Devam etmek için giriş yapın</p>
        </div>

        <?php if ($error): ?>
            <div class="error-message">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="form-group">
                <label for="username">Kullanıcı Adı</label>
                <input type="text" id="username" name="username"
                    value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" placeholder="Kullanıcı adınızı girin"
                    required>
            </div>

            <div class="form-group">
                <label for="password">Şifre</label>
                <input type="password" id="password" name="password" placeholder="Şifrenizi girin" required>
            </div>

            <button type="submit" class="btn-login">Giriş Yap</button>
        </form>
    </div>
</body>

</html>