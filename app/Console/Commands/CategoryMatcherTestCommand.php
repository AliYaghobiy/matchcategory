<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\Category;
use App\Services\CategoryMatcherService;
use Illuminate\Console\Command;

class CategoryMatcherTestCommand extends Command
{
    protected $signature = 'bot:test-categories
                          {--user-id=39 : شناسه کاربر}
                          {--sample-size=10 : تعداد نمونه برای تست}
                          {--show-products : نمایش لیست محصولات کاربر}
                          {--show-categories : نمایش لیست دسته‌بندی‌ها}
                          {--test-similarity= : تست میزان شباهت دو متن}';

    protected $description = 'ابزارهای تست و عیب‌یابی ربات تطبیق دسته‌بندی‌ها';

    public function handle()
    {
        $userId = (int) $this->option('user-id');

        $this->info("🧪 ابزار تست ربات دسته‌بندی - کاربر: {$userId}");
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        // تست شباهت متن
        if ($similarity = $this->option('test-similarity')) {
            $this->testSimilarity($similarity);
            return;
        }

        // نمایش محصولات
        if ($this->option('show-products')) {
            $this->showUserProducts($userId);
        }

        // نمایش دسته‌بندی‌ها
        if ($this->option('show-categories')) {
            $this->showCategories();
        }

        // تست نمونه
        $this->runSampleTest($userId);
    }

    private function showUserProducts(int $userId): void
    {
        $this->info("📦 محصولات کاربر {$userId}:");
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        $products = Product::where('user_id', $userId)
            ->select(['id', 'title', 'status'])
            ->orderBy('id', 'desc')
            ->limit(20)
            ->get();

        if ($products->isEmpty()) {
            $this->warn("❌ هیچ محصولی برای کاربر {$userId} یافت نشد");
            return;
        }

        $this->table(
            ['ID', 'عنوان محصول', 'وضعیت'],
            $products->map(fn($p) => [
                $p->id,
                mb_substr($p->title, 0, 60) . (mb_strlen($p->title) > 60 ? '...' : ''),
                $p->status ? '✅ فعال' : '❌ غیرفعال'
            ])->toArray()
        );

        $totalCount = Product::where('user_id', $userId)->count();
        $this->info("📊 مجموع محصولات: {$totalCount}");
        $this->newLine();
    }

    private function showCategories(): void
    {
        $this->info("📂 دسته‌بندی‌های موجود:");
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        $categories = Category::select(['id', 'name', 'slug'])
            ->orderBy('name')
            ->limit(30)
            ->get();

        if ($categories->isEmpty()) {
            $this->warn("❌ هیچ دسته‌بندی یافت نشد");
            return;
        }

        $this->table(
            ['ID', 'نام دسته‌بندی', 'Slug'],
            $categories->map(fn($c) => [
                $c->id,
                $c->name,
                $c->slug
            ])->toArray()
        );

        $totalCount = Category::count();
        $this->info("📊 مجموع دسته‌بندی‌ها: {$totalCount}");
        $this->newLine();
    }

    private function testSimilarity(string $testPair): void
    {
        $parts = explode('|', $testPair);

        if (count($parts) !== 2) {
            $this->error('❌ فرمت صحیح: --test-similarity="متن اول|متن دوم"');
            return;
        }

        $text1 = trim($parts[0]);
        $text2 = trim($parts[1]);

        $service = new CategoryMatcherService();
        $reflection = new \ReflectionClass($service);

        // دسترسی به متدهای private
        $normalizeMethod = $reflection->getMethod('normalizeText');
        $normalizeMethod->setAccessible(true);

        $similarityMethod = $reflection->getMethod('calculateSimilarity');
        $similarityMethod->setAccessible(true);

        $normalized1 = $normalizeMethod->invoke($service, $text1);
        $normalized2 = $normalizeMethod->invoke($service, $text2);
        $similarity = $similarityMethod->invoke($service, $normalized1, $normalized2);

        $this->info("🔍 تست میزان شباهت:");
        $this->line("متن اول: {$text1}");
        $this->line("متن دوم: {$text2}");
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->line("متن اول (یکسان‌سازی شده): {$normalized1}");
        $this->line("متن دوم (یکسان‌سازی شده): {$normalized2}");
        $this->line("میزان شباهت: {$similarity}%");

        if ($similarity > 90) {
            $this->info("✅ شباهت بسیار بالا - تطبیق قطعی");
        } elseif ($similarity > 85) {
            $this->warn("⚠️  شباهت بالا - احتمال تطبیق");
        } else {
            $this->error("❌ شباهت پایین - عدم تطبیق");
        }
    }

    private function runSampleTest(int $userId): void
    {
        $this->info("🎯 تست نمونه:");
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        $sampleSize = (int) $this->option('sample-size');
        $products = Product::where('user_id', $userId)
            ->inRandomOrder()
            ->limit($sampleSize)
            ->get(['id', 'title']);

        if ($products->isEmpty()) {
            $this->warn("❌ هیچ محصولی برای تست یافت نشد");
            return;
        }

        $service = new CategoryMatcherService($userId);
        $reflection = new \ReflectionClass($service);
        $findMethod = $reflection->getMethod('findProduct');
        $findMethod->setAccessible(true);

        $found = 0;
        $testResults = [];

        foreach ($products as $product) {
            $foundProduct = $findMethod->invoke($service, $product->title);
            $isFound = $foundProduct && $foundProduct->id === $product->id;

            if ($isFound) {
                $found++;
            }

            $testResults[] = [
                $product->id,
                mb_substr($product->title, 0, 50) . '...',
                $isFound ? '✅ یافت شد' : '❌ یافت نشد'
            ];
        }

        $this->table(['ID محصول', 'عنوان', 'نتیجه'], $testResults);

        $successRate = round(($found / count($products)) * 100, 2);
        $this->info("📊 نرخ موفقیت تست: {$successRate}% ({$found}/{$sampleSize})");

        if ($successRate < 80) {
            $this->newLine();
            $this->warn("⚠️  نکات بهبود:");
            $this->line("• بررسی صحت user_id");
            $this->line("• بررسی وجود کاراکترهای خاص در عناوین");
            $this->line("• تنظیم دقیق‌تر الگوریتم جستجو");
        }
    }
}
