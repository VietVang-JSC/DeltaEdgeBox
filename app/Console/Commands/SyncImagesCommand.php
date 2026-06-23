<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncImagesCommand extends Command
{
    protected $signature = 'edge:sync-images {--force : Re-download all product images}';

    protected $description = 'Download product images from cloud and cache locally on edge box';

    public function handle(): void
    {
        $force = (bool) $this->option('force');
        $this->info('Starting product image sync...');

        $query = Product::whereNotNull('image')
            ->where('image', '!=', '');

        if (!$force) {
            $query->where('image', 'LIKE', 'http%');
        }

        $products = $query->get();
        $total = $products->count();
        $downloaded = 0;
        $failed = 0;

        $this->info("Found {$total} product image(s) to process.");

        $cloudBaseUrl = rtrim(config('app.cloud_api_url', env('CLOUD_API_URL', '')), '/');
        $dir = storage_path('app/public/product-images');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        foreach ($products as $product) {
            $imageUrl = $product->image;
            $isLocalPath = str_contains($imageUrl, '/storage/product-images/');

            // Local path: check if file exists
            if ($isLocalPath) {
                $filename = basename($imageUrl);
                $localFile = $dir . '/' . $filename;
                if (file_exists($localFile)) {
                    continue; // File already exists
                }
                // File missing: try to reconstruct cloud URL from product's original cloud data
                // Fallback: use product code to construct a guessed URL
                if (!empty($cloudBaseUrl)) {
                    $imageUrl = $cloudBaseUrl . '/storage/product/' . $filename;
                } else {
                    $failed++;
                    $this->warn("  [SKIP] {$product->code} - local file missing, no cloud base URL configured");
                    continue;
                }
            }

            // For URL-based images: validate or prepend cloud base URL
            if (!filter_var($imageUrl, FILTER_VALIDATE_URL)) {
                // Relative path like "product/xxx.jpg" → prepend cloud base URL
                if (!empty($cloudBaseUrl) && !str_contains($imageUrl, '/')) {
                    $imageUrl = $cloudBaseUrl . '/storage/' . $imageUrl;
                } elseif (!empty($cloudBaseUrl) && str_starts_with($imageUrl, 'product/')) {
                    $imageUrl = $cloudBaseUrl . '/storage/' . $imageUrl;
                } else {
                    $failed++;
                    $this->warn("  [SKIP] {$product->code} - invalid URL: {$imageUrl}");
                    continue;
                }
            }

            $ext = pathinfo(parse_url($imageUrl, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'jpg';
            $filename = $product->code . '.' . $ext;
            $localPath = '/storage/product-images/' . $filename;

            // Skip if local file already exists (unless force)
            if (!$force && file_exists($dir . '/' . $filename)) {
                continue;
            }

            try {
                $imageContent = @file_get_contents($imageUrl);
                if ($imageContent !== false) {
                    file_put_contents($dir . '/' . $filename, $imageContent);
                    Product::where('id', $product->id)->update(['image' => $localPath]);
                    $downloaded++;
                    $this->line("  [OK] {$product->code} -> {$localPath}");
                } else {
                    $failed++;
                    $this->warn("  [FAIL] {$product->code} - could not fetch {$imageUrl}");
                }
            } catch (\Throwable $th) {
                $failed++;
                $this->warn("  [FAIL] {$product->code} - {$th->getMessage()}");
                Log::warning('Image sync failed', ['code' => $product->code, 'error' => $th->getMessage()]);
            }
        }

        $this->newLine();
        $this->info("Done. Downloaded: {$downloaded}, Failed: {$failed}, Total: {$total}");
    }
}
