<?php
// api/kyc_api.php
// احراز هویت کاربران: ثبت اطلاعات + عکس سلفی با کارت شناسایی + تایید ادمین
header('Content-Type: application/json');

require_once '../config/database.php';
require_once __DIR__ . '/../includes/notify_helper.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}
$userId = (int)$_SESSION['user_id'];
$action = $_GET['action'] ?? '';

// جدول درخواست‌های احراز هویت
$conn->query("CREATE TABLE IF NOT EXISTS `kyc_requests` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `user_id` INT NOT NULL,
    `first_name` VARCHAR(100) NOT NULL,
    `last_name` VARCHAR(100) NOT NULL,
    `email` VARCHAR(190) NOT NULL,
    `phone` VARCHAR(30) NOT NULL,
    `selfie_image` VARCHAR(255) DEFAULT NULL,
    `status` ENUM('pending','approved','rejected') DEFAULT 'pending',
    `admin_note` VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `reviewed_at` TIMESTAMP NULL DEFAULT NULL,
    INDEX idx_user (user_id),
    INDEX idx_status (status)
)");

// ستون وضعیت احراز روی users
$col = $conn->query("SHOW COLUMNS FROM users LIKE 'kyc_status'");
if (!$col || $col->num_rows === 0) {
    $conn->query("ALTER TABLE users ADD COLUMN kyc_status ENUM('none','pending','approved','rejected') DEFAULT 'none'");
}

function isAdminUser($conn, $uid) {
    $r = $conn->query("SELECT is_admin FROM users WHERE id = " . intval($uid));
    $row = $r ? $r->fetch_assoc() : null;
    return $row && (int)$row['is_admin'] === 1;
}

/* ---------- کاربر: وضعیت فعلی ---------- */
if ($action === 'status') {
    $stmt = $conn->prepare("SELECT id, first_name, last_name, email, phone, selfie_image, status, admin_note, created_at
                            FROM kyc_requests WHERE user_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if ($row && !empty($row['selfie_image'])) $row['selfie_image'] = '/ledor/' . ltrim($row['selfie_image'], '/');
    echo json_encode(['success' => true, 'request' => $row]);
    exit();
}

/* ---------- کاربر: مرحله ۱ - ثبت اطلاعات ---------- */
if ($action === 'submit_info' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data  = json_decode(file_get_contents('php://input'), true);
    $first = trim($data['first_name'] ?? '');
    $last  = trim($data['last_name'] ?? '');
    $email = trim($data['email'] ?? '');
    $phone = trim($data['phone'] ?? '');

    if ($first === '' || $last === '' || $email === '' || $phone === '') {
        echo json_encode(['success' => false, 'message' => 'همه فیلدها الزامی است']);
        exit();
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'ایمیل معتبر نیست']);
        exit();
    }

    // اگر درخواست pending موجود است، همان را به‌روزرسانی کن؛ وگرنه جدید بساز
    $stmt = $conn->prepare("SELECT id, status FROM kyc_requests WHERE user_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();

    if ($existing && $existing['status'] === 'approved') {
        echo json_encode(['success' => false, 'message' => 'حساب شما قبلاً تایید شده است']);
        exit();
    }

    if ($existing && $existing['status'] === 'pending') {
        $stmt = $conn->prepare("UPDATE kyc_requests SET first_name=?, last_name=?, email=?, phone=? WHERE id=?");
        $stmt->bind_param("ssssi", $first, $last, $email, $phone, $existing['id']);
        $stmt->execute();
        $reqId = (int)$existing['id'];
    } else {
        $stmt = $conn->prepare("INSERT INTO kyc_requests (user_id, first_name, last_name, email, phone) VALUES (?,?,?,?,?)");
        $stmt->bind_param("issss", $userId, $first, $last, $email, $phone);
        $stmt->execute();
        $reqId = $conn->insert_id;
    }
    $conn->query("UPDATE users SET kyc_status = 'pending' WHERE id = $userId");

    echo json_encode(['success' => true, 'request_id' => $reqId, 'message' => 'اطلاعات ثبت شد؛ حالا عکس را ارسال کنید']);
    exit();
}

