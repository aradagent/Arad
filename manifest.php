<?php
/**
 * manifest.php
 * ---------------------------------------------------------
 * نسخه‌ی پویای manifest.json. آیکون‌های PWA را از لوگوی
 * تنظیم‌شده توسط ادمین (در version.json) می‌خواند تا با تغییر
 * لوگو، آیکون نصب‌شده‌ی PWA هم به‌مرور توسط مرورگر آپدیت شود.
 *
 * نکته‌ی مهم: کروم/اندروید به‌صورت دوره‌ای (هنگام باز کردن PWA)
 * این فایل را دوباره می‌خواند و اگر آیکون تغییر کرده باشد، طی
 * چند بار اجرا، آیکون نصب‌شده را به‌روزرسانی می‌کند. این رفتار
 * کاملاً به‌دست مرورگر/سیستم‌عامل است و تحت کنترل کامل سرور نیست.
 * در iOS Safari این قابلیت اساساً پشتیبانی نمی‌شود (محدودیت اپل) —
 * آیکون فقط در لحظه‌ی "Add to Home Screen" گرفته می‌شود.
 * ---------------------------------------------------------
 */

require_once __DIR__ . '/includes/logo_helper.php';

header('Content-Type: application/manifest+json; charset=utf-8');
// عدم کش تا مرورگر همیشه آخرین نسخه‌ی manifest را بگیرد
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');

$appLogo = getAppLogo();

// آیکون پایه: یا لوگوی سفارشی ادمین، یا آیکون پیش‌فرض برنامه
$iconSrc = $appLogo ? $appLogo['url'] : '/ledor/AVAPAY.PNG';

$iconSizes = ['72x72', '96x96', '128x128', '144x144', '152x152', '192x192', '256x256', '384x384', '512x512'];

$icons = [];
foreach ($iconSizes as $size) {
    $icons[] = [
        'src'     => $iconSrc,
        'sizes'   => $size,
        'type'    => 'image/png',
        'purpose' => 'any maskable'
    ];
}

$manifest = [
    'name' => 'AvaPay NeoBank',
    'short_name' => 'AvaPay',
    'description' => 'The first currency exchange system in Iran',
    'start_url' => '/ledor/splash.html',
    'scope' => '/ledor/',
    'id' => '/ledor/',
    'display' => 'standalone',
    'theme_color' => '#1A0B2E',
    'background_color' => '#1A0B2E',
    'orientation' => 'portrait',
    'icons' => $icons,
    'shortcuts' => [
        [
            'name' => '🏠 Dashboard',
            'short_name' => 'Home',
            'description' => 'Go to dashboard',
            'url' => '/ledor/dashboard.php',
            'icons' => [['src' => $iconSrc, 'sizes' => '96x96']]
        ],
        [
            'name' => '💸 Send Money',
            'short_name' => 'Send',
            'description' => 'Send money to someone',
            'url' => '/ledor/dashboard.php?action=send',
            'icons' => [['src' => $iconSrc, 'sizes' => '96x96']]
        ],
        [
            'name' => '📊 Transactions',
            'short_name' => 'History',
            'description' => 'View transaction history',
            'url' => '/ledor/transactions.php',
            'icons' => [['src' => $iconSrc, 'sizes' => '96x96']]
        ],
        [
            'name' => '👤 Profile',
            'short_name' => 'Profile',
            'description' => 'View and edit profile',
            'url' => '/ledor/profile.php',
            'icons' => [['src' => $iconSrc, 'sizes' => '96x96']]
        ]
    ],
    'categories' => ['finance', 'business', 'productivity'],
    'prefer_related_applications' => false,
    'related_applications' => [],
    'screenshots' => [
        ['src' => $iconSrc, 'sizes' => '1080x1920', 'type' => 'image/png', 'form_factor' => 'wide'],
        ['src' => $iconSrc, 'sizes' => '1080x1920', 'type' => 'image/png', 'form_factor' => 'wide']
    ],
    'lang' => 'en',
    'dir' => 'ltr',
    'display_override' => ['window-controls-overlay', 'standalone'],
    'launch_handler' => ['client_mode' => ['focus-existing', 'auto']],
    'edge_side_panel' => ['preferred_width' => 400]
];

echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
