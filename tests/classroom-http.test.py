"""Synthetic, isolated HTTP checks. Never reads local accounts, quizzes or config.local.php.
Run: python tests/classroom-http.test.py [--robots] [--serve]
"""
import http.cookiejar
import json
import re
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.request
from pathlib import Path
from urllib.parse import urlencode
import sys
import os
import stat

root = Path(__file__).resolve().parents[1]
fixture = Path(tempfile.mkdtemp(prefix='quiz-classroom-'))
checks = 0
server = None

def check(condition, message):
    global checks
    if not condition:
        raise AssertionError(message)
    checks += 1

def client():
    return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))

def req(who, path, form=None, payload=None, token=None, raw=None, headers=None):
    headers = dict(headers or {})
    body = raw
    if form is not None:
        body = urlencode(form, doseq=True).encode()
    if payload is not None:
        body = json.dumps(payload).encode()
        headers['Content-Type'] = 'application/json'
    if token:
        headers['X-CSRF-Token'] = token
    request = urllib.request.Request(base + path, body, headers)
    try:
        with who.open(request) as response:
            return response.status, response.read().decode('utf-8'), response.url
    except urllib.error.HTTPError as error:
        return error.code, error.read().decode('utf-8'), error.url

def csrf(who, path='/student/'):
    status, text, _ = req(who, path)
    check(status == 200, 'Page unavailable: ' + path)
    return re.search(r'name="csrf_token" value="([^"]+)"', text).group(1)

def state():
    return json.loads((fixture / 'data/classroom.json').read_text('utf-8'))

def admin(form, who=None, token=None):
    return req(who or teacher, '/admin/classes.php', form={'csrf_token': token or teacher_csrf, **form})

