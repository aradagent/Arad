<?php
/**
 * api/push_resubscribe.php
 * ---------------------------------------------------------------------------
 * تمدید/جایگزینی اشتراک Web Push بدون نیاز به نشست (session).
 *
 * چرا لازم است؟
 *   مرورگر گاهی اشتراک push را باطل و یک اشتراک جدید صادر می‌کند (رویداد
 *   pushsubscriptionchange). این اتفاق معمولاً وقتی رخ می‌دهد که اپ بسته است
 *   و نشست PHP کاربر مدت‌هاست منقضی شده. بنابراین نمی‌توانیم به $_SESSION
 *   تکیه کنیم؛ در عوض کاربر را از روی «endpoint قدیمی» پیدا می‌کنیم.
 *
 * ورودی (JSON):
 *   { oldEndpoint, endpoint, p256dh, auth }
 * ---------------------------------------------------------------------------
 */

header('Content-Type: application/json; charset=UTF-8');

$response = ['success' => false, 'message' => ''];

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    echo json_encode($response + ['message' => 'bad payload']);
    exit;
}

$oldEndpoint = trim((string)($data['oldEndpoint'] ?? ''));
$endpoint    = trim((string)($data['endpoint'] ?? ''));
$p256dh      = trim((string)($data['p256dh'] ?? ''));
$auth        = trim((string)($data['auth'] ?? ''));

if ($endpoint === '' || $p256dh === '' || $auth === '') {
    echo json_encode($response + ['message' => 'missing fields']);
    exit;
}

require_once __DIR__ . '/../config/database.php';
if (!isset($conn) || !($conn instanceof mysqli)) {
    echo json_encode($response + ['message' => 'db error']);
    exit;
}

// تبدیل کلیدها به base64url در صورت نیاز (همان کمکی‌ای که در ذخیره‌ی عادی هست)
if (!function_exists('_avapay_to_base64url')) {
    function _avapay_to_base64url($s) {
        $s = trim((string)$s);
        // اگر base64 استاندارد بود، به base64url تبدیل کن
        $s = strtr($s, '+/', '-_');
        return rtrim($s, '=');
    }
}
$p256dh = _avapay_to_base64url($p256dh);
$auth   = _avapay_to_base64url($auth);

// گام ۱ - کاربر را از روی اشتراک قدیمی پیدا کن
$userId = 0;
if ($oldEndpoint !== '') {
    $st = $conn->prepare("SELECT user_id FROM push_subscriptions WHERE endpoint = ? LIMIT 1");
    if ($st) {
        $st->bind_param("s", $oldEndpoint);
        $st->execute();
        $rs = $st->get_result();
        if ($row = $rs->fetch_assoc()) $userId = (int)$row['user_id'];
    }
}

// گام ۲ - اگر پیدا نشد، شاید همین endpoint جدید از قبل ثبت شده باشد
if ($userId <= 0) {
    $st = $conn->prepare("SELECT user_id FROM push_subscriptions WHERE endpoint = ? LIMIT 1");
    if ($st) {
        $st->bind_param("s", $endpoint);
        $st->execute();
        $rs = $st->get_result();
        if ($row = $rs->fetch_assoc()) $userId = (int)$row['user_id'];
    }
}

// گام ۳ - در نهایت اگر نشست فعال بود، از آن استفاده کن
if ($userId <= 0) {
    if (session_status() === PHP_SESSION_NONE) @session_start();
    if (!empty($_SESSION['user_id'])) $userId = (int)$_SESSION['user_id'];
}

if ($userId <= 0) {
    // نمی‌دانیم این اشتراک مال کیست — کاربر باید یک‌بار اپ را باز کند
    echo json_encode($response + ['message' => 'unknown subscription']);
    exit;
}

// گام ۴ - اشتراک قدیمی را حذف و اشتراک جدید را ثبت/به‌روزرسانی کن
if ($oldEndpoint !== '' && $oldEndpoint !== $endpoint) {
    $d = $conn->prepare("DELETE FROM push_subscriptions WHERE endpoint = ?");
    if ($d) { $d->bind_param("s", $oldEndpoint); $d->execute(); }
}

$up = $conn->prepare("INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth)
                      VALUES (?,?,?,?)
                      ON DUPLICATE KEY UPDATE user_id = VALUES(user_id),
                                              p256dh  = VALUES(p256dh),
                                              auth    = VALUES(auth)");
if ($up) {
    $up->bind_param("isss", $userId, $endpoint, $p256dh, $auth);
    $ok = $up->execute();
    echo json_encode(['success' => (bool)$ok, 'user_id' => $userId, 'renewed' => true]);
    exit;
}

echo json_encode($response + ['message' => 'insert failed']);
