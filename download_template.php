<?php
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/SimpleXLSXGen.php';

use Shuchkin\SimpleXLSXGen;

$courseId = $_GET['id'] ?? null;
$examId = $_GET['exam_id'] ?? null;

if (!$courseId || !$examId) {
    die("Ders veya sınav ID'si eksik.");
}

$username = $_SESSION['username'];

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
    die("Bu derse erişim yetkiniz yok.");
}

// Sınavı çek
$stmtExam = $pdo->prepare('SELECT * FROM "Exams" WHERE "ExamID" = :examId AND "CourseOpenID" = :courseId');
$stmtExam->execute(['examId' => $examId, 'courseId' => $courseId]);
$exam = $stmtExam->fetch();

if (!$exam) {
    die("Sınav bulunamadı.");
}

// Derse kayıtlı öğrencileri çek
$stmtEnrolled = $pdo->prepare('
    SELECT e.*, s."FullName", s."StudentID" as "StudentNo"
    FROM "Enrollments" e 
    JOIN "Students" s ON e."StudentID" = s."StudentID" 
    WHERE e."CourseOpenID" = :courseId 
    ORDER BY s."FullName"
');
$stmtEnrolled->execute(['courseId' => $courseId]);
$students = $stmtEnrolled->fetchAll();

// Mevcut notları çek
$stmtResults = $pdo->prepare('SELECT * FROM "Exam_Results" WHERE "ExamID" = :examId');
$stmtResults->execute(['examId' => $examId]);
$rawResults = $stmtResults->fetchAll();

$examResults = [];
foreach ($rawResults as $res) {
    $examResults[$res['EnrollmentID']] = $res;
}

// Hangi sorular aktif?
$activeQuestions = [];
for ($i = 1; $i <= 20; $i++) {
    if (isset($exam["Q{$i}_MaxScore"]) && $exam["Q{$i}_MaxScore"] > 0) {
        $activeQuestions[] = $i;
    }
}

// Excel verisi oluştur
$data = [];

// Başlık satırı
$headers = ['StudentID', 'Öğrenci Adı'];
foreach ($activeQuestions as $q) {
    $headers[] = 'S' . $q . ' (Max:' . $exam["Q{$q}_MaxScore"] . ')';
}
$data[] = $headers;

// Öğrenci satırları
foreach ($students as $student) {
    $row = [
        $student['StudentNo'],
        $student['FullName']
    ];

    $studentResult = $examResults[$student['EnrollmentID']] ?? [];

    foreach ($activeQuestions as $q) {
        $score = $studentResult["Q{$q}"] ?? '';
        $row[] = $score !== '' ? (int) $score : '';
    }

    $data[] = $row;
}

// XLSX olarak indir
$filename = $course['CourseCode'] . '_' . $exam['ExamType'] . '_Notlar.xlsx';

$xlsx = SimpleXLSXGen::fromArray($data);
$xlsx->downloadAs($filename);
exit;
