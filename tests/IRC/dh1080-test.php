<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use FortKnox\IRC\FiSHDh1080;

$a = FiSHDh1080::generate();
$b = FiSHDh1080::generate();
if (strlen($a['public']) !== 181 || !preg_match('/^[A-Za-z0-9+\/]{180}A$/', $a['public'])) {
    fwrite(STDERR, "DH1080 FiSH Base64 public-key format test failed.\\n");
    exit(1);
}

$secretA = FiSHDh1080::compute($a['private'], $b['public']);
$secretB = FiSHDh1080::compute($b['private'], $a['public']);

if (!hash_equals($secretA, $secretB) || strlen($secretA) !== 43) {
    fwrite(STDERR, "DH1080 shared-secret test failed.\n");
    exit(1);
}

echo "DH1080 shared-secret test passed.\n";
