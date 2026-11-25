"""Synthetic, isolated HTTP checks. Never reads local accounts, quizzes or config.local.php.
Run: python tests/classroom-http.test.py [--robots] [--serve]
"""
import hashlib
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
    for name in ['config.php', 'index.php', 'admin.php', 'flashcards-admin.php', 'stats.php', 'edit-flashcard.php', 'test-upload.php', 'test-error.php', 'test-stats.php']:
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
$sortQuiz=['id'=>'q_sort','teacher_id'=>'t1','publication'=>'published','title'=>'Sorteringsquiz','type'=>'sort','subject'=>'Biologi','settings'=>['quiz_mode'=>'training','answer_mode'=>'multiple_choice','required_correct'=>1,'language'=>'sv','generate_flashcards'=>true,'tts_enabled'=>false],
'items'=>[['question'=>'Vilket rike?','flashcard_prompt'=>'Vilket rike tillhör','image'=>null,'fields'=>[['id'=>'svamp','title'=>'Svampar','text'=>'','image'=>null]],'objects'=>[['id'=>'champinjon','text'=>'Champinjon','image'=>null,'field'=>'svamp'],['id'=>'sten','text'=>'Sten','image'=>null,'field'=>null]]]],'results'=>[]];
$orderQuiz=['id'=>'q_order','teacher_id'=>'t1','publication'=>'published','title'=>'Ordningsquiz','type'=>'order','settings'=>['quiz_mode'=>'training','answer_mode'=>'multiple_choice','required_correct'=>1,'language'=>'sv','generate_flashcards'=>false,'tts_enabled'=>false],
'items'=>[['question'=>'Ordna','image'=>null,'orientation'=>'horizontal','labels'=>['start'=>'','middle'=>'','end'=>''],'objects'=>[['id'=>'a','text'=>'A','image'=>null],['id'=>'b','text'=>'B','image'=>null]]]],'results'=>[]];
$glossQuiz=['id'=>'q_gloss','teacher_id'=>'t1','publication'=>'published','title'=>'Glosquiz','type'=>'glossary','subject'=>'Engelska','settings'=>['quiz_mode'=>'training','answer_mode'=>'multiple_choice','required_correct'=>1,'language'=>'en','generate_flashcards'=>true,'tts_enabled'=>false,'reverse_enabled'=>false],
'items'=>[['word'=>'dog','translation'=>'hund','sentence'=>'The dog runs.','wrong_options'=>['katt','häst','fisk']],['word'=>'cat','translation'=>'katt','sentence'=>'The cat sleeps.','wrong_options'=>['hund','ko','mus']]],'results'=>[]];
$robotImage=fn($alt)=>['file'=>'ai_'.str_repeat('b',32).'.png','alt'=>$alt,'crop'=>['x'=>50,'y'=>50,'zoom'=>100]];
$imageQuiz=['id'=>'q_img','teacher_id'=>'t1','publication'=>'published','title'=>'Bildvalsquiz','type'=>'image_choice','settings'=>['quiz_mode'=>'training','required_correct'=>1,'language'=>'sv','tts_enabled'=>false],
'items'=>[['question'=>'Vilken är en katt?','image'=>null,'choices'=>[['id'=>'c1','image'=>$robotImage('Katt'),'correct'=>true],['id'=>'c2','image'=>$robotImage('Hund'),'correct'=>false]]]],'results'=>[]];
$archive=$quiz;$archive['id']='q_archive';$archive['title']='Arkiverat quiz';$archive['results']=[];
$repQuiz=['id'=>'q_rep','teacher_id'=>'t1','publication'=>'published','title'=>'Repetitionsquiz','type'=>'fact','settings'=>['quiz_mode'=>'training','answer_mode'=>'multiple_choice','required_correct'=>1,'language'=>'sv','generate_flashcards'=>false,'tts_enabled'=>false,'spaced_repetition'=>true],
'items'=>[['concept'=>'Fyra plus fyra?','description'=>'Åtta','wrong_options'=>['Sju','Nio','Tio']],['concept'=>'Fem plus fem?','description'=>'Tio','wrong_options'=>['Nio','Elva','Tolv']]],'results'=>[]];
writeJSON(QUIZZES_FILE,['q_fixture'=>$quiz,'aabbccdd'=>$legacy,'q_draft'=>$draft,'q_other'=>$other,'q_sort'=>$sortQuiz,'q_order'=>$orderQuiz,'q_gloss'=>$glossQuiz,'q_img'=>$imageQuiz,'q_archive'=>$archive,'q_rep'=>$repQuiz]);
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
    check(status == 200 and 'Klasser' in text, 'Teacher login failed')
    for diagnostic in ['test-upload.php', 'test-error.php', 'test-stats.php']:
        check(req(client(), '/' + diagnostic)[0] == 410, 'Public diagnostic is available: ' + diagnostic)
    # A valid raster named .php must be stored as a raster, never with its supplied suffix.
    import base64
    png = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=')
    def image_upload(blob, filename):
        boundary = 'quiz-upload-fixture-boundary'
        body = ('--'+boundary+'\r\nContent-Disposition: form-data; name="csrf_token"\r\n\r\n'+teacher_csrf+'\r\n' +
                '--'+boundary+'\r\nContent-Disposition: form-data; name="action"\r\n\r\nupload_image\r\n' +
                '--'+boundary+'\r\nContent-Disposition: form-data; name="image"; filename="'+filename+'"\r\nContent-Type: image/png\r\n\r\n').encode() + blob + ('\r\n--'+boundary+'--\r\n').encode()
        return req(teacher, '/edit-flashcard.php?deck_id=d_fixture', raw=body, headers={'Content-Type':'multipart/form-data; boundary='+boundary})
    uploaded = json.loads(image_upload(png, 'unsafe.php')[1])
    check(uploaded.get('success') and uploaded['filename'].endswith('.png'), 'Upload retained an executable extension')
    check(not json.loads(image_upload(b'not an image', 'fake.png')[1]).get('success'), 'Invalid raster accepted')

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
    # The printed card identifies nobody: the QR is only the login address.
    check('?qr=' not in cards and alex['qr'] not in cards, 'Card QR carried the pupil identity')
    check(cards.count('/student/')>=2 and 'Elevinloggning: http' in cards, 'Card login address missing')
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
    retry_result={**wrong_identity, 'request_id':'d'*32}
    before_count=len(saved_quizzes['q_fixture']['results'])
    check(req(pupil,'/api/save-result.php',payload=retry_result)[0]==200,'Receipt result failed')
    check(req(pupil,'/api/save-result.php',payload=retry_result)[0]==200,'Receipt retry failed')
    check(len(json.loads((fixture/'data/quizzes.json').read_text('utf-8'))['q_fixture']['results'])==before_count+1,'Retry duplicated result')
    check(req(pupil,'/api/save-result.php',payload={**retry_result,'score':99})[0]==409,'Conflicting result retry accepted')
    legacy_result={'quiz_id':'aabbccdd','student_id':alex['id'],'student_name':'Alex','completed':True,'time_seconds':12,'total_errors':0,'request_id':'e'*32}
    for _ in range(2):
        check(req(pupil,'/api/save-stats.php',payload=legacy_result)[0]==200,'Legacy receipt retry failed')
    check(json.loads((fixture/'data/stats.json').read_text('utf-8'))['aabbccdd']['total_attempts']==1,'Legacy retry duplicated result')
    # Failure after the classroom commit leaves durable, retryable delivery.
    quiz_path=fixture/'data/quizzes.json'
    quizzes=json.loads(quiz_path.read_text('utf-8'))
    quizzes['q_fixture']['settings']['spaced_repetition']=True
    quiz_path.write_text(json.dumps(quizzes),encoding='utf-8')
    repetition_path=fixture/'data/repetition.json'
    repetition_path.write_text('{broken',encoding='utf-8')
    review_activity={'student_id':alex['id'],'assignment_id':quiz_a,'attempt_id':'f'*32,'seq':1,'right':1,'wrong':0,'cards':0,'completed':False,'answers':[{'q':'0','w':0}]}
    check(req(pupil,'/api/classroom-activity.php',payload=review_activity,token=pupil_csrf)[0]==503,'Broken repetition data was not rejected')
    check(repetition_path.read_text('utf-8')=='{broken','Broken repetition data was overwritten')
    queued=state()['repetition_outbox']
    check(len(queued)==1,'Failed repetition delivery was lost')
    repetition_path.write_text('{}',encoding='utf-8')
    for _ in range(2):
        check(req(pupil,'/api/classroom-activity.php',payload=review_activity,token=pupil_csrf)[0]==200,'Failed repetition delivery did not resume')
    key=alex['id']+':q_fixture:0'
    check(json.loads(repetition_path.read_text('utf-8'))['cards'][key]['reps']==1,'Resumed delivery duplicated review')
    # Simulate successful repetition write followed by failed acknowledgement.
    classroom=state(); classroom['repetition_outbox']=queued
    (fixture/'data/classroom.json').write_text(json.dumps(classroom),encoding='utf-8')
    check(req(pupil,'/api/classroom-activity.php',payload=review_activity,token=pupil_csrf)[0]==200,'Delivery acknowledgement did not resume')
    check(not state()['repetition_outbox'] and json.loads(repetition_path.read_text('utf-8'))['cards'][key]['reps']==1,'Acknowledgement retry duplicated review')
    review_answer={'quiz_id':'q_fixture','question_index':0,'wrong_attempts':1,'request_id':'9'*32}
    for _ in range(2):
        check(req(pupil,'/api/repetition-review.php',payload=review_answer,token=pupil_csrf)[0]==200,'Review retry failed')
    check(json.loads(repetition_path.read_text('utf-8'))['cards'][key]['reps']==2,'Review retry counted twice')
    quizzes=json.loads(quiz_path.read_text('utf-8')); quizzes['q_fixture']['settings']['spaced_repetition']=False
    quiz_path.write_text(json.dumps(quizzes),encoding='utf-8')

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
    # The quiz is stored on the attempt, so practice can be summed per quiz later.
    check(attempt['content_id']=='q_fixture' and attempt['kind']=='quiz', 'Attempt lost the quiz it belongs to')
    # Several new answers delivered in one save must all reach the repetition pool.
    # array_slice's third argument is the length, so passing preserve_keys there
    # silently truncated the batch to one answer while answers_applied counted all.
    def repetition():
        path=fixture/'data/repetition.json'
        return json.loads(path.read_text('utf-8'))['cards'] if path.is_file() else {}
    rep_a=assign('quiz:q_rep')
    rep_payload={'student_id':alex['id'],'assignment_id':rep_a,'attempt_id':'d'*32,'seq':1,'right':2,'wrong':1,'cards':0,
                 'completed':True,'answers':[{'q':'0','w':0},{'q':'1','w':2}]}
    check(req(pupil,'/api/classroom-activity.php',payload=rep_payload,token=pupil_csrf)[0]==200,'Repetition attempt failed')
    rep_cards={k:v for k,v in repetition().items() if v['quiz_id']=='q_rep' and v['student_id']==alex['id']}
    check(len(rep_cards)==2, 'Batched answers lost: %d of 2 repetition cards created' % len(rep_cards))
    check(sorted(c['question_index'] for c in rep_cards.values())==[0,1], 'Repetition cards lost a question')
    # 2+ wrong attempts is a lapse, 0 wrong is a clean pass.
    by_index={c['question_index']:c for c in rep_cards.values()}
    check(by_index[0]['lapses']==0 and by_index[1]['lapses']==1, 'Wrong attempts did not map to FSRS ratings')
    check(not state().get('repetition_outbox'), 'Repetition outbox was not drained')
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
    download=state()['attempts']['download:'+pdf_a+':'+alex['id']]
    check(download['content_id']==pdf_a and download['kind']=='material', 'Material download lost its content reference')
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
    # Bulk reset: several chosen pupils at once, all-or-nothing, unique PIN each.
    def new_cards():
        return dict(re.findall(r'<h2>([^<]*)</h2>.*?PIN: ([0-9]{4})', req(teacher,'/admin/cards.php?new=1')[1], re.S))
    admin({'action':'students','class_id':c2,'names':'Nour Test\nSam Test'})
    created=new_cards()
    roster={s['name']:s for s in state()['students'].values() if s['name'] in ('Nour Test','Sam Test')}
    nour, sam = roster['Nour Test'], roster['Sam Test']
    untouched=state()
    check('hittades inte' in admin({'action':'reset_many','student_ids[]':[nour['id'],'s_nonexistent']})[1], 'Bulk reset accepted an unknown pupil')
    check(state()==untouched, 'Rejected bulk reset still wrote data')
    check('tagits bort' in admin({'action':'reset_many','student_ids[]':[nour['id'],alex['id']]})[1], 'Bulk reset accepted a removed pupil')
    check(state()==untouched, 'Bulk reset with a removed pupil wrote data')
    check('Välj 1–100 elever' in admin({'action':'reset_many'})[1], 'Empty bulk reset accepted')
    check('2 elever har fått ny PIN och QR-kod' in admin({'action':'reset_many','student_ids[]':[nour['id'],sam['id'],nour['id']]})[1], 'Bulk reset failed')
    after=state()['students']
    check(all(after[s['id']]['version']==s['version']+1 and after[s['id']]['qr']!=s['qr'] and after[s['id']]['code']==s['code'] for s in (nour,sam)), 'Bulk reset left credentials unchanged')
    fresh=new_cards()
    check(len(fresh)==2 and fresh['Nour Test']!=fresh['Sam Test'], 'Bulk reset did not issue two separate PIN cards')
    stale=client(); stale_csrf=csrf(stale)
    check('Hej Nour Test' not in req(stale,'/student/',form={'csrf_token':stale_csrf,'code':nour['code'],'pin':created['Nour Test']})[1], 'Old PIN worked after bulk reset')
    renewed=client(); renewed_csrf=csrf(renewed)
    check('Hej Sam Test' in req(renewed,'/student/',form={'csrf_token':renewed_csrf,'code':sam['code'],'pin':fresh['Sam Test']})[1], 'New PIN failed after bulk reset')
    # Rename and delete a class. Dedicated classes, so the robots and --serve keep c1/c2.
    def named(kind, name):
        return next(i for i,row in state()[kind].items() if row['name']==name)
    admin({'action':'class','name':'Tillfällig 9C'})
    temp=named('classes','Tillfällig 9C')
    admin({'action':'students','class_id':temp,'names':'Tove Test'})
    tove=next(s for s in state()['students'].values() if s['name']=='Tove Test')
    tove_pin=new_cards()['Tove Test']
    admin({'action':'group','name':'Tillfällig grupp','members[]':[tove['id']]})
    tgid=named('groups','Tillfällig grupp')
    assign('quiz:q_fixture','classes:'+temp)
    check('Klassens namn är ändrat' in admin({'action':'class_rename','class_id':temp,'name':'9C Omdöpt'})[1], 'Class rename failed')
    check(state()['classes'][temp]['name']=='9C Omdöpt' and '9C Omdöpt' in req(teacher,'/admin/classes.php')[1], 'Renamed class missing')
    guarded=state()
    admin({'action':'class_rename','class_id':temp,'name':'Kapad klass'},other,other_csrf)
    check(state()==guarded, 'Other teacher renamed a class')
    admin({'action':'class_delete','class_id':temp},other,other_csrf)
    check(state()==guarded, 'Other teacher deleted a class')
    live=client(); live_csrf=csrf(live)
    check('Hej Tove Test' in req(live,'/student/',form={'csrf_token':live_csrf,'code':tove['code'],'pin':tove_pin})[1], 'Pupil of the temporary class could not log in')
    check('är borttagen tillsammans med 1 elev' in admin({'action':'class_delete','class_id':temp})[1], 'Class delete failed')
    purged=state()
    check(temp not in purged['classes'], 'Deleted class row remained')
    check(not purged['students'][tove['id']]['active'] and purged['students'][tove['id']]['version']==tove['version']+1, 'Class delete left the pupil active')
    check(tove['id'] not in purged['groups'][tgid]['students'], 'Class delete kept the group membership')
    gone=client(); gone_csrf=csrf(gone)
    check('Hej Tove Test' not in req(gone,'/student/',form={'csrf_token':gone_csrf,'code':tove['code'],'pin':tove_pin})[1], 'Pupil of a deleted class still logged in')
    # Regression: the overview used to crash on an assignment whose target was gone.
    status,overview,_=req(teacher,'/admin/classes.php')
    check(status==200 and 'Borttagen klass' in overview and '9C Omdöpt' not in overview, 'Overview broke after the class was deleted')
    admin({'action':'class','name':'Tom klass'})
    check('Klassen Tom klass är borttagen.' in admin({'action':'class_delete','class_id':named('classes','Tom klass')})[1], 'Empty class delete failed')
    # Class and pupil practice views. Own class, so the robots keep c1/c2 intact.
    admin({'action':'class','name':'7D'})
    pc=named('classes','7D')
    admin({'action':'students','class_id':pc,'names':'Elin Test\nNoel Test'})
    pcards=new_cards()
    elin=next(s for s in state()['students'].values() if s['name']=='Elin Test')
    live_a=assign('quiz:q_fixture','classes:'+pc)
    archive_a=assign('quiz:q_archive','classes:'+pc)
    elin_client=client(); elin_csrf=csrf(elin_client)
    check('Hej Elin Test' in req(elin_client,'/student/',form={'csrf_token':elin_csrf,'code':elin['code'],'pin':pcards['Elin Test']})[1], 'Practice fixture pupil could not log in')
    elin_payload={'student_id':elin['id'],'assignment_id':live_a,'attempt_id':'e'*32,'seq':1,'right':2,'wrong':1,'cards':0,'completed':True}
    check(req(elin_client,'/api/classroom-activity.php',payload=elin_payload,token=elin_csrf)[0]==200,'Practice attempt failed')
    # Frågorna en elev svarade fel på finns bara i quizets egen resultatlista, så
    # elevvyn läser dem därifrån. Svaret är elevens egen text: ogranskad indata.
    elin_errors={'quiz_id':'q_fixture','student_id':elin['id'],'student_name':'Elin','score':1,'total':2,'request_id':'b'*32,
        'errors':[{'item_index':0,'given':'<script>alert(1)</script>','correct':'Fyra'},{'item_index':0,'given':'Tre','correct':'Fyra'},
                  {'item_index':99,'given':'x','correct':'y'},{'bad':'shape'},{'item_index':'inte ett tal'},'inte ens en lista']}
    check(req(elin_client,'/api/save-result.php',payload=elin_errors)[0]==200,'Wrong-answer result save failed')
    wrong_page=req(teacher,'/admin/pupil.php?id='+elin['id'])[1]
    check('Två plus två?' in wrong_page and '2 försök' in wrong_page,'Pupil page missing the wrong-answer list')
    check('&lt;script&gt;' in wrong_page and '<script>alert(1)</script>' not in wrong_page,'Pupil answer reached the page unescaped')
    check('Fråga 100' in wrong_page,'An error for a deleted question lost its fallback label')
    check('Två plus två?' not in req(other,'/admin/pupil.php?id='+elin['id'])[1],'Wrong answers leaked to another teacher')
    # Ett felfritt senare försök får inte visa ett äldre försöks fel som "senaste".
    # Noel används här, så Elins lista står kvar för --serve.
    noel_login=client(); noel_csrf=csrf(noel_login)
    noel_pupil=next(s for s in state()['students'].values() if s['name']=='Noel Test')
    check('Hej Noel Test' in req(noel_login,'/student/',form={'csrf_token':noel_csrf,'code':noel_pupil['code'],'pin':pcards['Noel Test']})[1],'Noel could not log in')
    noel_base={'quiz_id':'q_fixture','student_id':noel_pupil['id'],'student_name':'Noel','total':2}
    check(req(noel_login,'/api/save-result.php',payload={**noel_base,'score':1,'request_id':'c'*32,'errors':[{'item_index':1,'given':'Fem','correct':'Sex'}]})[0]==200,'First Noel result failed')
    check('Tre plus tre?' in req(teacher,'/admin/pupil.php?id='+noel_pupil['id'])[1],'Wrong answer missing before the clean run')
    check(req(noel_login,'/api/save-result.php',payload={**noel_base,'score':2,'request_id':'f'*32,'errors':[]})[0]==200,'Clean Noel result failed')
    clean_page=req(teacher,'/admin/pupil.php?id='+noel_pupil['id'])[1]
    check('Tre plus tre?' not in clean_page and 'Inga fel i elevens senaste försök' in clean_page,'A clean latest attempt still showed an older run as the latest')
    # Vägen från en utdelning till quizets statistik, bara för quiz läraren äger.
    overview=req(teacher,'/admin/classes.php?assignment='+live_a)[1]
    check('stats.php?id=q_fixture' in overview,'Overview lost the link to the quiz statistics')
    class_view=req(teacher,'/admin/class.php?id='+pc+'&assignment='+live_a)[1]
    check('stats.php?id=q_fixture' in class_view,'Class view lost the link to the quiz statistics')
    # Dela ut direkt från innehållslistan, samma handling som i Klasser.
    board=req(teacher,'/admin/dashboard.php')[1]
    check('data-assign-panel' in board and 'Dela ut' in board,'Dashboard lost the assign control')
    check('Publicera quizet' in board,'Dashboard did not say why an unpublished quiz cannot be assigned')
    check(req(teacher,'/admin/dashboard.php',form={'csrf_token':teacher_csrf,'action':'assign','content':'quiz:q_gloss','target':'classes:'+pc})[0]==200,'Assigning from the dashboard failed')
    check('Glosquiz' in req(teacher,'/admin/class.php?id='+pc)[1],'Assignment from the dashboard never reached the class')
    check('utdelad till 7D' in req(teacher,'/admin/dashboard.php')[1],'Dashboard row lost the receipt for where the quiz went')
    check(req(teacher,'/admin/dashboard.php',form={'action':'assign','content':'quiz:q_gloss','target':'classes:'+pc})[0]==403,'Assigning from the dashboard without CSRF was accepted')
    status,page,_=req(teacher,'/admin/class.php?id='+pc)
    check(status==200 and 'Testquiz' in page and 'Arkiverat quiz' in page and 'Elin Test' in page, 'Class page missing its assignments or pupils')
    status,page,_=req(teacher,'/admin/class.php?id='+pc+'&assignment='+live_a)
    check(status==200 and '2 rätt · 1 fel' in page and 'Inte påbörjat' in page, 'Class assignment view missing practice or a non-started pupil')
    # Unpublishing the quiz must not hide the practice that already happened.
    quizzes_file=fixture/'data/quizzes.json'
    archived=json.loads(quizzes_file.read_text('utf-8')); archived['q_archive']['publication']='draft'
    quizzes_file.write_text(json.dumps(archived,ensure_ascii=False),encoding='utf-8')
    status,page,_=req(teacher,'/admin/class.php?id='+pc+'&assignment='+archive_a)
    check(status==200 and 'Arkiverat quiz' in page, 'Unpublished quiz vanished from the class view')
    status,page,_=req(teacher,'/admin/pupil.php?id='+elin['id'])
    check(status==200 and 'Testquiz' in page and 'Arkiverat quiz' in page and '2 rätt · 1 fel' in page, 'Pupil page missing practice')
    check('Elin Test' in req(teacher,'/admin/pupil.php?q=elin')[1], 'Pupil search failed')
    check('Alex Test' in req(teacher,'/admin/pupil.php?q=ALEX')[1], 'Pupil search missed a removed pupil')
    # Exact numbers are not asserted here: two of Alex's attempts on the same quiz
    # can share a second, and "latest" then falls back to insertion order.
    gone_page=req(teacher,'/admin/pupil.php?id='+alex['id'])[1]
    check('borttagen, historiken är kvar' in gone_page and 'Testquiz' in gone_page and 'Klart' in gone_page, 'Removed pupil lost practice history')
    # A class shares one school IP. A pupil mistyping a PIN on a KNOWN code must not
    # count against that shared limit, or 60 typos lock out everyone in the room.
    # Only enumeration of unknown codes does. Few requests here: the robots and
    # --serve run afterwards from this same address.
    noel=next(s for s in state()['students'].values() if s['name']=='Noel Test')
    ip_key=hashlib.sha256(b'ip:127.0.0.1').hexdigest()
    noel_key=hashlib.sha256(('account:'+noel['id']).encode()).hexdigest()
    counts=lambda: {k:v['count'] for k,v in (state().get('login_limits') or {}).items()}
    before=counts()
    typo=client(); typo_csrf=csrf(typo)
    for _ in range(3):
        req(typo,'/student/',form={'csrf_token':typo_csrf,'code':noel['code'],'pin':'0000'})
    after=counts()
    check(after.get(ip_key,0)==before.get(ip_key,0), 'A known pupil mistyping a PIN still counted against the shared IP limit')
    check(after.get(noel_key,0)==before.get(noel_key,0)+3, 'Per-account limit stopped counting PIN mistakes')
    for n in (1,2):
        unknown=client(); unknown_csrf=csrf(unknown)
        req(unknown,'/student/',form={'csrf_token':unknown_csrf,'code':'Okand Kod Nummer%d'%n,'pin':'0000'})
    check(counts().get(ip_key,0)==before.get(ip_key,0)+2, 'Unknown codes no longer count toward the IP limit')
    # With the IP row saturated, a correct login must still work and enumeration stop.
    saturated=state(); saturated['login_limits'][ip_key]={'count':60,'until':int(time.time())+900}
    (fixture/'data/classroom.json').write_text(json.dumps(saturated,ensure_ascii=False),encoding='utf-8')
    held=client(); held_csrf=csrf(held)
    check('Hej Noel Test' in req(held,'/student/',form={'csrf_token':held_csrf,'code':noel['code'],'pin':pcards['Noel Test']})[1], 'Saturated IP limit blocked a correct login')
    walled=client(); walled_csrf=csrf(walled)
    check('Hej' not in req(walled,'/student/',form={'csrf_token':walled_csrf,'code':'Okand Kod Nummer9','pin':'0000'})[1], 'Saturated IP limit stopped throttling unknown codes')
    cleared=state(); del cleared['login_limits'][ip_key]
    (fixture/'data/classroom.json').write_text(json.dumps(cleared,ensure_ascii=False),encoding='utf-8')
    check('Elin Test' not in req(other,'/admin/class.php?id='+pc)[1], 'Class page leaked to another teacher')
    check('Testquiz' not in req(other,'/admin/pupil.php?id='+elin['id'])[1], 'Pupil page leaked to another teacher')
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
