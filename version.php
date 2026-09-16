<?php
// /ledor/version.php
// مدیریت نسخه PWA با شناسایی مستقیم آیدی ادمین

// شروع سشن
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


// تعریف آیدی تلگرام ادمین


// بررسی وجود فایل کانفیگ
$configFile = __DIR__ . '/config/database.php';
if (!file_exists($configFile)) {
    die('Config file not found: ' . $configFile);
}

require_once $configFile;
require_once __DIR__ . '/includes/upload_paths.php';

// بررسی لاگین بودن کاربر
if (!isset($_SESSION['user_id'])) {
    error_log("Version.php: User not logged in");
    header('Location: login.php');
    exit();
}

$userId = $_SESSION['user_id'];
error_log("Version.php: Checking admin access for user ID: " . $userId);

// ========== بررسی ادمین بودن با اولویت ==========
$isAdmin = false;
$userTelegramId = null;

// دریافت اطلاعات کاربر از دیتابیس
$sql = "SELECT id, first_name, last_name, telegram_id, is_admin FROM users WHERE id = ?";
$stmt = $conn->prepare($sql);

if ($stmt) {
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        $userTelegramId = $user['telegram_id'];
        
        // لاگ برای دیباگ
        error_log("User Data: ID={$user['id']}, Name={$user['first_name']} {$user['last_name']}, Telegram={$userTelegramId}, is_admin={$user['is_admin']}");
        
        // **بررسی ادمین بودن با سه روش:**
        // 1. بررسی is_admin در دیتابیس
        $isAdminFromDb = ($user['is_admin'] == 1);
        
        // 2. بررسی آیدی تلگرام خاص ادمین
        $isSpecialAdmin = ($userTelegramId == ADMIN_TELEGRAM_ID);
        
        // 3. بررسی سشن (اگر قبلاً ذخیره شده)
        $isAdminFromSession = isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true;
        
        // ترکیب نتایج
        $isAdmin = $isAdminFromDb || $isSpecialAdmin || $isAdminFromSession;
        
        error_log("Admin Check Results:");
        error_log("  - is_admin in DB: " . ($isAdminFromDb ? "YES" : "NO"));
        error_log("  - Special Telegram ID: " . ($isSpecialAdmin ? "YES" : "NO"));
        error_log("  - is_admin in Session: " . ($isAdminFromSession ? "YES" : "NO"));
        error_log("  - Final Result: " . ($isAdmin ? "IS ADMIN ✅" : "NOT ADMIN ❌"));
        
        // اگر کاربر با آیدی تلگرام خاص است اما is_admin در دیتابیس 0 است، آن را اصلاح کن
        if ($isSpecialAdmin && !$isAdminFromDb) {
            error_log("Updating user to admin in database...");
            $updateSql = "UPDATE users SET is_admin = 1 WHERE id = ?";
            $updateStmt = $conn->prepare($updateSql);
            if ($updateStmt) {
                $updateStmt->bind_param("i", $userId);
                $updateStmt->execute();
                $updateStmt->close();
                error_log("User updated to admin in database");
                
                // به‌روزرسانی سشن
                $_SESSION['is_admin'] = true;
            }
        }
    } else {
        error_log("User not found in database for ID: " . $userId);
    }
    $stmt->close();
} else {
    error_log("Failed to prepare SQL statement");
}

