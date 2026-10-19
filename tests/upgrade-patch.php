<?php declare(strict_types=1); namespace ProcessWire;
require __DIR__.'/support/bootstrap.php';
// Any service request would reach permissions/schema; this patch must return before that.
$module=new class extends Messenger {
    public int $wireCalls=0;
    public function wire($key=null){$this->wireCalls++;throw new \RuntimeException('legacy-upgrade-service');}
};
$module->upgrade(143,144);check($module->wireCalls===0,'exact 143 to144 patch performs no service or database calls');
$module->upgrade(144,145);check($module->wireCalls===0,'exact 144 to145 patch performs no service or database calls');
$module->upgrade('143','144');check($module->wireCalls===0,'ProcessWire numeric-string patch versions have the same no-op lifecycle');
$module->upgrade('144','145');check($module->wireCalls===0,'ProcessWire numeric-string WireWall patch versions have the same no-op lifecycle');
foreach([[142,144],[143,145],[144,144],[145,146]] as [$from,$to]){
    $before=$module->wireCalls;
    try{$module->upgrade($from,$to);throw new \RuntimeException('Expected unchanged legacy service path');}
    catch(\RuntimeException $error){check($error->getMessage()==='legacy-upgrade-service'&&$module->wireCalls===$before+1,'only the exact patch skips legacy upgrade work');}
}
echo 'Patch upgrade checks: '.$GLOBALS['checks'].PHP_EOL;
