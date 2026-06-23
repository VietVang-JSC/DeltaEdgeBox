<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$img = App\Models\Product::where('code', 'TRA01')->pluck('image')->first();
echo "TRA01 image: " . ($img ?: 'NULL') . PHP_EOL;

$img2 = App\Models\Product::where('code', 'SP98196126')->pluck('image')->first();
echo "SP98196126 image: " . ($img2 ?: 'NULL') . PHP_EOL;
