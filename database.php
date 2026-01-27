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

        // DersBilgileri tablosundan ilk 10 satırı çekelim
        $stmt = $pdo->query('SELECT * FROM "DersBilgileri" LIMIT 10');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($rows) > 0) {
            // Sütun başlıklarını al
            $columns = array_keys($rows[0]);

            echo "<h2>DersBilgileri Tablosu (İlk 10 Satır)</h2>";
            echo "<style>
                table { border-collapse: collapse; width: 100%; margin-top: 20px; }
                th, td { border: 1px solid #ddd; padding: 12px; text-align: left; }
                th { background-color: #4CAF50; color: white; }
                tr:nth-child(even) { background-color: #f2f2f2; }
                tr:hover { background-color: #ddd; }
            </style>";

            echo "<table>";

            // Tablo başlıkları
            echo "<tr>";
            foreach ($columns as $col) {
                echo "<th>" . htmlspecialchars($col) . "</th>";
            }
            echo "</tr>";

            // Tablo verileri
            foreach ($rows as $row) {
                echo "<tr>";
                foreach ($row as $value) {
                    echo "<td>" . htmlspecialchars($value ?? '') . "</td>";
                }
                echo "</tr>";
            }

            echo "</table>";
        } else {
            echo "<p>DersBilgileri tablosunda veri bulunamadı.</p>";
        }
    }
} catch (PDOException $e) {
    echo "<h1>Hata! 💥</h1>";
    echo $e->getMessage();
}
?>