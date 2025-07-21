<?php

namespace App\Console\Commands;

use App\Services\CategoryMatcherService;
use App\Models\Product;
use App\Models\Category;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * ربات تطبیق و تخصیص خودکار دسته‌بندی‌های محصولات
 *
 * این کلاس امکان پردازش فایل‌های JSON حاوی اطلاعات دسته‌بندی محصولات
 * و تطبیق آن‌ها با محصولات موجود در دیتابیس را فراهم می‌کند.
 */

class CategoryMatcherBot extends Command
{
    protected $signature = 'bot:match-categories
                          {file_path : مسیر فایل JSON}
                          {--user-id=39 : شناسه کاربر}
                          {--dry-run : اجرای تست بدون تغییر در دیتابیس}
                          {--show-details : نمایش جزئیات پردازش}
                          {--batch-size=100 : تعداد آیتم‌ها در هر بچ}';

    protected $description = 'ربات تطبیق و تخصیص خودکار دسته‌بندی‌های محصولات';

    private CategoryMatcherService $categoryMatcher;

    public function handle(): int
    {
        $this->info('🚀 شروع پردازش فایل دسته‌بندی‌ها...');

        $filePath = $this->argument('file_path');
        $userId = (int)$this->option('user-id');
        $isDryRun = $this->option('dry-run');
        $showDetails = $this->option('show-details');
        $batchSize = (int)$this->option('batch-size');

        // اعتبارسنجی ورودی‌ها
        if (!$this->validateInputs($filePath, $userId)) {
            return 1;
        }

        if ($isDryRun) {
            $this->warn('⚠️  حالت تست: هیچ تغییری در دیتابیس اعمال نخواهد شد');
        }

        $this->categoryMatcher = new CategoryMatcherService($userId);

        try {
            $this->displayProcessingInfo($userId, $filePath);

            $stats = $isDryRun
                ? $this->simulateProcessing($filePath, $showDetails, $batchSize)
                : $this->categoryMatcher->processFile($filePath);

            $this->showSummary($stats, $isDryRun);
            $this->showRecommendations($stats);

            return 0;

        } catch (\Exception $e) {
            $this->handleError($e, $filePath, $userId);
            return 1;
        }
    }

    /**
     * اعتبارسنجی ورودی‌ها
     */
    private function validateInputs(string $filePath, int $userId): bool
    {
        if (!file_exists($filePath)) {
            $this->error("❌ فایل یافت نشد: {$filePath}");
            return false;
        }

        if (!is_readable($filePath)) {
            $this->error("❌ فایل قابل خواندن نیست: {$filePath}");
            return false;
        }

        // بررسی وجود کاربر
        $userProductsCount = Product::where('user_id', $userId)->count();
        if ($userProductsCount === 0) {
            $this->warn("⚠️  هیچ محصولی برای کاربر {$userId} یافت نشد");
            if (!$this->confirm('آیا می‌خواهید ادامه دهید؟')) {
                return false;
            }
        } else {
            $this->info("📦 تعداد محصولات کاربر {$userId}: {$userProductsCount}");
        }

        return true;
    }

    /**
     * نمایش اطلاعات پردازش
     */
    private function displayProcessingInfo(int $userId, string $filePath): void
    {
        $this->info("👤 کاربر مورد نظر: {$userId}");
        $this->info("📁 فایل: {$filePath}");

        $fileSize = filesize($filePath);
        $this->info("📊 حجم فایل: " . $this->formatBytes($fileSize));
    }

