<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
if ($argc!==2) { fwrite(STDERR,"Usage: php generate-keys.php /absolute/private/config-snippet.php\n"); exit(1); }
$path=$argv[1];
if (!str_starts_with($path,'/') || file_exists($path)) { fwrite(STDERR,"Choose a new absolute path outside the web root.\n"); exit(1); }
$pair=sodium_crypto_sign_keypair();
$text="<?php\n// Private keys: add definitions to wp-config.php before loading WordPress. Never commit this file.\n";
$text.="define('WP_AI_CLOUD_VAULT_KEY', '".base64_encode(random_bytes(32))."');\n";
$text.="define('WP_AI_CLOUD_SIGNING_KEY', '".base64_encode(sodium_crypto_sign_secretkey($pair))."');\n";
$text.="define('WP_AI_CLOUD_ENABLED', true);\n";
umask(0077); $handle=fopen($path,'x'); if (!$handle) exit(1); fwrite($handle,$text); fclose($handle); chmod($path,0600);
fwrite(STDOUT,"Created private configuration snippet (0600). Values were not printed.\n");
