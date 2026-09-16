<?php
// api/iran_econ_news.php
// ------------------------------------------------------------------
// اخبار اقتصادی ایران از فیدهای RSS سایت‌های خبری ایرانی + لایک کاربران
//
// action=list          -> فهرست اخبار (کش ۱۰ دقیقه‌ای) + وضعیت لایک کاربر
// action=full&id=...   -> متن کامل/ادامه‌ی خبر (واکشی از صفحه‌ی منبع + کش)
// action=like (POST)   -> تاگل لایک: {id: "<hash>"}
//
// نکته: اگر فیدی در دسترس نبود، نادیده گرفته می‌شود؛ فهرست فیدها را
// می‌توانید در آرایه‌ی $feeds پایین‌تر ویرایش کنید.
// ------------------------------------------------------------------

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../config/database.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$userId = (int)($_SESSION['user_id'] ?? 0);
$action = $_GET['action'] ?? 'list';

// جدول لایک‌ها (خودکار)
@$conn->query("CREATE TABLE IF NOT EXISTS `iran_news_likes` (
    `news_hash` VARCHAR(32) NOT NULL,
    `user_id`   INT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`news_hash`, `user_id`)
) DEFAULT CHARSET=utf8mb4");

/* ---------- ابزار مشترک: دانلود URL ---------- */
function irn_http_get($url, $timeout = 8) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 4,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_ENCODING       => '',
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (AvaPay Iran News Reader)',
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    return $body ?: '';
}

/* ---------- تاگل لایک ---------- */
if ($action === 'like') {
    if ($userId <= 0) { http_response_code(401); echo json_encode(['error' => 'login required']); exit; }
    $in   = json_decode(file_get_contents('php://input'), true) ?: [];
    $hash = preg_replace('/[^a-f0-9]/', '', (string)($in['id'] ?? ''));
    if (strlen($hash) !== 32) { http_response_code(400); echo json_encode(['error' => 'bad id']); exit; }

    $st = $conn->prepare("SELECT 1 FROM iran_news_likes WHERE news_hash = ? AND user_id = ?");
    $st->bind_param("si", $hash, $userId); $st->execute();
    $liked = $st->get_result()->num_rows > 0;

    if ($liked) {
        $st = $conn->prepare("DELETE FROM iran_news_likes WHERE news_hash = ? AND user_id = ?");
    } else {
        $st = $conn->prepare("INSERT IGNORE INTO iran_news_likes (news_hash, user_id) VALUES (?, ?)");
    }
    $st->bind_param("si", $hash, $userId); $st->execute();

    $st = $conn->prepare("SELECT COUNT(*) c FROM iran_news_likes WHERE news_hash = ?");
    $st->bind_param("s", $hash); $st->execute();
    $cnt = (int)($st->get_result()->fetch_assoc()['c'] ?? 0);
    echo json_encode(['success' => true, 'liked' => !$liked, 'likes' => $cnt]); exit;
}

/* ---------- کش فهرست ---------- */
$cacheFile   = sys_get_temp_dir() . '/ava_iran_econ_news.json';
$CACHE_FRESH = 600;        // ۱۰ دقیقه: کش تازه
$CACHE_KEEP  = 4 * 86400;  // ۴ روز: حداکثر ماندگاری

if (is_file($cacheFile) && (time() - filemtime($cacheFile)) > $CACHE_KEEP) {
    @unlink($cacheFile);
}