    /**
     * شبیه‌سازی کامل پردازش برای حالت dry-run
     */
    private function simulateProcessing(string $filePath, bool $showDetails, int $batchSize): array
    {
        $this->info('🔍 در حال شبیه‌سازی پردازش...');

        $jsonData = json_decode(file_get_contents($filePath), true);

        if (!$jsonData || !is_array($jsonData)) {
            throw new \Exception("فایل JSON نامعتبر است");
        }

        $stats = [
            'processed' => 0,
            'matched' => 0,
            'not_found' => 0,
            'categories_created' => 0,
            'invalid_items' => 0,
            'categories_found' => 0,
            'categories_not_found' => 0,
            'total_category_attempts' => 0
        ];

        $totalItems = count($jsonData);
        $this->info("📋 تعداد کل آیتم‌ها: {$totalItems}");

        // گرفتن فهرست محصولات کاربر
        $userProducts = Product::where('user_id', $this->categoryMatcher->getUserId())
            ->pluck('title', 'id')
            ->toArray();

        // گرفتن فهرست دسته‌بندی‌های موجود
        $existingCategories = Category::pluck('name')->toArray();

        $progressBar = $this->output->createProgressBar($totalItems);
        $progressBar->start();

        $itemCounter = 0;
        foreach (array_chunk($jsonData, $batchSize) as $batch) {
            foreach ($batch as $item) {
                $itemCounter++;
                $result = $this->simulateItemProcessing($item, $userProducts, $existingCategories, $showDetails, $itemCounter);

                $stats['processed']++;
                $stats[$result['status']]++;

                if ($result['categories_count'] > 0) {
                    $stats['total_category_attempts'] += $result['categories_count'];
                    $stats['categories_found'] += $result['categories_found'];
                    $stats['categories_not_found'] += $result['categories_not_found'];
                    $stats['categories_created'] += $result['categories_to_create'];
                }

                $progressBar->advance();
            }

            // شبیه‌سازی تاخیر پردازش
            usleep(10000); // 10ms delay
        }

        $progressBar->finish();
        $this->newLine();

        // محاسبه نرخ موفقیت
        $stats['success_rate'] = $stats['processed'] > 0
            ? round(($stats['matched'] / $stats['processed']) * 100, 2)
            : 0;

        // محاسبه نرخ تطبیق دسته‌بندی‌ها
        $stats['category_match_rate'] = $stats['total_category_attempts'] > 0
            ? round(($stats['categories_found'] / $stats['total_category_attempts']) * 100, 2)
            : 0;

        return $stats;
    }

    /**
     * شبیه‌سازی پردازش یک آیتم
     */
    private function simulateItemProcessing(array $item, array $userProducts, array $existingCategories, bool $showDetails, int $itemNum): array
    {
        $result = [
            'status' => 'invalid_items',
            'categories_count' => 0,
            'categories_found' => 0,
            'categories_not_found' => 0,
            'categories_to_create' => 0
        ];

        // بررسی صحت داده
        if (!isset($item['title']) || !isset($item['categories'])) {
            if ($showDetails) {
                $this->warn("#{$itemNum} ❌ آیتم ناقص: " . json_encode($item, JSON_UNESCAPED_UNICODE));
            }
            return $result;
        }

        // شبیه‌سازی جستجوی محصول
        $productFound = $this->simulateProductSearch($item['title'], $userProducts);

        if (!$productFound['found']) {
            $result['status'] = 'not_found';
            if ($showDetails) {
                $this->line("#{$itemNum} ❌ محصول یافت نشد: {$item['title']}");
            }
            return $result;
        }

        // شبیه‌سازی تخصیص دسته‌بندی‌ها
        $result['status'] = 'matched';
        $result['categories_count'] = count($item['categories']);

        // بررسی دسته‌بندی‌ها
        $categoryDetails = $this->analyzeCategoryMatching($item['categories'], $existingCategories);
        $result['categories_found'] = $categoryDetails['found'];
        $result['categories_not_found'] = $categoryDetails['not_found'];
        $result['categories_to_create'] = $categoryDetails['to_create'];

        if ($showDetails) {
            $productInfo = $productFound['similarity'] > 99
                ? "✅ محصول تطبیق دقیق: {$item['title']}"
                : "✅ محصول تطبیق فازی: {$item['title']} (شباهت: {$productFound['similarity']}% با '{$productFound['matched_title']}')";

            $this->line("#{$itemNum} {$productInfo}");

            // جزئیات دسته‌بندی‌ها
            $this->line("    📂 دسته‌بندی‌ها ({$result['categories_count']} عدد):");
            $this->line("       ✅ موجود: {$categoryDetails['found']} | ➕ ایجاد می‌شود: {$categoryDetails['to_create']}");

            // نمایش دسته‌بندی‌های ایجاد شونده
            if (!empty($categoryDetails['new_categories']) && count($categoryDetails['new_categories']) <= 3) {
                $newCats = implode(', ', array_slice($categoryDetails['new_categories'], 0, 3));
                $this->line("       ➕ جدید: {$newCats}");
            }
        }

        return $result;
    }

