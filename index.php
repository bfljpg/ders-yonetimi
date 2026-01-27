<?php
session_start();

// Giriş yapılmamışsa login sayfasına yönlendir
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/db.php';

// Giriş yapan hocanın InstructorID'sini bul
$username = $_SESSION['username'];

// Önce Instructors tablosundan InstructorID'yi al
$stmtInstructor = $pdo->prepare('SELECT "InstructorID", "FullName" FROM "Instructors" WHERE "Username" = :username');
$stmtInstructor->execute(['username' => $username]);
$instructor = $stmtInstructor->fetch();

if (!$instructor) {
    die("Öğretmen bilgisi bulunamadı.");
}

$instructorId = $instructor['InstructorID'];
$instructorName = $instructor['FullName'] ?? $username;

// Hocanın derslerini çek
$stmt = $pdo->prepare('
    SELECT 
        oc.*,
        (SELECT COUNT(*) FROM "Course_Grades" cg WHERE cg."CourseOpenID" = oc."CourseOpenID") as ogrenci_sayisi
    FROM "Opened_Courses" oc
    WHERE oc."InstructorID" = :instructorId
    ORDER BY oc."Year" DESC, oc."Term" DESC, oc."CourseCode" ASC
');
$stmt->execute(['instructorId' => $instructorId]);
$courses = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="tr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Ders Yönetimi</title>
    <link rel="stylesheet" href="styles.css">
</head>

<body>
    <nav class="navbar">
        <h1>📚 Ders Yönetimi</h1>
        <div class="navbar-user">
            <span>👋 Hoş geldin, <?= htmlspecialchars($instructorName) ?></span>
            <a href="logout.php" class="btn-logout">Çıkış Yap</a>
        </div>
    </nav>

    <div class="container">
        <div class="page-header">
            <h2>Derslerim</h2>
            <p>Bu dönem verdiğiniz dersler aşağıda listelenmiştir.</p>
        </div>

        <?php if (count($courses) > 0): ?>
            <div class="courses-grid">
                <?php foreach ($courses as $course): ?>
                    <a href="course.php?id=<?= urlencode($course['CourseOpenID']) ?>" class="course-card">
                        <div class="course-header">
                            <div class="course-code"><?= htmlspecialchars($course['CourseCode']) ?></div>
                            <div class="course-name"><?= htmlspecialchars($course['CourseName']) ?></div>
                        </div>
                        <div class="course-body">
                            <div class="course-info">
                                <div class="info-row">
                                    <span class="info-label">Dönem</span>
                                    <span
                                        class="info-value"><?= htmlspecialchars($course['Year'] . ' ' . $course['Term']) ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">Bölüm</span>
                                    <span class="info-value"><?= htmlspecialchars($course['Department'] ?? '-') ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">Program</span>
                                    <span class="info-value"><?= htmlspecialchars($course['Program'] ?? '-') ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">Kredi</span>
                                    <span class="info-value"><?= htmlspecialchars($course['Credits'] ?? '-') ?></span>
                                </div>
                            </div>
                            <div class="student-count">
                                👨‍🎓 <?= $course['ogrenci_sayisi'] ?> Öğrenci
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="no-courses">
                <div class="emoji">📭</div>
                <h3>Henüz ders bulunamadı</h3>
                <p>Bu dönem size atanmış ders bulunmamaktadır.</p>
            </div>
        <?php endif; ?>
    </div>
</body>

</html>