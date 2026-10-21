<?php
// Classroom data is separate from public quizzes. All writes use one locked transaction.
if (!defined('CLASSROOM_FILE')) define('CLASSROOM_FILE', DATA_DIR . 'classroom.json');
function classroomRead(): array {
    if (!is_file(CLASSROOM_FILE)) return [];
    $data = json_decode(file_get_contents(CLASSROOM_FILE), true);
    if (!is_array($data)) throw new RuntimeException('Klassdata kunde inte läsas.');
    return $data;
}
function classroomUpdate(callable $change) {
    return updateJSONLocked(CLASSROOM_FILE, function (&$data) use ($change) { if (is_file(CLASSROOM_FILE) && !is_array(json_decode(file_get_contents(CLASSROOM_FILE),true))) throw new RuntimeException('Klassdata kunde inte läsas.'); return $change($data); });
}
function classroomOwned(array $data, string $kind, string $id, string $teacher): array {
    $row = $data[$kind][$id] ?? null;
    if (!$row || ($row['teacher_id'] ?? '') !== $teacher) throw new DomainException('Objektet hittades inte.');
    return $row;
}
function classroomText($value, int $max = 100): string {
    if (!is_string($value) || trim($value) === '' || strlen(trim($value)) > $max) throw new DomainException('Fyll i ett giltigt namn.');
    return trim($value);
}
function classroomCode(string $code): string {
    return preg_replace('/[\s-]+/u', ' ', trim(strtr(strtolower($code), ['Å'=>'å','Ä'=>'ä','Ö'=>'ö'])));
}
function classroomCredentials(array $students): array {
    $colors = ['Blå','Grön','Röd','Gul','Vit','Lila','Rosa','Brun','Grå','Svart','Turkos','Orange'];
    $trees = ['Björk','Ek','Tall','Gran','Alm','Asp','Bok','Lönn','Al','Pil','Rönn','Lind','En','Hassel','Sälg','Ceder'];
    $last = ['Hagel','Sol','Vind','Regn','Snö','Moln','Dimma','Hav','Berg','Sjö','Äng','Dal','Ö','Strand','Skog','Frost','Stjärna','Måne','Gryning','Skymning','Bäck','Fors','Våg','Sand','Sten','Is','Ljus','Glöd','Källa','Dagg','Åska','Bris'];
    $tries=0;
    do {
        if (++$tries>1000) throw new DomainException('Kunde inte skapa fler unika ordkoder.');
        $code = $colors[random_int(0,count($colors)-1)].' '.$trees[random_int(0,count($trees)-1)].' '.$last[random_int(0,count($last)-1)];
        $exists = false;
        foreach ($students as $student) if (classroomCode($student['code']) === classroomCode($code)) $exists = true;
    } while ($exists);
    $pin = str_pad((string)random_int(0,9999),4,'0',STR_PAD_LEFT);
    return ['code'=>$code,'pin'=>$pin,'pin_hash'=>password_hash($pin,PASSWORD_DEFAULT),'qr'=>bin2hex(random_bytes(24))];
}
function classroomStudent(?array $data = null): ?array {
    $session = $_SESSION['pupil'] ?? null;
    if (!$session || ($session['expires'] ?? 0) < time()) return null;
    $data = $data ?? classroomRead();
    $student = $data['students'][$session['id']] ?? null;
    if (!$student || empty($student['active']) || ($student['version'] ?? 1) !== $session['version']) return null;
    return $student;
}
function classroomLogin(string $code, string $qr, string $pin): bool {
    $id = classroomUpdate(function (&$data) use ($code,$qr,$pin) {
        $now = time();
        $data['login_limits'] = array_filter($data['login_limits'] ?? [], fn($r)=>$r['until']>$now);
        $studentId = null;
        foreach ($data['students'] ?? [] as $sid=>$s) {
            if (($qr !== '' && hash_equals($s['qr'],$qr)) || ($qr === '' && classroomCode($s['code']) === classroomCode($code))) { $studentId=$sid; break; }
        }
        // Both login routes share a per-account limit; an IP limit also covers unknown codes.
        $account = hash('sha256','account:'.($studentId ?? classroomCode($code)));
        $ip = hash('sha256','ip:'.($_SERVER['REMOTE_ADDR'] ?? 'local'));
        foreach ([$account=>8,$ip=>60] as $key=>$limit) {
            if (($data['login_limits'][$key]['count'] ?? 0)>=$limit) return null;
        }
        $student = $data['students'][$studentId] ?? null;
        if (!$student || empty($student['active']) || !preg_match('/^[0-9]{4}$/D',$pin) || !password_verify($pin,$student['pin_hash'])) {
            foreach ([$account,$ip] as $key) {
                $row = $data['login_limits'][$key] ?? ['count'=>0,'until'=>$now+900];
                $row['count']++; $data['login_limits'][$key]=$row;
            }
            return null;
        }
        unset($data['login_limits'][$account]);
        return $studentId;
    });
    if (!$id) return false;
    $student=classroomRead()['students'][$id];
    session_regenerate_id(true);
    unset($_SESSION['teacher_id'],$_SESSION['teacher_name'],$_SESSION['super_admin']);
    $_SESSION['pupil']=['id'=>$id,'version'=>$student['version'] ?? 1,'expires'=>time()+8*3600];
    return true;
}
function classroomRecipients(array $data, array $assignment): array {
    $target=classroomOwned($data,$assignment['target_type'],$assignment['target_id'],$assignment['teacher_id']);
    $students=[];
    foreach ($data['students'] ?? [] as $id=>$s) {
        if (empty($s['active']) || $s['teacher_id']!==$assignment['teacher_id']) continue;
        if ($assignment['target_type']==='classes' ? $s['class_id']===$assignment['target_id'] : in_array($id,$target['students'],true)) $students[$id]=$s;
    }
    return $students;
}
function classroomAllowed(array $data, array $student, array $assignment): bool {
    if ($student['teacher_id']!==$assignment['teacher_id']) return false;
    try { return isset(classroomRecipients($data,$assignment)[$student['id']]); }
    catch (DomainException $e) { return false; }
}
function classroomContent(array $assignment): ?array {
    if ($assignment['kind']==='material') return ['title'=>$assignment['title']];
    $file=$assignment['kind']==='deck' ? DATA_DIR.'flashcards.json' : QUIZZES_FILE;
    $items=is_file($file) ? json_decode(file_get_contents($file),true) : [];
    $item=$items[$assignment['content_id']] ?? null;
    if (!$item || ($item['teacher_id'] ?? '')!==$assignment['teacher_id'] || ($item['active'] ?? true)===false) return null;
    if ($assignment['kind']!=='deck') {
        require_once __DIR__.'/../ai/quiz-service.php';
        try { $item=aiStudentQuiz($item); } catch (QuizAiError $e) { return null; }
        if ($assignment['kind']==='cards' && empty($item['settings']['generate_flashcards'])) return null;
    }
    return $item;
}
function classroomAttempts(array $data, string $assignment, string $student): array {
    $rows=array_values(array_filter($data['attempts'] ?? [],fn($a)=>$a['assignment_id']===$assignment && $a['student_id']===$student));
    usort($rows,fn($a,$b)=>strcmp($b['updated'],$a['updated']));
    return $rows;
}
function classroomStatus(array $rows, string $kind): string {
    if (!$rows) return $kind==='material'?'Inte hämtad':'Inte påbörjat';
    if ($kind==='material') return 'Hämtad';
    if ($kind==='cards' || $kind==='deck') return 'Övat';
    foreach ($rows as $a) if ($a['completed']) return 'Klart';
    return 'Påbörjat';
}
function classroomRecord(array $student, array $input): void {
    classroomUpdate(function (&$data) use ($student,$input) {
        $current=classroomStudent($data);
        if (!$current || $current['id']!==$student['id'] || ($input['student_id'] ?? '')!==$current['id']) throw new DomainException('Logga in igen.');
        $assignment=classroomOwned($data,'assignments',(string)($input['assignment_id'] ?? ''),$student['teacher_id']);
        if (!classroomAllowed($data,$current,$assignment) || !classroomContent($assignment)) throw new DomainException('Uppgiften är inte tillgänglig.');
        if ($assignment['kind']==='material') throw new DomainException('Använd materiallänken.');
        $id=$input['attempt_id'] ?? '';
        if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D',$id)) throw new DomainException('Ogiltigt försök.');
        $old=$data['attempts'][$id] ?? null;
        if ($old && ($old['student_id']!==$student['id'] || $old['assignment_id']!==$assignment['id'])) throw new DomainException('Ogiltigt försök.');
        foreach (['seq','right','wrong','cards'] as $key) if (!isset($input[$key]) || !is_int($input[$key]) || $input[$key]<0 || $input[$key]>100000) throw new DomainException('Ogiltig aktivitet.');
        if ($old && $input['seq'] <= $old['seq']) return;
        $data['attempts'][$id]=[
            'student_id'=>$student['id'],'assignment_id'=>$assignment['id'],'teacher_id'=>$student['teacher_id'],
            'seq'=>$input['seq'],'right'=>max($old['right'] ?? 0,$input['right']),'wrong'=>max($old['wrong'] ?? 0,$input['wrong']),
            'cards'=>max($old['cards'] ?? 0,$input['cards']),'completed'=>!empty($old['completed']) || ($input['completed'] ?? false)===true,
            'started'=>$old['started'] ?? gmdate('c'),'updated'=>gmdate('c')
        ];
    });
}
function classroomResultIdentity(array $input): void {
    $student=classroomStudent();
    if (($input['student_id'] ?? null)!==($student['id'] ?? null)) {
        http_response_code(401); header('Content-Type: application/json'); exit(json_encode(['error'=>'Eleven har bytts. Ladda om sidan och logga in igen.']));
    }
}
function classroomEscape($value): string { return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); }
function classroomPlayer(string $contentId, string $kind): void {
    $student=classroomStudent();
    if (!$student) {
        if (isset($_GET['assignment'])) { header('Location: ../student/'); exit; }
        return;
    }
    header('Cache-Control: no-store');
    $data=classroomRead(); $assignment=null;
    if (isset($_GET['assignment'])) {
        $candidate=$data['assignments'][$_GET['assignment']] ?? null;
        if ($candidate && $candidate['content_id']===$contentId && $candidate['kind']===$kind && classroomAllowed($data,$student,$candidate)) $assignment=$candidate;
    } else {
        foreach ($data['assignments'] ?? [] as $candidate) if ($candidate['content_id']===$contentId && $candidate['kind']===$kind && classroomAllowed($data,$student,$candidate)) { $assignment=$candidate; break; }
    }
    if (isset($_GET['assignment']) && (!$assignment || !classroomContent($assignment))) { http_response_code(403); exit('Uppgiften är inte tillgänglig.'); }
    $GLOBALS['classroom_player']=['student'=>$student,'assignment'=>$assignment];
}
function classroomPlayerBar(): void {
    $context=$GLOBALS['classroom_player'] ?? null;
    echo '<div class="classroom-bar" style="padding:10px 16px;display:flex;gap:20px;flex-wrap:wrap;background:#eff6ff;color:#172554;font:16px system-ui">';
    if (!$context) { echo '<a href="../student/">Elevinloggning</a></div>'; return; }
    echo '<a href="../student/">Mina uppgifter</a><span>'.classroomEscape($context['student']['name']).'</span><form action="../student/" method="post">'.csrfField().'<button name="action" value="logout">Logga ut / byt elev</button></form></div>';
    if (!isset($_GET['preview'])) {
        $settings=['assignment_id'=>$context['assignment']['id'] ?? null,'student_id'=>$context['student']['id'],'kind'=>$context['assignment']['kind'] ?? null,'csrf'=>getCsrfToken()];
        echo '<script>window.classroomContext='.json_encode($settings,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).';</script><script src="../engine/classroom.js?v='.filemtime(__DIR__.'/../engine/classroom.js').'"></script>';
    }
}
