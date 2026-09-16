// assets/js/check-auth.js (نسخه بهبود یافته)

async function checkAuth() {
    try {
        // 🔥 اولویت‌بندی: ۱. توکن localStorage ۲. توکن Cookie
        let token = localStorage.getItem('auth_token');
        if (!token) {
            token = getCookie('auth_token');
            if (token) {
                localStorage.setItem('auth_token', token);
            }
        }
        
        // اگر توکنی وجود ندارد
        if (!token) {
            if (!window.location.pathname.includes('login.php')) {
                window.location.href = 'login.php';
            }
            return false;
        }
        
        // 🔥 درخواست به سرور برای تأیید اعتبار توکن
        const response = await fetch('/ledor/api/check-auth.php', {
            method: 'GET',
            credentials: 'include', // برای ارسال Cookie ها
            headers: {
                'Authorization': 'Bearer ' + token,
                'X-Requested-With': 'XMLHttpRequest'
            }
        });
        
        const data = await response.json();
        
        // ================= AUTHENTICATED =================
        if (data.authenticated) {
            // ذخیره اطلاعات کاربر برای استفاده فرانت
            localStorage.setItem('user_data', JSON.stringify(data.user));
            
            // 🔥 اگر در صفحه لاگین هست → بفرست داشبورد
            if (window.location.pathname.includes('login.php')) {
                window.location.href = 'dashboard.php';
            }
            
            // 🔥 تازه‌سازی توکن (اگر پشتیبانی شود)
            if (data.new_token) {
                localStorage.setItem('auth_token', data.new_token);
                setCookie('auth_token', data.new_token, 30);
            }
            
            return true;
        }
        
        // ================= NOT AUTHENTICATED =================
        localStorage.removeItem('auth_token');
        localStorage.removeItem('user_data');
        eraseCookie('auth_token');
        eraseCookie('user_id');
        
        if (!window.location.pathname.includes('login.php')) {
            window.location.href = 'login.php';
        }
        
        return false;
        
    } catch (err) {
        console.error('Auth check failed:', err);
        
        // 🔥 در صورت خطای شبکه، از داده‌های ذخیره شده استفاده کن
        const userData = localStorage.getItem('user_data');
        if (userData && !window.location.pathname.includes('login.php')) {
            return true;
        }
        
        if (!window.location.pathname.includes('login.php')) {
            window.location.href = 'login.php';
        }
        
        return false;
    }
}

// 🔥 اجرای چک هنگام لود صفحه
document.addEventListener('DOMContentLoaded', function() {
    // فقط اگر در صفحه لاگین نیستیم چک کنیم
    if (!window.location.pathname.includes('login.php')) {
        checkAuth();
    }
});

// 🔥 همچنین در زمان load شدن پنجره
window.addEventListener('load', function() {
    if (!window.location.pathname.includes('login.php')) {
        checkAuth();
    }
});