/* ---------- کاربر: مرحله ۲ - آپلود سلفی با کارت ---------- */
if ($action === 'submit_photo' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_FILES['selfie']['name']) || $_FILES['selfie']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'عکس ارسال نشد']);
        exit();
    }
    $stmt = $conn->prepare("SELECT id FROM kyc_requests WHERE user_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $req = $stmt->get_result()->fetch_assoc();
    if (!$req) {
        echo json_encode(['success' => false, 'message' => 'ابتدا اطلاعات مرحله اول را ثبت کنید']);
        exit();
    }

    $uploadDir = avapay_upload_dir('kyc');
    $ext = strtolower(pathinfo($_FILES['selfie']['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg','jpeg','png','webp'];
    if (!in_array($ext, $allowed)) {
        echo json_encode(['success' => false, 'message' => 'فرمت عکس مجاز نیست (jpg/png/webp)']);
        exit();
    }
    if ($_FILES['selfie']['size'] > 8 * 1024 * 1024) {
        echo json_encode(['success' => false, 'message' => 'حجم عکس حداکثر ۸ مگابایت']);
        exit();
    }
    $fname = 'kyc_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($_FILES['selfie']['tmp_name'], $uploadDir . $fname)) {
        echo json_encode(['success' => false, 'message' => 'خطا در ذخیره عکس']);
        exit();
    }
    $path = 'uploads/kyc/' . $fname;
    $stmt = $conn->prepare("UPDATE kyc_requests SET selfie_image = ? WHERE id = ?");
    $stmt->bind_param("si", $path, $req['id']);
    $stmt->execute();

    echo json_encode(['success' => true, 'message' => 'درخواست احراز هویت شما ثبت شد و در انتظار بررسی ادمین است']);
    exit();
}

/* ================= ادمین ================= */
if (!isAdminUser($conn, $userId)) {
    echo json_encode(['success' => false, 'message' => 'دسترسی غیرمجاز']);
    exit();
}

/* ---------- ادمین: لیست درخواست‌ها ---------- */
if ($action === 'admin_list') {
    $status = $_GET['status'] ?? 'pending';
    $allowed = ['pending','approved','rejected',''];
    if (!in_array($status, $allowed, true)) $status = 'pending';
    $sql = "SELECT k.*, u.account_number FROM kyc_requests k JOIN users u ON k.user_id = u.id";
    if ($status !== '') $sql .= " WHERE k.status = '" . $conn->real_escape_string($status) . "'";
    $sql .= " ORDER BY k.created_at DESC LIMIT 100";
    $res = $conn->query($sql);
    $rows = [];
    while ($row = $res->fetch_assoc()) {
        if (!empty($row['selfie_image'])) $row['selfie_image'] = '/ledor/' . ltrim($row['selfie_image'], '/');
        $rows[] = $row;
    }
    echo json_encode(['success' => true, 'requests' => $rows]);
    exit();
}

/* ---------- ادمین: تایید / رد ---------- */
if ($action === 'admin_review' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data   = json_decode(file_get_contents('php://input'), true);
    $reqId  = intval($data['request_id'] ?? 0);
    $decide = ($data['decision'] ?? '') === 'approve' ? 'approved' : 'rejected';
    $note   = trim($data['note'] ?? '');

    $stmt = $conn->prepare("SELECT user_id, first_name, last_name FROM kyc_requests WHERE id = ?");
    $stmt->bind_param("i", $reqId);
    $stmt->execute();
    $req = $stmt->get_result()->fetch_assoc();
    if (!$req) { echo json_encode(['success' => false, 'message' => 'درخواست یافت نشد']); exit(); }

    $stmt = $conn->prepare("UPDATE kyc_requests SET status = ?, admin_note = ?, reviewed_at = NOW() WHERE id = ?");
    $stmt->bind_param("ssi", $decide, $note, $reqId);
    $stmt->execute();

    $targetUser = (int)$req['user_id'];

    if ($decide === 'approved') {
        // نام و نام‌خانوادگی واردشده در احراز هویت، نام رسمی و غیرقابل‌تغییرِ پروفایل می‌شود
        $kFirst = $req['first_name'];
        $kLast  = $req['last_name'];
        $up = $conn->prepare("UPDATE users SET kyc_status = 'approved', first_name = ?, last_name = ?, updated_at = NOW() WHERE id = ?");
        $up->bind_param("ssi", $kFirst, $kLast, $targetUser);
        $up->execute();
    } else {
        $conn->query("UPDATE users SET kyc_status = '$decide' WHERE id = $targetUser");
    }

    // اطلاع‌رسانی به کاربر (push + email + telegram + db)
    $title = $decide === 'approved' ? '✅ احراز هویت شما تایید شد' : '❌ احراز هویت شما رد شد';
    $body  = $decide === 'approved'
        ? 'هویت شما با موفقیت تایید شد.'
        : ('درخواست احراز هویت شما رد شد.' . ($note !== '' ? " دلیل: $note" : ''));
    if (function_exists('notifyUser')) {
        notifyUser($conn, $targetUser, $title, $body, [
            'type' => 'kyc_' . $decide,
            'url'  => '/ledor/profile.php'
        ]);
    }

    echo json_encode(['success' => true, 'status' => $decide]);
    exit();
}

/* ---------- ادمین: جستجو/لیست کاربران برای ویرایش اطلاعات ---------- */
if ($action === 'admin_search_users') {
    $search = isset($_GET['search']) ? '%' . $_GET['search'] . '%' : '%%';
    $sql = "SELECT id, first_name, last_name, email, phone_number, telegram_id, kyc_status
            FROM users
            WHERE first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR phone_number LIKE ? OR telegram_id LIKE ?
            ORDER BY id DESC
            LIMIT 200";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sssss", $search, $search, $search, $search, $search);
    $stmt->execute();
    $result = $stmt->get_result();
    $users = [];
    while ($row = $result->fetch_assoc()) { $users[] = $row; }
    echo json_encode(['success' => true, 'users' => $users]);
    exit();
}