/* ---------- بارگذاری/ساخت فهرست اخبار (برای list و full هر دو لازم است) ---------- */
function irn_load_items($cacheFile, $CACHE_FRESH) {
    $items = null;

    if (is_readable($cacheFile) && (time() - filemtime($cacheFile)) < $CACHE_FRESH) {
        $items = json_decode(@file_get_contents($cacheFile), true);
    }

    if (is_array($items) && !empty($items)) return $items;

    $items = [];
    // فیدهای اقتصادی سایت‌های ایرانی — در صورت نیاز ویرایش کنید
    $feeds = [
        ['url' => 'https://www.tasnimnews.com/fa/rss/feed/0/8/7/%D8%A7%D9%82%D8%AA%D8%B5%D8%A7%D8%AF%DB%8C', 'src' => 'تسنیم'],
        ['url' => 'https://www.mehrnews.com/rss/tp/25',        'src' => 'مهر'],
        ['url' => 'https://www.isna.ir/rss/tp/34',             'src' => 'ایسنا'],
        ['url' => 'https://www.eghtesadonline.com/fa/rss/1',   'src' => 'اقتصادآنلاین'],
        ['url' => 'https://www.khabaronline.ir/rss',           'src' => 'خبرآنلاین'],
    ];

    foreach ($feeds as $f) {
        $xmlRaw = irn_http_get($f['url']);
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
            $descRaw = (string)$it->description;
            $desc = trim(strip_tags($descRaw));
            $desc = html_entity_decode($desc, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $desc = preg_replace('/\s+/u', ' ', $desc);
            if (mb_strlen($desc) > 900) $desc = mb_substr($desc, 0, 900) . '…';

            // تصویر: enclosure یا media:content یا اولین <img> داخل description
            $img = '';
            if (isset($it->enclosure)) {
                $eu = (string)$it->enclosure->attributes()->url;
                if (preg_match('/\.(jpe?g|png|webp|gif)(\?|$)/i', $eu)) $img = $eu;
            }
            if ($img === '') {
                $media = $it->children('media', true);
                if ($media && isset($media->content)) {
                    $img = (string)$media->content->attributes()->url;
                }
            }
            if ($img === '' && preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $descRaw, $m)) {
                $img = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
            }

            $ts = strtotime((string)$it->pubDate) ?: time();
            $items[] = [
                'id'      => md5($link),
                'title'   => $title,
                'summary' => $desc,
                'link'    => $link,
                'image'   => $img,
                'source'  => $f['src'],
                'date'    => date('Y-m-d H:i', $ts),
                'ts'      => $ts,
            ];
        }
    }

    // جدیدترین اول + سقف ۴۰ خبر
    usort($items, function($a, $b){ return ($b['ts'] ?? 0) <=> ($a['ts'] ?? 0); });
    $items = array_slice($items, 0, 40);

    if (!empty($items)) {
        @file_put_contents($cacheFile, json_encode($items, JSON_UNESCAPED_UNICODE), LOCK_EX);
    } elseif (is_readable($cacheFile)) {
        // شبکه در دسترس نبود → کش قدیمی بهتر از هیچ است
        $items = json_decode(@file_get_contents($cacheFile), true) ?: [];
    }

    return $items;
}

/* ---------- متن کامل/ادامه‌ی خبر برای مدال ---------- */
if ($action === 'full') {
    $hash = preg_replace('/[^a-f0-9]/', '', (string)($_GET['id'] ?? ''));
    if (strlen($hash) !== 32) { http_response_code(400); echo json_encode(['error' => 'bad id']); exit; }

    // کش هر مقاله ۶ ساعت
    $artCache = sys_get_temp_dir() . '/ava_irn_art_' . $hash . '.json';
    if (is_readable($artCache) && (time() - filemtime($artCache)) < 6 * 3600) {
        echo @file_get_contents($artCache); exit;
    }

    $items = irn_load_items($cacheFile, $CACHE_FRESH);
    $item  = null;
    foreach ($items as $i) { if ($i['id'] === $hash) { $item = $i; break; } }
    if (!$item) { echo json_encode(['success' => false, 'error' => 'not found']); exit; }

    $html = irn_http_get($item['link'], 10);
    $text = '';
    $img  = $item['image'] ?? '';

    if ($html !== '') {
        // og:image اگر تصویر نداشتیم
        if ($img === '' && preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)) {
            $img = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
        }

        // حذف اسکریپت/استایل و استخراج پاراگراف‌های محتوایی
        $clean = preg_replace('/<(script|style|noscript)[^>]*>.*?<\/\1>/is', ' ', $html);
        if (preg_match_all('/<p[^>]*>(.*?)<\/p>/is', $clean, $ps)) {
            $parts = [];
            foreach ($ps[1] as $p) {
                $t = trim(html_entity_decode(strip_tags($p), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                $t = preg_replace('/\s+/u', ' ', $t);
                // پاراگراف‌های خیلی کوتاه معمولاً منو/لینک هستند
                if (mb_strlen($t) < 60) continue;
                $parts[] = $t;
                if (mb_strlen(implode(' ', $parts)) > 5000) break;
            }
            $text = implode("\n\n", $parts);
        }

        // fallback: og:description
        if ($text === '' && preg_match('/<meta[^>]+property=["\']og:description["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)) {
            $text = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
    }

    if ($text === '') $text = $item['summary'] ?? '';
    if (mb_strlen($text) > 6000) $text = mb_substr($text, 0, 6000) . '…';

    $out = json_encode([
        'success' => true,
        'id'      => $hash,
        'title'   => $item['title'],
        'source'  => $item['source'],
        'date'    => $item['date'],
        'link'    => $item['link'],
        'image'   => $img,
        'body'    => $text,
    ], JSON_UNESCAPED_UNICODE);

    @file_put_contents($artCache, $out, LOCK_EX);
    echo $out; exit;
}

/* ---------- فهرست اخبار ---------- */
$items = irn_load_items($cacheFile, $CACHE_FRESH);

// شمارش لایک‌ها + وضعیت لایک کاربر جاری (یک کوئری برای همه)
$likeCount = []; $likedByMe = [];
if (!empty($items)) {
    $hashes = array_map(function($i){ return "'" . $i['id'] . "'"; }, $items);
    $inSql  = implode(',', $hashes);
    $q = @$conn->query("SELECT news_hash, COUNT(*) c, MAX(user_id = {$userId}) me
                        FROM iran_news_likes WHERE news_hash IN ({$inSql}) GROUP BY news_hash");
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
