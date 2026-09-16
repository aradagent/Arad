<?php
require_once __DIR__ . '/../includes/session_boot.php';   // سشن ماندگار ۳۰ روزه (قبل از هر session_start)
require_once '../config/database.php';
require_once __DIR__ . '/../includes/referral_system.php';

// برای دیباگ
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');
$response = ['success' => false, 'message' => ''];

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Start session
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // دیباگ: چک کنیم چه دیتایی می‌آید
        error_log("POST Data: " . print_r($_POST, true));
        error_log("FILES Data: " . print_r($_FILES, true));
        
        if (isset($_POST['telegram_id'])) {
            $telegramId = trim($_POST['telegram_id']);
            $firstName = trim($_POST['first_name'] ?? '');
            $lastName = trim($_POST['last_name'] ?? '');
            $phoneNumber = trim($_POST['phone_number'] ?? '');
            
            // Validation
            if (empty($firstName) || empty($lastName) || empty($phoneNumber)) {
                $response['message'] = 'All fields are required (First Name, Last Name, Phone Number)';
                echo json_encode($response);
                exit();
            }
            
            // Check if Telegram ID already exists
            $checkSql = "SELECT id FROM users WHERE telegram_id = ?";
            $checkStmt = $conn->prepare($checkSql);
            $checkStmt->bind_param("s", $telegramId);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();
            
            if ($checkResult->num_rows > 0) {
                $response['message'] = 'This Telegram ID is already registered';
                $checkStmt->close();
                echo json_encode($response);
                exit();
            }
            $checkStmt->close();
            
            // Generate unique account number and IBAN
            $accountNumber = generateAccountNumber();
            $ibanNumber = generateIBAN();
            
            // Handle avatar upload
            $avatar = 'default-avatar.png';
            if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = avapay_upload_dir('avatars');
                
                $fileName = $_FILES['avatar']['name'];
                $fileTmp = $_FILES['avatar']['tmp_name'];
                $fileSize = $_FILES['avatar']['size'];
                $fileError = $_FILES['avatar']['error'];
                
                // Check file size (max 5MB)
                if ($fileSize > 5 * 1024 * 1024) {
                    $response['message'] = 'File is too large (max 5MB)';
                    echo json_encode($response);
                    exit();
                }
                
                // Get file extension
                $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                
                if (in_array($fileExt, $allowedExtensions)) {
                    // Generate unique filename
                    $newFileName = 'avatar_' . time() . '_' . uniqid() . '.' . $fileExt;
                    $filePath = $uploadDir . $newFileName;
                    
                    // Check if file is actually an image
                    $check = getimagesize($fileTmp);
                    if ($check !== false) {
                        if (move_uploaded_file($fileTmp, $filePath)) {
                            $avatar = 'uploads/avatars/' . $newFileName;
                        } else {
                            error_log("Failed to move uploaded file");
                        }
                    } else {
                        error_log("File is not an image");
                    }
                } else {
                    error_log("Invalid file extension: " . $fileExt);
                }
            } else {
                error_log("No file uploaded or upload error: " . ($_FILES['avatar']['error'] ?? 'No file'));
            }
            
            // Insert new user
            $sql = "INSERT INTO users (
                telegram_id, 
                iban_number, 
                account_number, 
                first_name, 
                last_name, 
                phone_number, 
                avatar,
                created_at,
                updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
            
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                $response['message'] = 'Database error: ' . $conn->error;
                echo json_encode($response);
                exit();
            }
            
            $stmt->bind_param(
                "sssssss",
                $telegramId,
                $ibanNumber,
                $accountNumber,
                $firstName,
                $lastName,
                $phoneNumber,
                $avatar
            );
            
            if ($stmt->execute()) {
                $userId = $stmt->insert_id;
                
                // ---- زیرمجموعه‌گیری: اگر ثبت‌نام از لینک دعوت آمده باشد،
                // جایزه‌ی خوش‌آمدگویی به کیف‌پول یورویی هم معرف و هم خودِ
                // کاربر تازه‌ثبت‌نام‌شده واریز می‌شود ----
                $refCode = trim((string)($_POST['ref_code'] ?? ''));
                $referralBonusEur = 0.0;
                if ($refCode !== '') {
                    try {
                        $referralBonusEur = (float) ava_ref_register_new_user($conn, $userId, $refCode);
                    } catch (Exception $e) {
                        error_log('referral welcome bonus error (web register): ' . $e->getMessage());
                    }
                }

                // Set session
                $_SESSION['user_id'] = $userId;
                $_SESSION['telegram_id'] = $telegramId;
                $_SESSION['first_name'] = $firstName;
                $_SESSION['last_name'] = $lastName;
                $_SESSION['account_number'] = $accountNumber;
                $_SESSION['is_admin'] = false;
                $_SESSION['last_activity'] = time();

                // ---------------------------------------------------------
                // ساخت توکن ۳۰ روزه + کوکی auth_token تا کاربر تازه‌ثبت‌نام‌شده
                // در مرورگرهای درون‌برنامه‌ای (تلگرام و ...) لاگین بماند
                // ---------------------------------------------------------
                try {
                    $conn->query("CREATE TABLE IF NOT EXISTS sessions (
                        id INT PRIMARY KEY AUTO_INCREMENT,
                        user_id INT NOT NULL,
                        token VARCHAR(255) NOT NULL,
                        expires_at DATETIME NOT NULL,
                        ip_address VARCHAR(45),
                        user_agent TEXT,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        UNIQUE KEY unique_token (token)
                    )");

                    $regToken   = bin2hex(random_bytes(32));
                    $regExpires = date('Y-m-d H:i:s', time() + (defined('AVAPAY_SESSION_LIFETIME') ? AVAPAY_SESSION_LIFETIME : 60*60*24*30));
                    $regIp      = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                    $regUa      = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

                    $sst = $conn->prepare("INSERT INTO sessions (user_id, token, expires_at, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)");
                    if ($sst) {
                        $sst->bind_param("issss", $userId, $regToken, $regExpires, $regIp, $regUa);
                        $sst->execute();
                        $sst->close();
                    }

                    $secureCookie = (
                        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
                        (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
                    );
                    @setcookie('auth_token', $regToken, [
                        'expires'  => time() + (defined('AVAPAY_SESSION_LIFETIME') ? AVAPAY_SESSION_LIFETIME : 60*60*24*30),
                        'path'     => '/',
                        'secure'   => $secureCookie,
                        'httponly' => false,
                        'samesite' => 'Lax',
                    ]);
                    $response['token'] = $regToken;
                } catch (Exception $e) {
                    error_log('Register token error: ' . $e->getMessage());
                }

                $response['success'] = true;
                $response['message'] = 'Registration successful!';
                if ($referralBonusEur > 0) {
                    $response['referral_bonus_eur'] = $referralBonusEur;
                }
                $response['user'] = [
                    'id' => $userId,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'account_number' => $accountNumber,
                    'iban_number' => $ibanNumber,
                    'avatar' => $avatar
                ];
                
                // Send Telegram notification
                $message = "🎉 New user registered:\n👤 {$firstName} {$lastName}\n📱 ID: {$telegramId}\n📞 Phone: {$phoneNumber}\n💳 Account: {$accountNumber}";
                sendTelegramMessage(ADMIN_TELEGRAM_ID, $message);
                
            } else {
                $response['message'] = 'Registration failed: ' . $stmt->error;
            }
            
            $stmt->close();
        } else {
            $response['message'] = 'Telegram ID is required';
        }
    } else {
        $response['message'] = 'Invalid request method. Use POST.';
    }
} catch (Exception $e) {
    $response['message'] = 'Server error: ' . $e->getMessage();
    error_log("Registration error: " . $e->getMessage());
}

echo json_encode($response);

// Close connection
if (isset($conn)) {
    $conn->close();
}
?>