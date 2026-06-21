<?php
/**
 * Build PHAR archive for DeltaPOS Edge Box
 * Usage: php build-phar.php
 *
 * Creates edge-box.phar containing all source code.
 * public/index.php auto-detects PHAR and loads from it.
 * Filesystem files are kept as fallback.
 */

$srcDir   = __DIR__;
$outDir   = $srcDir . '/build';
$pharFile = $outDir . '/edge-box.phar';

if (!is_dir($outDir)) {
    mkdir($outDir, 0755, true);
}
if (file_exists($pharFile)) {
    unlink($pharFile);
}

$excludePatterns = [
    '#\.git#', '#node_modules#', '#tests#', '#test#',
    '#\.log#', '#\.zip#', '#\.env#', '#build#', '#docs#',
    '#storage/#', '#public/#', '#bootstrap/#', '#scripts/#',
    '#vendor/bin#', '#vendor/phpunit#', '#vendor/mockery#',
    '#vendor/fakerphp#', '#vendor/nunomaduro#',
    '#vendor/spatie/ignition#', '#vendor/spatie/flare#',
    '#vendor/phpdocumentor#', '#vendor/sebastian#',
    '#vendor/phpunit#', '#vendor/myclabs#', '#vendor/phar-io#',
    '#vendor/theseer#', '#vendor/pestphp#', '#vendor/phpstan#',
];

$phar = new Phar($pharFile, FilesystemIterator::CURRENT_AS_FILEINFO | FilesystemIterator::KEY_AS_FILENAME, 'edge-box.phar');
$phar->startBuffering();

$count = 0;
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    $path = $file->getRealPath();
    $localPath = substr($path, strlen($srcDir) + 1);

    $skip = false;
    foreach ($excludePatterns as $pattern) {
        if (preg_match($pattern, $localPath)) {
            $skip = true;
            break;
        }
    }
    if ($skip) continue;

    if (!preg_match('#\.(php|json|yaml|yml|blade\.php)$#', $localPath) && !is_dir($path)) {
        continue;
    }

    $phar->addFile($path, $localPath);
    $count++;
}

echo "Added $count files to PHAR\n";

$stub = "<?php
Phar::mapPhar('edge-box.phar');
define('EDGE_PHAR_PATH', 'phar://' . __FILE__);
__HALT_COMPILER();
";

$phar->setStub($stub);
$phar->stopBuffering();

$size = round(filesize($pharFile) / 1048576, 2);
echo "PHAR created: $pharFile ($size MB)\n";