/* ---------- ادمین: اطلاعات کامل یک کاربر برای فرم ویرایش ---------- */
if ($action === 'admin_get_user') {
    $targetId = intval($_GET['user_id'] ?? 0);
    if ($targetId <= 0) { echo json_encode(['success' => false, 'message' => 'کاربر نامعتبر است']); exit(); }
    $stmt = $conn->prepare("SELECT id, first_name, last_name, email, phone_number, telegram_id, account_number, kyc_status
                            FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $targetId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) { echo json_encode(['success' => false, 'message' => 'کاربر یافت نشد']); exit(); }
    echo json_encode(['success' => true, 'user' => $row]);
    exit();
}

/* ---------- ادمین: به‌روزرسانی اطلاعات یک کاربر (از بخش KYC) ----------
 * این ویرایش دستی ادمین است — درست مثل ویرایش خودِ کاربر از پروفایلش،
 * از این به بعد این مقدار همان‌جا می‌ماند و با ورود بعدی از تلگرام هرگز
 * بازنویسی نمی‌شود (طبق منطق telegram_entry.php). */
if ($action === 'admin_update_user' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $targetId = intval($data['user_id'] ?? 0);
    if ($targetId <= 0) { echo json_encode(['success' => false, 'message' => 'کاربر نامعتبر است']); exit(); }

    $first = trim((string)($data['first_name'] ?? ''));
    $last  = trim((string)($data['last_name'] ?? ''));
    $email = trim((string)($data['email'] ?? ''));
    $phone = trim((string)($data['phone_number'] ?? ''));
    $kyc   = (string)($data['kyc_status'] ?? '');

    if ($first === '' || $last === '') {
        echo json_encode(['success' => false, 'message' => 'نام و نام‌خانوادگی الزامی است']);
        exit();
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'ایمیل معتبر نیست']);
        exit();
    }
    $allowedKyc = ['none', 'pending', 'approved', 'rejected'];
    if (!in_array($kyc, $allowedKyc, true)) { $kyc = 'none'; }

    $stmt = $conn->prepare("UPDATE users SET first_name = ?, last_name = ?, email = ?, phone_number = ?, kyc_status = ?, updated_at = NOW() WHERE id = ?");
    $stmt->bind_param("sssssi", $first, $last, $email, $phone, $kyc, $targetId);
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'اطلاعات کاربر با موفقیت به‌روزرسانی شد']);
    } else {
        echo json_encode(['success' => false, 'message' => 'خطا در به‌روزرسانی: ' . $conn->error]);
    }
    exit();
}

/* ---------- ادمین: حذف کامل کاربر ----------
 * طبق دستور صریح ادمین: با زدن این دکمه، کاربر باید در هر صورت واقعاً از
 * جدول users حذف شود — نه غیرفعال‌سازی، نه ناشناس‌سازی.
 *
 * مشکلی که قبلاً باعث می‌شد این دکمه برای هیچ کاربر واقعی‌ای کار نکند:
 * چند جدول (ad_deals، ad_offers, transactions, user_contacts, user_discounts)
 * روی users.id فارین‌کیِ RESTRICT دارند (بدون ON DELETE)، یعنی دیتابیس
 * تا وقتی رکورد وابسته‌ای وجود دارد به‌طور کامل از حذف ردیف کاربر جلوگیری
 * می‌کند. راه‌حل: پیش از حذف، این قیدها را (فقط یک‌بار، به‌صورت خودترمیم و
 * ایمن) به ON DELETE SET NULL تغییر می‌دهیم — یعنی وقتی کاربر حذف شد،
 * ستون‌های مربوط در آن معاملات/تراکنش‌های قدیمی NULL می‌شوند (سابقه‌ی خودِ
 * معامله و سهم طرف مقابل دست‌نخورده می‌ماند)، ولی خودِ ردیف کاربر واقعاً
 * از دیتابیس پاک می‌شود، همان‌طور که خواسته شده. */
