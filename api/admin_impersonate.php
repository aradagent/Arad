<?php
/**
 * api/admin_impersonate.php
 * ------------------------------------------------------------------
 * ورود مخفیانه‌ی ادمین به داشبورد یک کاربر — بدون اینکه خودِ کاربر
 * متوجه شود (last_seen/last_login او دست‌نخورده می‌ماند، هیچ نوتیف یا
 * ایمیلی برایش ارسال نمی‌شود).
 *
 * روش کار: به‌جای باز کردن یک سشن کاملاً جداگانه (که با کوکی تک‌دامنه‌ی
 * PHP عملاً ممکن نیست)، سشن *خودِ ادمین* موقتاً «تبدیل» به سشن کاربر
 * هدف می‌شود — شناسه‌ی واقعی ادمین در impersonator_admin_id نگه داشته
 * می‌شود تا با زدن «بازگشت به پنل ادمین» دقیقاً به همان‌جا برگردد.
 * یعنی این یک ترفند دو-تب-همزمان نیست؛ ادمین وارد داشبورد کاربر می‌شود
 * و با دکمه‌ی بازگشت، به پنل ادمین برمی‌گردد — دقیقاً همان الگویی که
 * افزونه‌های «User Switching» در سایر پلتفرم‌ها استفاده می‌کنند.
 *
 * هر شروع/پایان impersonation در جدول admin_impersonation_log ثبت
 * می‌شود — این لاگ فقط برای خودِ ادمین‌ها قابل مشاهده است، هرگز به
 * کاربر نشان داده نمی‌شود؛ هدفش فقط پاسخ‌گویی داخلی است.
 * ------------------------------------------------------------------
 */

header('Content-Type: application/json');

require_once '../config/database.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'لطفاً وارد شوید']);
    exit();
}

function avapay_imp_is_admin($conn, $userId) {
    $stmt = $conn->prepare("SELECT is_admin FROM users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row && (int)$row['is_admin'] === 1;
}

$conn->query("CREATE TABLE IF NOT EXISTS `admin_impersonation_log` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `admin_id` INT NOT NULL,
    `target_user_id` INT NOT NULL,
    `started_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `ended_at` TIMESTAMP NULL DEFAULT NULL,
    INDEX idx_admin (admin_id),
    INDEX idx_target (target_user_id)
)");

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ==================== شروع ورود مخفیانه ====================
if ($action === 'start') {
    // اگر همین حالا هم در حال impersonate کردن است، یعنی این خودِ ادمینِ
    // واقعی نیست (بلکه یک impersonation در حال اجراست) — شناسه‌ی ادمین
    // واقعی را از همان‌جا برمی‌داریم، نه از سشن فعلی که موقتاً کاربر است.
    $realAdminId = (int)($_SESSION['impersonator_admin_id'] ?? $_SESSION['user_id']);

    if (!avapay_imp_is_admin($conn, $realAdminId)) {
        echo json_encode(['success' => false, 'message' => 'دسترسی غیرمجاز']);
        exit();
    }

    $targetUserId = (int)($_POST['user_id'] ?? 0);
    if ($targetUserId <= 0) {
        echo json_encode(['success' => false, 'message' => 'کاربر نامعتبر']);
        exit();
    }

    $chk = $conn->prepare("SELECT id, first_name, last_name, telegram_id FROM users WHERE id = ?");
    $chk->bind_param("i", $targetUserId);
    $chk->execute();
    $target = $chk->get_result()->fetch_assoc();
    if (!$target) {
        echo json_encode(['success' => false, 'message' => 'کاربر یافت نشد']);
        exit();
    }

    // ثبت شروع در لاگ داخلی (فقط برای پاسخ‌گویی ادمین‌ها — کاربر هرگز این را نمی‌بیند)
    $log = $conn->prepare("INSERT INTO admin_impersonation_log (admin_id, target_user_id) VALUES (?, ?)");
    $log->bind_param("ii", $realAdminId, $targetUserId);
    $log->execute();
    $_SESSION['impersonation_log_id'] = $conn->insert_id;

    $_SESSION['impersonator_admin_id']   = $realAdminId;
    $_SESSION['impersonator_admin_name'] = trim(($_SESSION['impersonator_admin_name'] ?? '')) ?: null;
    $_SESSION['user_id'] = $targetUserId;

    $fullName = trim(($target['first_name'] ?? '') . ' ' . ($target['last_name'] ?? '')) ?: ('@' . $target['telegram_id']);
    echo json_encode(['success' => true, 'redirect' => 'dashboard.php', 'target_name' => $fullName]);
    exit();
}

// ==================== پایان ورود مخفیانه (بازگشت به پنل ادمین) ====================
if ($action === 'stop') {
    if (empty($_SESSION['impersonator_admin_id'])) {
        echo json_encode(['success' => false, 'message' => 'در حال حاضر impersonation فعالی وجود ندارد']);
        exit();
    }

    $realAdminId = (int)$_SESSION['impersonator_admin_id'];

    if (!empty($_SESSION['impersonation_log_id'])) {
        $upd = $conn->prepare("UPDATE admin_impersonation_log SET ended_at = NOW() WHERE id = ?");
        $upd->bind_param("i", $_SESSION['impersonation_log_id']);
        $upd->execute();
    }

    $_SESSION['user_id'] = $realAdminId;
    unset($_SESSION['impersonator_admin_id'], $_SESSION['impersonator_admin_name'], $_SESSION['impersonation_log_id']);

    echo json_encode(['success' => true, 'redirect' => 'admin_panel.php']);
    exit();
}

// ==================== وضعیت فعلی (برای نمایش نوار «بازگشت به ادمین») ====================
if ($action === 'status') {
    if (empty($_SESSION['impersonator_admin_id'])) {
        echo json_encode(['success' => true, 'impersonating' => false]);
        exit();
    }
    $stmt = $conn->prepare("SELECT first_name, last_name, telegram_id FROM users WHERE id = ?");
    $curId = (int)$_SESSION['user_id'];
    $stmt->bind_param("i", $curId);
    $stmt->execute();
    $u = $stmt->get_result()->fetch_assoc();
    $name = $u ? (trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?: ('@' . $u['telegram_id'])) : '—';
    echo json_encode(['success' => true, 'impersonating' => true, 'target_name' => $name]);
    exit();
}

echo json_encode(['success' => false, 'message' => 'اکشن نامعتبر']);
