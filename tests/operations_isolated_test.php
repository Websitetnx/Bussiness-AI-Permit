<?php
declare(strict_types=1);
// Local runner: never import fixtures into the configured application database.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/includes/functions.php';
require __DIR__.'/support/project_fixture.php';
$root=dirname(__DIR__);$settings=app_config('db');
$database='permit_test_ops_'.bin2hex(random_bytes(6));$restoreDatabase=$database.'_restore';
$workspace=sys_get_temp_dir().'/'.$database;$created=[];
$server=new PDO('mysql:host='.$settings['host'].';port='.$settings['port'].';charset=utf8mb4',$settings['user'],$settings['pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$previousRestore=getenv('PERMIT_TEST_RESTORE_DB');
try {
    foreach([$database,$restoreDatabase] as $name) {
        $server->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$created[]=$name;
    }
    $server->exec('USE `'.$database.'`');
    $schema=preg_replace('/\ACREATE DATABASE.*?;\s*USE permitflow;\s*/s','',file_get_contents($root.'/database/schema.sql'),1,$removed);
    if ($removed!==1) throw new RuntimeException('Schema must not select the live database.');
    $server->exec($schema);
    mkdir($workspace.'/storage/uploads',0700,true);mkdir($workspace.'/tests',0700);
    copy_project_test_fixture($root,$workspace);
    mkdir($workspace.'/vendor',0700);
    file_put_contents($workspace.'/vendor/autoload.php','<?php require_once '.var_export($root.'/vendor/autoload.php',true).';');
    file_put_contents($workspace.'/config.local.php','<?php return '.var_export([
        'db'=>array_replace($settings,['name'=>$database]),
        'mail'=>['host'=>'','username'=>'','password'=>''],
        'openai'=>['api_key'=>''],
        'app_url'=>'https://permits.example.test',
    ],true).';');
    putenv('PERMIT_TEST_RESTORE_DB='.$restoreDatabase);
    foreach(['operations_integration_test.php','operations_http_test.php'] as $test) {
        copy($root.'/tests/'.$test,$workspace.'/tests/'.$test);
        $process=proc_open([PHP_BINARY,$workspace.'/tests/'.$test],[0=>['pipe','r'],1=>STDOUT,2=>STDERR],$pipes,$workspace);
        if (!is_resource($process)) throw new RuntimeException('Cannot start '.$test);
        fclose($pipes[0]);
        if (proc_close($process)!==0) throw new RuntimeException($test.' failed.');
    }
    echo "Isolated operations and recovery tests passed; live data/configuration unchanged.\n";
} finally {
    putenv($previousRestore===false?'PERMIT_TEST_RESTORE_DB':'PERMIT_TEST_RESTORE_DB='.$previousRestore);
    foreach(array_reverse($created) as $name) {
        if (preg_match('/^permit_test_ops_[a-f0-9]{12}(_restore)?$/D',$name)) $server->exec('DROP DATABASE `'.$name.'`');
    }
    if (is_dir($workspace) && realpath(dirname($workspace))===realpath(sys_get_temp_dir()) && basename($workspace)===$database) {
        $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($workspace,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach($files as $file) {
            if ($file->isDir() && !$file->isLink()) rmdir($file->getPathname()); else unlink($file->getPathname());
        }
        rmdir($workspace);
    }
}