if (!function_exists('avapay_fix_user_fk_for_delete')) {
    function avapay_fix_user_fk_for_delete($conn, $table, $column) {
        // نام واقعیِ قید را از information_schema پیدا می‌کنیم (به‌جای فرض کردن
        // نامی مثل ad_deals_ibfk_3) تا مستقل از این باشد که روی سرور واقعی چه نامی دارد.
        $q = $conn->query("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}' AND COLUMN_NAME = '{$column}'
              AND REFERENCED_TABLE_NAME = 'users' LIMIT 1");
        $row = $q ? $q->fetch_assoc() : null;
        if (!$row || empty($row['CONSTRAINT_NAME'])) return; // فارین‌کی‌ای پیدا نشد (شاید قبلاً حذف/تغییر کرده)
        $constraintName = $row['CONSTRAINT_NAME'];

        $ruleQ = $conn->query("SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}' AND CONSTRAINT_NAME = '{$constraintName}'");
        $rule = $ruleQ ? ($ruleQ->fetch_assoc()['DELETE_RULE'] ?? null) : null;
        if ($rule === 'SET NULL') return; // قبلاً درست شده، کاری لازم نیست

        try {
            $conn->query("ALTER TABLE `{$table}` MODIFY `{$column}` INT NULL");
            $conn->query("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$constraintName}`");
            $conn->query("ALTER TABLE `{$table}` ADD CONSTRAINT `{$constraintName}` FOREIGN KEY (`{$column}`) REFERENCES `users`(`id`) ON DELETE SET NULL");
        } catch (\Throwable $e) {
            error_log("AvaPay: FK fix failed for {$table}.{$column}: " . $e->getMessage());
        }
    }
}

if ($action === 'admin_delete_user' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $targetId = intval($data['user_id'] ?? 0);
    if ($targetId <= 0) { echo json_encode(['success' => false, 'message' => 'کاربر نامعتبر است']); exit(); }
    if ($targetId === $userId) { echo json_encode(['success' => false, 'message' => 'نمی‌توانید حساب خودتان را حذف کنید']); exit(); }

    $chk = $conn->query("SELECT id, first_name, last_name, telegram_id, is_admin FROM users WHERE id = " . $targetId);
    $target = $chk ? $chk->fetch_assoc() : null;
    if (!$target) { echo json_encode(['success' => false, 'message' => 'کاربر یافت نشد']); exit(); }
    if ((int)$target['is_admin'] === 1) {
        echo json_encode(['success' => false, 'message' => 'حذف حساب‌های ادمین از این بخش مجاز نیست']);
        exit();
    }

    // یک رشته‌ی تأییدی که فرانت باید دقیقاً برابر با شماره‌ی کاربر ارسال کند
    // (لایه‌ی دوم محافظت در برابر کلیک اشتباهی، جدا از تأیید در رابط کاربری)
    $confirm = trim((string)($data['confirm'] ?? ''));
    if ($confirm !== (string)$targetId) {
        echo json_encode(['success' => false, 'message' => 'تأیید حذف نامعتبر است']);
        exit();
    }

    // خودترمیمی قیدهای فارین‌کی (فقط اولین بار روی هر جدول واقعاً ALTER می‌زند،
    // دفعات بعد چون DELETE_RULE از قبل SET NULL است بلافاصله رد می‌شود)
    avapay_fix_user_fk_for_delete($conn, 'ad_deals', 'buyer_id');
    avapay_fix_user_fk_for_delete($conn, 'ad_deals', 'seller_id');
    avapay_fix_user_fk_for_delete($conn, 'ad_offers', 'buyer_id');
    avapay_fix_user_fk_for_delete($conn, 'ad_offers', 'seller_id');
    avapay_fix_user_fk_for_delete($conn, 'transactions', 'sender_id');
    avapay_fix_user_fk_for_delete($conn, 'transactions', 'receiver_id');
    avapay_fix_user_fk_for_delete($conn, 'transactions', 'admin_id');
    avapay_fix_user_fk_for_delete($conn, 'user_contacts', 'user_id');
    avapay_fix_user_fk_for_delete($conn, 'user_contacts', 'contact_id');
    avapay_fix_user_fk_for_delete($conn, 'user_discounts', 'created_by');

    $conn->begin_transaction();
    try {
        // جلسات و توکن‌های ورود فعال این کاربر
        @$conn->query("DELETE FROM sessions WHERE user_id = " . $targetId);
        if (!empty($target['telegram_id'])) {
            $tgEsc = $conn->real_escape_string($target['telegram_id']);
            @$conn->query("DELETE FROM telegram_login_tokens WHERE telegram_id = '{$tgEsc}'");
        }
        // آزاد کردن کد معرفِ این کاربر تا کاربر دیگری بتواند از آن استفاده مجدد کند
        @$conn->query("UPDATE users SET referred_by = NULL WHERE referred_by = " . $targetId);

        $name = trim(($target['first_name'] ?? '') . ' ' . ($target['last_name'] ?? ''));

        $del = $conn->prepare("DELETE FROM users WHERE id = ? LIMIT 1");
        $del->bind_param("i", $targetId);
        $del->execute();

        if ($del->affected_rows <= 0) { throw new Exception('حذف انجام نشد (هیچ ردیفی مطابقت نداشت)'); }

        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'حساب «' . $name . '» به‌طور کامل و برای همیشه حذف شد']);
    } catch (\Throwable $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'خطا در حذف حساب: ' . $e->getMessage()]);
    }
    exit();
}

