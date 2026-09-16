<?php
/**
 * includes/site_updater.php
 * ---------------------------------------------------------------
 * منطقِ خامِ «آپدیت سایت با آپلود زیپ» — از api/site_update_api.php صدا
 * زده می‌شود. هرچه اینجاست عمداً «فقط توابع خام» است (بدون echo/exit)
 * تا هم قابل تست باشد و هم بشود همان‌ها را برای rollback دوباره استفاده
 * کرد.
 *
 * قاعده‌ی امنیتی: پوشه‌های config/ ، uploads/ ، backups/ و .git هرگز —
 * نه در ساختِ بکاپِ پیش‌از‌آپدیت و نه در جایگزینیِ فایل‌های جدید — دست
 * نمی‌خورند، مگر ادمین صریحاً تیکِ «این‌ها هم بازنویسی شوند» را بزند
 * (که فقط برای config/uploads معنا دارد، نه برای backups/.git).
 * ---------------------------------------------------------------
 */

if (!function_exists('site_update_dir')) {
    function site_update_dir(): string {
        $base = function_exists('avapay_backup_dir') ? avapay_backup_dir() : (dirname(dirname(__DIR__)) . '/backups');
        $dir = rtrim($base, '/\\') . '/site_updates';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $htaccess = $dir . '/.htaccess';
        if (!is_file($htaccess)) @file_put_contents($htaccess, "Require all denied\n" . "Deny from all\n");
        return $dir;
    }
}

if (!function_exists('site_update_is_protected')) {
    /**
     * @param string $relPath مسیر نسبی از ریشه‌ی سایت، با اسلش رو به جلو، بدون اسلش ابتدایی
     */
    function site_update_is_protected(string $relPath, bool $includeConfig, bool $includeUploads, string $backupsDirName = 'backups'): bool {
        $relPath = str_replace('\\', '/', $relPath);
        $relPath = ltrim($relPath, '/');
        if ($relPath === '') return false;

        // این‌ها هیچ‌وقت دست نمی‌خورند — نه در بکاپ، نه در جایگزینی
        if ($relPath === '.git' || strpos($relPath, '.git/') === 0) return true;
        if ($relPath === $backupsDirName || strpos($relPath, $backupsDirName . '/') === 0) return true;
        if (basename($relPath) === 'maintenance_state.json') return true;

        if (!$includeConfig) {
            if ($relPath === 'config' || strpos($relPath, 'config/') === 0) return true;
        }
        if (!$includeUploads) {
            if ($relPath === 'uploads' || strpos($relPath, 'uploads/') === 0) return true;
        }
        return false;
    }
}

if (!function_exists('site_update_zip_directory')) {
    /**
     * کل $srcDir را (به‌جز مسیرهای protected) در یک فایل zip می‌ریزد.
     * برای ساختِ بکاپِ ایمنی پیش از آپدیت استفاده می‌شود.
     */
    function site_update_zip_directory(string $srcDir, string $destZipPath, bool $includeConfig, bool $includeUploads): int {
        $srcDir = rtrim($srcDir, '/\\');
        $zip = new ZipArchive();
        if ($zip->open($destZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('امکان ساختِ فایلِ بکاپ نبود');
        }
        $count = 0;
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($srcDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $item) {
            $rel = ltrim(str_replace('\\', '/', substr($item->getPathname(), strlen($srcDir))), '/');
            if ($rel === '') continue;
            if (site_update_is_protected($rel, $includeConfig, $includeUploads)) continue;
            if ($item->isDir()) {
                $zip->addEmptyDir($rel);
            } else {
                $zip->addFile($item->getPathname(), $rel);
                $count++;
            }
        }
        $zip->close();
        return $count;
    }
}

if (!function_exists('site_update_copy_recursive')) {
    /**
     * محتوای $srcDir (بسته‌ی استخراج‌شده) را روی $destDir (ریشه‌ی سایت) کپی
     * می‌کند — فقط اضافه/بازنویسیِ فایل‌ها؛ چیزی که در سایتِ فعلی هست ولی در
     * بسته‌ی جدید نیست، پاک نمی‌شود (برای امنیت بیشتر، عمدی است).
     */
    function site_update_copy_recursive(string $srcDir, string $destDir, bool $includeConfig, bool $includeUploads): int {
        $srcDir = rtrim($srcDir, '/\\');
        $destDir = rtrim($destDir, '/\\');
        $count = 0;
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($srcDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $item) {
            $rel = ltrim(str_replace('\\', '/', substr($item->getPathname(), strlen($srcDir))), '/');
            if ($rel === '') continue;
            if (site_update_is_protected($rel, $includeConfig, $includeUploads)) continue;
            $target = $destDir . '/' . $rel;
            if ($item->isDir()) {
                if (!is_dir($target)) @mkdir($target, 0755, true);
            } else {
                $targetDir = dirname($target);
                if (!is_dir($targetDir)) @mkdir($targetDir, 0755, true);
                if (@copy($item->getPathname(), $target)) $count++;
            }
        }
        return $count;
    }
}

if (!function_exists('site_update_restore_from_zip')) {
    /**
     * برای rollback: بکاپِ پیش‌از‌آپدیت را دوباره روی ریشه‌ی سایت می‌کشد.
     */
    function site_update_restore_from_zip(string $zipPath, string $destDir): int {
        $stagingDir = dirname($zipPath) . '/rollback_' . date('Ymd_His') . '_' . substr(md5($zipPath), 0, 6);
        @mkdir($stagingDir, 0755, true);
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('باز کردنِ فایلِ بکاپ برای rollback ناموفق بود');
        }
        $zip->extractTo($stagingDir);
        $zip->close();
        // بکاپ خودش هم قبلاً بدون config/uploads ساخته شده، پس اینجا هر دو true
        // بی‌ضرر است (چیزی از آن نوع در بکاپ اصلاً وجود ندارد که protected شود)
        $count = site_update_copy_recursive($stagingDir, $destDir, true, true);
        site_update_rrmdir($stagingDir);
        return $count;
    }
}

if (!function_exists('site_update_rrmdir')) {
    function site_update_rrmdir(string $dir): void {
        if (!is_dir($dir)) return;
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            if ($item->isDir()) @rmdir($item->getPathname());
            else @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}

if (!function_exists('site_update_flatten_single_wrapper')) {
    /**
     * اگر زیپِ آپلودی یک پوشه‌ی رپردارِ تک‌تایی داشته باشد (مثلاً همه‌چیز
     * داخل AvaPay_updated113/ باشد)، همان پوشه را به‌عنوان ریشه‌ی واقعی
     * برمی‌گرداند — مستقل از اینکه ادمین زیپِ «بدون پوشه» بدهد یا «با پوشه».
     */
    function site_update_flatten_single_wrapper(string $stagingDir): string {
        $entries = array_values(array_diff((array)@scandir($stagingDir), ['.', '..']));
        if (count($entries) === 1 && is_dir($stagingDir . '/' . $entries[0])) {
            return $stagingDir . '/' . $entries[0];
        }
        return $stagingDir;
    }
}
