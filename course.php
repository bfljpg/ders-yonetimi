<?php
session_start();

// Giriş kontrolü
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/db.php';

// ID kontrolü
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header('Location: index.php');
    exit;
}

$courseId = $_GET['id'];
$username = $_SESSION['username'];
$message = '';
$messageType = '';

// Hocanın InstructorID'sini al
$stmtInstructor = $pdo->prepare('SELECT "InstructorID" FROM "Instructors" WHERE "Username" = :username');
$stmtInstructor->execute(['username' => $username]);
$instructor = $stmtInstructor->fetch();

if (!$instructor) {
    die("Öğretmen bilgisi bulunamadı.");
}

$instructorId = $instructor['InstructorID'];

// Dersi çek ve yetki kontrolü yap
$stmt = $pdo->prepare('SELECT * FROM "Opened_Courses" WHERE "CourseOpenID" = :courseId AND "InstructorID" = :instructorId');
$stmt->execute(['courseId' => $courseId, 'instructorId' => $instructorId]);
$course = $stmt->fetch();

if (!$course) {
    die("Bu derse erişim yetkiniz yok veya ders bulunamadı.");
}

// Derse ait sınavları çek
$stmtExams = $pdo->prepare('SELECT * FROM "Exams" WHERE "CourseOpenID" = :courseId ORDER BY "ExamDate" DESC');
$stmtExams->execute(['courseId' => $courseId]);
$exams = $stmtExams->fetchAll();

