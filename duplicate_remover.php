<?php

declare(strict_types=1);
function get_file_list_recursively(string $dir, bool $realpath = false): array
{
    $files = array();
    $files = [];
    foreach ((new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS))) as $file) {
        /** @var SplFileInfo $file */
        if ($realpath) {
            $files[] = $file->getRealPath();
        } else {
            $files[] = $file->getPathname();
        }
    }
    return $files;
}
$files = get_file_list_recursively(__DIR__ . DIRECTORY_SEPARATOR . "downloads", true);

$hashes = [];
$duplicates = [];
foreach ($files as $file) {
    $hash = hash_file('tiger128,3', $file, true);
    if (isset($hashes[$hash])) {
        echo $file . ' is a duplicate of ' . $hashes[$hash] . PHP_EOL;
        $duplicates[$file] = $hashes[$hash];
        //unlink($file);
    } else {
        $hashes[$hash] = $file;
    }
}
var_dump($duplicates);
echo 'Found ' . count($duplicates) . ' duplicates' . PHP_EOL;
echo "press Y{enter} to delete duplicates" . PHP_EOL;
$Y = fgets(STDIN);
if (trim($Y) === 'Y') {
    foreach ($duplicates as $duplicate => $original) {
        echo 'deleting duplicates' . $duplicate . PHP_EOL;
        unlink($duplicate);
    }
} else {
    echo 'not deleting duplicates' . PHP_EOL;
    var_dump($Y);
}
