<?php declare(strict_types=1); namespace ProcessWire;
require __DIR__.'/support/bootstrap.php';
[$m,$db,$a,$b]=fixture();
class DirectoryRoleUser extends User {
 public function __construct(int $id,private array $roleIds=[],private bool $super=false){parent::__construct($id);}
 public function hasRole($id){return in_array((int)$id,$this->roleIds,true);}
 public function isSuperuser(){return $this->super;}
}
$f=new \ReflectionMethod($m,'directoryRoleVisible');
$member=new DirectoryRoleUser(22,[5]);$manager=new DirectoryRoleUser(33,[5,6]);$other=new DirectoryRoleUser(44,[7]);
check($f->invoke($m,$member),'empty allowlist preserves eligible discovery');
check(!$f->invoke($m,new DirectoryRoleUser(55,[5],true)),'superuser hidden');
$m->set('directory_visible_roles',['5']);check($f->invoke($m,$member),'selected role visible');check(!$f->invoke($m,$other),'unselected role absent');
$m->set('directory_hidden_roles',['6']);check(!$f->invoke($m,$manager),'excluded staff wins over member role');
$m->set('directory_visible_roles',[]);check($f->invoke($m,$other),'empty include supports blacklist');
$m->set('directory_hidden_roles',['bad']);check(!$f->invoke($m,$member),'corrupt role configuration fails closed');
$m->set('directory_hidden_roles',[])->set('directory_visible_roles',['7'])->set('first_contact_mode','open');
$chat=$m->startConversation($a,$b,'existing chat remains allowed','roles_existing_0001');check($m->conversation($chat['conversation']['id'],$a)!==null,'directory visibility never revokes chat authorization');
// Exercise the public directory and personal-contact APIs against the same fixture.
$m->set('directory_visible_roles',['5'])->set('directory_hidden_roles',['6']);
$m->services['users']=new class($member,$manager,$other){
 public function __construct(private User $member,private User $manager,private User $other){}
 public function find($selector){return [$this->member,$this->manager,$this->other];}
 public function get($id){foreach([$this->member,$this->manager,$this->other] as $u)if($u->id===(int)$id)return $u;return new User((int)$id);}
};
$m->services['sanitizer']=new class{public function selectorValue($value){return $value;}};
check(array_column($m->searchRecipients($a,'fi'),'id')===[22],'new-conversation search cannot bypass role discovery');
$found=$m->memberDirectory($a);check(array_column($found,'id')===[22],'public directory applies role visibility');
$db->prepare('INSERT INTO messenger_contacts (owner_id,contact_id,added_at) VALUES (?,?,?)')->execute([$a->id,33,time()]);
check(array_column($m->contactBook($a),'id')===[33],'personal contacts remain independent of discovery visibility');
echo 'Directory role checks: '.$GLOBALS['checks'].PHP_EOL;
