<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$backend=dirname(__DIR__);$backup=null;
function stop09(string $message): never { throw new RuntimeException($message); }
try {
 if(PHP_VERSION_ID<80500)stop09('PHP 8.5 or newer is required. Use the PHP installation running your MOSH server.');
 foreach(['pdo_sqlite','sqlite3','mbstring','openssl','bcmath','fileinfo','dom']as$ext)if(!extension_loaded($ext))stop09('Missing PHP extension: '.$ext.'. Use COMPLETE_UI09.cmd.');
 if(!is_file($backend.'/.env')||!is_file(dirname($backend).'/MOSH_VANILLA_FRONTEND/config.js'))stop09('Merge both folders into your EXISTING installation first. Keep the existing .env and frontend config.js. Do not delete the existing folders.');
 require $backend.'/vendor/autoload.php';
 $env=Dotenv\Dotenv::parse(file_get_contents($backend.'/.env'));
 if(($env['DB_CONNECTION']??'')!=='sqlite'||!empty($env['DB_URL']))stop09('This completion tool requires the existing SQLite deployment. No database was changed.');
 $key=$env['APP_KEY']??'';if(!str_starts_with($key,'base64:')||strlen((string)base64_decode(substr($key,7),true))!==32)stop09('Restore your ORIGINAL .env and APP_KEY. No replacement key will be generated.');
 $database=$env['DB_DATABASE']??($backend.'/database/database.sqlite');if(!preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~',$database))$database=$backend.'/'.$database;
 if(!is_file($database)||is_link($database))stop09('Current database missing. Restore your current database before continuing.');
 $db=new SQLite3($database,SQLITE3_OPEN_READONLY);$db->busyTimeout(10000);
 if($db->querySingle('PRAGMA quick_check')!=='ok')stop09('Database integrity check failed.');
 foreach(['users','employees','migrations']as$table)if(!$db->querySingle("SELECT count(*) FROM sqlite_master WHERE type='table' AND name='$table'"))stop09('Current application table missing: '.$table);
 $counts=['users'=>$db->querySingle('SELECT count(*) FROM users'),'employees'=>$db->querySingle('SELECT count(*) FROM employees')];
 echo "PASS: current database, original settings and bundled libraries found.\n";
 if(!in_array('--apply',$argv,true)){$db->close();echo "CHECK ONLY: no changes made.\n";exit(0);}
 $backup=dirname($backend,2).'/MOSH_UI09_DATABASE_BACKUP_'.gmdate('Ymd_His').'_'.bin2hex(random_bytes(3));
 if(!mkdir($backup,0700,true))stop09('Cannot create backup folder.');
 $saved=new SQLite3($backup.'/current.sqlite');if(!$db->backup($saved))stop09('Cannot back up current database.');$saved->close();$db->close();
 if(!copy($backend.'/.env',$backup.'/.env')||!copy(dirname($backend).'/MOSH_VANILLA_FRONTEND/config.js',$backup.'/config.js'))stop09('Cannot back up original settings.');
 foreach(['storage/framework/cache/data','storage/framework/sessions','storage/framework/views','storage/logs','storage/app/private','bootstrap/cache']as$dir)if(!is_dir($backend.'/'.$dir)&&!mkdir($backend.'/'.$dir,0770,true))stop09('Cannot prepare writable directory.');
 $cache=$backend.'/bootstrap/cache/config.php';if(is_file($cache)){if(!copy($cache,$backup.'/config-cache.php')||!unlink($cache))stop09('Cannot clear old generated configuration cache.');}
 $app=require $backend.'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
 if(config('database.default')!=='sqlite'||realpath(config('database.connections.sqlite.database'))!==realpath($database))stop09('Runtime database differs from original configuration. Check service environment overrides.');
 if(config('app.key')!==$key)stop09('Runtime application key differs from the original .env. Check environment overrides.');
 if(Illuminate\Support\Facades\Artisan::call('migrate',['--force'=>true])!==0)stop09('Migration failed. Keep the service stopped and review the backup.');
 foreach(['communication_messages','communication_participants','communication_folders','communication_drafts']as$table)if(!Illuminate\Support\Facades\Schema::hasTable($table))stop09('A communication table is missing.');
 foreach($counts as$table=>$count)if(Illuminate\Support\Facades\DB::table($table)->count()!==$count)stop09('Record counts changed unexpectedly. Keep the service stopped and review the backup.');
 file_put_contents($backup.'/result.json',json_encode(['release'=>'R40-UI09','status'=>'completed','record_counts'=>$counts,'time'=>gmdate('c')],JSON_PRETTY_PRINT));
 echo "DONE: UI09 tables ready. Existing records and credentials preserved.\nBackup: $backup\nRestart your existing server, then refresh the browser with Ctrl+F5.\n";
} catch(Throwable $e) {
 fwrite(STDERR,'STOP: '.($e instanceof RuntimeException?$e->getMessage():'Could not complete setup. Keep the service stopped and review the application log.')."\n");
 if($backup)fwrite(STDERR,"Backup: $backup\n");exit(1);
}
