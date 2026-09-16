<?php
/**
 * api/version_sync.php
 * ---------------------------------------------------------
 * اندپوینت مستقل و سبک برای خواندن/ذخیره‌ی نسخه‌ی PWA کاربر.
 *
 * چرا این فایل ساخته شد؟
 * قبلاً این کار توسط dashboard.php?action=get_version /
 * update_version انجام می‌شد. اما dashboard.php قبل از رسیدن به
 * آن بخش، کوئری‌های سنگین زیادی (تراکنش‌ها، دوستان، رسیدها،
 * ساخت جدول‌ها و...) را اجرا می‌کند. اگر هرکدام از آن‌ها خطا یا
 * هشداری چاپ می‌کرد (حتی یک Notice ساده‌ی PHP)، خروجی HTML آن
 * قبل از JSON اضافه می‌شد و باعث می‌شد fetch().json() در مرورگر
 * با خطا مواجه شده و آپدیت نسخه در دیتابیس ذخیره نشود — دقیقاً
 * همان مشکلی که گزارش شده بود.
 *
 * این فایل کاملاً مستقل و حداقلی است: فقط سشن را چک می‌کند و
 * نسخه را در دیتابیس می‌خواند/می‌نویسد. هیچ کوئری اضافه‌ای اجرا
 * نمی‌شود، پس امکان خراب شدن خروجی JSON عملاً از بین می‌رود.
 * ---------------------------------------------------------
 */

// خاموش کردن نمایش خطا در خروجی (فقط در لاگ سرور ثبت شود) تا هیچ
// چیزی به‌جز JSON خام در پاسخ چاپ نشود
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'not_authenticated']);
    exit;
}

$userId = (int)$_SESSION['user_id'];
$action = $_GET['action'] ?? '';

// ========== دریافت نسخه‌ی فعلی کاربر ==========
if ($action === 'get_version') {
    $stmt = $conn->prepare("SELECT app_version FROM users WHERE id = ?");
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'db_prepare_failed']);
        exit;
    }

    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        echo json_encode(['success' => true, 'version' => $row['app_version'] ?: '1.0.0']);
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'user_not_found']);
    }

    $stmt->close();
    exit;
}

// ========== ذخیره‌ی نسخه‌ی جدید کاربر پس از آپدیت ==========
if ($action === 'update_version') {
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    $newVersion = $input['version'] ?? null;

    if (!$newVersion || !preg_match('/^\d+\.\d+\.\d+$/', $newVersion)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'invalid_version_format']);
        exit;
    }

    $stmt = $conn->prepare("UPDATE users SET app_version = ? WHERE id = ?");
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'db_prepare_failed']);
        exit;
    }

    $stmt->bind_param("si", $newVersion, $userId);

    if ($stmt->execute()) {
        // هم‌زمان در سشن هم به‌روزرسانی می‌کنیم تا صفحات دیگر هم سریع‌تر بفهمند
        $_SESSION['app_version'] = $newVersion;
        echo json_encode([
            'success'        => true,
            'version'        => $newVersion,
            'affected_rows'  => $stmt->affected_rows
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'db_update_failed', 'detail' => $stmt->error]);
    }

    $stmt->close();
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'invalid_action']);
