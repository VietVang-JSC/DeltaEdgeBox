<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$products = App\Models\Product::whereNotNull('image')->where('image', '!=', '')->limit(5)->get();
foreach ($products as $p) {
    echo "code={$p->code} image={$p->image}\n";
}

echo "\n--- Files in storage ---\n";
$files = glob('storage/app/public/product-images/*');
if ($files) {
    foreach (array_slice($files, 0, 10) as $f) {
        echo basename($f) . "\n";
    }
} else {
    echo "(no files)\n";
}
