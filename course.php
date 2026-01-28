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

// Derse kayıtlı öğrencileri çek
$stmtEnrolled = $pdo->prepare('
    SELECT cg.*, s."FullName", s."Email" 
    FROM "Enrollments" cg 
    JOIN "Students" s ON cg."StudentID" = s."StudentID" 
    WHERE cg."CourseOpenID" = :courseId 
    ORDER BY s."FullName"
');
$stmtEnrolled->execute(['courseId' => $courseId]);
$enrolledStudents = $stmtEnrolled->fetchAll();

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
    } elseif ($action === 'update_student_grades') {
        // Öğrenci bazlı not güncelleme
        $enrollmentId = $_POST['enrollment_id'] ?? null;

        if ($enrollmentId) {
            try {
                // Güvenlik: Bu enrollmentId gerçekten şuan düzenlenen derse mi ait?
                $securityCheck = $pdo->prepare('SELECT "CourseOpenID" FROM "Enrollments" WHERE "EnrollmentID" = :eid');
                $securityCheck->execute(['eid' => $enrollmentId]);
                $securityInfo = $securityCheck->fetch();

                if (!$securityInfo || $securityInfo['CourseOpenID'] != $courseId) {
                     throw new Exception('Bu öğrenci notunu düzenleme yetkiniz yok (IDOR Koruması).');
                }
                // Gelen veriler: scores[EXAM_ID][q][QUESTION_NUMBER] = POINT
                $incomingScores = $_POST['scores'] ?? [];

                foreach ($incomingScores as $examId => $examData) {
                    $qScores = $examData['q'] ?? [];

                    // Önce bu enrollment için bu sınavın sonucu var mı?
                    $checkStmt = $pdo->prepare('SELECT "ResultID" FROM "Exam_Results" WHERE "EnrollmentID" = :enrollmentId AND "ExamID" = :examId');
                    $checkStmt->execute(['enrollmentId' => $enrollmentId, 'examId' => $examId]);
                    $existingResult = $checkStmt->fetch();

                    $setClauses = [];
                    $params = [];

                    for ($i = 1; $i <= 20; $i++) {
                        if (isset($qScores[$i]) && $qScores[$i] !== '') {
                            $setClauses[] = "\"Q{$i}\" = :q{$i}";
                            $params["q{$i}"] = (int) $qScores[$i];
                        }
                    }

                    if (empty($setClauses))
                        continue; // Eğer hiç veri girilmemişse atla

                    if ($existingResult) {
                        // UPDATE
                        $params['resultId'] = $existingResult['ResultID'];
                        $sql = 'UPDATE "Exam_Results" SET ' . implode(', ', $setClauses) . ' WHERE "ResultID" = :resultId';
                        $updateStmt = $pdo->prepare($sql);
                        $updateStmt->execute($params);
                    } else {
                        // INSERT
                        $cols = ['"EnrollmentID"', '"ExamID"'];
                        $vals = [':enrollmentId', ':examId'];
                        $insertParams = ['enrollmentId' => $enrollmentId, 'examId' => $examId];

                        foreach ($params as $key => $val) {
                            $cols[] = "\"Q" . substr($key, 1) . "\"";
                            $vals[] = ":" . $key;
                            $insertParams[$key] = $val;
                        }

                        $sql = 'INSERT INTO "Exam_Results" (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $vals) . ')';
                        $insertStmt = $pdo->prepare($sql);
                        $insertStmt->execute($insertParams);
                    }
                }



                $message = 'Notlar kaydedildi!';
                $messageType = 'success';

                // Verileri yenile
                $stmtEnrolled->execute(['courseId' => $courseId]);
                $enrolledStudents = $stmtEnrolled->fetchAll();
            } catch (PDOException $e) {
                $message = 'Kaydetme hatası: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
    }
}