// Form gönderildiğinde
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_resources') {
        // Kaynak güncelleme
        $resources = trim($_POST['resources'] ?? '');
        try {
            $updateStmt = $pdo->prepare('UPDATE "Opened_Courses" SET "Resources" = :resources WHERE "CourseOpenID" = :courseId');
            $updateStmt->execute(['resources' => $resources, 'courseId' => $courseId]);
            $message = 'Kaynak bilgileri güncellendi!';
            $messageType = 'success';
            $stmt->execute(['courseId' => $courseId, 'instructorId' => $instructorId]);
            $course = $stmt->fetch();
        } catch (PDOException $e) {
            $message = 'Hata: ' . $e->getMessage();
            $messageType = 'error';
        }
    } elseif ($action === 'add_exam') {
        // Yeni sınav ekleme
        $examType = trim($_POST['exam_type'] ?? '');
        $examDate = $_POST['exam_date'] ?? null;

        if (empty($examType)) {
            $message = 'Sınav tipi zorunludur.';
            $messageType = 'error';
        } else {
            try {
                // Q MaxScore değerlerini topla
                $columns = ['"CourseOpenID"', '"ExamType"', '"ExamDate"'];
                $placeholders = [':courseId', ':examType', ':examDate'];
                $params = ['courseId' => $courseId, 'examType' => $examType, 'examDate' => $examDate ?: null];

                for ($i = 1; $i <= 20; $i++) {
                    $maxScore = $_POST["q{$i}_max"] ?? null;
                    if ($maxScore !== null && $maxScore !== '') {
                        $columns[] = "\"Q{$i}_MaxScore\"";
                        $placeholders[] = ":q{$i}";
                        $params["q{$i}"] = (int) $maxScore;
                    }
                }

                $sql = 'INSERT INTO "Exams" (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';
                $insertStmt = $pdo->prepare($sql);
                $insertStmt->execute($params);

                $message = 'Sınav başarıyla eklendi!';
                $messageType = 'success';

                // Sınavları yeniden çek
                $stmtExams->execute(['courseId' => $courseId]);
                $exams = $stmtExams->fetchAll();
            } catch (PDOException $e) {
                $message = 'Sınav eklenirken hata: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
    } elseif ($action === 'delete_exam') {
        // Sınav silme
        $examId = $_POST['exam_id'] ?? null;
        if ($examId) {
            try {
                // Önce sınavın bu derse ait olduğunu kontrol et
                $checkStmt = $pdo->prepare('SELECT "ExamID" FROM "Exams" WHERE "ExamID" = :examId AND "CourseOpenID" = :courseId');
                $checkStmt->execute(['examId' => $examId, 'courseId' => $courseId]);
                if ($checkStmt->fetch()) {
                    // Önce bu sınava ait notları sil
                    $deleteGradesStmt = $pdo->prepare('DELETE FROM "Course_Grades" WHERE "ExamID" = :examId');
                    $deleteGradesStmt->execute(['examId' => $examId]);
                    $deletedGrades = $deleteGradesStmt->rowCount();

                    // Sonra sınavı sil
                    $deleteStmt = $pdo->prepare('DELETE FROM "Exams" WHERE "ExamID" = :examId');
                    $deleteStmt->execute(['examId' => $examId]);

                    if ($deletedGrades > 0) {
                        $message = "Sınav ve {$deletedGrades} adet öğrenci notu silindi!";
                    } else {
                        $message = 'Sınav silindi!';
                    }
                    $messageType = 'success';
                    $stmtExams->execute(['courseId' => $courseId]);
                    $exams = $stmtExams->fetchAll();
                } else {
                    $message = 'Bu sınavı silme yetkiniz yok.';
                    $messageType = 'error';
                }
            } catch (PDOException $e) {
                $message = 'Silme hatası: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
    } elseif ($action === 'update_exam') {
        // Sınav güncelleme
        $examId = $_POST['exam_id'] ?? null;
        $examType = trim($_POST['exam_type'] ?? '');
        $examDate = $_POST['exam_date'] ?? null;

        if ($examId && !empty($examType)) {
            try {
                // Önce sınavın bu derse ait olduğunu kontrol et
                $checkStmt = $pdo->prepare('SELECT "ExamID" FROM "Exams" WHERE "ExamID" = :examId AND "CourseOpenID" = :courseId');
                $checkStmt->execute(['examId' => $examId, 'courseId' => $courseId]);
                if ($checkStmt->fetch()) {
                    // Update sorgusunu oluştur
                    $setClauses = ['"ExamType" = :examType', '"ExamDate" = :examDate'];
                    $params = ['examType' => $examType, 'examDate' => $examDate ?: null, 'examId' => $examId];

                    for ($i = 1; $i <= 20; $i++) {
                        $maxScore = $_POST["q{$i}_max"] ?? null;
                        $setClauses[] = "\"Q{$i}_MaxScore\" = :q{$i}";
                        $params["q{$i}"] = ($maxScore !== null && $maxScore !== '') ? (int) $maxScore : null;
                    }

                    $sql = 'UPDATE "Exams" SET ' . implode(', ', $setClauses) . ' WHERE "ExamID" = :examId';
                    $updateStmt = $pdo->prepare($sql);
                    $updateStmt->execute($params);

                    $message = 'Sınav güncellendi!';
                    $messageType = 'success';
                    $stmtExams->execute(['courseId' => $courseId]);
                    $exams = $stmtExams->fetchAll();
                } else {
                    $message = 'Bu sınavı düzenleme yetkiniz yok.';
                    $messageType = 'error';
                }
            } catch (PDOException $e) {
                $message = 'Güncelleme hatası: ' . $e->getMessage();
                $messageType = 'error';
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
    <title>
        <?= htmlspecialchars($course['CourseCode']) ?> - Ders Düzenleme
    </title>
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

        .btn-back {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 14px;
            transition: background 0.3s;
        }

        .btn-back:hover {
            background: rgba(255, 255, 255, 0.3);
        }

        .container {
            max-width: 900px;
            margin: 0 auto;
            padding: 32px;
        }

        .course-header {
            background: white;
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        }

        .course-header h2 {
            color: #333;
            font-size: 24px;
            margin-bottom: 8px;
        }

        .course-meta {
            display: flex;
            gap: 24px;
            flex-wrap: wrap;
            color: #666;
            font-size: 14px;
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        }

        .card h3 {
            color: #333;
            font-size: 18px;
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 2px solid #eee;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
        }

        .info-item {
            background: #f8f9fa;
            padding: 16px;
            border-radius: 8px;
        }

        .info-label {
            color: #888;
            font-size: 12px;
            text-transform: uppercase;
            margin-bottom: 4px;
        }

        .info-value {
            color: #333;
            font-weight: 500;
            font-size: 16px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 500;
        }

        .form-group textarea {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid #e1e1e1;
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
            resize: vertical;
            min-height: 120px;
            transition: border-color 0.3s, box-shadow 0.3s;
        }

        .form-group textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.2);
        }

        .btn-save {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 14px 32px;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .btn-save:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(102, 126, 234, 0.4);
        }

        .message {
            padding: 16px;
            border-radius: 8px;
            margin-bottom: 24px;
            font-weight: 500;
        }

        .message.success {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid #28a745;
        }

        .message.error {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid #dc3545;
        }

        .exam-card {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 16px;
            border-left: 4px solid #667eea;
        }

        .exam-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }

        .exam-type {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 6px 14px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 14px;
        }

        .exam-date {
            color: #666;
            font-size: 14px;
        }

        .exam-questions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 12px;
        }

        .question-badge {
            background: #e8f4fd;
            color: #1976d2;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 500;
        }

        .exam-summary {
            display: flex;
            gap: 24px;
            padding-top: 12px;
            border-top: 1px solid #e0e0e0;
            color: #555;
            font-size: 14px;
            font-weight: 500;
        }

        .no-data {
            color: #888;
            text-align: center;
            padding: 24px;
        }

        .form-row {
            display: flex;
            gap: 16px;
            margin-bottom: 16px;
        }

        .form-group.half {
            flex: 1;
        }

        .form-group select,
        .form-group input[type="date"] {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e1e1e1;
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
            background: white;
            transition: border-color 0.3s, box-shadow 0.3s;
        }

        .form-group select:focus,
        .form-group input[type="date"]:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.2);
        }

        .questions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(80px, 1fr));
            gap: 12px;
            margin-top: 12px;
        }

        .question-input {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
        }

        .question-input label {
            font-size: 12px;
            color: #666;
            font-weight: 500;
        }

        .question-input input {
            width: 100%;
            padding: 8px;
            border: 2px solid #e1e1e1;
            border-radius: 6px;
            font-size: 14px;
            text-align: center;
            transition: border-color 0.3s;
        }

        .question-input input:focus {
            outline: none;
            border-color: #667eea;
        }

        .exam-actions {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn-icon {
            background: none;
            border: none;
            cursor: pointer;
            font-size: 16px;
            padding: 4px 8px;
            border-radius: 4px;
            transition: background 0.2s;
        }

        .btn-icon:hover {
            background: rgba(0, 0, 0, 0.1);
        }

        .btn-delete:hover {
            background: #fee;
        }

        .edit-form {
            margin-top: 16px;
            padding-top: 16px;
            border-top: 2px dashed #ddd;
        }

        .edit-actions {
            display: flex;
            gap: 12px;
            margin-top: 16px;
        }

        .btn-save-sm {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            font-size: 14px;
            cursor: pointer;
            transition: transform 0.2s;
        }

        .btn-save-sm:hover {
            transform: translateY(-1px);
        }

        .btn-cancel {
            background: #eee;
            color: #666;
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            font-size: 14px;
            cursor: pointer;
        }

        .btn-cancel:hover {
            background: #ddd;
        }
    </style>
    <script>
        function toggleEditForm(examId) {
            const form = document.getElementById('edit-form-' + examId);
            form.style.display = form.style.display === 'none' ? 'block' : 'none';
        }
    </script>
</head>

<body>
    <nav class="navbar">
        <h1>📚 Ders Düzenleme</h1>
        <a href="index.php" class="btn-back">← Geri Dön</a>
    </nav>

    <div class="container">
        <?php if ($message): ?>
            <div class="message <?= $messageType ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <div class="course-header">
            <h2>
                <?= htmlspecialchars($course['CourseCode']) ?> -
                <?= htmlspecialchars($course['CourseName']) ?>
            </h2>
            <div class="course-meta">
                <span class="meta-item">📅
                    <?= htmlspecialchars($course['Year'] . ' ' . $course['Term']) ?>
                </span>
                <span class="meta-item">🏫
                    <?= htmlspecialchars($course['Department'] ?? '-') ?>
                </span>
                <span class="meta-item">📖
                    <?= htmlspecialchars($course['Program'] ?? '-') ?>
                </span>
            </div>
        </div>

        <div class="card">
            <h3>📊 Kredi Bilgileri</h3>
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label">Teorik</div>
                    <div class="info-value">
                        <?= htmlspecialchars($course['Teorik'] ?? '-') ?>
                    </div>
                </div>
                <div class="info-item">
                    <div class="info-label">Uygulama</div>
                    <div class="info-value">
                        <?= htmlspecialchars($course['Uygulama'] ?? '-') ?>
                    </div>
                </div>
                <div class="info-item">
                    <div class="info-label">Kredi</div>
                    <div class="info-value">
                        <?= htmlspecialchars($course['Credits'] ?? '-') ?>
                    </div>
                </div>
                <div class="info-item">
                    <div class="info-label">Sınıf</div>
                    <div class="info-value">
                        <?= htmlspecialchars($course['ClassLevel'] ?? '-') ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <h3>📝 Sınavlar</h3>
            <?php if (count($exams) > 0): ?>
                <?php foreach ($exams as $exam): ?>
                    <div class="exam-card" id="exam-<?= $exam['ExamID'] ?>">
                        <div class="exam-header">
                            <span class="exam-type"><?= htmlspecialchars($exam['ExamType']) ?></span>
                            <div class="exam-actions">
                                <span class="exam-date">📅 <?= htmlspecialchars($exam['ExamDate'] ?? '-') ?></span>
                                <button type="button" class="btn-icon btn-edit"
                                    onclick="toggleEditForm(<?= $exam['ExamID'] ?>)">✏️</button>
                                <form method="POST" style="display:inline"
                                    onsubmit="return confirm('⚠️ DİKKAT: Bu sınavı sildiğinizde, sınava ait tüm öğrenci notları da silinecek ve yeniden girmeniz gerekecek!\n\nDevam etmek istiyor musunuz?')">
                                    <input type="hidden" name="action" value="delete_exam">
                                    <input type="hidden" name="exam_id" value="<?= $exam['ExamID'] ?>">
                                    <button type="submit" class="btn-icon btn-delete">🗑️</button>
                                </form>
                            </div>
                        </div>
                        <div class="exam-questions">
                            <?php
                            $totalScore = 0;
                            $questionCount = 0;
                            for ($i = 1; $i <= 20; $i++):
                                $maxScore = $exam["Q{$i}_MaxScore"] ?? null;
                                if ($maxScore !== null && $maxScore > 0):
                                    $totalScore += $maxScore;
                                    $questionCount++;
                                    ?>
                                    <span class="question-badge">S<?= $i ?>: <?= $maxScore ?> puan</span>
                                <?php endif; endfor; ?>
                        </div>
                        <div class="exam-summary">
                            <span>📊 <?= $questionCount ?> Soru</span>
                            <span>📈 Toplam: <?= $totalScore ?> puan</span>
                        </div>

                        <!-- Düzenleme Formu (gizli) -->
                        <div class="edit-form" id="edit-form-<?= $exam['ExamID'] ?>" style="display:none">
                            <form method="POST">
                                <input type="hidden" name="action" value="update_exam">
                                <input type="hidden" name="exam_id" value="<?= $exam['ExamID'] ?>">

                                <div class="form-row">
                                    <div class="form-group half">
                                        <label>Sınav Tipi</label>
                                        <select name="exam_type" required>
                                            <option value="Final" <?= $exam['ExamType'] === 'Final' ? 'selected' : '' ?>>Final
                                            </option>
                                            <option value="Bütünleme" <?= $exam['ExamType'] === 'Bütünleme' ? 'selected' : '' ?>>
                                                Bütünleme</option>
                                            <option value="Vize" <?= $exam['ExamType'] === 'Vize' ? 'selected' : '' ?>>Vize
                                            </option>
                                            <option value="Quiz" <?= $exam['ExamType'] === 'Quiz' ? 'selected' : '' ?>>Quiz
                                            </option>
                                        </select>
                                    </div>
                                    <div class="form-group half">
                                        <label>Tarih</label>
                                        <input type="date" name="exam_date"
                                            value="<?= htmlspecialchars($exam['ExamDate'] ?? '') ?>">
                                    </div>
                                </div>

                                <div class="questions-grid">
                                    <?php for ($i = 1; $i <= 20; $i++): ?>
                                        <div class="question-input">
                                            <label>S<?= $i ?></label>
                                            <input type="number" name="q<?= $i ?>_max" min="0" max="100"
                                                value="<?= $exam["Q{$i}_MaxScore"] ?? '' ?>">
                                        </div>
                                    <?php endfor; ?>
                                </div>

                                <div class="edit-actions">
                                    <button type="submit" class="btn-save-sm">💾 Kaydet</button>
                                    <button type="button" class="btn-cancel"
                                        onclick="toggleEditForm(<?= $exam['ExamID'] ?>)">İptal</button>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="no-data">Bu ders için henüz sınav tanımlanmamış.</p>
            <?php endif; ?>
        </div>

        <div class="card">
            <h3>➕ Yeni Sınav Ekle</h3>
            <form method="POST" action="">
                <input type="hidden" name="action" value="add_exam">

                <div class="form-row">
                    <div class="form-group half">
                        <label for="exam_type">Sınav Tipi *</label>
                        <select id="exam_type" name="exam_type" required>
                            <option value="">Seçiniz...</option>
                            <option value="Final">Final</option>
                            <option value="Bütünleme">Bütünleme</option>
                            <option value="Vize">Vize</option>
                            <option value="Quiz">Quiz</option>
                        </select>
                    </div>
                    <div class="form-group half">
                        <label for="exam_date">Sınav Tarihi</label>
                        <input type="date" id="exam_date" name="exam_date">
                    </div>
                </div>

                <div class="form-group">
                    <label>Soru Puanları (sadece kullanılacak soruları doldurun)</label>
                    <div class="questions-grid">
                        <?php for ($i = 1; $i <= 20; $i++): ?>
                            <div class="question-input">
                                <label>S<?= $i ?></label>
                                <input type="number" name="q<?= $i ?>_max" min="0" max="100" placeholder="0">
                            </div>
                        <?php endfor; ?>
                    </div>
                </div>

                <button type="submit" class="btn-save">➕ Sınav Ekle</button>
            </form>
        </div>

        <div class="card">
            <h3>✏️ Ders Kaynakları Düzenleme</h3>
            <form method="POST" action="">
                <input type="hidden" name="action" value="update_resources">
                <div class="form-group">
                    <label for="resources">Kitap / Kaynak</label>
                    <textarea id="resources" name="resources"
                        placeholder="Kaynak ve materyalleri yazın..."><?= htmlspecialchars($course['Resources'] ?? '') ?></textarea>
                </div>

                <button type="submit" class="btn-save">💾 Kaydet</button>
            </form>
        </div>
    </div>
</body>

</html>