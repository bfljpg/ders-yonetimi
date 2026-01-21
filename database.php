<?php
// Postgres bağlantı ayarlarını .env dosyasından okuyalım
$env = parse_ini_file(__DIR__ . '/.env');

if (!$env) {
    die(".env dosyası bulunamadı! Lütfen .env.example dosyasını kopyalayıp düzenleyin.");
}

$host = $env['DB_HOST'];
$port = $env['DB_PORT'];
$dbname = $env['DB_NAME'];
$user = $env['DB_USER'];
$password = $env['DB_PASS'];

try {
    $dsn = "pgsql:host=$host;port=$port;dbname=$dbname";
    $pdo = new PDO($dsn, $user, $password);

    if ($pdo) {
        echo "<h1>Başarılı! 🎉</h1>";
        echo "<p>Apache üzerinden PHP ile PostgreSQL sunucusuna bağlandın.</p>";

        // Örnek: Veritabanı sürümünü çekelim
        $stmt = $pdo->query('SELECT version()');
        $version = $stmt->fetchColumn();
        echo "<pre>Veritabanı Sürümü: $version</pre>";
    }
} catch (PDOException $e) {
    echo "<h1>Hata! 💥</h1>";
    echo $e->getMessage();
}
?>