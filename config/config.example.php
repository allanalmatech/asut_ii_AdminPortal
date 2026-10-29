<?php
declare(strict_types=1);
// Copy to config/local.php. Use the MySQL database/user created in your
// hosting control panel. Keep the storage directory outside public_html
// when the provider allows it; otherwise its .htaccess must remain in place.
return [
    'db_host' => '127.0.0.1',
    'db_port' => 3306,
    'db_name' => 'your_database',
    'db_user' => 'your_database_user',
    'db_password' => 'replace-me',
    'table_prefix' => 'asut2_',
    'storage_path' => dirname(__DIR__) . '/storage',
    'setup_token' => 'replace-with-a-long-random-setup-key-at-least-24-characters',
    'auth_limit' => 30,
    // Optional private path for download.php. Upload the customer EXE here,
    // or set its HTTPS download link through Admin → App updates.
    // 'client_download_file' => dirname(__DIR__).'/storage/releases/ASUT-II-Portable.exe',
];
