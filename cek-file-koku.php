<?php
/**
 * Cek file Koku: bandingkan isi file di project dengan versi yang lolos 74 tes.
 * Pakai: php cek-file-koku.php   (jalankan dari root wallet-backend)
 * Aman dihapus setelah semua OK.
 */

$expected = [
    'database/migrations/2026_09_11_000001_convert_users_to_pin_auth.php' => '5c4e56a891b25405a43f06aa3b381e2358ad3d1635bd6873037441c1e1eed76a',
    'database/migrations/2026_09_11_000002_create_passkeys_table.php' => '954f443bb78716d7d088742c6835c1512afb9bbe70a95ef033b9c588e564edf8',
    'config/koku.php' => '56fe969de78e34ecc2e90f67b3f9fed8dbf25611a4b061fba60f9d4f9a73e48a',
    'app/Support/PhoneNumber.php' => '5af39a3fc3e0f0983b716cd9630384544139ba5c83bfcc433d32e27b22e8b04e',
    'app/Rules/StrongPin.php' => '7944635a05e22ffce33c600c09d8270211ab9fa2c9499e52c53915284e3fa8b6',
    'app/Services/Otp/OtpSender.php' => '056797d3be23f8ec3772f41ddc42f38704c73525c67c23030c476e8eeeb8fec4',
    'app/Services/Otp/LogOtpSender.php' => 'f8a83d64602a08443642f3cddc6e31a342fd056e0f66e07adb4662e050667450',
    'app/Services/Otp/FonnteOtpSender.php' => '225770929b9b9c25501f970c64f0dad5b95b520da71786fe370e3226461e6eaa',
    'app/Services/OtpService.php' => '80857d75307e92e6ba153e6327384fc015f6b62e08c602412c50bec12106c8ec',
    'app/Services/RegistrationService.php' => '9e9c17344a92a45c3cdabf3267f2e9aec4047d1659d0c4fabea2a1e01afc2e5a',
    'app/Services/PinAuthService.php' => '60124d0b3fbc0df858374add76658250f548cfadbdb711802a1650b18d437d5f',
    'app/Services/PasskeyService.php' => 'b939d75389c16c92c8386b0dea49b246b301fa20e2df1b1ce4eb45ffb5b3c3e4',
    'app/Exceptions/OtpDeliveryException.php' => '464681edc1de342e70a65d1798bf2f6c36a1d54cc60d08bd70e478cb8ce99aa4',
    'app/Exceptions/OtpException.php' => '1e8372c49726260500a1691f397c516cc3cc722d265ea79a4f71aef9c629afac',
    'app/Exceptions/RegistrationExpiredException.php' => '69e38c737dc3c04205fdbff1749a948ab1ae4f925cdc761c1957784290583e23',
    'app/Exceptions/PinException.php' => '20add05a7c95c1883953e27f15dc8c3f9d6b8eca6f4482dbf1abd9e8169c24cf',
    'app/Exceptions/PasskeyException.php' => '9e52d76ad67a1faf01a8910f8161eecb754b89fbdce592e8badfc93c794b7e5f',
    'app/Http/Requests/Auth/RegisterStartRequest.php' => '727674de6f959dc3905ba6e79f77aa85ab7002563749c53caece5ebc05092424',
    'app/Http/Requests/Auth/RegisterVerifyRequest.php' => '98e7eca1bd2a7371dabd48ee55464011515821e168a297ad188a57e7944a5a43',
    'app/Http/Requests/Auth/LoginRequest.php' => '9a2ab08f300f1bc51d770be37890731ed4ff82468ffdacf215d009f6575bdf9e',
    'app/Http/Requests/Wallet/TransferRequest.php' => '5cdec7e64c9c1698f1e2e3d55207a3a6a2f2ff3ce9c4334847cbe0934ec712fb',
    'app/Http/Controllers/Api/AuthController.php' => 'fb0b50f3594cb5e979c7ec75fe6ebc85e4f421f8f0a989fdfa59668dea566b40',
    'app/Http/Controllers/Api/PasskeyController.php' => '67a60d107981d359c87792e6094ba83894966d8105152b0276580c7028386ae8',
    'app/Http/Controllers/Concerns/IssuesAuthTokens.php' => 'e51ca27f44bb2b247a5bd39f4b5490119a9aa5fec96255fa1ae3384dff139c66',
    'app/Models/User.php' => '1485c95a6b0f2f05a861ebe9c7052df184c9396f74846cbe803ae38a4af6b284',
    'app/Models/Passkey.php' => '6955b52d0094decc71f4453e78bae267fca6c376897e704055da1d230cf8da41',
    'routes/api.php' => '1a5211d3923e0b19a4f6fcd804f783ecf9e5d3fa147efacad7ffe710667e0dc4',
    'database/factories/UserFactory.php' => '9a7efbc421a4b4396eac31b9c89a7d2377a434781f784a39de3ea0e0fc63078d',
    'database/seeders/DatabaseSeeder.php' => '766933375e38714bd753566f2b25859ad647decb8f6fe8d785ec789ce6fa63e7',
    'tests/Support/FakeAuthenticator.php' => 'c9a5779b008ca11ef5e1072815d9f4d560a8a5b4d06a86a5993dc5df49d9804d',
    'tests/Feature/AuthTest.php' => 'cfbb23d92dc2c918cd8b55d66be0ca3aaa9f2b6b89526a072526cbd64675419a',
    'tests/Feature/RegistrationTest.php' => 'a6f558d0ed9718c9a46f4aab3661b237a011ee0c02511a4fcce9a45988744411',
    'tests/Feature/PasskeyTest.php' => '2ce7a7c82a82a03ee8b206a367393e47490b6766b52aeed3458fc2c1c2a310f9',
    'tests/Feature/WalletApiTest.php' => 'e595d2c0e207e7abf495daf6be0ab6769934fecc9b38693c52740da3444dde68',
    'tests/Unit/AuthHelpersTest.php' => '4aaf5eee0c481408848e5abe863b8093fb926a89452253d2f438710f0efc7201',
];

$bad = 0;

foreach ($expected as $path => $hash) {
    $real = __DIR__ . '/' . $path;

    // Cek nama file PERSIS (Windows tidak peduli huruf besar/kecil, Linux peduli).
    $dir = dirname($real);
    $listing = is_dir($dir) ? scandir($dir) : [];

    if (! in_array(basename($path), $listing, true)) {
        $hint = '';
        foreach ($listing as $f) {
            if (strcasecmp($f, basename($path)) === 0) {
                $hint = "  (tertulis '$f' -> ganti huruf besar/kecilnya)";
            }
        }
        echo "HILANG   $path$hint\n";
        $bad++;
        continue;
    }

    $bytes = file_get_contents($real);

    if (str_starts_with($bytes, "\xFF\xFE") || str_starts_with($bytes, "\xFE\xFF")) {
        echo "ENCODING $path  (UTF-16, simpan ulang sebagai UTF-8)\n";
        $bad++;
        continue;
    }

    $bytes = str_replace("\r", '', $bytes);
    if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
        $bytes = substr($bytes, 3); // BOM UTF-8 diabaikan
    }

    if (strlen($bytes) === 0) {
        echo "KOSONG   $path  (belum di-save?)\n";
        $bad++;
    } elseif (hash('sha256', $bytes) !== $hash) {
        echo "BEDA     $path\n";
        $bad++;
    }
}

echo $bad === 0
    ? "\nSemua " . count($expected) . " file sama persis dengan versi yang lolos tes.\n"
    : "\n$bad file perlu dicek. Timpa dengan file terbaru dari chat.\n";