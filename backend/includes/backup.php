<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' && !defined('PERMIT_ENTRY')) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/permit_payments.php';

function backup_identifier(string $name): string
{
    if (!preg_match('/^[A-Za-z0-9_]+$/D',$name)) throw new RuntimeException('Unsafe database identifier.');
    return '`'.$name.'`';
}

function backup_rows(PDO $pdo, string $table): array
{
    $quoted=backup_identifier($table);
    $keys=$pdo->query("SHOW KEYS FROM $quoted WHERE Key_name='PRIMARY'")->fetchAll(PDO::FETCH_ASSOC);
    if (!$keys) throw new RuntimeException('All backup tables must have primary keys.');
    usort($keys,static fn($a,$b)=>(int)$a['Seq_in_index']<=>(int)$b['Seq_in_index']);
    $order=implode(',',array_map(static fn($k)=>backup_identifier($k['Column_name']),$keys));
    return $pdo->query("SELECT * FROM $quoted ORDER BY $order")->fetchAll(PDO::FETCH_ASSOC);
}

function backup_json(mixed $data): string { return json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); }

function backup_zip_add(ZipArchive $zip, string $path, string $data, string $password): void
{
    if (!$zip->addFromString($path,$data) || !$zip->setEncryptionName($path,ZipArchive::EM_AES_256,$password)) throw new RuntimeException('Cannot write encrypted backup entry.');
}

/** Caller holds exclusive maintenance lock. Schema changes must also be paused. */
function create_backup(PDO $pdo, string $uploads, string $target, string $password): array
{
    if (strlen($password)<16) throw new RuntimeException('BACKUP_PASSWORD must contain at least 16 characters.');
    if (file_exists($target)) throw new RuntimeException('Backup target already exists.');
    if (!is_dir(dirname($target)) || !is_dir($uploads)) throw new RuntimeException('Backup parent and upload directory must exist.');
    $zip=new ZipArchive();
    if ($zip->open($target,ZipArchive::CREATE|ZipArchive::EXCL)!==true) throw new RuntimeException('Cannot create backup.');
    $manifest=['format'=>1,'created_at'=>gmdate('c'),'tables'=>[],'uploads'=>[],'entries'=>[]];
    try {
        $pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES,true);
        $pdo->exec("SET time_zone='+00:00'");
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$pdo->beginTransaction();
        foreach($pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_NUM) as $item) {
            $table=$item[0];$quoted=backup_identifier($table);
            $ddl=$pdo->query('SHOW CREATE TABLE '.$quoted)->fetch(PDO::FETCH_NUM)[1];
            if (!str_contains($ddl,'ENGINE=InnoDB')) throw new RuntimeException('Backup requires InnoDB tables.');
            $rows=backup_rows($pdo,$table);
            foreach(['schema/'.$table.'.sql'=>$ddl,'rows/'.$table.'.json'=>backup_json($rows)] as $path=>$content) {
                backup_zip_add($zip,$path,$content,$password);$manifest['entries'][$path]=hash('sha256',$content);
            }
            $manifest['tables'][$table]=count($rows);
        }
        // Include current uploads, every historical document, and current payment proof.
        $proofSql="SELECT stored_name FROM application_documents UNION SELECT stored_name FROM document_versions UNION SELECT proof_stored_name FROM payments WHERE proof_stored_name IS NOT NULL";
        if (function_exists('installment_workflow_ready') && installment_workflow_ready($pdo)) $proofSql.=" UNION SELECT proof_stored_name FROM payment_installments WHERE proof_stored_name IS NOT NULL UNION SELECT proof_stored_name FROM payment_evidence WHERE proof_stored_name IS NOT NULL";
        $names=$pdo->query($proofSql)->fetchAll(PDO::FETCH_COLUMN);
        foreach($names as $name) {
            if (!preg_match('/^[A-Za-z0-9_.-]+$/D',$name) || $name==='.' || $name==='..') throw new RuntimeException('Unsafe upload path in database.');
            $source=$uploads.'/'.$name;
            if (is_link($source) || !is_file($source)) throw new RuntimeException('A referenced upload is missing or is a symbolic link.');
            $bytes=file_get_contents($source);
            if ($bytes===false) throw new RuntimeException('Cannot read an upload.');
            $path='uploads/'.$name;backup_zip_add($zip,$path,$bytes,$password);
            $manifest['entries'][$path]=hash('sha256',$bytes);$manifest['uploads'][]=$name;
        }
        $pdo->commit();
        backup_zip_add($zip,'manifest.json',backup_json($manifest),$password);
        if (!$zip->close()) throw new RuntimeException('Could not finalize backup.');
        chmod($target,0600);
        return $manifest;
    } catch(Throwable $e) {
        if($pdo->inTransaction())$pdo->rollBack();$zip->close();
        // Keep partial artifact for operator inspection; never call it a successful backup.
        throw $e;
    }
}

