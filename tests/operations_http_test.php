<?php
declare(strict_types=1);
// Run after operations_integration_test.php, with synthetic fixtures only.
require dirname(__DIR__).'/includes/functions.php';
require dirname(__DIR__).'/includes/database.php';
if (!str_starts_with((string)app_config('db')['name'],'permit_test_')) exit(1);
$root=dirname(__DIR__);$sessions=sys_get_temp_dir().'/permit-sessions-'.bin2hex(random_bytes(5));mkdir($sessions,0700);
session_save_path($sessions);session_name('permitflow_session');
$cookies=[];
foreach([1,2,3,5,6] as $id){session_id('fixture'.$id);session_start();$_SESSION=['user'=>['id'=>$id,'name'=>'Fixture','email'=>'fixture@example.test','role'=>'admin']];session_write_close();$cookies[$id]='permitflow_session=fixture'.$id;}
$file=$root.'/storage/uploads/first.pdf';
if(file_exists($file))throw new RuntimeException('Refuse to overwrite existing upload.');
file_put_contents($file,'synthetic first file');
$log=$sessions.'/http.log';
$router=$sessions.'/router.php';
file_put_contents($router,'<?php session_save_path('.var_export($sessions,true).'); return false;');
$process=proc_open([PHP_BINARY,'-S','127.0.0.1:8765','-t',$root,$router],[0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes);
if(!is_resource($process))throw new RuntimeException('Test server could not start.');
function request_test(string $path, ?int $user=null, ?array $post=null): array {
    global $cookies;
    $c=curl_init('http://127.0.0.1:8765/'.$path);
    curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>5,CURLOPT_COOKIE=>$user===null?'':$cookies[$user]]);
    if($post!==null){curl_setopt($c,CURLOPT_POST,true);curl_setopt($c,CURLOPT_POSTFIELDS,http_build_query($post));}
    $body=curl_exec($c);$status=curl_getinfo($c,CURLINFO_HTTP_CODE);curl_close($c);return [$status,$body];
}
function http_check(bool $ok,string $why): void {if(!$ok)throw new RuntimeException($why);}
try {
    for($i=0;$i<40;$i++){if(request_test('verify-permit.php')[0]!==0)break;usleep(100000);}
    http_check(request_test('document-history.php?application_id=1')[0]===302,'Guest must sign in.');
    [$code,$body]=request_test('document-history.php?application_id=1',1);
    http_check($code===200 && str_contains($body,'first.pdf') && !str_contains($body,'Private review note'),'Owner sees history but no internal note.');
    http_check(request_test('document-history.php?application_id=1',2)[0]===404,'Other applicant denied history.');
    [$code,$body]=request_test('document-history.php?application_id=1',3);
    http_check($code===200 && str_contains($body,'Private review note'),'Admin sees version notes.');
    http_check(request_test('document-history.php?application_id=1',5)[0]===302,'Inactive session denied.');
    http_check(request_test('document-history.php?application_id=1',6)[0]===302,'Treasurer redirected away from document history.');
    http_check(request_test('document-version.php?id=1',1)===[200,'synthetic first file'],'Owner can download historical upload.');
    http_check(request_test('document-version.php?id=1',2)[0]===404,'Other applicant denied historical upload.');
    http_check(request_test('document-history.php?application_id=1',3,['version_id'=>1,'note'=>'Missing CSRF'])[0]===419,'Reviewer notes require CSRF.');
    foreach(['admin/assignments.php','admin/reports.php'] as $path){http_check(request_test($path,1)[0]===302,'Applicant redirected away from '.$path);http_check(request_test($path,3)[0]===200,'Admin can open '.$path);}
    http_check(request_test('admin/reports.php?type=applications',6)[0]===403,'Treasurer denied application report.');
    [$code,$body]=request_test('admin/reports.php?type=collections&start=2026-01-01&end=2026-01-31&format=csv',6);
    http_check($code===200 && str_contains($body,'1500'),'Treasurer can export paid collections.');
    $token=db()->query('SELECT token FROM permit_verification WHERE application_id=1')->fetchColumn();
    [$code,$body]=request_test('verify-permit.php?token='.$token);
    http_check($code===200 && str_contains($body,'BP-TEST-1') && !str_contains($body,'owner@example.test') && !str_contains($body,'Private address') && !str_contains($body,'Synthetic Business'),'Public QR response excludes applicant information.');
    http_check(request_test('verify-permit.php?token=unknown')[0]===404,'Unknown QR returns not found.');
    echo "HTTP authorization, internal-note privacy, CSRF, report export and public QR tests passed.\n";
} catch(Throwable $e) {fwrite(STDERR,(string)file_get_contents($log));throw $e;}
finally {proc_terminate($process);proc_close($process);unlink($file);}
