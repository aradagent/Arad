<?php
// api/download.php - سرو فایل‌های آپلود شده (نسخه اصلاح‌شده)
require_once __DIR__ . '/../config/database.php';
error_reporting(E_ALL);
ini_set('display_errors', 1); // در محیط تولید این خطوط را غیرفعال کنید
// بررسی لاگین بودن کاربر
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    die('دسترسی غیرمجاز');
}

$userId = $_SESSION['user_id'];
$fileName = isset($_GET['file']) ? basename($_GET['file']) : '';

if (empty($fileName)) {
    http_response_code(404);
    die('فایل یافت نشد');
}

// مسیر کامل فایل — ابتدا محل جدید (خارج از /ledor)، سپس مسیر قدیمی برای سازگاری با فایل‌های قبلی
$fullPath = avapay_upload_dir('chat') . $fileName;
if (!file_exists($fullPath)) {
    $fullPath = __DIR__ . '/../uploads/chat/' . $fileName;
}

if (!file_exists($fullPath)) {
    http_response_code(404);
    die('فایل یافت نشد');
}

// بررسی مالکیت فایل
$stmt = $conn->prepare("SELECT id FROM chat_messages WHERE user_id = ? AND file_path LIKE ?");
$searchPath = '%' . $conn->real_escape_string($fileName);
$stmt->bind_param("is", $userId, $searchPath);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    http_response_code(403);
    die('شما به این فایل دسترسی ندارید');
}

// تعیین type فایل (بدون نیاز به finfo)
if (function_exists('mime_content_type')) {
    $mimeType = mime_content_type($fullPath);
} else {
    // fallback بر اساس پسوند
    $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
    $mimeTypes = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'pdf' => 'application/pdf',
        'txt' => 'text/plain',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'zip' => 'application/zip',
        'mp3' => 'audio/mpeg',
        'mp4' => 'video/mp4',
    ];
    $mimeType = $mimeTypes[$ext] ?? 'application/octet-stream';
}

// ارسال فایل
header('Content-Type: ' . $mimeType);
header('Content-Disposition: inline; filename="' . $fileName . '"');
header('Content-Length: ' . filesize($fullPath));
readfile($fullPath);
exit;