    /**
     * تجزیه و تحلیل تطبیق دسته‌بندی‌ها
     */
    private function analyzeCategoryMatching(array $categories, array $existingCategories): array
    {
        $found = 0;
        $toCreate = 0;
        $newCategories = [];

        foreach ($categories as $categoryData) {
            if (!isset($categoryData['name'])) {
                continue;
            }

            $categoryName = $categoryData['name'];

            // جستجوی مستقیم
            if (in_array($categoryName, $existingCategories)) {
                $found++;
                continue;
            }

            // جستجوی فازی
            $fuzzyMatch = $this->simulateCategoryFuzzySearch($categoryName, $existingCategories);

            if ($fuzzyMatch) {
                $found++;
            } else {
                $toCreate++;
                $newCategories[] = $categoryName;
            }
        }

        return [
            'found' => $found,
            'not_found' => count($categories) - $found,
            'to_create' => $toCreate,
            'new_categories' => $newCategories
        ];
    }

    /**
     * شبیه‌سازی جستجوی فازی دسته‌بندی
     */
    private function simulateCategoryFuzzySearch(string $categoryName, array $existingCategories): bool
    {
        $normalizedName = $this->normalizeText($categoryName);

        foreach ($existingCategories as $existingCategory) {
            $normalizedExisting = $this->normalizeText($existingCategory);
            $similarity = $this->calculateSimilarity($normalizedName, $normalizedExisting);

            if ($similarity > 85) {
                return true;
            }
        }

        return false;
    }

    /**
     * شبیه‌سازی جستجوی محصول
     */
    private function simulateProductSearch(string $title, array $userProducts): array
    {
        // جستجوی مستقیم
        if (in_array($title, $userProducts)) {
            return [
                'found' => true,
                'similarity' => 100,
                'matched_title' => $title
            ];
        }

        // شبیه‌سازی جستجوی فازی
        $normalizedTitle = $this->normalizeText($title);
        $bestSimilarity = 0;
        $bestMatch = null;

        foreach ($userProducts as $productTitle) {
            $normalizedProductTitle = $this->normalizeText($productTitle);
            $similarity = $this->calculateSimilarity($normalizedTitle, $normalizedProductTitle);

            if ($similarity > $bestSimilarity && $similarity > 85) {
                $bestSimilarity = $similarity;
                $bestMatch = $productTitle;
            }
        }

        return [
            'found' => $bestMatch !== null,
            'similarity' => round($bestSimilarity, 1),
            'matched_title' => $bestMatch
        ];
    }

    /**
     * یکسان‌سازی متن (کپی از سرویس)
     */
    private function normalizeText(string $text): string
    {
        $text = trim($text);
        $text = preg_replace('/\s+/', ' ', $text);
        $text = str_replace(['،', '؍', '٫', '،'], ',', $text);
        $text = str_replace(['‌'], ' ', $text);
        $text = mb_strtolower($text);
        $text = preg_replace('/[^\x{0600}-\x{06FF}\x{200C}\x{200D}a-zA-Z0-9\s,.-]/u', '', $text);
        return $text;
    }

    /**
     * محاسبه شباهت (کپی از سرویس)
     */
    private function calculateSimilarity(string $text1, string $text2): float
    {
        $similarity = 0;
        similar_text($text1, $text2, $similarity);

        $words1 = explode(' ', $text1);
        $words2 = explode(' ', $text2);
        $commonWords = array_intersect($words1, $words2);

        if (count($commonWords) > 0) {
            $wordBonus = (count($commonWords) / max(count($words1), count($words2))) * 10;
            $similarity += $wordBonus;
        }

        return min(100, $similarity);
    }

