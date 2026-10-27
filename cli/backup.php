<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/includes/functions.php';require dirname(__DIR__).'/includes/database.php';require dirname(__DIR__).'/includes/backup.php';
$lock=null;
try {
    app_config(); // Load .env before reading backup and restore credentials.
    if(!class_exists('ZipArchive'))throw new RuntimeException('Enable PHP zip extension.');
    $command=$argv[1]??'';$file=$argv[2]??'';$password=(string)getenv('BACKUP_PASSWORD');
    if(strlen($password)<16)throw new RuntimeException('Set BACKUP_PASSWORD to at least 16 characters.');
    $root=realpath(dirname(__DIR__));$parent=realpath(dirname($file));
    if(!$parent || $parent===$root || str_starts_with($parent,$root.DIRECTORY_SEPARATOR))throw new RuntimeException('Keep backup archives outside the project and web root.');
    if($command==='create') {
        $lock=fopen($root.'/storage/maintenance.lock','c');
        if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Active requests or worker detected. Stop incoming requests, then retry.');
        $m=create_backup(db(),(string)app_config('upload_dir'),$file,$password);
        inspect_backup($file,$password);
    } elseif($command==='verify') {$m=inspect_backup($file,$password);}
    elseif($command==='restore') {
        $name=(string)getenv('RESTORE_DB_NAME');$dest=$argv[3]??'';
        backup_identifier($name);
        if($name===app_config('db')['name'])throw new RuntimeException('Restore database must differ from configured live database.');
        $destParent=realpath(dirname($dest));$liveUploads=realpath((string)app_config('upload_dir'));
        if(!$destParent || $destParent===$root || str_starts_with($destParent,$root.DIRECTORY_SEPARATOR) || ($liveUploads && ($destParent===$liveUploads || str_starts_with($destParent,$liveUploads.DIRECTORY_SEPARATOR))))throw new RuntimeException('Use a new restore directory outside the live project and uploads.');
        $cfg=app_config('db');
        $pdo=new PDO('mysql:host='.$cfg['host'].';port='.$cfg['port'].';dbname='.$name.';charset=utf8mb4',(string)(getenv('RESTORE_DB_USER')?:$cfg['user']),(string)(getenv('RESTORE_DB_PASS')?:$cfg['pass']),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
        $m=restore_backup($pdo,$file,$dest,$password);
    } else {throw new RuntimeException('Usage: php cli/backup.php create|verify ARCHIVE.zip OR restore ARCHIVE.zip NEW_UPLOAD_DIRECTORY');}
    echo 'Completed '.$command.': '.count($m['tables']).' tables, '.array_sum($m['tables']).' rows, '.count($m['uploads'])." files.\n";
} catch(Throwable $e){fwrite(STDERR,$e instanceof PDOException?"Database operation failed; check target permissions and server logs.\n":$e->getMessage()."\n");exit(1);}
finally {if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}}
