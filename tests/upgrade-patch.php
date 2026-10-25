<?php declare(strict_types=1); namespace ProcessWire;
require __DIR__.'/support/bootstrap.php';
// Any service request would reach permissions/schema; this patch must return before that.
$module=new class extends Messenger {
    public int $wireCalls=0;
    public function wire($key=null){$this->wireCalls++;throw new \RuntimeException('legacy-upgrade-service');}
};
foreach([[143,157],[155,157],[156,157],[143,156],[154,156],[155,156],[143,155],[151,155],[152,155],[153,155],[154,155],[153,154],[143,153],[151,153],[152,153],[143,152],[144,152],[145,152],[146,152],[147,152],[148,152],[149,152],[150,152],[151,152],[143,154],[151,154],[152,154],[143,144],[143,145],[144,145],[143,146],[144,146],[145,146],[143,147],[144,147],[145,147],[146,147],[143,148],[144,148],[145,148],[146,148],[147,148],[143,149],[144,149],[145,149],[146,149],[147,149],[148,149],[143,150],[144,150],[145,150],[146,150],[147,150],[148,150],[149,150],[143,151],[144,151],[145,151],[146,151],[147,151],[148,151],[149,151],[150,151]] as [$from,$to]) {
    $module->upgrade($from,$to);check($module->wireCalls===0,'exact additive patch performs no service or database calls');
    $module->upgrade((string)$from,(string)$to);check($module->wireCalls===0,'numeric-string additive patch performs no service or database calls');
}
foreach([[157,157],[156,158],[156,156],[155,155],[142,144],[142,145],[142,146],[154,154],[151,151],[151,150],[144,144],[145,145],[146,146],[145,144],[146,145]] as [$from,$to]){
    $before=$module->wireCalls;
    try{$module->upgrade($from,$to);throw new \RuntimeException('Expected unchanged legacy service path');}
    catch(\RuntimeException $error){check($error->getMessage()==='legacy-upgrade-service'&&$module->wireCalls===$before+1,'older, repeated and future paths retain legacy upgrade work');}
}
echo 'Patch upgrade checks: '.$GLOBALS['checks'].PHP_EOL;
