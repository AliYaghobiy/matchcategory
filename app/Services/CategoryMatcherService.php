<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Category;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CategoryMatcherService
{
    private int $userId;
    private array $stats = [
        'processed' => 0,
        'matched' => 0,
        'not_found' => 0,
        'categories_created' => 0
    ];

    public function __construct(int $userId = 39)
    {
        $this->userId = $userId;
    }

    /**
     * پردازش اصلی فایل JSON
     */
    public function processFile(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new \Exception("فایل یافت نشد: {$filePath}");
        }

        $jsonData = json_decode(file_get_contents($filePath), true);

        if (!$jsonData) {
            throw new \Exception("خطا در خواندن فایل JSON");
        }

        foreach ($jsonData as $item) {
            $this->processItem($item);
        }

        return $this->getStats();
    }

    /**
     * پردازش یک آیتم از فایل
     */
    private function processItem(array $item): void
    {
        $this->stats['processed']++;

        if (!isset($item['title']) || !isset($item['categories'])) {
            Log::warning("آیتم ناقص یافت شد", ['item' => $item]);
            return;
        }

        $product = $this->findProduct($item['title']);

        if (!$product) {
            $this->stats['not_found']++;
            Log::info("محصول یافت نشد: {$item['title']}");
            return;
        }

        $this->assignCategories($product, $item['categories']);
        $this->stats['matched']++;
    }

    /**
     * یافتن محصول بر اساس عنوان
     */
    private function findProduct(string $title): ?Product
    {
        // جستجوی مستقیم
        $product = Product::where('user_id', $this->userId)
            ->where('title', $title)
            ->first();

        if ($product) {
            return $product;
        }

        // جستجوی فازی
        return $this->fuzzySearchProduct($title);
    }

    /**
     * جستجوی فازی محصولات
     */
    private function fuzzySearchProduct(string $title): ?Product
    {
        $normalizedTitle = $this->normalizeText($title);

        $products = Product::where('user_id', $this->userId)
            ->select(['id', 'title'])
            ->get();

        $bestMatch = null;
        $bestSimilarity = 0;

        foreach ($products as $product) {
            $normalizedProductTitle = $this->normalizeText($product->title);
            $similarity = $this->calculateSimilarity($normalizedTitle, $normalizedProductTitle);

            if ($similarity > $bestSimilarity && $similarity > 85) {
                $bestMatch = $product;
                $bestSimilarity = $similarity;
            }
        }

        if ($bestMatch && $bestSimilarity > 90) {
            Log::info("محصول با جستجوی فازی یافت شد", [
                'original' => $title,
                'found' => $bestMatch->title,
                'similarity' => $bestSimilarity
            ]);
        }

        return $bestMatch;
    }

    /**
     * تخصیص دسته‌بندی‌ها به محصول
     */
    private function assignCategories(Product $product, array $categories): void
    {
        DB::beginTransaction();

        try {
            // حذف دسته‌بندی‌های قبلی
            $product->categories()->detach();

            $categoryIds = [];

            // مرتب‌سازی دسته‌بندی‌ها بر اساس سطح
            usort($categories, fn($a, $b) => ($b['level'] ?? 0) <=> ($a['level'] ?? 0));

            foreach ($categories as $categoryData) {
                if (!isset($categoryData['name'])) {
                    continue;
                }

                $category = $this->findOrCreateCategory($categoryData['name'], $categoryData['level'] ?? null);

                if ($category) {
                    $categoryIds[] = $category->id;
                }
            }

            // تخصیص دسته‌بندی‌های جدید
            if (!empty($categoryIds)) {
                $product->categories()->attach($categoryIds);
            }

            DB::commit();

            Log::info("دسته‌بندی‌های محصول به‌روزرسانی شد", [
                'product_id' => $product->id,
                'product_title' => $product->title,
                'categories_count' => count($categoryIds)
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("خطا در تخصیص دسته‌بندی", [
                'product_id' => $product->id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * یافتن یا ایجاد دسته‌بندی
     */
    private function findOrCreateCategory(string $categoryName, ?int $level = null): ?Category
    {
        // جستجوی مستقیم
        $category = Category::where('name', $categoryName)->first();

        if ($category) {
            return $category;
        }

        // جستجوی فازی
        $category = $this->fuzzySearchCategory($categoryName);

        if ($category) {
            return $category;
        }

        // ایجاد دسته‌بندی جدید
        return $this->createCategory($categoryName, $level);
    }

    /**
     * جستجوی فازی دسته‌بندی‌ها
     */
    private function fuzzySearchCategory(string $categoryName): ?Category
    {
        $normalizedName = $this->normalizeText($categoryName);

        $categories = Category::select(['id', 'name'])->get();

        foreach ($categories as $category) {
            $normalizedCatName = $this->normalizeText($category->name);
            $similarity = $this->calculateSimilarity($normalizedName, $normalizedCatName);

            if ($similarity > 85) {
                Log::info("دسته‌بندی با جستجوی فازی یافت شد", [
                    'original' => $categoryName,
                    'found' => $category->name,
                    'similarity' => $similarity
                ]);
                return $category;
            }
        }

        return null;
    }

    /**
     * ایجاد دسته‌بندی جدید
     */
    private function createCategory(string $categoryName, ?int $level = null): ?Category
    {
        try {
            $category = Category::create([
                'name' => $categoryName,
                'slug' => $this->generateUniqueSlug($categoryName),
                'nameSeo' => $categoryName,
                'type' => 0,
            ]);

            $this->stats['categories_created']++;

            Log::info("دسته‌بندی جدید ایجاد شد", [
                'name' => $categoryName,
                'level' => $level,
                'id' => $category->id
            ]);

            return $category;

        } catch (\Exception $e) {
            Log::error("خطا در ایجاد دسته‌بندی جدید", [
                'name' => $categoryName,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * یکسان‌سازی متن
     */
    private function normalizeText(string $text): string
    {
        $text = trim($text);
        $text = preg_replace('/\s+/', ' ', $text);
        $text = str_replace(['،', '؍', '٫', '،'], ',', $text);
        $text = str_replace(['‌'], ' ', $text);
        $text = mb_strtolower($text);

        // حذف کاراکترهای خاص
        $text = preg_replace('/[^\x{0600}-\x{06FF}\x{200C}\x{200D}a-zA-Z0-9\s,.-]/u', '', $text);

        return $text;
    }

    /**
     * محاسبه میزان شباهت بین دو متن
     */
    private function calculateSimilarity(string $text1, string $text2): float
    {
        $similarity = 0;
        similar_text($text1, $text2, $similarity);

        // اعمال وزن بیشتر برای کلمات کلیدی مشترک
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
     * تولید slug یکتا
     */
    private function generateUniqueSlug(string $text): string
    {
        $slug = $this->createSlug($text);
        $originalSlug = $slug;
        $counter = 1;

        while (Category::where('slug', $slug)->exists()) {
            $slug = $originalSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    /**
     * ایجاد slug از متن
     */
    private function createSlug(string $text): string
    {
        $slug = $this->normalizeText($text);
        $slug = str_replace([' ', ','], '-', $slug);
        $slug = preg_replace('/[^a-zA-Z0-9\x{0600}-\x{06FF}\-]/u', '', $slug);
        $slug = preg_replace('/-+/', '-', $slug);
        $slug = trim($slug, '-');

        return $slug ?: 'category-' . time();
    }

    /**
     * دریافت آمار پردازش
     */
    public function getStats(): array
    {
        $successRate = $this->stats['processed'] > 0
            ? round(($this->stats['matched'] / $this->stats['processed']) * 100, 2)
            : 0;

        return array_merge($this->stats, ['success_rate' => $successRate]);
    }

    /**
     * تنظیم user_id
     */
    public function setUserId(int $userId): self
    {
        $this->userId = $userId;
        return $this;
    }
    public function getUserId(): int
    {
        return $this->userId;
    }
}
