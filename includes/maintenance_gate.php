<?php
/**
 * includes/maintenance_gate.php
 * ---------------------------------------------------------------
 * گاردِ سراسری «حالت آپدیت». دقیقاً به همان روشِ includes/ban_guard.php:
 * از داخل config/database.php — یعنی نقطه‌ای که هر صفحه/API بدون استثنا
 * از آن عبور می‌کند — یک‌بار در هر درخواست اجرا می‌شود.
 *
 * وقتی ادمین از بخش «آپدیت سایت» (api/site_update_api.php) یک بسته‌ی زیپ
 * آپلود می‌کند، پیش از شروع جایگزینیِ فایل‌ها، این حالت فعال می‌شود؛ تمام
 * بازدیدکنندگانِ غیرادمین به‌جای صفحه‌ی درخواستی، پیامِ «سایت در حال
 * آپدیت است» با شمارش معکوس می‌بینند. خودِ ادمین (is_admin=1) همیشه
 * مستثناست تا بتواند در همان حین، پنل ادمین و حتی صفحات کاربری را برای
 * تستِ آپدیت باز کند.
 * ---------------------------------------------------------------
 */

if (!function_exists('avapay_maintenance_state_path')) {
    function avapay_maintenance_state_path(): string {
        $dir = null;
        if (!empty($_SERVER['DOCUMENT_ROOT'])) {
            $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\');
            if (is_dir($docRoot) && is_writable($docRoot)) $dir = $docRoot . '/backups';
        }
        // (هماهنگ با avapay_backup_dir() در cron_db_backup.php): این فایل در
        // includes/ است، یعنی یک پوشه پایین‌تر از ریشه‌ی سایت؛ برای رسیدن به
        // همان «یک پوشه بالاترِ ریشه‌ی سایت» که آن تابع به‌عنوان fallback
        // برمی‌گرداند، باید دو سطح از __DIR__ بالا رفت، نه یک سطح.
        if (!$dir) $dir = dirname(dirname(__DIR__)) . '/backups';
        if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
        return $dir . '/maintenance_state.json';
    }
}

if (!function_exists('avapay_maintenance_read')) {
    function avapay_maintenance_read(): array {
        $path = avapay_maintenance_state_path();
        if (!is_file($path)) return ['active' => false];
        $raw = @file_get_contents($path);
        $d = $raw ? json_decode($raw, true) : null;
        return is_array($d) ? $d : ['active' => false];
    }
}

