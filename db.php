<?php
/**
 * Veritabanı Bağlantı Dosyası
 * Tüm sayfalarda require_once ile kullanılır.
 */

$env = parse_ini_file(__DIR__ . '/.env');

if (!$env) {
    die(".env dosyası bulunamadı! Lütfen .env.example dosyasını kopyalayıp düzenleyin.");
}

try {
    $dsn = "pgsql:host={$env['DB_HOST']};port={$env['DB_PORT']};dbname={$env['DB_NAME']}";
    $pdo = new PDO($dsn, $env['DB_USER'], $env['DB_PASS']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Veritabanı bağlantı hatası: " . $e->getMessage());
}
