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

        foreach ($products as $product) {
            $cloudImageUrl = $product->image;
            if (!filter_var($cloudImageUrl, FILTER_VALIDATE_URL)) {
                continue;
            }

            $dir = storage_path('app/public/product-images');
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            $ext = pathinfo(parse_url($cloudImageUrl, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'jpg';
            $filename = $product->code . '.' . $ext;
            $localPath = '/storage/product-images/' . $filename;

            try {
                $imageContent = @file_get_contents($cloudImageUrl);
                if ($imageContent !== false) {
                    file_put_contents($dir . '/' . $filename, $imageContent);
                    Product::where('id', $product->id)->update(['image' => $localPath]);
                    $downloaded++;
                    $this->line("  [OK] {$product->code} -> {$localPath}");
                } else {
                    $failed++;
                    $this->warn("  [FAIL] {$product->code} - could not fetch {$cloudImageUrl}");
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
