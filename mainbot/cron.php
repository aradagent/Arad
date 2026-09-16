<?php
/**
 * @author      => Alireza Jarayedi
 * @version     => 3
 * @copyright   => Copyright (c) 2022, ©️Nova Code
 * @link        => https://nova-code.ir
 *
 * چرخه‌ی مدیریت عمر آگهی (مجموعاً ۵ روز):
 *   1) پس از ۴ روز (۹۶ ساعت) از ثبت آگهی و در صورتی که هنوز یادآوری نشده باشد،
 *      برای صاحب آگهی پیام «تمدید یا حذف» ارسال می‌شود و پرچم reminded = 1 می‌شود
 *      و زمان یادآوری در reminded_at ثبت می‌گردد.
 *   2) اگر کاربر تمدید کند، تاریخ آگهی به «اکنون» و reminded به 0 برمی‌گردد (در update.php)
 *      و چرخه‌ی ۵ روزه از نو آغاز می‌شود.
 *   3) اگر کاربر پاسخ ندهد و ۲۴ ساعت از زمانِ یادآوری (reminded_at) بگذرد (یعنی مجموعاً
 *      ۵ روز از ثبت/تمدید آگهی گذشته باشد)، آگهی به‌صورت خودکار حذف می‌شود و در پیام
 *      کانال با دکمه‌ی «این آگهی منقضی شده است» جایگزین می‌شود.
 */

require 'config.php';
require 'strings/jdf.php';

if (!CronJob) die;

// اطمینان از وجود ستون‌های reminded و reminded_at (تابع مشترک در strings/settings.php تعریف شده است)
ensure_mozayede_reminder_columns($bot);

// Function to format review data
function formatReviews($sisoog, int $id) {
    $result = $sisoog->Db->select('review', '*', ['mozayede' => $id, "ORDER" => "id"]);
    $text = [];
    if (is_array($result)) {
        foreach ($result as $res) {
            $stat = is_null($res['ok']) ? '🔸' : ($res['ok'] ? '✅' : '❌');
            $date = jdate('j F, G:i', strtotime($res['date']));
            $res['name'] = (string) $res['name'][0];
            $res['lastname'] = (string) $res['lastname'][0];
            $res['meghdar'] = number_format($res['meghdar']);
            $text[] = "$stat {$res['meghdar']} تومان در $date";
        }
    }
    return implode(PHP_EOL, $text);
}

// Function to get auction and review info
function getInfo($sisoog, int $id) {
    $review = ['test'];
    $mozayede = $sisoog->Db->get('mozayede', '*', ['id' => $id]);
    return [$review, $mozayede];
}

#--------------------------------------------------------------------------------#
# مرحله ۱ – ارسال یادآوری «تمدید/حذف» برای آگهی‌هایی که ۴ روز از ثبتشان گذشته
#           و هنوز یادآوری نشده‌اند (reminded = 0). یک روز مهلت بعدی (مرحله ۲)
#           مجموع را به ۵ روز می‌رساند.
#--------------------------------------------------------------------------------#
$now  = new DateTime();
$date4days = (new DateTime('-4 days'))->format('Y-m-d H:i:s');

