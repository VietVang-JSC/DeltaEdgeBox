<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$t = App\Models\Table::find(246);
if ($t) {
    echo "tablename: {$t->tablename}\n";
    echo "name: {$t->name}\n";
    echo "id: {$t->id}\n";
} else {
    echo "Table 246 not found\n";
}
