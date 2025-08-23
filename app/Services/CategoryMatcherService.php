<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Brand;
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
        'categories_created' => 0,
        'brands_created' => 0,
        'brands_assigned' => 0
    ];

    private array $processedProducts = []; // محصولاتی که قبلاً پردازش شده‌اند

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

        // مرحله اول: پردازش عناوین دقیق
        Log::info("شروع مرحله اول: جستجوی دقیق عناوین");
        foreach ($jsonData as $item) {
            $this->processItemExactMatch($item);
        }

        // مرحله دوم: پردازش عناوین مشابه (فقط آنهایی که در مرحله اول پردازش نشده‌اند)
        Log::info("شروع مرحله دوم: جستجوی فازی عناوین");
        foreach ($jsonData as $item) {
            if (!$this->isItemProcessed($item)) {
                $this->processItemFuzzyMatch($item);
            }
        }

        return $this->getStats();
    }

    /**
     * بررسی اینکه آیا آیتم قبلاً پردازش شده است
     */
    private function isItemProcessed(array $item): bool
    {
        if (!isset($item['title'])) {
            return true;
        }

        $normalizedTitle = $this->normalizeText($item['title']);
        return in_array($normalizedTitle, $this->processedProducts);
    }

    /**
     * پردازش آیتم با جستجوی دقیق
     */
    private function processItemExactMatch(array $item): void
    {
        $this->stats['processed']++;

        if (!isset($item['title']) || !isset($item['categories'])) {
            Log::warning("آیتم ناقص یافت شد", ['item' => $item]);
            return;
        }

        $product = $this->findProductExact($item['title']);

        if (!$product) {
            Log::info("محصول با جستجوی دقیق یافت نشد: {$item['title']}");
            return;
        }

        $this->processMatchedProduct($product, $item);
        $this->markItemAsProcessed($item['title']);
    }

    /**
     * پردازش آیتم با جستجوی فازی
     */
    private function processItemFuzzyMatch(array $item): void
    {
        if (!isset($item['title']) || !isset($item['categories'])) {
            return;
        }

        $product = $this->findProductFuzzy($item['title']);

        if (!$product) {
            $this->stats['not_found']++;
            Log::info("محصول با جستجوی فازی یافت نشد: {$item['title']}");
            return;
        }

        $this->processMatchedProduct($product, $item);
        $this->markItemAsProcessed($item['title']);
    }

    /**
     * علامت‌گذاری آیتم به عنوان پردازش شده
     */
    private function markItemAsProcessed(string $title): void
    {
        $normalizedTitle = $this->normalizeText($title);
        if (!in_array($normalizedTitle, $this->processedProducts)) {
            $this->processedProducts[] = $normalizedTitle;
        }
    }

    /**
     * پردازش محصول تطبیق یافته
     */
    private function processMatchedProduct(Product $product, array $item): void
    {
        Log::info("محصول تطبیق یافت", [
            'product_id' => $product->id,
            'product_title' => $product->title,
            'json_title' => $item['title']
        ]);

        // تخصیص دسته‌بندی‌ها
        $this->assignCategories($product, $item['categories']);

        // تخصیص برند
        if (isset($item['brand']) && !empty(trim($item['brand']))) {
            $this->assignBrand($product, trim($item['brand']));
        }

        // تخصیص توضیحات کلیدی و عمومی
        $this->assignSpecifications($product, $item);

        $this->stats['matched']++;
    }

    /**
     * یافتن محصول با جستجوی دقیق
     */
    private function findProductExact(string $title): ?Product
    {
        return Product::where('user_id', $this->userId)
            ->where('title', $title)
            ->first();
    }

    /**
     * یافتن محصول با جستجوی فازی بهبود یافته
     */
    private function findProductFuzzy(string $title): ?Product
    {
        $titleWords = $this->extractWords($title);

        if (count($titleWords) < 3) {
            Log::info("عنوان کمتر از 3 کلمه دارد، جستجوی فازی انجام نمی‌شود", [
                'title' => $title,
                'word_count' => count($titleWords)
            ]);
            return null;
        }

        // دریافت محصولاتی که هنوز پردازش نشده‌اند
        $unprocessedProducts = $this->getUnprocessedProducts();

        $bestMatch = null;
        $bestScore = 0;

        foreach ($unprocessedProducts as $product) {
            $productWords = $this->extractWords($product->title);

            $matchScore = $this->calculateWordMatchScore($titleWords, $productWords);

            if ($matchScore >= 2 && $matchScore > $bestScore) {
                $bestMatch = $product;
                $bestScore = $matchScore;

                Log::info("کاندید جدید برای تطبیق یافت شد", [
                    'json_title' => $title,
                    'product_title' => $product->title,
                    'match_score' => $matchScore,
                    'json_words' => $titleWords,
                    'product_words' => $productWords
                ]);
            }
        }

        if ($bestMatch && $bestScore >= 3) {
            Log::info("بهترین تطبیق فازی یافت شد", [
                'json_title' => $title,
                'product_title' => $bestMatch->title,
                'final_score' => $bestScore,
                'product_id' => $bestMatch->id
            ]);
        } else {
            Log::info("هیچ تطبیق معتبری یافت نشد", [
                'title' => $title,
                'best_score' => $bestScore,
                'minimum_required' => 3
            ]);
        }

        return $bestMatch;
    }

    /**
     * دریافت محصولاتی که هنوز پردازش نشده‌اند
     */
    private function getUnprocessedProducts(): \Illuminate\Database\Eloquent\Collection
    {
        $processedProductIds = [];

        if (!empty($this->processedProducts)) {
            // یافتن شناسه محصولاتی که قبلاً پردازش شده‌اند
            $processedProductIds = Product::where('user_id', $this->userId)
                ->get()
                ->filter(function ($product) {
                    $normalizedTitle = $this->normalizeText($product->title);
                    return in_array($normalizedTitle, $this->processedProducts);
                })
                ->pluck('id')
                ->toArray();
        }

        return Product::where('user_id', $this->userId)
            ->when(!empty($processedProductIds), function ($query) use ($processedProductIds) {
                return $query->whereNotIn('id', $processedProductIds);
            })
            ->select(['id', 'title'])
            ->get();
    }

    /**
     * استخراج کلمات معنادار از عنوان
     */
    private function extractWords(string $title): array
    {
        $normalizedTitle = $this->normalizeText($title);

        // حذف کلمات بی‌معنا
        $stopWords = ['و', 'در', 'با', 'به', 'از', 'برای', 'که', 'این', 'آن', 'تا', 'را', 'های'];

        $words = explode(' ', $normalizedTitle);
        $meaningfulWords = [];

        foreach ($words as $word) {
            $word = trim($word);
            // حذف کلمات کوتاه‌تر از 2 کاراکتر و کلمات بی‌معنا
            if (strlen($word) >= 2 && !in_array($word, $stopWords)) {
                $meaningfulWords[] = $word;
            }
        }

        return array_unique($meaningfulWords);
    }

    /**
     * محاسبه امتیاز تطبیق کلمات
     */
    private function calculateWordMatchScore(array $titleWords, array $productWords): int
    {
        $commonWords = array_intersect($titleWords, $productWords);
        $matchCount = count($commonWords);

        Log::debug("محاسبه امتیاز تطبیق کلمات", [
            'title_words' => $titleWords,
            'product_words' => $productWords,
            'common_words' => $commonWords,
            'match_count' => $matchCount
        ]);

        return $matchCount;
    }

    /**
     * تخصیص برند به محصول - کد تصحیح شده
     */
    private function assignBrand(Product $product, string $brandName): void
    {
        try {
            Log::info("شروع تخصیص برند", [
                'product_id' => $product->id,
                'product_title' => $product->title,
                'brand_name' => $brandName
            ]);

            $brand = $this->findOrCreateBrand($brandName);

            if ($brand) {
                // بررسی اینکه آیا این برند قبلاً به محصول اختصاص داده شده است
                $existingRelation = DB::table('brandables')
                    ->where('brandables_id', $product->id)
                    ->where('brandables_type', 'App\\Models\\Product')
                    ->where('brand_id', $brand->id)
                    ->exists();

                if (!$existingRelation) {
                    // اختصاص برند جدید با ساختار صحیح در جدول brandables
                    DB::table('brandables')->insert([
                        'brand_id' => $brand->id,
                        'brandables_id' => $product->id,
                        'brandables_type' => 'App\\Models\\Product'
                    ]);

                    $this->stats['brands_assigned']++;

                    Log::info("برند با موفقیت به محصول اختصاص داده شد", [
                        'product_id' => $product->id,
                        'product_title' => $product->title,
                        'brand_id' => $brand->id,
                        'brand_name' => $brand->name,
                        'original_brand_name' => $brandName
                    ]);
                } else {
                    Log::info("برند قبلاً به محصول اختصاص داده شده بود", [
                        'product_id' => $product->id,
                        'brand_id' => $brand->id,
                        'brand_name' => $brand->name
                    ]);
                }
            }

        } catch (\Exception $e) {
            Log::error("خطا در تخصیص برند", [
                'product_id' => $product->id,
                'brand' => $brandName,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
    /**
     * یافتن یا ایجاد برند
     */
    private function findOrCreateBrand(string $brandName): ?Brand
    {
        Log::info("جستجوی برند شروع شد", ['brand_name' => $brandName]);

        // جستجوی مستقیم
        $brand = Brand::where('name', $brandName)->first();

        if ($brand) {
            Log::info("برند با جستجوی مستقیم یافت شد", [
                'brand_id' => $brand->id,
                'brand_name' => $brand->name
            ]);
            return $brand;
        }

        // جستجوی فازی
        $brand = $this->fuzzySearchBrand($brandName);

        if ($brand) {
            Log::info("برند با جستجوی فازی یافت شد", [
                'brand_id' => $brand->id,
                'brand_name' => $brand->name,
                'original_name' => $brandName
            ]);
            return $brand;
        }

        // ایجاد برند جدید
        return $this->createBrand($brandName);
    }

    /**
     * جستجوی فازی برندها
     */
    private function fuzzySearchBrand(string $brandName): ?Brand
    {
        $normalizedName = $this->normalizeText($brandName);

        Log::info("شروع جستجوی فازی برند", [
            'original' => $brandName,
            'normalized' => $normalizedName
        ]);

        $brands = Brand::select(['id', 'name'])->get();

        $bestMatch = null;
        $bestSimilarity = 0;

        foreach ($brands as $brand) {
            $normalizedBrandName = $this->normalizeText($brand->name);
            $similarity = $this->calculateSimilarity($normalizedName, $normalizedBrandName);

            if ($similarity > $bestSimilarity && $similarity > 85) {
                $bestMatch = $brand;
                $bestSimilarity = $similarity;
            }
        }

        if ($bestMatch) {
            Log::info("بهترین تطبیق فازی برند یافت شد", [
                'original' => $brandName,
                'found' => $bestMatch->name,
                'similarity' => $bestSimilarity,
                'brand_id' => $bestMatch->id
            ]);
        } else {
            Log::info("هیچ تطبیق فازی برای برند یافت نشد", ['brand_name' => $brandName]);
        }

        return $bestMatch;
    }

    /**
     * ایجاد برند جدید
     */
    private function createBrand(string $brandName): ?Brand
    {
        try {
            Log::info("شروع ایجاد برند جدید", ['brand_name' => $brandName]);

            $brand = Brand::create([
                'name' => $brandName,
                'slug' => $this->generateUniqueBrandSlug($brandName),
                'nameSeo' => $brandName,
            ]);

            $this->stats['brands_created']++;

            Log::info("برند جدید با موفقیت ایجاد شد", [
                'brand_id' => $brand->id,
                'name' => $brandName,
                'slug' => $brand->slug
            ]);

            return $brand;

        } catch (\Exception $e) {
            Log::error("خطا در ایجاد برند جدید", [
                'name' => $brandName,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
     * تولید slug یکتا برای برند
     */
    private function generateUniqueBrandSlug(string $text): string
    {
        $slug = $this->createSlug($text);
        $originalSlug = $slug;
        $counter = 1;

        while (Brand::where('slug', $slug)->exists()) {
            $slug = $originalSlug . '-' . $counter;
            $counter++;
        }

        Log::info("Slug یکتا برای برند تولید شد", [
            'original_text' => $text,
            'slug' => $slug
        ]);

        return $slug;
    }

    /**
     * تخصیص توضیحات کلیدی و عمومی به محصول
     */
    private function assignSpecifications(Product $product, array $item): void
    {
        try {
            $specifications = [];
            $property = [];

            // پردازش key_specs
            if (isset($item['specifications']['key_specs']) && is_array($item['specifications']['key_specs'])) {
                foreach ($item['specifications']['key_specs'] as $spec) {
                    if (isset($spec['title']) && isset($spec['body'])) {
                        // فیلتر کردن داده‌های نامعتبر ترب
                        if ($spec['title'] === 'ترب' && $spec['body'] === 'ورود / ثبت نام') {
                            continue;
                        }
                        $property[] = [
                            'title' => $spec['title'],
                            'body' => $spec['body']
                        ];
                    }
                }
            }

            // پردازش general_specs
            if (isset($item['specifications']['general_specs']) && is_array($item['specifications']['general_specs'])) {
                foreach ($item['specifications']['general_specs'] as $spec) {
                    if (isset($spec['title']) && isset($spec['body'])) {
                        // فیلتر کردن داده‌های نامعتبر ترب
                        if ($spec['title'] === 'ترب' && $spec['body'] === 'ورود / ثبت نام') {
                            continue;
                        }
                        $specifications[] = [
                            'title' => $spec['title'],
                            'body' => $spec['body']
                        ];
                    }
                }
            }

            // بروزرسانی فیلدهای محصول
            $updateData = [];

            if (!empty($property)) {
                $updateData['property'] = $property;
            }

            if (!empty($specifications)) {
                $updateData['specifications'] = $specifications;
            }

            if (!empty($updateData)) {
                $product->update($updateData);

                Log::info("توضیحات محصول به‌روزرسانی شد", [
                    'product_id' => $product->id,
                    'key_specs_count' => count($property),
                    'general_specs_count' => count($specifications)
                ]);
            }

        } catch (\Exception $e) {
            Log::error("خطا در تخصیص توضیحات", [
                'product_id' => $product->id,
                'error' => $e->getMessage()
            ]);
        }
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

            if ($similarity > 75) {
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

        return array_merge($this->stats, [
            'success_rate' => $successRate,
            'processed_products_count' => count($this->processedProducts)
        ]);
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

    /**
     * ریست کردن وضعیت پردازش
     */
    public function resetProcessingState(): self
    {
        $this->processedProducts = [];
        $this->stats = [
            'processed' => 0,
            'matched' => 0,
            'not_found' => 0,
            'categories_created' => 0,
            'brands_created' => 0,
            'brands_assigned' => 0
        ];
        return $this;
    }
}
