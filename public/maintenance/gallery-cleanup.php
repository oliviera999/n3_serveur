<?php
header('Content-Type: text/plain; charset=utf-8');
$root = dirname(__DIR__, 2);
$dir = $root . '/uploads/msp1';
$count = 0;
foreach (scandir($dir) as $f) {
    if (str_starts_with($f, 'TEST_') || $f === '2026-03-20_19-14-36_9d6df2c5.jpg') {
        unlink($dir . '/' . $f);
        echo "Supprimé: $f\n";
        $count++;
    }
}
echo "Total supprimé: $count fichiers\n";