if (!function_exists('avapay_maintenance_write')) {
    function avapay_maintenance_write(array $state): void {
        $path = avapay_maintenance_state_path();
        @file_put_contents($path, json_encode($state, JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
}

if (!function_exists('avapay_maintenance_app_base_url')) {
    /**
     * مسیر پایه‌ی اپ را با توجه به عمق فایلِ درحال‌اجرا حساب می‌کند — چه
     * صفحه‌ای در ریشه‌ی اپ باشد (dashboard.php) چه داخل api/ — تا آدرسِ
     * صحیحِ api/site_update_api.php برای polling ساخته شود، مستقل از اینکه
     * اپ در ریشه‌ی هاست است یا داخل یک ساب‌فولدر.
     */
    function avapay_maintenance_app_base_url(): string {
        $scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '/');
        $inApi = (basename($scriptDir) === 'api');
        $appBase = $inApi ? dirname($scriptDir) : $scriptDir;
        $appBase = rtrim($appBase, '/');
        return $appBase === '' ? '' : $appBase;
    }
}

if (!function_exists('avapay_render_maintenance_page')) {
    function avapay_render_maintenance_page(array $state): void {
        $message = $state['message'] ?? 'سایت در حال آپدیت است. لطفاً چند لحظه‌ی دیگر دوباره سر بزنید.';
        $reopenAt = $state['reopen_at'] ?? null;
        $reopenMs = $reopenAt ? (strtotime($reopenAt) * 1000) : 0;
        $statusUrl = avapay_maintenance_app_base_url() . '/api/site_update_api.php?action=status';
        ?><!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover">
<title>سایت در حال آپدیت است</title>
<style>
    *{ box-sizing:border-box; }
    body{
        margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center;
        background:radial-gradient(1200px 600px at 50% -10%, rgba(124,58,237,.25), transparent),#0A0520;
        color:#fff; font-family:'Vazirmatn','Segoe UI',Tahoma,sans-serif; padding:24px;
    }
    .wrap{
        max-width:420px; width:100%; text-align:center; background:rgba(255,255,255,.04);
        border:1px solid rgba(168,85,247,.28); border-radius:22px; padding:32px 24px;
        backdrop-filter:blur(16px);
    }
    .ico{
        width:72px; height:72px; margin:0 auto 18px; border-radius:20px; display:flex; align-items:center;
        justify-content:center; font-size:2rem; background:rgba(124,58,237,.18); border:1px solid rgba(168,85,247,.4);
        animation:spin 3s linear infinite;
    }
    @keyframes spin{ from{ transform:rotate(0deg); } to{ transform:rotate(360deg); } }
    h1{ font-size:1.15rem; margin:0 0 10px; }
    p{ font-size:.85rem; color:rgba(255,255,255,.65); line-height:1.9; margin:0 0 22px; }
    .cd{
        font-size:2.1rem; font-weight:900; direction:ltr; letter-spacing:2px;
        background:linear-gradient(90deg,#A855F7,#38BDF8); -webkit-background-clip:text;
        background-clip:text; -webkit-text-fill-color:transparent; margin-bottom:6px;
    }
    .cd-lbl{ font-size:.65rem; color:rgba(255,255,255,.4); font-weight:700; }
</style>
</head>
<body>
    <div class="wrap">
        <div class="ico">🛠️</div>
        <h1>سایت در حال آپدیت است</h1>
        <p><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></p>
        <div class="cd" id="avaMaintCd">--:--</div>
        <div class="cd-lbl">تا بازگشایی دوباره</div>
    </div>
    <script>
        const reopenAt = <?php echo (int)$reopenMs; ?>;
        const cdEl = document.getElementById('avaMaintCd');
        function tick(){
            if (!reopenAt){ cdEl.textContent = '...'; return; }
            const diff = Math.max(0, Math.floor((reopenAt - Date.now())/1000));
            const m = Math.floor(diff/60), s = diff%60;
            cdEl.textContent = String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0');
        }
        async function checkStatus(){
            try {
                const res = await fetch(<?php echo json_encode($statusUrl); ?>, { cache:'no-store' });
                const d = await res.json();
                const stillActive = (d && (d.maintenance === true || d.active === true));
                if (!stillActive) { location.reload(); }
            } catch(e){}
        }
        tick();
        setInterval(tick, 1000);
        setInterval(checkStatus, 5000);
    </script>
</body>
</html>
<?php
    }
}

if (!function_exists('avapay_maintenance_guard')) {
    function avapay_maintenance_guard($conn): void {
        if (PHP_SAPI === 'cli')                        return;
        if (defined('AVAPAY_MAINTENANCE_GUARD_RAN'))    return;
        define('AVAPAY_MAINTENANCE_GUARD_RAN', 1);

        $state = avapay_maintenance_read();
        if (empty($state['active'])) return;

        // ادمین همیشه مستثناست
        if ($conn instanceof mysqli && session_status() === PHP_SESSION_ACTIVE) {
            $uid = (int)($_SESSION['user_id'] ?? 0);
            if ($uid > 0) {
                try {
                    $r = $conn->query("SELECT is_admin FROM users WHERE id = {$uid}");
                    $row = $r ? $r->fetch_assoc() : null;
                    if ($row && (int)$row['is_admin'] === 1) return;
                } catch (\Throwable $e) {}
            }
        }

        while (ob_get_level()) { ob_end_clean(); }
        http_response_code(503);
        header('Retry-After: 60');

        $isJson = function_exists('avapay_ban_is_json_request') ? avapay_ban_is_json_request() : (stripos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false);
        if ($isJson) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success'     => false,
                'maintenance' => true,
                'active'      => true,
                'message'     => $state['message'] ?? 'سایت در حال آپدیت است',
                'reopen_at'   => $state['reopen_at'] ?? null,
            ], JSON_UNESCAPED_UNICODE);
            exit();
        }

        avapay_render_maintenance_page($state);
        exit();
    }
}
