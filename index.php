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
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f7fa;
            min-height: 100vh;
        }

        .navbar {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 16px 32px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
        }

        .navbar h1 {
            font-size: 24px;
            font-weight: 600;
        }

        .navbar-user {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .navbar-user span {
            font-size: 14px;
            opacity: 0.9;
        }

        .btn-logout {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 14px;
            transition: background 0.3s;
        }

        .btn-logout:hover {
            background: rgba(255, 255, 255, 0.3);
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 32px;
        }

        .page-header {
            margin-bottom: 32px;
        }

        .page-header h2 {
            color: #333;
            font-size: 28px;
            margin-bottom: 8px;
        }

        .page-header p {
            color: #666;
        }

        .courses-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 24px;
        }

        .course-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            overflow: hidden;
            transition: transform 0.3s, box-shadow 0.3s;
            text-decoration: none;
            color: inherit;
            display: block;
        }

        .course-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.12);
        }

        .course-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
        }

        .course-code {
            font-size: 14px;
            opacity: 0.9;
            margin-bottom: 4px;
        }

        .course-name {
            font-size: 20px;
            font-weight: 600;
        }

        .course-body {
            padding: 20px;
        }

        .course-info {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .info-label {
            color: #888;
            font-size: 13px;
        }

        .info-value {
            color: #333;
            font-weight: 500;
            font-size: 14px;
        }

        .student-count {
            background: #e8f5e9;
            color: #2e7d32;
            padding: 8px 16px;
            border-radius: 8px;
            font-weight: 600;
            text-align: center;
            margin-top: 12px;
        }

        .no-courses {
            text-align: center;
            padding: 60px 20px;
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        }

        .no-courses .emoji {
            font-size: 48px;
            margin-bottom: 16px;
        }

        .no-courses h3 {
            color: #333;
            margin-bottom: 8px;
        }

        .no-courses p {
            color: #666;
        }
    </style>
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