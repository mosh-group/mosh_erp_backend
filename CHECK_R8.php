<?php
/** Read-only installation check. Does not load Laravel, write files, or alter data. */
$failed = false;
function result(bool $ok, string $message): void { global $failed; echo ($ok ? '[OK] ' : '[CHECK] ').$message.PHP_EOL; if (!$ok) $failed = true; }
result(version_compare(PHP_VERSION, '8.5.0', '>=') && version_compare(PHP_VERSION, '9.0.0', '<'), 'PHP ^8.5 required by the original composer.json. Current: '.PHP_VERSION);
foreach (['bcmath','fileinfo','pdo','pdo_sqlite','mbstring','openssl'] as $ext) result(extension_loaded($ext), 'PHP extension: '.$ext);
foreach (['.env','vendor/autoload.php','app/Support/TaskExecutionClock.php','app/Support/WorkdayReport.php','app/Support/WorkdayHistory.php'] as $f) result(is_file(__DIR__.'/'.$f), 'File exists: '.$f);
$env = is_file(__DIR__.'/.env') ? file_get_contents(__DIR__.'/.env') : '';
result((bool)preg_match('/^APP_KEY=.+$/m', $env), 'APP_KEY present (value not displayed)');
$connection = preg_match('/^DB_CONNECTION=(.*)$/m', $env, $m) ? trim($m[1], " \t\r\n\"'") : 'sqlite';
if ($connection === 'sqlite') {
    $db = preg_match('/^DB_DATABASE=(.*)$/m', $env, $m) ? trim($m[1], " \t\r\n\"'") : '';
    if ($db === '') $db = __DIR__.'/database/database.sqlite';
    if (!preg_match('~^(?:[A-Za-z]:[\\\\/]|/|\\\\\\\\)~', $db) && $db !== ':memory:') $db = __DIR__.'/'.$db;
    result($db !== ':memory:' && is_file($db) && filesize($db)>0, 'Configured SQLite database exists and is not empty (path not displayed)');
}
result(is_dir(__DIR__.'/storage') && is_writable(__DIR__.'/storage'), 'storage directory exists and is writable');
result(is_file(dirname(__DIR__).'/MOSH_VANILLA_FRONTEND/config.js'), 'Frontend config.js exists beside backend');
echo 'No data or settings were changed. This is a preflight, not an end-to-end runtime test.'.PHP_EOL;
exit($failed ? 1 : 0);