/* ---------- ادمین: همگام‌سازی با همه‌ی اعضای ربات mainbot ----------
 * ربات (mainbot) یک سیستم کاملاً جدا دارد (دیتابیس aradexch_bot، جدول
 * account). هر کسی که فقط وارد ربات شده (حتی بدون احراز هویت) همان‌جا
 * یک ردیف account با chat_id/name/lastname/username می‌گیرد — یعنی خودِ
 * جدول account لیست کامل اعضای ربات است، نه فقط افراد verified=1.
 * این اکشن روی همه‌ی این اعضا حرکت می‌کند و برای هرکدام که از قبل در
 * اپ (users, بر اساس telegram_id) وجود دارد، نام/نام‌خانوادگی/یوزرنیم
 * را از همان پروفایل تلگرامشان می‌نویسد. وضعیت kyc_status='approved'
 * فقط برای verified=1 تنظیم می‌شود؛ برای بقیه تغییری در kyc_status داده
 * نمی‌شود. کاربرانی که هنوز اصلاً در اپ ثبت‌نام نکرده‌اند اینجا ساخته
 * نمی‌شوند (ساخت خودکار حساب فقط جایی معنی دارد که کاربر عملی مثل ثبت
 * آگهی انجام داده باشد — همان‌طور که در ثبت آگهی mainbot اضافه شده). */
if ($action === 'admin_sync_bot_kyc' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../includes/fast_mysqli.php';
    $botDb = avapay_fast_mysqli('localhost', 'aradexch_bot', 'dA!G&&aSq7-1', 'aradexch_bot', 3);
    if (!$botDb) {
        echo json_encode(['success' => false, 'message' => 'اتصال به دیتابیس ربات برقرار نشد (یا بیش از حد طول کشید)']);
        exit();
    }

    // اطمینان از وجود ستون telegram_username روی users اپ (خودترمیم‌شونده،
    // طبق قاعده‌ی همیشگی: قبل از ALTER حتماً وجود ستون چک شود)
    $colChk = $conn->query("SHOW COLUMNS FROM users LIKE 'telegram_username'");
    if ($colChk && $colChk->num_rows === 0) {
        $conn->query("ALTER TABLE users ADD COLUMN telegram_username VARCHAR(64) DEFAULT NULL AFTER last_name");
    }

    $checked = 0; $updated = 0; $membersFound = 0;
    $res = $botDb->query("SELECT chat_id, name, lastname, username, phone, verified FROM account WHERE chat_id IS NOT NULL");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $checked++;
            $tgId     = (string)$row['chat_id'];
            $first    = trim((string)($row['name'] ?? ''));
            $last     = trim((string)($row['lastname'] ?? ''));
            $username = trim((string)($row['username'] ?? ''));
            if ($username === 'null') { $username = ''; } // مقدار پیش‌فرض ربات برای «بدون یوزرنیم»
            $phone    = trim((string)($row['phone'] ?? ''));
            $isVerified = ((string)($row['verified'] ?? '')) === '1';
            if ($tgId === '') continue;
            $membersFound++;

            $sets = ['updated_at = NOW()'];
            $types = ''; $vals = [];
            if ($first !== '') { $sets[] = 'first_name = ?'; $types .= 's'; $vals[] = $first; }
            if ($last  !== '') { $sets[] = 'last_name = ?';  $types .= 's'; $vals[] = $last; }
            $sets[] = 'telegram_username = ?'; $types .= 's'; $vals[] = ($username !== '' ? $username : null);
            if ($isVerified) { $sets[] = "kyc_status = 'approved'"; }

            $sql = "UPDATE users SET " . implode(', ', $sets) . " WHERE telegram_id = ?";
            $upd = $conn->prepare($sql);
            if (!$upd) continue;
            $types .= 's'; $vals[] = $tgId;
            $upd->bind_param($types, ...$vals);
            $upd->execute();
            if ($upd->affected_rows > 0) $updated++;
            $upd->close();

            // شماره تلفن فقط وقتی از ربات پر می‌شود که کاربر تا الان خودش
            // شماره‌ای در اپ ثبت نکرده باشد (مثل منطق نام: یک‌بار، نه بازنویسی).
            if ($phone !== '') {
                $conn->query("UPDATE users SET phone_number = '" . $conn->real_escape_string($phone) . "'
                              WHERE telegram_id = '" . $conn->real_escape_string($tgId) . "' AND (phone_number IS NULL OR phone_number = '')");
            }
        }
    }
    $botDb->close();

    echo json_encode(['success' => true, 'checked' => $checked, 'updated' => $updated, 'members_found' => $membersFound,
        'message' => "اعضای ربات بررسی‌شده: {$checked} — پروفایل‌های به‌روزشده در اپ: {$updated}"]);
    exit();
}

