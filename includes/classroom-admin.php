<?php
require_once __DIR__.'/classroom.php';
// New PIN and QR for one pupil. The caller has already checked ownership and that
// the pupil is active. Returns the plain PIN so it can be shown on a login card.
function classroomResetLogin(array &$data, string $id): string {
    $credentials=classroomCredentials($data['students']);
    $data['students'][$id]['version']++;
    unset($data['login_limits'][hash('sha256','account:'.$id)]);
    $data['students'][$id]['pin_hash']=$credentials['pin_hash'];
    $data['students'][$id]['qr']=$credentials['qr'];
    return $credentials['pin'];
}
// Take a pupil out of use: login revoked, group memberships pruned. The row and the
// pupil's attempts are kept, so the teacher keeps the practice history.
// The caller has already checked ownership and that the pupil is active.
function classroomRemovePupil(array &$data, string $id): void {
    $data['students'][$id]['version']++;
    unset($data['login_limits'][hash('sha256','account:'.$id)]);
    $data['students'][$id]['active']=false;
    foreach ($data['groups'] ?? [] as $gid=>$group) $data['groups'][$gid]['students']=array_values(array_diff($group['students'],[$id]));
}
function classroomAdminAction(string $teacher, array $post, array $files): string {
    $action=(string)($post['action'] ?? '');
    $uploaded=null;
    if ($action==='assign' && ($post['content'] ?? '')==='material') {
        $file=$files['pdf'] ?? [];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || ($file['size'] ?? 0)>20*1024*1024 || !is_uploaded_file($file['tmp_name'])) throw new DomainException('Välj en PDF på högst 20 MB.');
        $signature=file_get_contents($file['tmp_name'],false,null,0,5);
        if ($signature!=='%PDF-' || strtolower(pathinfo($file['name'],PATHINFO_EXTENSION))!=='pdf' || (class_exists('finfo') && (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name'])!=='application/pdf')) throw new DomainException('Filen måste vara en PDF.');
        $dir=DATA_DIR.'materials/'; if (!is_dir($dir) && !mkdir($dir,0755,true)) throw new RuntimeException('Materialmappen kunde inte skapas.');
        $uploaded=bin2hex(random_bytes(16)).'.pdf';
        if (!move_uploaded_file($file['tmp_name'],$dir.$uploaded)) throw new RuntimeException('PDF-filen kunde inte sparas.');
    }
    try {
        $result=classroomUpdate(function (&$data) use ($teacher,$post,$action,$uploaded) {
            if ($action==='class') {
                $id='c_'.bin2hex(random_bytes(8));
                $data['classes'][$id]=['id'=>$id,'teacher_id'=>$teacher,'name'=>classroomText($post['name'] ?? '')];
                return 'Klassen är skapad.';
            }
            if ($action==='class_rename') {
                $id=(string)($post['class_id'] ?? ''); classroomOwned($data,'classes',$id,$teacher);
                $data['classes'][$id]['name']=classroomText($post['name'] ?? '');
                return 'Klassens namn är ändrat.';
            }
            // Klassen tas bort och dess elever tas ur bruk, som vid borttagning av en
            // enskild elev: raderna och övningshistoriken finns kvar för läraren.
            // Utdelade uppgifter behålls också, och visas då som "Borttagen klass".
            if ($action==='class_delete') {
                $id=(string)($post['class_id'] ?? ''); $class=classroomOwned($data,'classes',$id,$teacher);
                $pupils=0;
                foreach ($data['students'] ?? [] as $sid=>$s) {
                    if ($s['teacher_id']!==$teacher || $s['class_id']!==$id || empty($s['active'])) continue;
                    classroomRemovePupil($data,$sid); $pupils++;
                }
                unset($data['classes'][$id]);
                return 'Klassen '.$class['name'].' är borttagen'.($pupils?' tillsammans med '.$pupils.($pupils===1?' elev':' elever'):'').'. Tidigare övningsresultat är kvar.';
            }
            if ($action==='students') {
                $class=(string)($post['class_id'] ?? ''); classroomOwned($data,'classes',$class,$teacher);
                $names=preg_split('/\R/u',trim((string)($post['names'] ?? '')));
                $names=array_values(array_filter(array_map('trim',$names),fn($n)=>$n!==''));
                if (!$names || count($names)>100) throw new DomainException('Skriv 1–100 elevnamn, ett per rad.');
                foreach ($names as $name) classroomText($name);
                $cards=[];
                foreach ($names as $name) {
                    $id='s_'.bin2hex(random_bytes(12)); $credentials=classroomCredentials($data['students'] ?? []);
                    $data['students'][$id]=['id'=>$id,'teacher_id'=>$teacher,'class_id'=>$class,'name'=>$name,'active'=>true,'version'=>1,'code'=>$credentials['code'],'pin_hash'=>$credentials['pin_hash'],'qr'=>$credentials['qr']];
                    $cards[]=['id'=>$id,'pin'=>$credentials['pin']];
                }
                return ['message'=>count($names).' elever tillagda.','cards'=>$cards];
            }
            if (in_array($action,['move','remove','reset'],true)) {
                $id=(string)($post['student_id'] ?? ''); $student=classroomOwned($data,'students',$id,$teacher);
                if (empty($student['active'])) throw new DomainException('Eleven har tagits bort.');
                if ($action==='move') {
                    $class=(string)($post['class_id'] ?? ''); classroomOwned($data,'classes',$class,$teacher);
                    $data['students'][$id]['class_id']=$class; return 'Eleven är flyttad. Gruppmedlemskapen är kvar.';
                }
                if ($action==='remove') {
                    classroomRemovePupil($data,$id);
                    return 'Eleven är borttagen och kan inte längre logga in.';
                }
                return ['message'=>'Ny PIN och QR-kod skapade. Tidigare inloggningar är avslutade.','cards'=>[['id'=>$id,'pin'=>classroomResetLogin($data,$id)]]];
            }
            // Ny PIN för flera valda elever, eller en hel klass via klassfiltret.
            // Allt valideras före första skrivningen: en främmande eller borttagen
            // elev i listan avbryter hela omgången utan att något ändras.
            if ($action==='reset_many') {
                $ids=$post['student_ids'] ?? [];
                if (!is_array($ids)) throw new DomainException('Välj minst en elev.');
                foreach ($ids as $sid) if (!is_string($sid)) throw new DomainException('Välj minst en elev.');
                $ids=array_values(array_unique($ids));
                if (!$ids || count($ids)>100) throw new DomainException('Välj 1–100 elever.');
                foreach ($ids as $sid) {
                    $s=classroomOwned($data,'students',$sid,$teacher);
                    if (empty($s['active'])) throw new DomainException('Eleven har tagits bort.');
                }
                $cards=[];
                foreach ($ids as $sid) $cards[]=['id'=>$sid,'pin'=>classroomResetLogin($data,$sid)];
                $count=count($cards);
                return ['message'=>$count.($count===1?' elev har':' elever har').' fått ny PIN och QR-kod. Tidigare inloggningar är avslutade.','cards'=>$cards];
            }
            if ($action==='group') {
                $id=(string)($post['group_id'] ?? '');
                if ($id!=='') classroomOwned($data,'groups',$id,$teacher); else $id='g_'.bin2hex(random_bytes(8));
                $members=$post['members'] ?? [];
                if (!is_array($members)) throw new DomainException('Välj elever.');
                $members=array_values(array_unique($members));
                foreach ($members as $sid) { $s=classroomOwned($data,'students',(string)$sid,$teacher); if (empty($s['active'])) throw new DomainException('Eleven har tagits bort.'); }
                $data['groups'][$id]=['id'=>$id,'teacher_id'=>$teacher,'name'=>classroomText($post['name'] ?? ''),'students'=>$members];
                return 'Gruppen är sparad.';
            }
            if ($action==='assign') {
                $target=explode(':',(string)($post['target'] ?? ''),2);
                if (count($target)!==2 || !in_array($target[0],['classes','groups'],true)) throw new DomainException('Välj klass eller grupp.');
                classroomOwned($data,$target[0],$target[1],$teacher);
                $id='a_'.bin2hex(random_bytes(12));
                $assignment=['id'=>$id,'teacher_id'=>$teacher,'target_type'=>$target[0],'target_id'=>$target[1],'created'=>gmdate('c')];
                if ($uploaded) {
                    $assignment+=['kind'=>'material','content_id'=>$id,'title'=>classroomText($post['title'] ?? ''),'file'=>$uploaded];
                } else {
                    $content=explode(':',(string)($post['content'] ?? ''),2);
                    if (count($content)!==2 || !in_array($content[0],['quiz','cards','deck'],true)) throw new DomainException('Välj innehåll.');
                    $assignment+=['kind'=>$content[0],'content_id'=>$content[1]];
                    $item=classroomContent($assignment);
                    if (!$item) throw new DomainException('Innehållet måste vara publicerat och tillgängligt för elever.');
                    $assignment['title']=$item['title'];
                }
                $data['assignments'][$id]=$assignment; return 'Uppgiften är utdelad.';
            }
            throw new DomainException('Okänd åtgärd.');
        });
    } catch (Throwable $e) { if ($uploaded) unlink(DATA_DIR.'materials/'.$uploaded); throw $e; }
    if (is_array($result)) { $_SESSION['classroom_cards']=['expires'=>time()+900,'cards'=>$result['cards']]; return $result['message']; }
    return $result;
}
