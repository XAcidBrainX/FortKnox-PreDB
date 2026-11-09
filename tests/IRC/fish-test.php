<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
use FortKnox\IRC\FiSH;

foreach ([false, true] as $cbc) {
    $key = ($cbc ? 'cbc:' : '') . 'FortKnox-FiSH10-test-key';
    foreach (['hello', '12345678', 'A longer message with spaces!', 'Ümlaut / UTF-8 ✓'] as $plain) {
        $encrypted = FiSH::encrypt($plain, $key);
        $decrypted = FiSH::decrypt($encrypted, $key);
        if ($decrypted !== $plain) {
            fwrite(STDERR, "FAIL " . ($cbc ? 'CBC' : 'ECB') . "\n");
            exit(1);
        }
    }
}
echo "FiSH ECB/CBC round-trip tests passed.\n";