function inspect_backup(string $file, string $password): array
{
    $zip=new ZipArchive();
    if ($zip->open($file)!==true) throw new RuntimeException('Backup cannot be opened.');
    $zip->setPassword($password);
    try {
        $raw=$zip->getFromName('manifest.json');
        if($raw===false)throw new RuntimeException('Wrong password or missing manifest.');
        $m=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
        if (($m['format']??null)!==1 || !is_array($m['entries']??null) || !is_array($m['tables']??null) || !is_array($m['uploads']??null)) throw new RuntimeException('Invalid backup manifest.');
        if($zip->numFiles!==count($m['entries'])+1)throw new RuntimeException('Unexpected backup entries.');
        foreach($m['entries'] as $path=>$hash) {
            if (!preg_match('~^(schema/[A-Za-z0-9_]+\.sql|rows/[A-Za-z0-9_]+\.json|uploads/[A-Za-z0-9_.-]+)$~D',$path))throw new RuntimeException('Unsafe backup entry.');
            $data=$zip->getFromName($path);
            if($data===false || !hash_equals($hash,hash('sha256',$data)))throw new RuntimeException('Backup integrity check failed.');
        }
        foreach($m['tables'] as $table=>$count) {
            backup_identifier($table);
            if(!isset($m['entries']['schema/'.$table.'.sql'],$m['entries']['rows/'.$table.'.json']))throw new RuntimeException('Missing table entry.');
            $rows=json_decode($zip->getFromName('rows/'.$table.'.json'),true,512,JSON_THROW_ON_ERROR);
            if(!is_array($rows) || count($rows)!==$count)throw new RuntimeException('Row count mismatch.');
        }
        foreach($m['uploads'] as $name)if($name==='.'||$name==='..'||!isset($m['entries']['uploads/'.$name]))throw new RuntimeException('Invalid upload manifest.');
        return $m;
    } finally {$zip->close();}
}

/** Restore only into an empty, separately configured database and NEW directory. */
function restore_backup(PDO $target, string $file, string $uploads, string $password): array
{
    $m=inspect_backup($file,$password);
    if($target->query('SHOW TABLES')->fetch())throw new RuntimeException('Restore database must be empty.');
    if(file_exists($uploads) || is_link($uploads))throw new RuntimeException('Restore upload directory must not exist.');
    if(!is_dir(dirname($uploads)))throw new RuntimeException('Restore parent directory must exist.');
    $zip=new ZipArchive();$zip->open($file);$zip->setPassword($password);
    $target->setAttribute(PDO::ATTR_STRINGIFY_FETCHES,true);$target->exec("SET time_zone='+00:00'");
    try {
        $target->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach($m['tables'] as $table=>$count)$target->exec($zip->getFromName('schema/'.$table.'.sql'));
        $target->beginTransaction();
        foreach($m['tables'] as $table=>$count) {
            $rows=json_decode($zip->getFromName('rows/'.$table.'.json'),true,512,JSON_THROW_ON_ERROR);
            foreach($rows as $row) {
                $columns=implode(',',array_map('backup_identifier',array_keys($row)));
                $q=$target->prepare('INSERT INTO '.backup_identifier($table).' ('.$columns.') VALUES ('.implode(',',array_fill(0,count($row),'?')).')');$q->execute(array_values($row));
            }
            $restored=backup_json(backup_rows($target,$table));
            if(!hash_equals($m['entries']['rows/'.$table.'.json'],hash('sha256',$restored)))throw new RuntimeException('Restored table does not match the backup.');
        }
        if(!mkdir($uploads,0700))throw new RuntimeException('Cannot create restore directory.');
        foreach($m['uploads'] as $name) {
            $path=$uploads.'/'.$name;
            if(file_put_contents($path,$zip->getFromName('uploads/'.$name),LOCK_EX)===false)throw new RuntimeException('Cannot restore upload.');
            chmod($path,0600);
            if(!hash_equals($m['entries']['uploads/'.$name],hash_file('sha256',$path)))throw new RuntimeException('Restored upload checksum failed.');
        }
        $target->commit();
        return $m;
    } catch(Throwable $e) {if($target->inTransaction())$target->rollBack();throw $e;}
    finally {$target->exec('SET FOREIGN_KEY_CHECKS=1');$zip->close();}
}
