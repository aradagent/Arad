<?php
// Database connection settings
$servername = "localhost";
$username = "aradexch_bot";
$password = "dA!G&&aSq7-1";
$dbname = "aradexch_bot";

// Create connection
// (اصلاح) قبلاً new mysqli(...) بدون مهلتِ اتصال بود که می‌توانست تا سقفِ
// پیش‌فرضِ سیستم بلاک شود. حالا حداکثر ۳ ثانیه، مطابق includes/fast_mysqli.php.
require_once __DIR__ . '/../includes/fast_mysqli.php';
$conn = avapay_fast_mysqli($servername, $username, $password, $dbname, 3, 'utf8');

// Check connection
if (!$conn) {
    die("Connection failed or timed out");
}

// Pagination settings
$records_per_page = 15;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$start_from = ($page - 1) * $records_per_page;

// SQL query to fetch 20 records in descending order
$sql = "SELECT name, id, stat, meghdar_arz, arz, mablagh_pishnehad, type, pay FROM mozayede ORDER BY id DESC LIMIT $start_from, $records_per_page";
$stmt = $conn->prepare($sql);
$stmt->execute();
$result = $stmt->get_result();
$data = $result->fetch_all(MYSQLI_ASSOC);

// Count total records for pagination
$sql_count = "SELECT COUNT(*) FROM mozayede";
$result_count = $conn->query($sql_count);
$total_records = $result_count->fetch_row()[0];

// Calculate total pages
$total_pages = ceil($total_records / $records_per_page);

$conn->close();
?>

<!DOCTYPE html>
<html lang="fa">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>اطلاعات جدول مزایده</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            direction: rtl;
            background-color: #f4f4f9;
            margin: 0;
            padding: 0;
        }
        table {
    width: 100%;
    border-collapse: collapse;
    margin: 20px 0;
    font-size: 16px;
    background-color: #fff;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    table-layout: fixed; /* Ensures the column widths are respected */
}
th, td {
    padding: 10px;
    text-align: center;
    word-wrap: break-word; /* Prevents overflow if text is long */
}
th {
    background-color: #f9f9f9;
    border-bottom: 1px solid #ddd;
}
.type-button {
    display: inline-block;
    width: 100%;
    text-align: center;
    padding: 10px 15px;
    border: none;
    border-radius: 8px;
    color: white; /* Text color set to white */
    font-size: 16px;
    font-weight: bold;
    background-color: #f44336; /* Default color for 'فروشنده' */
}

.buy-button {
    background-color: #4CAF50; /* Green for 'خریدار' */
}

.sell-button {
    background-color: #f44336; /* Red for 'فروشنده' */
}

        @media (max-width: 768px) {
            table {
                font-size: 14px;
            }
            select {
                width: 100%;
            }
        }
    </style>
</head>
<body>

<h2>اطلاعات جدول مزایده</h2>

<input type="text" id="search" placeholder="جستجو..." onkeyup="debouncedApplyFilters()">
<button class="button buy-button filter-button" onclick="filterType('خریدار')">حواله های خرید</button>
<button class="button sell-button filter-button" onclick="filterType('فروشنده')">حواله های فروش</button>
<button class="button reset-button filter-button" onclick="resetFilter()">همه</button>

<select id="status-filter" onchange="applyFilters()">
    <option value="">همه وضعیت ها</option>
    <option value="1">حواله های فعال</option>
    <option value="0">حواله های غیر فعال</option>
</select>

<select id="payment-filter" onchange="applyFilters()">
    <option value="">نحوه پرداخت</option>
    <option value="حواله بانکی">حواله بانکی</option>
    <option value="پـیـپــال">پـیـپــال</option>
</select>
<table id="data-table">
    <tr>
        <th style="width: 80%; text-align: right;">اطلاعات حواله</th>
        <th style="width: 20%; text-align: center;">درخواست</th>
    </tr>

    <?php foreach ($data as $row): ?>
        <tr 
            data-type="<?php echo htmlspecialchars($row["type"]); ?>" 
            data-status="<?php echo htmlspecialchars($row["stat"]); ?>" 
            data-pay="<?php echo htmlspecialchars($row["pay"]); ?>"
        >
            <td style="text-align: right; line-height: 1.8;">
                <?php echo htmlspecialchars($row["type"]); ?> : <?php echo htmlspecialchars($row["name"]); ?><br>
                نوع ارز : <?php echo htmlspecialchars($row["meghdar_arz"]) . " " . htmlspecialchars($row["arz"]); ?><br>
                به قیمت : <?php echo htmlspecialchars($row["mablagh_pishnehad"]) . " تومان"; ?><br>
                بصورت : <?php echo htmlspecialchars($row["pay"]); ?>
            </td>
            <td style="text-align: center;">
                <?php if ($row["stat"] == 1): ?>
                    <a href="https://t.me/aradexchange_bot?start=mozayedeget=<?php echo $row['id']; ?>" style="color: green; font-weight: bold; text-decoration: none;">
                        ارسال درخواست
                    </a>
                <?php else: ?>
                    <span style="color: #aaa; font-weight: bold;">غیرفعال</span>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
</table>


<script>
let currentType = '';
let debounceTimeout;

function applyFilters() {
    const input = document.getElementById('search').value.toLowerCase();
    const status = document.getElementById('status-filter').value;
    const payment = document.getElementById('payment-filter').value;
    const rows = document.querySelectorAll('#data-table tr');

    rows.forEach((row, index) => {
        if (index === 0) return; // رد کردن هدر

        const rowText = row.innerText.toLowerCase();
        const rowStatus = row.getAttribute('data-status');
        const rowType = row.getAttribute('data-type');
        const rowPay = row.getAttribute('data-pay');

        let match = true;

        // جستجو در متن ردیف
        if (input && !rowText.includes(input)) {
            match = false;
        }

        // فیلتر وضعیت
        if (status && rowStatus !== status) {
            match = false;
        }

        // فیلتر نحوه پرداخت
        if (payment && rowPay !== payment) {
            match = false;
        }

        // فیلتر نوع (خریدار / فروشنده)
        if (currentType && rowType !== currentType) {
            match = false;
        }

        row.style.display = match ? '' : 'none';
    });
}

function filterType(type) {
    currentType = type;
    applyFilters();
}

function resetFilter() {
    currentType = '';
    document.getElementById('search').value = '';
    document.getElementById('status-filter').value = '';
    document.getElementById('payment-filter').value = '';
    applyFilters();
}

function debouncedApplyFilters() {
    clearTimeout(debounceTimeout);
    debounceTimeout = setTimeout(applyFilters, 300);
}
</script>

</body>
</html>