    /**
     * نمایش خلاصه نتایج
     */
    private function showSummary(array $stats, bool $isDryRun): void
    {
        $this->newLine(2);
        $this->info('📈 خلاصه نتایج پردازش:');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        // نمایش آمار محصولات
        $this->info('🎯 آمار محصولات:');
        $this->table(
            ['شاخص', 'مقدار', 'درصد'],
            [
                ['کل آیتم‌های پردازش شده', number_format($stats['processed']), '100%'],
                ['محصولات تطبیق یافته', number_format($stats['matched']), round(($stats['matched'] / $stats['processed']) * 100, 1) . '%'],
                ['محصولات یافت نشده', number_format($stats['not_found']), round(($stats['not_found'] / $stats['processed']) * 100, 1) . '%'],
                ['آیتم‌های نامعتبر', number_format($stats['invalid_items'] ?? 0), round((($stats['invalid_items'] ?? 0) / $stats['processed']) * 100, 1) . '%'],
            ]
        );

        // نمایش آمار دسته‌بندی‌ها
        if ($stats['total_category_attempts'] > 0) {
            $this->newLine();
            $this->info('📂 آمار دسته‌بندی‌ها:');
            $this->table(
                ['شاخص', 'مقدار', 'درصد'],
                [
                    ['کل دسته‌بندی‌های بررسی شده', number_format($stats['total_category_attempts']), '100%'],
                    ['دسته‌بندی‌های موجود', number_format($stats['categories_found']), round(($stats['categories_found'] / $stats['total_category_attempts']) * 100, 1) . '%'],
                    ['دسته‌بندی‌های جدید (ایجاد می‌شود)', number_format($stats['categories_created']), round(($stats['categories_created'] / $stats['total_category_attempts']) * 100, 1) . '%'],
                ]
            );
        }

        $this->newLine();
        $this->info("🎯 نرخ موفقیت محصولات: {$stats['success_rate']}%");

        if (isset($stats['category_match_rate'])) {
            $this->info("📂 نرخ تطبیق دسته‌بندی‌ها: {$stats['category_match_rate']}%");
        }

        // ارزیابی عملکرد
        if ($stats['success_rate'] > 80) {
            $this->info('🎉 پردازش با موفقیت بالا تکمیل شد!');
        } elseif ($stats['success_rate'] > 50) {
            $this->warn('⚠️  پردازش تکمیل شد اما نرخ موفقیت متوسط بود.');
        } else {
            $this->error('❌ نرخ موفقیت پایین. لطفاً داده‌ها را بررسی کنید.');
        }

        if ($isDryRun) {
            $this->newLine();
            $this->info('💡 برای اعمال تغییرات، دستور را بدون --dry-run اجرا کنید');
        }
    }

    /**
     * نمایش پیشنهادات بهبود
     */
    private function showRecommendations(array $stats): void
    {
        $recommendations = [];

        if ($stats['not_found'] > ($stats['processed'] * 0.3)) {
            $recommendations[] = '• احتمالاً نام‌گذاری محصولات در فایل JSON با دیتابیس متفاوت است';
            $recommendations[] = '• در نظر گیرید آستانه تشابه را کاهش دهید';
        }

        if (($stats['invalid_items'] ?? 0) > 0) {
            $recommendations[] = '• بررسی ساختار فایل JSON و اصلاح داده‌های ناقص';
        }

        if ($stats['categories_created'] > ($stats['matched'] * 2)) {
            $recommendations[] = '• تعداد دسته‌بندی‌های جدید زیاد است، ممکن است نیاز به بهینه‌سازی باشد';
        }

        if (isset($stats['category_match_rate']) && $stats['category_match_rate'] < 50) {
            $recommendations[] = '• نرخ تطبیق دسته‌بندی‌ها پایین است. احتمالاً نام‌گذاری دسته‌بندی‌ها متفاوت است';
        }

        if (!empty($recommendations)) {
            $this->newLine();
            $this->warn('💡 پیشنهادات بهبود:');
            foreach ($recommendations as $recommendation) {
                $this->line($recommendation);
            }
        }
    }

    /**
     * مدیریت خطاها
     */
    private function handleError(\Exception $e, string $filePath, int $userId): void
    {
        $this->error('❌ خطا در پردازش: ' . $e->getMessage());

        // اطلاعات تکمیلی خطا
        if (str_contains($e->getMessage(), 'JSON')) {
            $this->warn('💡 احتمالاً فایل JSON معتبر نیست. آن را بررسی کنید');
        }

        Log::error('خطا در CategoryMatcherBot', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'file' => $filePath,
            'user_id' => $userId
        ]);

        $this->newLine();
        $this->warn('🔍 برای اطلاعات بیشتر لاگ‌ها را بررسی کنید');
    }

    /**
     * فرمت کردن اندازه فایل
     */
    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, $precision) . ' ' . $units[$i];
    }
}