// Tüm sınav sonuçlarını çek (EnrollmentID ve ExamID bazlı)
// Yapı: $allResults[EnrollmentID][ExamID] = [Row Data]
$stmtAllResults = $pdo->prepare('
    SELECT er.* 
    FROM "Exam_Results" er
    JOIN "Enrollments" e ON er."EnrollmentID" = e."EnrollmentID"
    WHERE e."CourseOpenID" = :courseId
');
$stmtAllResults->execute(['courseId' => $courseId]);
$rawAllResults = $stmtAllResults->fetchAll();

$allResults = [];
foreach ($rawAllResults as $res) {
    $allResults[$res['EnrollmentID']][$res['ExamID']] = $res;
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
    <link rel="stylesheet" href="styles.css">
    <script>
        function toggleEditForm(examId) {
            const form = document.getElementById('edit-form-' + examId);
            form.style.display = form.style.display === 'none' ? 'block' : 'none';
        }
        function toggleGradeForm(enrollmentId) {
            const row = document.getElementById('grade-form-' + enrollmentId);
            row.style.display = row.style.display === 'none' ? 'table-row' : 'none';
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

        <!--  MEVCUT DASHBOARD -->
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
                                            <button type="submit" class="btn-icon btn-delete">🗑️</button>
                                            </form>
                                            <a href="?id=<?= $courseId ?>&mode=grade_entry&exam_id=<?= $exam['ExamID'] ?>#grade-entry"
                                                class="btn-icon" style="text-decoration:none" title="Not Gir">📝</a>
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

            <!-- Öğrenci Listesi -->
            <div class="card">
                <h3>👥 Öğrenci Listesi (<?= count($enrolledStudents) ?> Öğrenci)</h3>

                <?php if (count($enrolledStudents) > 0): ?>
                        <div class="table-responsive">
                            <table class="students-table">
                                <thead>
                                    <tr>
                                        <th>Öğrenci Adı</th>
                                        <?php foreach ($exams as $exam): ?>
                                            <th><?= htmlspecialchars($exam['ExamType']) ?></th>
                                        <?php endforeach; ?>
                                        <th>İşlem</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($enrolledStudents as $student): ?>
                                            <tr>
                                                <td>
                                                    <div class="student-name">
                                                <?= htmlspecialchars($student['FullName']) ?>
                                                <span style="font-size: 0.85em; color: #666; font-weight: normal;">(<?= $student['StudentID'] ?>)</span>
                                            </div>
                                                    <div class="student-email"><?= htmlspecialchars($student['Email'] ?? '') ?></div>
                                                </td>
                                        <?php foreach ($exams as $exam):
                                            $examId = $exam['ExamID'];
                                            $results = $allResults[$student['EnrollmentID']][$examId] ?? [];
                                            $totalScore = 0;
                                            $hasScore = false;
                                            for($k=1; $k<=20; $k++) {
                                                if(isset($results["Q{$k}"]) && $results["Q{$k}"] !== null) {
                                                    $totalScore += $results["Q{$k}"];
                                                    $hasScore = true;
                                                }
                                            }
                                        ?>
                                            <td style="text-align: center;">
                                                <?= $hasScore ? $totalScore : '-' ?>
                                            </td>
                                        <?php endforeach; ?>
                                                <td>
                                                    <button type="button" class="btn-icon btn-edit"
                                                        onclick="toggleGradeForm(<?= $student['EnrollmentID'] ?>)">✏️</button>
                                                </td>
                                            </tr>
                                            <!-- Not Düzenleme (Genel) -->
                                            <tr class="grade-form-row" id="grade-form-<?= $student['EnrollmentID'] ?>" style="display:none">
                                        <td colspan="<?= count($exams) + 2 ?>">
                                                <form method="POST" class="inline-grade-form">
                                                    <input type="hidden" name="action" value="update_student_grades">
                                                    <input type="hidden" name="enrollment_id" value="<?= $student['EnrollmentID'] ?>">
                                            
                                                    <div class="grade-edit-container" style="padding: 10px;">


                                                        <!-- Sınavlar -->
                                                        <?php foreach ($exams as $exam):
                                                            $examId = $exam['ExamID'];
                                                            $studentExamResult = $allResults[$student['EnrollmentID']][$examId] ?? [];
                                                            $hasQuestions = false;
                                                            // Sınavda en az bir soru var mı kontrol et
                                                            for ($k = 1; $k <= 20; $k++)
                                                                if (($exam["Q{$k}_MaxScore"] ?? 0) > 0)
                                                                    $hasQuestions = true;

                                                            if (!$hasQuestions)
                                                                continue;
                                                            ?>
                                                                <div class="exam-grade-block" style="margin-bottom: 15px; background: #fff; padding: 10px; border-radius: 6px; border: 1px solid #eee;">
                                                                    <h4 style="margin: 0 0 10px 0; font-size: 14px; color: #555;">
                                                                        <?= htmlspecialchars($exam['ExamType']) ?> 
                                                                        <small class="text-muted">(<?= htmlspecialchars($exam['ExamDate'] ?? '-') ?>)</small>
                                                                    </h4>
                                                                    <div style="display: flex; flex-wrap: wrap; gap: 10px;">
                                                                        <?php for ($i = 1; $i <= 20; $i++):
                                                                            $maxScore = $exam["Q{$i}_MaxScore"] ?? null;
                                                                            if ($maxScore !== null && $maxScore > 0):
                                                                                $score = $studentExamResult["Q{$i}"] ?? '';
                                                                                ?>
                                                                                    <div style="display: flex; flex-direction: column; align-items: center;">
                                                                                        <label style="font-size: 11px; color: #888;">S<?= $i ?> (<?= $maxScore ?>)</label>
                                                                                        <input type="number" 
                                                                                               name="scores[<?= $examId ?>][q][<?= $i ?>]" 
                                                                                               value="<?= $score ?>"
                                                                                               min="0" max="<?= $maxScore ?>"
                                                                                               style="width: 50px; text-align: center; padding: 4px; border: 1px solid #ddd; border-radius: 4px;">
                                                                                    </div>
                                                                            <?php endif; endfor; ?>
                                                                    </div>
                                                                </div>
                                                        <?php endforeach; ?>

                                                        <div style="text-align: right; margin-top: 15px;">
                                                            <button type="submit" class="btn-save-sm">💾 Değişiklikleri Kaydet</button>
                                                            <button type="button" class="btn-cancel" onclick="toggleGradeForm(<?= $student['EnrollmentID'] ?>)">İptal</button>
                                                        </div>
                                                    </div>
                                                </form>
                                            </td>
                                            </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                <?php else: ?>
                        <p class="no-data">Bu derse kayıtlı öğrenci bulunmuyor.</p>
                <?php endif; ?>
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