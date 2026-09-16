<?php
/**
 * includes/wallet_hero.php
 * کامپوننت قابل‌استفاده‌ی مجدد برای کارت اصلی موجودی (Wallet Hero).
 *
 * استفاده:
 *   renderWalletHero([
 *     'USD'  => 12.5,
 *     'EUR'  => 0,
 *     'USDT' => 3.2,
 *     'IRR'  => 1500000,
 *   ]);
 *
 * پارامتر دوم $withCss را فقط یک بار در هر صفحه true بگذارید تا CSS تکرار نشود.
 */
function renderWalletHero($balances = [], $withCss = true) {
    static $cssPrinted = false;
    static $instance = 0;
    $instance++;
    $uid = 'wh' . $instance;

    $curMeta = [
        'USD'  => ['icon' => 'fa-dollar-sign', 'label' => 'USD',  'name' => 'US Dollar'],
        'EUR'  => ['icon' => 'fa-euro-sign',   'label' => 'EUR',  'name' => 'Euro'],
        'USDT' => ['icon' => 'fa-coins',       'label' => 'USDT', 'name' => 'Tether'],
        'IRR'  => ['icon' => 'fa-rial',        'label' => 'IRR',  'name' => 'Toman'],
    ];
    $heroCode = 'USD';
    $heroBal  = $balances[$heroCode] ?? 0;

    if ($withCss && !$cssPrinted):
        $cssPrinted = true; ?>
    <style>
    .wallet-display-section { margin: 0 0 22px 0; }
    .wallet-header { display: flex; align-items: center; gap: 8px; margin-bottom: 14px; direction: ltr; }
    .wallet-header i { color: #FF4D8D; font-size: .9rem; }
    .wallet-header span { font-size: .78rem; font-weight: 700; color: rgba(255,255,255,0.6); text-transform: uppercase; letter-spacing: .06em; flex: 1; }
    .wallet-header .refresh-btn { background: rgba(255,255,255,0.05); border: none; color: rgba(255,255,255,0.4); width: 32px; height: 32px; border-radius: 50%; cursor: pointer; font-size: .85rem; }
    .wallet-hero {
        position: relative; border-radius: 22px; padding: 20px 20px 18px;
        background:
            radial-gradient(120% 140% at 0% 0%, rgba(108,64,197,0.55), transparent 60%),
            radial-gradient(120% 140% at 100% 100%, rgba(255,77,141,0.45), transparent 55%),
            linear-gradient(135deg, #241041, #12061f);
        border: 1px solid rgba(255,255,255,0.10); overflow: hidden;
        box-shadow: 0 14px 40px rgba(23,10,45,0.55); direction: ltr;
    }
    .wallet-hero::after { content: ''; position: absolute; top: -40%; right: -20%; width: 220px; height: 220px; border-radius: 50%; background: rgba(255,255,255,0.05); filter: blur(10px); pointer-events: none; }
    .wallet-hero-top { display: flex; align-items: center; justify-content: space-between; position: relative; z-index: 1; }
    .wallet-hero-label { font-size: .62rem; font-weight: 700; letter-spacing: .12em; color: rgba(255,255,255,0.55); text-transform: uppercase; }
    .wallet-hero-chip { display: inline-flex; align-items: center; gap: 5px; background: rgba(255,255,255,0.10); border: 1px solid rgba(255,255,255,0.14); padding: 4px 10px; border-radius: 20px; font-size: .6rem; font-weight: 700; color: #fff; }
    .wallet-hero-chip .dot { width: 6px; height: 6px; border-radius: 50%; background: #4ade80; box-shadow: 0 0 8px #4ade80; }
    .wallet-hero-amount { position: relative; z-index: 1; font-size: 2rem; font-weight: 800; color: #fff; margin: 14px 0 2px; letter-spacing: -.02em; display: flex; align-items: baseline; gap: 8px; }
    .wallet-hero-amount .cur-tag { font-size: .8rem; font-weight: 700; color: rgba(255,255,255,0.5); }
    .wallet-hero-eye { background: none; border: none; color: rgba(255,255,255,0.45); cursor: pointer; font-size: .85rem; margin-left: 4px; }
    .wallet-hero-switch { position: relative; z-index: 1; display: flex; gap: 6px; margin-top: 14px; flex-wrap: wrap; }
    .wallet-hero-switch button { background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.10); color: rgba(255,255,255,0.6); font-size: .62rem; font-weight: 700; padding: 5px 12px; border-radius: 20px; cursor: pointer; transition: all .2s; }
    .wallet-hero-switch button.active { background: #fff; color: #1a0b2e; border-color: #fff; }
    html[data-theme="light"] .wallet-hero { box-shadow: 0 12px 34px rgba(108,64,197,0.22) !important; }
    </style>
    <script>
    function avaSelectHeroCurrency(btn, uid) {
        var box = document.getElementById(uid);
        box.querySelectorAll('.wallet-hero-switch button').forEach(function(b){ b.classList.remove('active'); });
        btn.classList.add('active');
        var code = btn.dataset.code, name = btn.dataset.name, bal = parseFloat(btn.dataset.bal || '0');
        var amtEl = box.querySelector('.wallet-hero-amount .amt');
        box.querySelector('.wallet-hero-amount .cur-tag').textContent = code;
        box.querySelector('.wallet-hero-label').textContent = name + ' BALANCE';
        var formatted = code === 'IRR'
            ? bal.toLocaleString('en-US', {maximumFractionDigits:0})
            : bal.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2});
        amtEl.dataset.real = formatted;
        amtEl.textContent = box.dataset.hidden === '1' ? '••••••' : formatted;
    }
    function avaToggleHeroVisibility(uid) {
        var box = document.getElementById(uid);
        var amtEl = box.querySelector('.wallet-hero-amount .amt');
        var icon = box.querySelector('.wallet-hero-eye i');
        var hidden = box.dataset.hidden === '1';
        box.dataset.hidden = hidden ? '0' : '1';
        if (icon) icon.className = hidden ? 'fas fa-eye' : 'fas fa-eye-slash';
        if (hidden) { amtEl.textContent = amtEl.dataset.real || amtEl.textContent; }
        else { amtEl.dataset.real = amtEl.textContent; amtEl.textContent = '••••••'; }
    }
    </script>
    <?php endif; ?>

    <div class="wallet-display-section">
        <div class="wallet-header">
            <i class="fas fa-wallet"></i>
            <span>Wallet Balance</span>
        </div>
        <div class="wallet-hero" id="<?php echo $uid; ?>" data-hidden="0">
            <div class="wallet-hero-top">
                <span class="wallet-hero-label"><?php echo strtoupper($curMeta[$heroCode]['name']); ?> BALANCE</span>
                <span class="wallet-hero-chip"><span class="dot"></span> Active</span>
            </div>
            <div class="wallet-hero-amount">
                <span class="amt"><?php echo number_format($heroBal, 2); ?></span>
                <span class="cur-tag"><?php echo $heroCode; ?></span>
                <button class="wallet-hero-eye" onclick="avaToggleHeroVisibility('<?php echo $uid; ?>')" title="Hide/Show"><i class="fas fa-eye"></i></button>
            </div>
            <div class="wallet-hero-switch">
                <?php foreach ($curMeta as $code => $info): ?>
                <button class="<?php echo $code === $heroCode ? 'active' : ''; ?>"
                        data-code="<?php echo $code; ?>"
                        data-name="<?php echo $info['name']; ?>"
                        data-bal="<?php echo htmlspecialchars($balances[$code] ?? 0); ?>"
                        onclick="avaSelectHeroCurrency(this, '<?php echo $uid; ?>')"><?php echo $info['label']; ?></button>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php
}