/* ---------- ادمین: دانلود و تنظیم خودکار عکس پروفایل تلگرام برای کاربران ----------
 * هدف این اکشن: عکس پروفایل هر کاربر در AvaPay دقیقاً همان عکس پروفایل
 * فعلی‌اش در تلگرام (که همان mainbot است، چون هر دو از یک BOT_TOKEN
 * استفاده می‌کنند) باشد. برخلاف نسخه‌ی قبلی، این اکشن دیگر فقط کاربرانی
 * که آواتار خالی دارند را پوشش نمی‌دهد — هر بار که ادمین دکمه را بزند،
 * آواتار همه‌ی کاربران دارای telegram_id دوباره از تلگرام کشیده و
 * بازنویسی می‌شود تا واقعاً «همگام» بماند، نه فقط «یک‌بار پر شود».
 * اگر کاربری در تلگرام هیچ عکس عمومی نداشته باشد (getUserProfilePhotos
 * خالی برگرداند)، آواتارش را به لوگوی خودِ AvaPay تنظیم می‌کنیم (نه یک
 * آیکون خالی/کاربر عمومی) — طبق درخواست صریح ادمین.
 * برای جلوگیری از تایم‌اوت روی هاست‌های اشتراکی، هر بار حداکثر روی یک
 * دسته (batch) از کاربران اجرا می‌شود؛ فرانت‌اند این اکشن را پشت‌سرهم
 * صدا می‌زند تا کل کاربران پوشش داده شوند. برای این‌که هر بار دسته‌ی
 * *بعدی* کاربران گرفته شود (نه همیشه همان ۲۵ نفر اول، حالا که فیلتر
 * «آواتار خالی» را دیگر نداریم)، ستون self-healing جدید
 * avatar_synced_at روی هر تلاش (موفق یا ناموفق) به‌روز می‌شود و
 * کوئری همیشه قدیمی‌ترین‌ها را اول برمی‌دارد؛ فرانت‌اند یک run_started
 * ثابت برای کل اجرای دکمه می‌فرستد تا «remaining» به‌درستی به صفر برسد
 * (کاربرانی که همین الان تازه sync شدند دوباره در همین اجرا شمرده
 * نشوند). */
/* ---------------------------------------------------------------------------
 * دریافت یک آدرس با cURL.
 * قبلاً از file_get_contents استفاده می‌شد؛ اما روی اکثر هاست‌ها
 * allow_url_fopen خاموش است (و مشکلات SSL هم دارد)، بنابراین همه‌ی
 * درخواست‌ها بی‌صدا false برمی‌گرداندند و همگام‌سازی عکس پروفایل هرگز
 * کار نمی‌کرد. بقیه‌ی بخش‌های پروژه هم از cURL استفاده می‌کنند.
 * ------------------------------------------------------------------------- */
