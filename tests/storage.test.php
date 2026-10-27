<?php
// Synthetic storage only; the real config.local.php and data are never loaded.
if (($argv[1] ?? '') === '--worker') {
    require $argv[2].'/config.php';
    for ($i=0;$i<40;$i++) updateJSONLocked(QUIZZES_FILE, function (&$rows) use ($argv,$i) {
        $rows[$argv[3].':'.$i] = ['results'=>[$i]];
        usleep(1000);
    });
    exit;
}
$fixture=sys_get_temp_dir().'/quiz-storage-'.bin2hex(random_bytes(8));
mkdir($fixture.'/data',0755,true);
copy(dirname(__DIR__).'/config.php',$fixture.'/config.php');
register_shutdown_function(function () use ($fixture) {
    foreach (glob($fixture.'/data/*') as $file) { if (is_dir($file)) rmdir($file); else unlink($file); }
    unlink($fixture.'/config.php'); rmdir($fixture.'/data'); rmdir($fixture);
});
require $fixture.'/config.php';
require dirname(__DIR__).'/includes/result-storage.php';
$checks=0;
function check($value,$message) { global $checks; if (!$value) throw new RuntimeException($message); $checks++; }
check(readJSON(QUIZZES_FILE)===[] && !file_exists(QUIZZES_FILE),'Reading a missing file must not create it.');
foreach (['{broken','null','false','123','"text"'] as $bad) {
    file_put_contents(QUIZZES_FILE,$bad);
    try { updateJSONLocked(QUIZZES_FILE,function (&$rows) { $rows['new']=true; }); throw new LogicException('Expected rejection'); }
    catch (RuntimeException $e) { check(file_get_contents(QUIZZES_FILE)===$bad,'Invalid storage was overwritten.'); }
}
writeJSON(QUIZZES_FILE,['keep'=>['results'=>[1]]]);
try { updateJSONLocked(QUIZZES_FILE,function (&$rows) { $rows=[]; throw new DomainException('Abort'); }); }
catch (DomainException $e) { check(isset(readJSON(QUIZZES_FILE)['keep']),'Aborted transaction changed data.'); }
$processes=[];
for ($i=0;$i<4;$i++) $processes[]=proc_open([PHP_BINARY,__FILE__,'--worker',$fixture,(string)$i],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
foreach ($processes as $process) check(proc_close($process)===0,'Concurrent writer failed.');
$rows=readJSON(QUIZZES_FILE);
check(count($rows)===161 && $rows['keep']['results']===[1],'Concurrent updates lost records.');
$record=[]; $input=['request_id'=>str_repeat('a',32),'score'=>1];
check(resultRequest($record,$input),'First request rejected.');
check(!resultRequest($record,$input),'Duplicate request accepted.');
try { resultRequest($record,array_replace($input,['score'=>2])); throw new LogicException('Expected conflict'); }
catch (DomainException $e) { check(true,'Conflicting retry rejected.'); }
check(resultRequest($record,['score'=>1]),'Older clients must remain compatible.');
echo "PASS: $checks strict-storage, concurrency and receipt checks.\n";
