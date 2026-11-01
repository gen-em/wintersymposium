<?php
declare(strict_types=1);

/*
 * TOTP-Code für Tests (AP-17 Unterpunkt 11, V-51): nutzt totp_lib, damit
 * http_tests.php und der Browser-Durchlauf dieselbe Rechnung verwenden wie
 * die App. Aufruf:
 *
 *   php tools/tests/totp_code.php <geheimnis-base32> [unix-zeit]
 */

require dirname(__DIR__, 2) . '/server/lib/totp_lib.php';

if ($argc < 2) {
    fwrite(STDERR, "Aufruf: php tools/tests/totp_code.php <geheimnis-base32> [unix-zeit]\n");
    exit(2);
}
echo totp_code(totp_base32_dekodieren($argv[1]), isset($argv[2]) ? (int)$argv[2] : time()), "\n";