try:
    for folder in ['admin', 'ai', 'api', 'assets', 'engine', 'includes', 'play', 'q']:
        shutil.copytree(root / folder, fixture / folder)
    for name in ['config.php', 'index.php', 'admin.php', 'flashcards-admin.php', 'stats.php']:
        shutil.copy2(root / name, fixture / name)
    (fixture / 'data/materials').mkdir(parents=True)
    (fixture / 'sessions').mkdir()
    shutil.copytree(root / 'student', fixture / 'student')
    (fixture / 'seed.php').write_text('''<?php
require __DIR__.'/config.php';
writeJSON(TEACHERS_FILE,[
 't1'=>['username'=>'fixture','name'=>'Testlärare','active'=>true,'password_hash'=>password_hash('fixture-pass',PASSWORD_DEFAULT)],
 't2'=>['username'=>'other','name'=>'Annan lärare','active'=>true,'password_hash'=>password_hash('other-pass',PASSWORD_DEFAULT)]]);
$quiz=['id'=>'q_fixture','teacher_id'=>'t1','publication'=>'published','title'=>'Testquiz','type'=>'fact','settings'=>['quiz_mode'=>'test','answer_mode'=>'multiple_choice','required_correct'=>1,'language'=>'sv','generate_flashcards'=>true,'tts_enabled'=>false],
'items'=>[['concept'=>'Två plus två?','description'=>'Fyra','wrong_options'=>['Tre','Fem','Sex']],['concept'=>'Tre plus tre?','description'=>'Sex','wrong_options'=>['Fyra','Fem','Sju']]],'results'=>[['student_name'=>'OLD_PRIVATE_RESULT','score'=>99,'total'=>100]]];
$legacy=['id'=>'aabbccdd','teacher_id'=>'t1','title'=>'Gammalt quiz','type'=>'fact','active'=>true,'quiz_mode'=>'test','answer_mode'=>'multiple_choice','questions'=>[['question'=>'Två plus två?','answer'=>'Fyra','options'=>['Fyra','Tre','Fem','Sex']]]];
$draft=$quiz;$draft['id']='q_draft';$draft['publication']='draft';
$other=$quiz;$other['id']='q_other';$other['teacher_id']='t2';
$sortQuiz=['id'=>'q_sort','teacher_id'=>'t1','publication'=>'published','title'=>'Sorteringsquiz','type'=>'sort','settings'=>['quiz_mode'=>'training','answer_mode'=>'multiple_choice','required_correct'=>1,'language'=>'sv','generate_flashcards'=>true,'tts_enabled'=>false],
'items'=>[['question'=>'Vilket rike?','flashcard_prompt'=>'Vilket rike tillhör','image'=>null,'fields'=>[['id'=>'svamp','title'=>'Svampar','text'=>'','image'=>null]],'objects'=>[['id'=>'champinjon','text'=>'Champinjon','image'=>null,'field'=>'svamp'],['id'=>'sten','text'=>'Sten','image'=>null,'field'=>null]]]],'results'=>[]];
$orderQuiz=['id'=>'q_order','teacher_id'=>'t1','publication'=>'published','title'=>'Ordningsquiz','type'=>'order','settings'=>['quiz_mode'=>'training','answer_mode'=>'multiple_choice','required_correct'=>1,'language'=>'sv','generate_flashcards'=>false,'tts_enabled'=>false],
'items'=>[['question'=>'Ordna','image'=>null,'orientation'=>'horizontal','labels'=>['start'=>'','middle'=>'','end'=>''],'objects'=>[['id'=>'a','text'=>'A','image'=>null],['id'=>'b','text'=>'B','image'=>null]]]],'results'=>[]];
writeJSON(QUIZZES_FILE,['q_fixture'=>$quiz,'aabbccdd'=>$legacy,'q_draft'=>$draft,'q_other'=>$other,'q_sort'=>$sortQuiz,'q_order'=>$orderQuiz]);
writeJSON(DATA_DIR.'flashcards.json',['d_fixture'=>['id'=>'d_fixture','teacher_id'=>'t1','title'=>'Separata kort','active'=>true,'cards'=>[['front'=>'A','back'=>'B'],['front'=>'C','back'=>'D']],'results'=>[['student_name'=>'OLD_PRIVATE_DECK_RESULT']]]]);
writeJSON(STATS_FILE,[]);
''', encoding='utf-8')
    subprocess.run(['php', str(fixture / 'seed.php')], check=True, capture_output=True)
    (fixture / 'router.php').write_text('''<?php
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if (str_starts_with($path,'/data/') || $path==='/seed.php') {http_response_code(403);exit;}
if (preg_match('~^/q/[a-f0-9]+[.]html$~',$path)) {chdir(__DIR__.'/q'); require __DIR__.'/q/index.php';return true;}
return false;
''', encoding='utf-8')
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    base = f'http://127.0.0.1:{port}'
    log = open(fixture / 'server.log', 'w', encoding='utf-8')
    server = subprocess.Popen(['php', '-d', 'session.save_path=' + str(fixture/'sessions'), '-d', 'upload_max_filesize=20M', '-d', 'post_max_size=22M', '-S', f'127.0.0.1:{port}', '-t', str(fixture), str(fixture/'router.php')], cwd=fixture, stdout=log, stderr=log)
    for _ in range(80):
        try:
            urllib.request.urlopen(base+'/student/').close()
            break
        except urllib.error.URLError:
            time.sleep(0.1)
    teacher = client()
    teacher_csrf = csrf(teacher, '/index.php')
    status, text, _ = req(teacher, '/index.php', form={'username':'fixture','password':'fixture-pass','csrf_token':teacher_csrf})
    check(status == 200 and 'Klasser och uppgifter' in text, 'Teacher login failed')
    check(admin({'action':'class','name':'6A'})[0] == 200, 'Class create failed')
    c1 = next(iter(state()['classes']))
    admin({'action':'class','name':'6B'})
    c2 = list(state()['classes'])[1]
    status, text, _ = admin({'action':'students','class_id':c1,'names':'Alex Test\nKim Test'})
    check('2 elever tillagda' in text, 'Batch student create failed')
    students = list(state()['students'].values())
    alex, kim = students
    status, cards, _ = req(teacher, '/admin/cards.php?new=1')
    pins = re.findall(r'PIN: ([0-9]{4})', cards)
    check(len(pins) == 2, 'New PIN cards unavailable')
    alex_pin, kim_pin = pins
    check('pin_hash' not in text and 'qr=' not in text, 'Student list leaked credentials')
    check(all(s['pin_hash'] != p for s, p in zip(students, pins)), 'Plain PIN persisted')
    group_form={'action':'group','name':'Blandad grupp','members[]':[alex['id'],kim['id']]}
    admin(group_form)
    gid=next(iter(state()['groups']))
    admin({'action':'move','student_id':kim['id'],'class_id':c2})
    check(len(state()['groups'][gid]['students']) == 2, 'Move lost group membership')
    def assign(content, target='classes:'+c1):
        before=set(state().get('assignments', {}))
        response=admin({'action':'assign','target':target,'content':content})
        added=set(state().get('assignments', {}))-before
        check(response[0] == 200 and len(added) == 1, 'Assignment failed: '+content)
        return added.pop()
    quiz_a=assign('quiz:q_fixture')
    cards_a=assign('cards:q_fixture')
    deck_a=assign('deck:d_fixture', 'groups:'+gid)
    legacy_a=assign('quiz:aabbccdd')
    before=state()
    admin({'action':'assign','target':'classes:'+c1,'content':'quiz:q_draft'})
    check(state()==before, 'Draft was assigned')
    admin({'action':'assign','target':'classes:'+c1,'content':'quiz:q_other'})
    check(state()==before, 'Other teacher quiz was assigned')
    other=client();other_csrf=csrf(other,'/index.php')
    req(other,'/index.php',form={'username':'other','password':'other-pass','csrf_token':other_csrf})
    check('Alex Test' not in req(other,'/admin/classes.php')[1], 'Teacher isolation failed')
    check('Alex Test' not in req(other,'/admin/cards.php')[1], 'Cards leaked to other teacher')
    admin({'action':'move','student_id':alex['id'],'class_id':c2},other,other_csrf)
    check(state()==before, 'Cross-teacher mutation succeeded')
    check(req(client(), '/api/quiz-data.php?id=q_fixture')[0] == 200, 'Public quiz broke')
    pupil=client();pupil_csrf=csrf(pupil)
    status, home, _ = req(pupil,'/student/',form={'csrf_token':pupil_csrf,'code':alex['code'].upper(),'pin':alex_pin})
    check(status==200 and 'Hej Alex Test' in home, 'Word-code login failed')
    check('OLD_PRIVATE' not in home and 'score' not in home and 'pin_hash' not in home, 'Pupil history leak')
    status, player, player_url = req(pupil,'/student/open.php?assignment='+quiz_a)
    check(status==200 and '/play/quiz.php?' in player_url and 'classroomContext=' in player, 'Assigned quiz player failed')
    check('OLD_PRIVATE' not in req(pupil,'/api/quiz-data.php?id=q_fixture')[1], 'Public API history leak')
    check(req(pupil,'/play/quiz.php?id=q_other&name=Alex')[0]==200,'Unassigned public quiz unavailable to logged-in pupil')
    wrong_identity={'quiz_id':'q_fixture','student_name':'Pretend Name','student_id':kim['id'],'score':1,'total':1}
    check(req(pupil,'/api/save-result.php',payload=wrong_identity)[0]==401,'Result endpoint accepted stale pupil identity')
    wrong_identity['student_id']=alex['id']
    check(req(pupil,'/api/save-result.php',payload=wrong_identity)[0]==200,'Authenticated legacy result save failed')
    saved_quizzes=json.loads((fixture/'data/quizzes.json').read_text('utf-8'))
    saved_result=saved_quizzes['q_fixture']['results'][-1]
    check(saved_result['student_id']==alex['id'] and saved_result['student_name']=='Alex Test','Result trusted typed identity')
    check('/q/aabbccdd.html?' in req(pupil,'/student/open.php?assignment='+legacy_a)[2], 'Legacy player failed')
    for n, (content, player) in enumerate([('quiz:q_sort','/play/sort-quiz.php?'),('cards:q_sort','/play/flashcards.php?'),('quiz:q_order','/play/order-quiz.php?')], 1):
        drag_a=assign(content)
        status, page, url = req(pupil,'/student/open.php?assignment='+drag_a)
        check(status==200 and player in url and 'classroomContext=' in page, 'Assigned drag quiz player failed: '+content)
        drag_payload={'student_id':alex['id'],'assignment_id':drag_a,'attempt_id':str(n)*32,'seq':1,'right':5,'wrong':2,'cards':0,'completed':True}
        check(req(pupil,'/api/classroom-activity.php',payload=drag_payload,token=pupil_csrf)[0]==200, 'Drag quiz activity failed: '+content)
    check(state()['attempts']['1'*32]['right']==5, 'Sort quiz activity not stored')
    payload={'student_id':alex['id'],'assignment_id':quiz_a,'attempt_id':'a'*32,'seq':1,'right':1,'wrong':0,'cards':0,'completed':False}
    check(req(pupil,'/api/classroom-activity.php',payload=payload)[0]==403,'CSRF not enforced')
    check(req(pupil,'/api/classroom-activity.php',payload=payload,token=pupil_csrf)[0]==200,'Partial attempt failed')
    check('Påbörjat' in req(pupil,'/student/')[1], 'Partial status missing')
    status, view, _=req(teacher,'/admin/classes.php')
    check('1 rätt' in view and '0 fel' in view, 'Teacher partial result missing')
    payload.update(seq=2,right=1,wrong=1,completed=True)
    check(req(pupil,'/api/classroom-activity.php',payload=payload,token=pupil_csrf)[0]==200,'Completed attempt failed')
    payload.update(seq=1,right=0,wrong=0,completed=False)
    req(pupil,'/api/classroom-activity.php',payload=payload,token=pupil_csrf)
    attempt=state()['attempts']['a'*32]
    check(attempt['completed'] and attempt['wrong']==1, 'Out-of-order retry overwrote activity')
    home=req(pupil,'/student/')[1]
    check('Klart' in home and '1 rätt' not in home and '1 fel' not in home, 'Pupil status/score privacy failed')
    payload.update(assignment_id=cards_a,attempt_id='b'*32,seq=1,right=0,wrong=0,cards=1,completed=False)
    req(pupil,'/api/classroom-activity.php',payload=payload,token=pupil_csrf)
    check('Övat' in req(pupil,'/student/')[1], 'Flashcard practice missing')
    second=client();second_csrf=csrf(second)
    status,home,_=req(second,'/student/?qr='+kim['qr'],form={'csrf_token':second_csrf,'qr':kim['qr'],'pin':kim_pin})
    check('Hej Kim Test' in home, 'QR plus PIN login failed')
    check(req(second,'/student/open.php?assignment='+quiz_a)[0]==403, 'Non-recipient opened assignment')
    check(req(second,'/api/classroom-activity.php',payload=payload,token=second_csrf)[0]==403, 'Non-recipient wrote activity')
    check('Separata kort' in home, 'Cross-class group assignment missing')
    stale={**payload,'assignment_id':deck_a,'attempt_id':'c'*32,'student_id':alex['id']}
    check(req(second,'/api/classroom-activity.php',payload=stale,token=second_csrf)[0]==403,'Old tab attributed another pupil activity')
    stale['student_id']=kim['id']
    check(req(second,'/api/classroom-activity.php',payload=stale,token=second_csrf)[0]==200,'Current pupil group activity failed')
    check('OLD_PRIVATE_DECK_RESULT' not in req(second,'/student/open.php?assignment='+deck_a)[1], 'Deck history leaked')
    # Multipart PDF upload and authorized download.
    boundary='ClassroomSyntheticBoundary'
    parts=[]
    for key,value in {'csrf_token':teacher_csrf,'action':'assign','target':'classes:'+c1,'content':'material','title':'Övningsblad'}.items():
        parts.append(('--'+boundary+'\r\nContent-Disposition: form-data; name="'+key+'"\r\n\r\n'+value+'\r\n').encode())
    pdf=b'%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n'
    parts.append(('--'+boundary+'\r\nContent-Disposition: form-data; name="pdf"; filename="blad.pdf"\r\nContent-Type: application/pdf\r\n\r\n').encode()+pdf+b'\r\n')
    parts.append(('--'+boundary+'--\r\n').encode())
    before=set(state()['assignments'])
    upload_response=req(teacher,'/admin/classes.php',raw=b''.join(parts),headers={'Content-Type':'multipart/form-data; boundary='+boundary})
    added=set(state()['assignments'])-before
    check(len(added)==1,'PDF upload failed: '+upload_response[1][:3500])
    pdf_a=added.pop(); material=state()['assignments'][pdf_a]
    check(req(pupil,'/student/open.php?assignment='+pdf_a)[1].startswith('%PDF'), 'PDF download failed')
    check('Hämtad' in req(pupil,'/student/')[1], 'Download status missing')
    check(req(second,'/student/open.php?assignment='+pdf_a)[0]==403, 'Non-recipient downloaded PDF')
    check(req(pupil,'/data/materials/'+material['file'])[0]==403, 'Direct material access permitted')
    # Reset revokes existing sessions and the old QR. Restore access using the unchanged word code.
    admin({'action':'reset','student_id':alex['id']})
    check('Elevinloggning' in req(pupil,'/student/')[1], 'Reset kept session alive')
    new_pin=re.search(r'PIN: ([0-9]{4})',req(teacher,'/admin/cards.php?new=1')[1]).group(1)
    oldqr=client();token=csrf(oldqr)
    check('Hej Alex' not in req(oldqr,'/student/',form={'csrf_token':token,'qr':alex['qr'],'pin':new_pin})[1], 'Revoked QR accepted')
    pupil=client();pupil_csrf=csrf(pupil)
    check('Hej Alex Test' in req(pupil,'/student/',form={'csrf_token':pupil_csrf,'code':alex['code'],'pin':new_pin})[1], 'Reset word login failed')
    admin({'action':'remove','student_id':alex['id']})
    check(req(pupil,'/api/classroom-activity.php',payload=payload,token=pupil_csrf)[0]==401, 'Removed student retained access')
    check('borttagen' in req(teacher,'/admin/classes.php')[1] and '1 rätt' in req(teacher,'/admin/classes.php')[1], 'Removal lost teacher history')
    # Rate limit applies across QR and word routes.
    blocked=client(); token=csrf(blocked)
    for _ in range(8):
        req(blocked,'/student/',form={'csrf_token':token,'code':kim['code'],'pin':'not-a-pin'})
    check('Hej Kim Test' not in req(blocked,'/student/',form={'csrf_token':token,'qr':kim['qr'],'pin':kim_pin})[1], 'PIN limit bypassed with QR')
    admin({'action':'reset','student_id':kim['id']})
    kim_new_pin=re.search(r'PIN: ([0-9]{4})',req(teacher,'/admin/cards.php?new=1')[1]).group(1)
    check('Hej Kim Test' in req(blocked,'/student/',form={'csrf_token':token,'code':kim['code'],'pin':kim_new_pin})[1], 'Teacher reset did not unlock login')
    req(blocked,'/student/',form={'csrf_token':token,'action':'logout'})
    check('Elevinloggning' in req(blocked,'/student/')[1], 'Logout failed')
    if '--robots' in sys.argv:
        from classroom_robots import run_robots
        run_robots(fixture)
    log.flush()
    server_log=(fixture/'server.log').read_text('utf-8')
    check(not any(x in server_log for x in ['PHP Warning','PHP Fatal','PHP Parse']), 'PHP runtime warning: '+server_log[-2500:])
    print(f'PASS: {checks} classroom HTTP checks', flush=True)
    if '--serve' in sys.argv:
        # Add a fresh pupil for browser QA. All accounts below are synthetic.
        admin({'action':'students','class_id':c1,'names':'Robin Demo'})
        robin=list(state()['students'].values())[-1]
        robin_pin=re.search(r'PIN: ([0-9]{4})',req(teacher,'/admin/cards.php?new=1')[1]).group(1)
        print(json.dumps({'url':base,'teacher':'fixture','password':'fixture-pass','student_code':robin['code'],'student_pin':robin_pin,'student_qr':robin['qr'],'fixture':str(fixture)},ensure_ascii=False),flush=True)
        while True:
            time.sleep(1)
finally:
    if server:
        server.terminate()
        server.wait(timeout=10)
    if 'log' in globals():
        log.close()
    # Verify the disposable target before recursive cleanup on Windows.
    if fixture.resolve().parent == Path(tempfile.gettempdir()).resolve() and fixture.name.startswith('quiz-classroom-'):
        def unlock_and_remove(function, path, error):
            if Path(path).resolve().is_relative_to(fixture.resolve()):
                os.chmod(path, stat.S_IWRITE | stat.S_IREAD | stat.S_IEXEC)
                function(path)
        shutil.rmtree(fixture, onexc=unlock_and_remove)
