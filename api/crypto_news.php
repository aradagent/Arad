<?php
// api/crypto_news.php
// ------------------------------------------------------------------
// اخبار کریپتو (انگلیسی) از فیدهای RSS معتبر + لایک کاربران
//
// action=list          -> فهرست اخبار (کش ۱۰ دقیقه‌ای) + وضعیت لایک کاربر
// action=like (POST)   -> تاگل لایک: {id: "<hash>"}
// ------------------------------------------------------------------

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../config/database.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$userId = (int)($_SESSION['user_id'] ?? 0);
$action = $_GET['action'] ?? 'list';

// جدول لایک‌ها (خودکار)
@$conn->query("CREATE TABLE IF NOT EXISTS `crypto_news_likes` (
    `news_hash` VARCHAR(32) NOT NULL,
    `user_id`   INT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`news_hash`, `user_id`)
) DEFAULT CHARSET=utf8mb4");

/* ---------- تاگل لایک ---------- */
if ($action === 'like') {
    if ($userId <= 0) { http_response_code(401); echo json_encode(['error' => 'login required']); exit; }
    $in   = json_decode(file_get_contents('php://input'), true) ?: [];
    $hash = preg_replace('/[^a-f0-9]/', '', (string)($in['id'] ?? ''));
    if (strlen($hash) !== 32) { http_response_code(400); echo json_encode(['error' => 'bad id']); exit; }

    $st = $conn->prepare("SELECT 1 FROM crypto_news_likes WHERE news_hash = ? AND user_id = ?");
    $st->bind_param("si", $hash, $userId); $st->execute();
    $liked = $st->get_result()->num_rows > 0;

    if ($liked) {
        $st = $conn->prepare("DELETE FROM crypto_news_likes WHERE news_hash = ? AND user_id = ?");
    } else {
        $st = $conn->prepare("INSERT IGNORE INTO crypto_news_likes (news_hash, user_id) VALUES (?, ?)");
    }
    $st->bind_param("si", $hash, $userId); $st->execute();

    $st = $conn->prepare("SELECT COUNT(*) c FROM crypto_news_likes WHERE news_hash = ?");
    $st->bind_param("s", $hash); $st->execute();
    $cnt = (int)($st->get_result()->fetch_assoc()['c'] ?? 0);
    echo json_encode(['success' => true, 'liked' => !$liked, 'likes' => $cnt]); exit;
}

/* ---------- فهرست اخبار ---------- */
$cacheFile  = sys_get_temp_dir() . '/ava_crypto_news.json';
$CACHE_FRESH = 600;          // ۱۰ دقیقه: کش تازه
$CACHE_KEEP  = 4 * 86400;   // ۴ روز: حداکثر ماندگاری

// اگر فایل کش بیش از ۴ روز پیر است پاک کن
if (is_file($cacheFile) && (time() - filemtime($cacheFile)) > $CACHE_KEEP) {
    @unlink($cacheFile);
}

$items = null;

// کش معتبر (۱۰ دقیقه)
if (is_readable($cacheFile) && (time() - filemtime($cacheFile)) < $CACHE_FRESH) {
    $items = json_decode(@file_get_contents($cacheFile), true);
}

if (!is_array($items) || empty($items)) {
    $items = [];
    $feeds = [
        ['url' => 'https://www.coindesk.com/arc/outboundfeeds/rss/', 'src' => 'CoinDesk'],
        ['url' => 'https://cointelegraph.com/rss',                    'src' => 'Cointelegraph'],
        ['url' => 'https://decrypt.co/feed',                          'src' => 'Decrypt'],
    ];
    foreach ($feeds as $f) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $f['url'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (AvaPay News Reader)',
        ]);
        $xmlRaw = curl_exec($ch);
        curl_close($ch);
        if (!$xmlRaw) continue;

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlRaw, 'SimpleXMLElement', LIBXML_NOCDATA);
        if (!$xml || !isset($xml->channel->item)) continue;

        $n = 0;
        foreach ($xml->channel->item as $it) {
            if (++$n > 12) break; // از هر منبع حداکثر ۱۲ خبر
            $link  = trim((string)$it->link);
            $title = trim((string)$it->title);
            if ($link === '' || $title === '') continue;

            // خلاصه: description بدون تگ
            $desc = trim(strip_tags((string)$it->description));
            $desc = html_entity_decode($desc, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (mb_strlen($desc) > 900) $desc = mb_substr($desc, 0, 900) . '…';

            // تصویر: media:content یا enclosure
            $img = '';
            $media = $it->children('media', true);
            if ($media && isset($media->content)) {
                $img = (string)$media->content->attributes()->url;
            }
            if ($img === '' && isset($it->enclosure)) {
                $eu = (string)$it->enclosure->attributes()->url;
                if (preg_match('/\.(jpe?g|png|webp|gif)(\?|$)/i', $eu)) $img = $eu;
            }

            $items[] = [
                'id'      => md5($link),
                'title'   => $title,
                'summary' => $desc,
                'link'    => $link,
                'image'   => $img,
                'source'  => $f['src'],
                'date'    => date('Y-m-d H:i', strtotime((string)$it->pubDate) ?: time()),
                'ts'      => strtotime((string)$it->pubDate) ?: time(),
            ];
        }
    }

    // جدیدترین اول + سقف ۳۰ خبر
    usort($items, function($a, $b){ return ($b['ts'] ?? 0) <=> ($a['ts'] ?? 0); });
    $items = array_slice($items, 0, 30);

    if (!empty($items)) {
        @file_put_contents($cacheFile, json_encode($items, JSON_UNESCAPED_UNICODE), LOCK_EX);
    } elseif (is_readable($cacheFile)) {
        // شبکه در دسترس نبود → کش قدیمی بهتر از هیچ است
        $items = json_decode(@file_get_contents($cacheFile), true) ?: [];
    }
}

// شمارش لایک‌ها + وضعیت لایک کاربر جاری (یک کوئری برای همه)
$likeCount = []; $likedByMe = [];
if (!empty($items)) {
    $hashes = array_map(function($i){ return "'" . $i['id'] . "'"; }, $items);
    $inSql  = implode(',', $hashes);
    $q = @$conn->query("SELECT news_hash, COUNT(*) c, MAX(user_id = {$userId}) me
                        FROM crypto_news_likes WHERE news_hash IN ({$inSql}) GROUP BY news_hash");
    if ($q) while ($r = $q->fetch_assoc()) {
        $likeCount[$r['news_hash']] = (int)$r['c'];
        $likedByMe[$r['news_hash']] = ((int)$r['me'] === 1);
    }
}
foreach ($items as &$i) {
    $i['likes'] = $likeCount[$i['id']] ?? 0;
    $i['liked'] = $likedByMe[$i['id']] ?? false;
    unset($i['ts']);
}
unset($i);

echo json_encode(['success' => true, 'items' => $items], JSON_UNESCAPED_UNICODE);