// اگر کاربر ادمین نیست، پیغام خطا نمایش بده
if (!$isAdmin) {
    // نمایش پیغام خطا به جای ریدایرکت (برای دیباگ)
    ?>
    <!DOCTYPE html>
    <html lang="fa" dir="rtl">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>دسترسی غیرمجاز - AvaPay</title>
        <style>
            body {
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                background: linear-gradient(135deg, #1A0B2E 0%, #2A0D3F 100%);
                color: white;
                display: flex;
                justify-content: center;
                align-items: center;
                min-height: 100vh;
                margin: 0;
                padding: 20px;
            }
            .error-container {
                max-width: 500px;
                background: rgba(255, 255, 255, 0.1);
                backdrop-filter: blur(10px);
                border-radius: 20px;
                padding: 40px;
                text-align: center;
                border: 1px solid rgba(255, 59, 48, 0.5);
            }
            .error-icon {
                font-size: 4rem;
                color: #FF3B30;
                margin-bottom: 20px;
            }
            h1 {
                font-size: 1.8rem;
                margin-bottom: 15px;
            }
            p {
                color: #B8B8D1;
                margin-bottom: 10px;
                line-height: 1.6;
            }
            .user-info {
                background: rgba(0, 0, 0, 0.3);
                border-radius: 10px;
                padding: 15px;
                margin: 20px 0;
                text-align: right;
            }
            .user-info p {
                margin: 5px 0;
                font-family: monospace;
            }
            .btn {
                display: inline-block;
                background: linear-gradient(45deg, #6C40C5, #FF4D8D);
                color: white;
                text-decoration: none;
                padding: 12px 30px;
                border-radius: 10px;
                margin-top: 20px;
                transition: all 0.3s ease;
            }
            .btn:hover {
                transform: translateY(-2px);
                box-shadow: 0 5px 15px rgba(108, 64, 197, 0.4);
            }
        </style>
    </head>
    <body>
        <div class="error-container">
            <div class="error-icon">
                <i class="fas fa-lock"></i>
            </div>
            <h1>⛔ دسترسی غیرمجاز</h1>
            <p>شما دسترسی به این بخش را ندارید.</p>
            <div class="user-info">
                <p><strong>🆔 شناسه کاربری:</strong> <?php echo htmlspecialchars($userId); ?></p>
                <p><strong>📱 آیدی تلگرام:</strong> <?php echo htmlspecialchars($userTelegramId ?? 'نامشخص'); ?></p>
                <p><strong>👑 آیدی ادمین مورد نیاز:</strong> <?php echo ADMIN_TELEGRAM_ID; ?></p>
            </div>
            <p>لطفاً با حساب کاربری ادمین وارد شوید.</p>
            <a href="dashboard.php" class="btn">
                <i class="fas fa-arrow-left"></i> بازگشت به داشبورد
            </a>
        </div>
        
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    </body>
    </html>
    <?php
    exit();
}

// ========== ادامه کد اصلی مدیریت نسخه ==========

// دریافت نسخه فعلی
$versionFile = __DIR__ . '/version.json';
$currentVersion = '1.0.0';

if (file_exists($versionFile)) {
    $versionData = json_decode(file_get_contents($versionFile), true);
    $currentVersion = $versionData['version'] ?? '1.0.0';
}

// خواندن اطلاعات لوگوی فعلی (برای حفظ آن در صورتی که ادمین لوگوی جدید آپلود نکند)
$existingVersionData = file_exists($versionFile) ? json_decode(file_get_contents($versionFile), true) : [];
$currentLogoUrl = $existingVersionData['logo_url'] ?? null;
$currentLogoVersion = isset($existingVersionData['logo_version']) ? (int)$existingVersionData['logo_version'] : 0;

// پردازش آپدیت نسخه
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['version'])) {
    $newVersion = trim($_POST['version']);
    $updateType = $_POST['update_type'] ?? 'manual';
    $updateMessage = trim($_POST['update_message']) ?: 'نسخه جدید AvaPay با بهبودهای جدید منتشر شد';
    $forceUpdate = isset($_POST['force_update']) ? true : false;
    $minVersion = trim($_POST['min_version']) ?: '1.0.0';

    // ========== پردازش آپلود لوگوی جدید (اختیاری) ==========
    $newLogoUrl = $currentLogoUrl;
    $newLogoVersion = $currentLogoVersion;

    if (isset($_FILES['logo_file']) && $_FILES['logo_file']['error'] === UPLOAD_ERR_OK) {
        $logoFile = $_FILES['logo_file'];
        $logoExt = strtolower(pathinfo($logoFile['name'], PATHINFO_EXTENSION));
        $allowedLogoExt = ['png', 'jpg', 'jpeg', 'webp', 'svg'];

        if (!in_array($logoExt, $allowedLogoExt)) {
            $error = 'فرمت فایل لوگو نامعتبر است. فرمت‌های مجاز: PNG, JPG, JPEG, WEBP, SVG';
        } elseif ($logoFile['size'] > 3 * 1024 * 1024) {
            $error = 'حجم فایل لوگو نباید بیشتر از 3 مگابایت باشد';
        } else {
            // طبق درخواست: آپلود واقعی بیرون از پوشه‌ی کد، در public_html/avapay_uploads/logo
            $logoUploadDir = avapay_upload_dir('logo');

            $logoFileName = 'logo_' . time() . '_' . substr(md5(mt_rand()), 0, 8) . '.' . $logoExt;
            $logoDestination = $logoUploadDir . $logoFileName;

            if (move_uploaded_file($logoFile['tmp_name'], $logoDestination)) {
                // حذف لوگوی قبلی از دیسک (در صورت وجود) — هم مسیر جدید و هم
                // مسیر قدیمِ داخل /ledor/uploads/logo (برای لوگوهایی که پیش از
                // این تغییر آپلود شده بودند) چک می‌شود
                if (!empty($currentLogoUrl)) {
                    $oldRel = ltrim($currentLogoUrl, '/');
                    $oldSub = preg_replace('#^uploads/#', '', $oldRel);
                    $oldNewPath = rtrim(avapay_uploads_root(), '/') . '/' . $oldSub;
                    $oldLegacyPath = __DIR__ . '/' . $oldRel;
                    if (file_exists($oldNewPath))    @unlink($oldNewPath);
                    if (file_exists($oldLegacyPath)) @unlink($oldLegacyPath);
                }
                $newLogoUrl = 'uploads/logo/' . $logoFileName;
                $newLogoVersion = time();
            } else {
                $error = 'خطا در آپلود فایل لوگو. لطفاً مجوزهای پوشه‌ی آپلود را بررسی کنید.';
            }
        }
    }

    // اعتبارسنجی فرمت نسخه
    if (!empty($error)) {
        // خطای آپلود لوگو از مرحله قبل - چیزی بیشتر انجام نده
    } elseif (!preg_match('/^\d+\.\d+\.\d+$/', $newVersion)) {
        $error = 'فرمت نسخه نامعتبر. فرمت صحیح: major.minor.patch (مثال: 2.0.1)';
    } elseif ($newVersion === $currentVersion && $newLogoUrl === $currentLogoUrl) {
        $error = 'نسخه وارد شده با نسخه فعلی یکسان است و لوگوی جدیدی هم آپلود نشده است';
    } else {
        // ذخیره نسخه جدید
        $versionData = [
            'version' => $newVersion,
            'previous_version' => $currentVersion,
            'release_date' => date('Y-m-d H:i:s'),
            'update_message' => $updateMessage,
            'update_type' => $updateType,
             'force_update' => true, // همیشه true
            'min_version' => $minVersion,
            'logo_url' => $newLogoUrl,
            'logo_version' => $newLogoVersion
        ];
        
        if (file_put_contents($versionFile, json_encode($versionData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
            // ثبت در دیتابیس
            $checkTableSql = "SHOW TABLES LIKE 'pwa_update_logs'";
            $tableExists = $conn->query($checkTableSql);
            
            if ($tableExists && $tableExists->num_rows > 0) {
                $logSql = "INSERT INTO pwa_update_logs (version, message, updated_by, created_at) 
                           VALUES (?, ?, ?, NOW())";
                $logStmt = $conn->prepare($logSql);
                if ($logStmt) {
                    $logStmt->bind_param("ssi", $newVersion, $updateMessage, $userId);
                    $logStmt->execute();
                    $logStmt->close();
                }
            }
            
            $success = "✅ نسخه با موفقیت به {$newVersion} آپدیت شد";
            if ($newLogoUrl !== $currentLogoUrl) {
                $success .= " و لوگوی جدید نیز ثبت شد";
            }
            $currentVersion = $newVersion;
            $currentLogoUrl = $newLogoUrl;
            $currentLogoVersion = $newLogoVersion;
            
            // ارسال نوتیفیکیشن به تلگرام ادمین
            if (function_exists('sendTelegramMessage')) {
                $message = "🚀 **نسخه جدید PWA منتشر شد!**\n\n";
                $message .= "📦 **نسخه:** v{$newVersion}\n";
                $message .= "📝 **نوع:** " . getUpdateTypeName($updateType) . "\n";
                $message .= "💬 **پیام:** {$updateMessage}\n";
                $message .= "⏰ **زمان:** " . date('Y-m-d H:i:s') . "\n";
                $message .= "👨‍💼 **توسط:** Admin\n\n";
                $message .= "کاربران در حال دریافت آپدیت هستند...";
                
                sendTelegramMessage(ADMIN_TELEGRAM_ID, $message);
            }
        } else {
            $error = 'خطا در ذخیره فایل نسخه. لطفاً مجوزهای پوشه را بررسی کنید.';
        }
    }
}

// دریافت لاگ آپدیت‌ها
$logs = [];
$checkTableSql = "SHOW TABLES LIKE 'pwa_update_logs'";
$tableExists = $conn->query($checkTableSql);

if ($tableExists && $tableExists->num_rows > 0) {
    $logsSql = "SELECT * FROM pwa_update_logs ORDER BY created_at DESC LIMIT 10";
    $logsResult = $conn->query($logsSql);
    if ($logsResult) {
        $logs = $logsResult->fetch_all(MYSQLI_ASSOC);
    }
}

// تابع کمکی برای نام نوع آپدیت
function getUpdateTypeName($type) {
    switch($type) {
        case 'major': return '🚀 آپدیت اصلی';
        case 'minor': return '✨ آپدیت جزئی';
        case 'patch': return '🐛 رفع اشکال';
        case 'security': return '🔒 امنیتی';
        default: return '📦 معمولی';
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>مدیریت نسخه PWA - AvaPay</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .version-manager {
            max-width: 800px;
            margin: 30px auto;
            padding: 20px;
        }
        
        .current-version {
            background: linear-gradient(135deg, #6C40C5, #FF4D8D);
            border-radius: 20px;
            padding: 30px;
            text-align: center;
            margin-bottom: 30px;
        }
        
        .version-number {
            font-size: 3rem;
            font-weight: bold;
            margin: 10px 0;
        }
        
        .update-form {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 30px;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #B8B8D1;
        }
        
        .form-control {
            width: 100%;
            padding: 12px 15px;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            color: white;
            font-size: 1rem;
            transition: all 0.3s ease;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #6C40C5;
            box-shadow: 0 0 0 3px rgba(108, 64, 197, 0.2);
        }
        
        .form-control option {
            background: #1A0B2E;
        }
        
        .version-badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: bold;
        }
        
        .version-badge.major {
            background: #FF3B30;
            color: white;
        }
        
        .version-badge.minor {
            background: #FFC107;
            color: #000;
        }
        
        .version-badge.patch {
            background: #4CD964;
            color: #000;
        }
        
        .version-badge.security {
            background: #007AFF;
            color: white;
        }
        
        .logs-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .logs-table th,
        .logs-table td {
            padding: 12px;
            text-align: right;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .logs-table th {
            color: #B8B8D1;
            font-weight: 600;
        }
        
        .btn-update {
            background: linear-gradient(45deg, #6C40C5, #FF4D8D);
            border: none;
            padding: 14px 28px;
            border-radius: 12px;
            color: white;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s ease;
            width: 100%;
            font-size: 1rem;
        }
        
        .btn-update:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(108, 64, 197, 0.4);
        }
        
        .alert {
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .alert-success {
            background: rgba(76, 217, 100, 0.2);
            border: 1px solid #4CD964;
            color: #4CD964;
        }
        
        .alert-error {
            background: rgba(255, 59, 48, 0.2);
            border: 1px solid #FF3B30;
            color: #FF3B30;
        }
        
        .checkbox-label {
            display: flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
        }
        
        .checkbox-label input {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
        
        .admin-nav a:hover,
        .admin-nav a.active {
            background: #6C40C5;
        }
        
        small {
            display: block;
            margin-top: 5px;
            color: #B8B8D1;
            font-size: 0.8rem;
        }
        
        @media (max-width: 600px) {
            .version-manager {
                padding: 15px;
            }
            
            .logs-table {
                font-size: 0.85rem;
            }
            
            .logs-table th,
            .logs-table td {
                padding: 8px;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard">
        <div class="header">
            <div class="greeting">
                <h1><i class="fas fa-code-branch"></i> مدیریت نسخه PWA</h1>
                <p>کنترل نسخه و مدیریت آپدیت‌های برنامه</p>
            </div>
            <a href="dashboard.php" style="color: var(--text-light); text-decoration: none;">
                <i class="fas fa-arrow-left" style="font-size: 1.5rem;"></i>
            </a>
        </div>
        
        
        
        <div class="version-manager">
            <?php if (!empty($success)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo $success; ?>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($error)): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
            </div>
            <?php endif; ?>
            
            <div class="current-version">
                <i class="fas fa-rocket" style="font-size: 2rem;"></i>
                <div class="version-number">v<?php echo htmlspecialchars($currentVersion); ?></div>
                <div>نسخه فعلی برنامه</div>
                <div style="margin-top: 10px; font-size: 0.85rem; opacity: 0.8;">
                    آخرین آپدیت: <?php echo date('Y/m/d H:i:s'); ?>
                </div>
            </div>
            
            <div class="update-form">
                <h3><i class="fas fa-cloud-upload-alt"></i> انتشار نسخه جدید</h3>
                <?php if (!empty($currentLogoUrl)): ?>
                <div style="display:flex;align-items:center;gap:15px;margin-bottom:20px;background:rgba(255,255,255,0.05);border-radius:14px;padding:15px;">
                    <img src="/ledor/<?php echo htmlspecialchars($currentLogoUrl); ?>?v=<?php echo (int)$currentLogoVersion; ?>" alt="لوگوی فعلی" style="width:60px;height:60px;border-radius:12px;object-fit:contain;background:rgba(255,255,255,0.08);">
                    <div>
                        <strong>لوگوی فعلی برنامه</strong>
                        <div style="font-size:.8rem;color:#B8B8D1;">این لوگو هم‌اکنون در برنامه نمایش داده می‌شود</div>
                    </div>
                </div>
                <?php endif; ?>
                <form method="POST" action="" enctype="multipart/form-data">
                    <div class="form-group">
                        <label><i class="fas fa-image"></i> لوگوی جدید (اختیاری)</label>
                        <input type="file" name="logo_file" class="form-control" accept=".png,.jpg,.jpeg,.webp,.svg">
                        <small>اگر لوگوی جدیدی انتخاب کنید، همراه با این آپدیت برای همه کاربران اعمال می‌شود. فرمت‌های مجاز: PNG, JPG, WEBP, SVG — حداکثر 3 مگابایت</small>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-tag"></i> شماره نسخه جدید</label>
                        <input type="text" name="version" class="form-control" 
                               placeholder="مثال: 2.0.1" required pattern="\d+\.\d+\.\d+">
                        <small>فرمت: major.minor.patch (مثال: 2.0.1)</small>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-chart-line"></i> نوع آپدیت</label>
                        <select name="update_type" class="form-control">
                            <option value="patch">🐛 آپدیت رفع اشکال (Patch)</option>
                            <option value="minor">✨ آپدیت جزئی (Minor)</option>
                            <option value="major">🚀 آپدیت اصلی (Major)</option>
                            <option value="security">🔒 آپدیت امنیتی</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-comment"></i> پیام آپدیت</label>
                        <textarea name="update_message" class="form-control" rows="4" 
                                  placeholder="توضیحات تغییرات جدید...">✨ بهبود عملکرد و رفع باگ‌ها
🚀 افزایش سرعت بارگذاری
🔒 بهبود امنیت</textarea>
                        <small>این پیام به کاربران نمایش داده می‌شود</small>
                    </div>
                    
                    <div class="form-group">
                        <label class="checkbox-label">
                            <input type="checkbox" name="force_update" value="1">
                            <span><i class="fas fa-exclamation-triangle"></i> آپدیت اجباری</span>
                        </label>
                        <small>اگر فعال باشد، کاربران بدون آپدیت نمی‌توانند از برنامه استفاده کنند</small>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-arrow-down"></i> حداقل نسخه مورد نیاز</label>
                        <input type="text" name="min_version" class="form-control" 
                               value="1.0.0" placeholder="حداقل نسخه مورد نیاز">
                        <small>کاربران با نسخه کمتر از این نمی‌توانند وارد شوند</small>
                    </div>
                    
                    <button type="submit" class="btn-update">
                        <i class="fas fa-cloud-upload-alt"></i> انتشار نسخه جدید
                    </button>
                </form>
            </div>
            
            <div class="update-form">
                <h3><i class="fas fa-history"></i> تاریخچه آپدیت‌ها</h3>
                <?php if (count($logs) > 0): ?>
                <table class="logs-table">
                    <thead>
                        <tr>
                            <th>نسخه</th>
                            <th>نوع</th>
                            <th>پیام</th>
                            <th>تاریخ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): 
                            $typeClass = 'patch';
                            $typeIcon = '🐛';
                            if (strpos($log['message'], 'اصلی') !== false || strpos($log['message'], 'Major') !== false) {
                                $typeClass = 'major';
                                $typeIcon = '🚀';
                            } elseif (strpos($log['message'], 'جزئی') !== false || strpos($log['message'], 'Minor') !== false) {
                                $typeClass = 'minor';
                                $typeIcon = '✨';
                            } elseif (strpos($log['message'], 'امنیتی') !== false) {
                                $typeClass = 'security';
                                $typeIcon = '🔒';
                            }
                        ?>
                        <tr>
                            <td><strong>v<?php echo htmlspecialchars($log['version']); ?></strong></td>
                            <td><span class="version-badge <?php echo $typeClass; ?>"><?php echo $typeIcon; ?></span></td>
                            <td style="max-width: 300px;"><?php echo htmlspecialchars(mb_substr($log['message'], 0, 50)); ?></td>
                            <td><?php echo date('Y/m/d H:i', strtotime($log['created_at'])); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <div style="text-align: center; padding: 40px; color: #B8B8D1;">
                    <i class="fas fa-history" style="font-size: 3rem; margin-bottom: 10px;"></i>
                    <p>هنوز آپدیتی ثبت نشده است</p>
                </div>
                <?php endif; ?>
            </div>
        
            </div>
        </div>
    </div>
    
    <script src="assets/js/app.js"></script>
    <script>
        // نمایش نوتیفیکیشن
        function showToast(message, type) {
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            toast.innerHTML = `<i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'}"></i> ${message}`;
            document.body.appendChild(toast);
            
            setTimeout(() => toast.classList.add('show'), 10);
            setTimeout(() => {
                toast.classList.remove('show');
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }
        
        // اعتبارسنجی فرم
        document.querySelector('form').addEventListener('submit', function(e) {
            const version = this.querySelector('[name="version"]').value;
            const versionPattern = /^\d+\.\d+\.\d+$/;
            
            if (!versionPattern.test(version)) {
                e.preventDefault();
                showToast('فرمت نسخه نامعتبر است. فرمت صحیح: major.minor.patch', 'error');
            }
        });
    </script>
</body>
</html>