$bot->Db->select('mozayede', '*', [
    'date[<]'  => $date4days,
    'stat'     => 1,
    'reminded' => 0,
], function ($data) use ($bot, $now) {

    $All = $bot->Db->has('review', ['mozayede' => $data['id']]) ? formatReviews($bot, $data['id']) : '';

    $data['type'] = $data['type'] === 'فروشنده' ? 'خرید' : 'فروش';
    $user = $bot->GetinfoUser(user_id: $data['chat_id']);
    $data['mablagh_pishnehad'] = number_format($data['mablagh_pishnehad']);

    $text = "
🔄 حواله [{$data['id']}](https://t.me/" . CHANNEL . "/{$data['message']})
بابت {$data['type']} {$data['arz']}
💶 مبلغ: {$data['meghdar_arz']} {$data['arz']}
💰 نرخ پیشنهادی: {$data['mablagh_pishnehad']} تومان
💳 نوع حواله: {$data['pay']} از {$data['country']}
$user
پیشنهادهای ارسال شده:
$All
حواله [{$data['id']}](https://t.me/" . CHANNEL . "/{$data['message']}) توسط شما در سیستم ثبت شده است.
در صورتیکه همچنان تمایل دارید ارز خود را از طریق کانال ما تبادل کنید، گزینه «تمدید» را انتخاب نمایید تا آگهی برای ۵ روز دیگر تمدید شود؛ همچنین می‌توانید مقدار و نرخ حواله را به‌روز کنید.
در صورت عدم تمایل، گزینه «حذف» را انتخاب کنید تا حواله از سیستم پاک شود.

⏳ توجه: در صورت عدم پاسخ ظرف ۲۴ ساعت آینده، این آگهی به‌دلیل انقضا (۵ روز از ثبت آن) به‌صورت خودکار حذف خواهد شد.
";

    $bot->send_message($text, $data['chat_id'], kboard: [
        'inline_keyboard' => [
            [
                ['text' => '📝توضیحات', 'callback_data' => 'edit info ' . $data['id']],
                ['text' => '📝تغییرمقدار', 'callback_data' => 'edit meghdar ' . $data['id']],
                ['text' => '📝تغییرنرخ', 'callback_data' => 'edit nerkh ' . $data['id']]
            ],
            [['text' => '♻️ تمدید', 'callback_data' => 'cron revival ' . $data['id']]],
            [['text' => '🗑 حذف', 'callback_data' => 'cron delete ' . $data['id']]]
        ]
    ], parse_mode: 'Markdown', link_preview_options: ['is_disabled' => true]);

    // ثبت اینکه یادآوری ارسال شد و زمان آن
    $bot->Db->update('mozayede', [
        'reminded'    => 1,
        'reminded_at' => $now->format('Y-m-d H:i:s'),
    ], ['id' => $data['id']]);
});

#--------------------------------------------------------------------------------#
# مرحله ۲ – حذف خودکار آگهی‌هایی که یادآوری برایشان ارسال شده (reminded = 1)
#           ولی کاربر ظرف ۲۴ ساعت پاسخ نداده (reminded_at قدیمی‌تر از ۲۴ ساعت است).
#--------------------------------------------------------------------------------#
$deleteThreshold = (new DateTime('-24 hours'))->format('Y-m-d H:i:s');

$bot->Db->select('mozayede', '*', [
    'reminded'        => 1,
    'reminded_at[<]'  => $deleteThreshold,
    'stat'            => 1,
], function ($data) use ($bot) {

    $All = formatReviews($bot, $data['id']);

    $info = $data;
    $info['type'] = ($info['type'] === 'فروشنده') ? 'فروشنده' : 'خریدار';
    $user = $bot->GetinfoUser(user_id: $data['chat_id']);
    $info['mablagh_pishnehad'] = number_format($info['mablagh_pishnehad']);

    // به‌روزرسانی پیام کانال به «حذف‌شده»
    $bot->edit_Message("
❇️ حواله  : {$info['id']}
❇️ {$info['type']} {$info['arz']}
👤 {$info['type']}: {$info['name']}
💶 مقدار ارز: {$info['meghdar_arz']} {$info['arz']}
💰 مبلغ پیشنهادی: {$info['mablagh_pishnehad']} تومان
💳 نحوه پــرداخــت :حواله بانکی حســاب شــخـص از {$info['country']}
✍️ توضیحات : {$info['info']}
$user
✍️ پیشنهادهای ارسال شده :
$All
  🆔👉 @" . CHANNEL, kboard: [
        'inline_keyboard' => [
            [['text' => '⌛️ این آگهی منقضی شده است', 'url' => 'https://t.me/' . user_bot]]
        ]
    ],
    chat_id: $info['channel'],
    message_id: $info['message']
    );

    // اطلاع به صاحب آگهی
    $bot->send_message(
        "⌛️ آگهی شماره {$info['id']} پس از ۵ روز از ثبت (بدون خرید/فروش و بدون پاسخ به یادآوری) منقضی و به‌صورت خودکار حذف شد.",
        $data['chat_id']
    );

    // حذف از دیتابیس
    $bot->Db->delete('mozayede', ['id' => $data['id']]);
    $bot->Db->delete('review', ['mozayede' => $data['id']]);
});
?>