if (!function_exists('kyc_http_get')) {
    function kyc_http_get($url, &$err = null) {
        $err = null;
        if (!function_exists('curl_init')) {
            // اگر cURL نبود، به روش قدیمی برمی‌گردیم
            $r = @file_get_contents($url);
            if ($r === false) $err = 'curl و allow_url_fopen هر دو در دسترس نیستند';
            return $r;
        }
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 25);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $out  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($out === false) { $err = 'curl: ' . curl_error($ch); }
        elseif ($code >= 400) { $err = 'HTTP ' . $code; $out = false; }
        curl_close($ch);
        return $out;
    }
}

if ($action === 'admin_sync_telegram_avatars' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $botToken = defined('BOT_TOKEN') ? BOT_TOKEN : '';
    if ($botToken === '') {
        echo json_encode(['success' => false, 'message' => 'توکن ربات تنظیم نشده است']);
        exit();
    }

    // ستون خودترمیم‌شونده برای پیگیری آخرین باری که آواتار هر کاربر
    // sync شده — طبق قاعده‌ی همیشگی: قبل از ALTER حتماً وجود ستون چک شود
    $colChk = $conn->query("SHOW COLUMNS FROM users LIKE 'avatar_synced_at'");
    if ($colChk && $colChk->num_rows === 0) {
        $conn->query("ALTER TABLE users ADD COLUMN avatar_synced_at DATETIME NULL DEFAULT NULL AFTER avatar");
    }

    require_once __DIR__ . '/../includes/logo_helper.php';
    $__logo = getAppLogo();
    // مسیر لوگوی AvaPay برای کاربرانی که در تلگرام هیچ عکس عمومی ندارند —
    // اگر ادمین لوگوی سفارشی تنظیم کرده همان، وگرنه لوگوی پیش‌فرض ریشه‌ی پروژه
    $logoAvatarPath = $__logo['url'] ?? 'AVAPAY.PNG';

    $batchSize = 25;
    $runStarted = isset($_GET['run_started']) ? $conn->real_escape_string($_GET['run_started']) : null;

    $res = $conn->query("SELECT id, telegram_id FROM users
                          WHERE telegram_id IS NOT NULL AND telegram_id <> ''
                          ORDER BY (avatar_synced_at IS NULL) DESC, avatar_synced_at ASC
                          LIMIT {$batchSize}");
    $checked = 0; $updated = 0; $skipped = 0;
    $netErr = null; $lastNetErr = null;
    $uploadsDir = rtrim(avapay_upload_dir('avatars'), '/');

    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $checked++;
            $uid = (int)$row['id'];
            $tgId = (string)$row['telegram_id'];
            $gotPhoto = false;
            $hadNetError = false;

            try {
                $photosJson = kyc_http_get("https://api.telegram.org/bot{$botToken}/getUserProfilePhotos?user_id=" . urlencode($tgId) . "&limit=1", $netErr);
                if ($photosJson === false && $netErr) { $lastNetErr = $netErr; $hadNetError = true; }
                $sizes = null;
                if ($photosJson !== false && $photosJson !== '') {
                    $photos = json_decode($photosJson, true);
                    $sizes = is_array($photos) ? ($photos['result']['photos'][0] ?? null) : null;
                }

                if (!empty($sizes) && is_array($sizes)) {
                    $largest = end($sizes); // بزرگ‌ترین سایز، آخرین آیتم آرایه است
                    $fileId = $largest['file_id'] ?? null;

                    if ($fileId) {
                        $fileInfoJson = kyc_http_get("https://api.telegram.org/bot{$botToken}/getFile?file_id=" . urlencode($fileId), $netErr);
                        if ($fileInfoJson === false && $netErr) { $lastNetErr = $netErr; $hadNetError = true; }
                        $fileInfoArr = ($fileInfoJson !== false && $fileInfoJson !== '') ? json_decode($fileInfoJson, true) : null;
                        $filePath = is_array($fileInfoArr) ? ($fileInfoArr['result']['file_path'] ?? null) : null;

                        if ($filePath) {
                            $imgData = kyc_http_get("https://api.telegram.org/file/bot{$botToken}/{$filePath}", $netErr);
                            if ($imgData === false && $netErr) { $lastNetErr = $netErr; $hadNetError = true; }

                            if ($imgData !== false && strlen($imgData) >= 100) {
                                $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
                                if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) { $ext = 'jpg'; }
                                $fname = 'tg_' . preg_replace('/[^a-zA-Z0-9]/', '', $tgId) . '_' . time() . '.' . $ext;
                                $fullPath = $uploadsDir . '/' . $fname;

                                if (@file_put_contents($fullPath, $imgData) !== false && @is_file($fullPath) && @filesize($fullPath) >= 100) {
                                    $avatarPath = 'uploads/avatars/' . $fname;
                                    $upd = $conn->prepare("UPDATE users SET avatar = ?, avatar_synced_at = NOW(), updated_at = NOW() WHERE id = ?");
                                    if ($upd) {
                                        $upd->bind_param("si", $avatarPath, $uid);
                                        $upd->execute();
                                        if ($upd->affected_rows > 0) { $updated++; $gotPhoto = true; } else { @unlink($fullPath); }
                                        $upd->close();
                                    } else {
                                        @unlink($fullPath);
                                    }
                                } else {
                                    @unlink($fullPath);
                                }
                            }
                        }
                    }
                }

                if (!$gotPhoto) {
                    if ($hadNetError) {
                        // خطای واقعی شبکه/ارتباط با تلگرام — آواتار فعلی دست‌نخورده می‌ماند
                        // (نمی‌خواهیم یک قطعی موقت شبکه، عکس معتبر قبلی کاربر را با لوگو جایگزین کند)
                        $conn->query("UPDATE users SET avatar_synced_at = NOW() WHERE id = " . $uid);
                    } else {
                        // تلگرام با موفقیت پاسخ داد و این کاربر واقعاً هیچ عکس پروفایل عمومی‌ای ندارد
                        // → طبق درخواست ادمین، آواتارش را لوگوی AvaPay می‌گذاریم
                        $upd = $conn->prepare("UPDATE users SET avatar = ?, avatar_synced_at = NOW(), updated_at = NOW() WHERE id = ?");
                        if ($upd) {
                            $upd->bind_param("si", $logoAvatarPath, $uid);
                            $upd->execute();
                            $upd->close();
                        }
                    }
                    $skipped++;
                }
            } catch (Throwable $e) {
                error_log('admin_sync_telegram_avatars item failed (non-fatal): ' . $e->getMessage());
                $conn->query("UPDATE users SET avatar_synced_at = NOW() WHERE id = " . $uid);
                $skipped++;
            }
        }
    }

    $remaining = 0;
    if ($runStarted !== null && $runStarted !== '') {
        $remRes = $conn->query("SELECT COUNT(*) AS c FROM users
                                 WHERE telegram_id IS NOT NULL AND telegram_id <> ''
                                   AND (avatar_synced_at IS NULL OR avatar_synced_at < '{$runStarted}')");
    } else {
        // اگر فرانت‌اند run_started نفرستد (سازگاری با نسخه‌ی قدیمی)، حداقل یک تخمین
        // معقول برمی‌گردانیم؛ ممکن است دقیقاً صفر نشود، اما دیگر حلقه‌ی بی‌نهایت رخ نمی‌دهد
        // چون هر کاربر با هر تلاش avatar_synced_at خودش را می‌گیرد و به انتهای صف می‌رود.
        $remRes = $conn->query("SELECT COUNT(*) AS c FROM users WHERE telegram_id IS NOT NULL AND telegram_id <> '' AND avatar_synced_at IS NULL");
    }
    if ($remRes) { $remaining = (int)($remRes->fetch_assoc()['c'] ?? 0); }

    $msg = "این دسته: بررسی {$checked} — عکس تلگرام تنظیم‌شد {$updated} — بدون عکس/لوگوی AvaPay {$skipped}"
         . ($remaining > 0 ? " — {$remaining} کاربر دیگر باقی مانده، دوباره بزنید" : " — همه انجام شد");
    if ($updated === 0 && $lastNetErr) {
        $msg .= " — ⚠️ خطای ارتباط با تلگرام: {$lastNetErr}";
    }

    echo json_encode([
        'success' => true, 'checked' => $checked, 'updated' => $updated, 'skipped' => $skipped, 'remaining' => $remaining,
        'net_error' => $lastNetErr,
        'message' => $msg,
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

/* ---------- ادمین: حذف درخواست ---------- */
if ($action === 'admin_delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data  = json_decode(file_get_contents('php://input'), true);
    $reqId = intval($data['request_id'] ?? 0);
    $r = $conn->query("SELECT selfie_image FROM kyc_requests WHERE id = $reqId");
    $row = $r ? $r->fetch_assoc() : null;
    if ($row && !empty($row['selfie_image'])) @unlink('../' . $row['selfie_image']);
    $conn->query("DELETE FROM kyc_requests WHERE id = $reqId");
    echo json_encode(['success' => true]);
    exit();
}

echo json_encode(['success' => false, 'message' => 'Invalid action']);
