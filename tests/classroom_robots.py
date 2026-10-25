"""Classroom traffic robots against multiple real PHP processes sharing disposable data."""
from concurrent.futures import ThreadPoolExecutor
import hashlib
import http.cookiejar
import json
from pathlib import Path
import re
import socket
import subprocess
import threading
import time
import urllib.error
import urllib.request
from urllib.parse import urlencode


def run_robots(fixture: Path):
    started = time.monotonic()
    mutex = threading.Lock()
    counts = {'checks': 0, 'requests': 0}
    timings = []
    failures = []
    workers = []

    def check(condition, message):
        with mutex:
            counts['checks'] += 1
        if not condition:
            raise AssertionError(message)

    class Robot:
        def __init__(self, base):
            self.base = base
            self.client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
            self.csrf = None

        def request(self, path, form=None, payload=None, csrf=True, base=None, raw=None, headers=None):
            request_headers = dict(headers or {})
            body = raw
            if form is not None:
                body = urlencode(form, doseq=True).encode()
            if payload is not None:
                body = json.dumps(payload).encode()
                request_headers['Content-Type'] = 'application/json'
                if csrf:
                    request_headers['X-CSRF-Token'] = self.csrf
            request = urllib.request.Request((base or self.base) + path, body, request_headers)
            before = time.monotonic()
            try:
                with self.client.open(request, timeout=30) as response:
                    result = response.status, response.read().decode('utf-8'), response.url
            except urllib.error.HTTPError as error:
                result = error.code, error.read().decode('utf-8'), error.url
            finally:
                with mutex:
                    counts['requests'] += 1
                    timings.append(time.monotonic() - before)
            return result

        def token(self, path='/student/'):
            status, text, _ = self.request(path)
            check(status == 200, 'CSRF page failed: ' + path)
            self.csrf = re.search(r'name="csrf_token" value="([^"]+)"', text).group(1)
            return self.csrf

        def teacher_login(self, name='fixture', password='fixture-pass'):
            self.token('/index.php')
            status, text, _ = self.request('/index.php', form={'username': name, 'password': password, 'csrf_token': self.csrf})
            check(status == 200 and 'Klasser och uppgifter' in text, 'Teacher login failed')

        def pupil_login(self, student, pin, qr=False):
            self.token()
            fields = {'qr': student['qr']} if qr else {'code': student['code'].upper()}
            status, text, _ = self.request('/student/', form={**fields, 'pin': pin, 'csrf_token': self.csrf})
            check(status == 200 and 'Hej ' + student['name'] in text, 'Pupil login failed: ' + student['name'])
            return text

        def admin(self, **fields):
            result = self.request('/admin/classes.php', form={**fields, 'csrf_token': self.csrf})
            check(result[0] == 200, 'Teacher operation failed: ' + fields['action'])
            return result

    def state():
        return json.loads((fixture / 'data/classroom.json').read_text('utf-8'))

    def attempt_id(label):
        return hashlib.sha256(label.encode()).hexdigest()[:32]

    try:
        # On Windows the built-in PHP server is single-process. Four separate servers
        # share file storage and a fixture-only session directory to exercise real races.
        for index in range(4):
            with socket.socket() as sock:
                sock.bind(('127.0.0.1', 0))
                port = sock.getsockname()[1]
            log = open(fixture / f'robots-worker-{index}.log', 'w', encoding='utf-8')
            process = subprocess.Popen(['php', '-d', 'session.save_path=' + str(fixture/'sessions'),
                                        '-d', 'upload_max_filesize=20M', '-d', 'post_max_size=22M',
                                        '-S', f'127.0.0.1:{port}', '-t', str(fixture), str(fixture/'router.php')],
                                       cwd=fixture, stdout=log, stderr=log)
            workers.append((process, log, f'http://127.0.0.1:{port}'))
        bases = [row[2] for row in workers]
        for base in bases:
            for _ in range(100):
                try:
                    urllib.request.urlopen(base + '/student/', timeout=1).close()
                    break
                except urllib.error.URLError:
                    time.sleep(.05)
            else:
                raise AssertionError('Robot worker did not start')

        teacher = Robot(bases[0])
        teacher.teacher_login()
        teacher.admin(action='class', name='Robotklass 32')
        classroom = next(c['id'] for c in state()['classes'].values() if c['name'] == 'Robotklass 32')
        names = ['Robot ' + str(i+1).zfill(2) for i in range(32)]
        teacher.admin(action='students', class_id=classroom, names='\n'.join(names))
        pupils = [s for s in state()['students'].values() if s['class_id'] == classroom]
        cards = teacher.request('/admin/cards.php?new=1')[1]
        pins = re.findall(r'PIN: ([0-9]{4})', cards)
        check(len(pupils) == len(pins) == 32, '32 login cards not created')
        teacher.admin(action='group', name='Robotgrupp A', **{'members[]': [s['id'] for s in pupils[:16]]})
        group_a = next(g['id'] for g in state()['groups'].values() if g['name'] == 'Robotgrupp A')
        teacher.admin(action='group', name='Robotgrupp B', **{'members[]': [s['id'] for s in pupils[16:]]})
        group_b = next(g['id'] for g in state()['groups'].values() if g['name'] == 'Robotgrupp B')

        def assign(content, target):
            before = set(state()['assignments'])
            teacher.admin(action='assign', content=content, target=target)
            added = set(state()['assignments']) - before
            check(len(added) == 1, 'Robot assignment not created')
            return added.pop()

        quiz = assign('quiz:q_fixture', 'classes:' + classroom)
        flashcards = assign('cards:q_fixture', 'classes:' + classroom)
        # Every quiz type, assigned to the whole class: (content, quiz id, player page).
        type_routes = [('quiz:q_gloss', 'q_gloss', '/play/quiz.php?'), ('quiz:q_img', 'q_img', '/play/image-quiz.php?'),
                       ('quiz:q_sort', 'q_sort', '/play/sort-quiz.php?'), ('quiz:q_order', 'q_order', '/play/order-quiz.php?'),
                       ('cards:q_gloss', None, '/play/flashcards.php?'), ('cards:q_sort', None, '/play/flashcards.php?')]
        typed = [(assign(content, 'classes:' + classroom), content, quiz_id, player) for content, quiz_id, player in type_routes]
        deck = assign('deck:d_fixture', 'groups:' + group_a)
        boundary = 'RobotPDFUploadBoundary'
        parts = []
        fields = {'csrf_token': teacher.csrf, 'action': 'assign', 'content': 'material',
                  'target': 'groups:' + group_b, 'title': 'Robotarnas PDF'}
        for key, value in fields.items():
            parts.append(('--'+boundary+'\r\nContent-Disposition: form-data; name="'+key+'"\r\n\r\n'+value+'\r\n').encode())
        parts.append(('--'+boundary+'\r\nContent-Disposition: form-data; name="pdf"; filename="robot.pdf"\r\nContent-Type: application/pdf\r\n\r\n%PDF-1.4\n%%EOF\n\r\n--'+boundary+'--\r\n').encode())
        before = set(state()['assignments'])
        check(teacher.request('/admin/classes.php', raw=b''.join(parts), headers={'Content-Type': 'multipart/form-data; boundary='+boundary})[0] == 200, 'Robot PDF upload failed')
        pdf_assignment = (set(state()['assignments']) - before).pop()
        pdf_file = state()['assignments'][pdf_assignment]['file']
        print('Robots: 32 pupils, 4 PHP workers, concurrent traffic in every quiz type plus flashcards and material', flush=True)

        def journey(index):
            student, pin = pupils[index], pins[index]
            robot = Robot(bases[index % 4])
            home = robot.pupil_login(student, pin, qr=index % 2 == 0)
            check('OLD_PRIVATE' not in home and 'pin_hash' not in home and ' rätt' not in home, 'Pupil home exposed scores')
            # Same login cookie is valid on another server process.
            check('Hej '+student['name'] in robot.request('/student/', base=bases[(index+1) % 4])[1], 'Session unavailable on another worker')
            check(robot.request('/student/open.php?assignment='+quiz)[0] == 200, 'Assigned player unavailable')
            ident = attempt_id('quiz:'+student['id'])
            last = 5 if index % 4 == 0 else 12
            for seq in range(1, last+1):
                # A disconnected robot accumulates answers locally and sends the latest
                # cumulative snapshot when it reconnects, as the browser tracker does.
                if index % 4 == 1 and 5 <= seq <= 9:
                    continue
                payload = {'student_id':student['id'], 'assignment_id':quiz, 'attempt_id':ident,
                           'seq':seq, 'right':seq-seq//4, 'wrong':seq//4, 'cards':0,
                           'completed': seq == 12}
                status, text, _ = robot.request('/api/classroom-activity.php', payload=payload,
                                               base=bases[(index+seq) % 4])
                check(status == 200 and text.lstrip().startswith('{') and json.loads(text).get('saved'), 'Quiz snapshot rejected: '+str(status)+' '+text[:1800])
                if seq == 4:
                    check(robot.request('/api/classroom-activity.php', payload=payload)[0] == 200, 'Duplicate delivery failed')
            # Deliver an old snapshot after the final one: must not regress counters/status.
            old = {'student_id':student['id'], 'assignment_id':quiz, 'attempt_id':ident,
                   'seq':2, 'right':2, 'wrong':0, 'cards':0, 'completed':False}
            check(robot.request('/api/classroom-activity.php', payload=old)[0] == 200, 'Old snapshot not handled')
            for seq in range(1, 7):
                card = {'student_id':student['id'], 'assignment_id':flashcards,
                        'attempt_id':attempt_id('cards:'+student['id']), 'seq':seq,
                        'right':0, 'wrong':0, 'cards':seq, 'completed':seq==6}
                check(robot.request('/api/classroom-activity.php', payload=card)[0] == 200, 'Flashcard snapshot rejected')
            private_assignment = pdf_assignment if index < 16 else deck
            check(robot.request('/student/open.php?assignment='+private_assignment)[0] == 403, 'Other group material/player exposed')
            forged = {**old, 'assignment_id':private_assignment, 'attempt_id':attempt_id('forged:'+student['id'])}
            check(robot.request('/api/classroom-activity.php', payload=forged)[0] == 403, 'Forged group activity accepted')
            if index >= 16:
                check(robot.request('/student/open.php?assignment='+pdf_assignment)[1].startswith('%PDF'), 'Authorized download failed')
            check(robot.request('/data/materials/'+pdf_file)[0] == 403, 'Direct PDF request accepted')
            # Each robot works through every quiz type in its own order, on rotating servers.
            for n in range(len(typed)):
                assignment, content, quiz_id, player = typed[(index + n) % len(typed)]
                status, page, url = robot.request('/student/open.php?assignment='+assignment, base=bases[(index+n) % 4])
                check(status == 200 and player in url and 'classroomContext=' in page, 'Wrong player for '+content+': '+url)
                if quiz_id:
                    check(robot.request('/api/quiz-data.php?id='+quiz_id)[0] == 200, 'Quiz data unavailable: '+quiz_id)
                done = {'student_id':student['id'], 'assignment_id':assignment, 'attempt_id':attempt_id(content+':'+student['id']),
                        'seq':1, 'right':0 if not quiz_id else 3, 'wrong':0 if not quiz_id else index % 3,
                        'cards':4 if not quiz_id else 0, 'completed':True}
                check(robot.request('/api/classroom-activity.php', payload=done, base=bases[(index+n+1) % 4])[0] == 200, 'Activity rejected: '+content)
                if quiz_id:
                    finish = {'quiz_id':quiz_id, 'student_id':student['id'], 'student_name':student['name'], 'score':3, 'total':3+index % 3, 'errors':[]}
                    check(robot.request('/api/save-result.php', payload=finish, base=bases[(index+n+2) % 4])[0] == 200, 'Result rejected: '+quiz_id)
            # The established modern result endpoint also receives simultaneous finishes.
            result = {'quiz_id':'q_fixture','student_id':student['id'],'student_name':'Spoofed Name',
                      'score':last-last//4,'total':last,'errors':[]}
            check(robot.request('/api/save-result.php', payload=result)[0] == 200, 'Modern result save rejected')
            home = robot.request('/student/')[1]
            check(('Påbörjat' if last == 5 else 'Klart') in home and 'Övat' in home and ' rätt' not in home,
                  'Pupil status or privacy incorrect')
            return robot, last

        with ThreadPoolExecutor(max_workers=32) as pool:
            journeys = list(pool.map(journey, range(32)))
        data = state()
        for i, (robot, last) in enumerate(journeys):
            student = pupils[i]
            row = data['attempts'][attempt_id('quiz:'+student['id'])]
            check(row['student_id'] == student['id'] and row['right'] == last-last//4 and row['wrong'] == last//4
                  and row['completed'] == (last==12), 'Concurrent quiz data lost or mixed')
            card = data['attempts'][attempt_id('cards:'+student['id'])]
            check(card['cards'] == 6 and card['completed'], 'Concurrent flashcard data lost')
        for assignment, content, quiz_id, player in typed:
            for i, student in enumerate(pupils):
                row = data['attempts'].get(attempt_id(content+':'+student['id']))
                check(row and row['completed'] and row['assignment_id'] == assignment
                      and (row['cards'] == 4 if not quiz_id else row['wrong'] == i % 3), 'Lost or mixed activity: '+content)
        saved_quizzes = json.loads((fixture/'data/quizzes.json').read_text('utf-8'))
        pupil_ids = {s['id'] for s in pupils}
        for quiz_id in ['q_gloss', 'q_img', 'q_sort', 'q_order']:
            saved = [r['student_id'] for r in saved_quizzes[quiz_id]['results'] if r.get('student_id') in pupil_ids]
            check(len(saved) == len(set(saved)) == 32, 'Concurrent results lost: '+quiz_id)
        downloads = [a for a in data['attempts'].values() if a['assignment_id'] == pdf_assignment]
        check(len(downloads) == 16, 'Download records lost or mixed')
        results = json.loads((fixture/'data/quizzes.json').read_text('utf-8'))['q_fixture']['results']
        ids = [r.get('student_id') for r in results if r.get('student_id') in {s['id'] for s in pupils}]
        check(len(ids) == len(set(ids)) == 32, 'Modern results lost under concurrent writes')
        check(all(r['student_name'] != 'Spoofed Name' for r in results), 'Result endpoint trusted spoofed name')

        # Deliberately collide two different logged-in sessions for the SAME pupil.
        duplicate = Robot(bases[1]); duplicate.pupil_login(pupils[0], pins[0])
        ident = attempt_id('colliding-sessions')
        def collision(seq):
            robot = journeys[0][0] if seq % 2 else duplicate
            value = {'student_id':pupils[0]['id'],'assignment_id':quiz,'attempt_id':ident,
                     'seq':seq,'right':seq,'wrong':0,'cards':0,'completed':seq==40}
            check(robot.request('/api/classroom-activity.php',payload=value,base=bases[seq % 4])[0] == 200,
                  'Colliding snapshot request failed')
        with ThreadPoolExecutor(max_workers=16) as pool:
            list(pool.map(collision, list(range(40, 0, -1))))
        check(state()['attempts'][ident]['seq'] == 40 and state()['attempts'][ident]['right'] == 40
              and state()['attempts'][ident]['completed'], 'Out-of-order parallel sessions regressed the attempt')

        # Shared-device switch, stale tabs and teacher reset/removal while the old tab is open.
        shared = journeys[0][0]
        stale = {'student_id':pupils[0]['id'],'assignment_id':quiz,'attempt_id':attempt_id('stale-tab'),
                 'seq':1,'right':1,'wrong':0,'cards':0,'completed':False}
        shared.request('/student/',form={'csrf_token':shared.csrf,'action':'logout'})
        check(shared.request('/api/classroom-activity.php',payload=stale)[0] == 401,'Logged-out tab saved activity')
        shared.pupil_login(pupils[1],pins[1])
        check(shared.request('/api/classroom-activity.php',payload=stale)[0] == 403,'Stale tab wrote to next pupil')
        check(attempt_id('stale-tab') not in state()['attempts'],'Stale tab left an activity record')
        teacher.admin(action='reset',student_id=pupils[2]['id'])
        check(journeys[2][0].request('/api/classroom-activity.php',payload={**stale,'student_id':pupils[2]['id']})[0] == 401,'Reset failed to revoke active tab')
        teacher.admin(action='remove',student_id=pupils[3]['id'])
        check(journeys[3][0].request('/api/classroom-activity.php',payload={**stale,'student_id':pupils[3]['id']})[0] == 401,'Removal failed to revoke active tab')
        teacher_view = teacher.request('/admin/classes.php?assignment='+quiz)[1]
        check('Robot 04 (borttagen)' in teacher_view and '9 rätt' in teacher_view,'Teacher overview lost removed pupil results')

        # Exercise old quiz/deck statistics concurrently; these share the same stats file.
        old_before = json.loads((fixture/'data/stats.json').read_text('utf-8')) or {}
        old_counts = {key:len(old_before.get(key,{}).get('attempts',[])) for key in ['aabbccdd','d_fixture']}
        aggregate_before={}
        for name in ['teacher_stats','system_stats']:
            path=fixture/'data'/(name+'.json')
            values=json.loads(path.read_text('utf-8')) if path.exists() else {}
            aggregate_before[name]=(values.get('t1',{}) if name=='teacher_stats' else values).get('total_attempts',0)
        def legacy_finish(index):
            pupil_index = 4 + index % 28
            student = pupils[pupil_index]; robot = journeys[pupil_index][0]
            if index % 2:
                payload={'student_id':student['id'],'student_name':student['name'],'quiz_id':'aabbccdd',
                         'completed':True,'time_seconds':20,'total_errors':1,'question_errors':{'0':1}}
                path='/api/save-stats.php'
            else:
                payload={'student_id':student['id'],'student_name':student['name'],'deck_id':'d_fixture',
                         'completed':True,'time_seconds':20,'card_grades':{'0':[2],'1':[3]},'grade_distribution':{'0':0,'1':0,'2':1,'3':1}}
                path='/api/save-flashcard-stats.php'
            check(robot.request(path,payload=payload,base=bases[index % 4])[0] == 200,'Legacy result request failed')
        with ThreadPoolExecutor(max_workers=32) as pool:
            list(pool.map(legacy_finish,range(128)))
        old_after=json.loads((fixture/'data/stats.json').read_text('utf-8'))
        for key in old_counts:
            actual=len(old_after.get(key,{}).get('attempts',[]))-old_counts[key]
            if actual != 64:
                failures.append(f'{key}: expected 64 new attempts, stored {actual}')
        check(not failures,'Legacy concurrent results lost: '+'; '.join(failures))
        for key in old_counts:
            check(old_after[key]['total_attempts']==old_counts[key]+64 and old_after[key]['completed']==old_counts[key]+64,'Legacy totals disagree with stored attempts')
        for name in aggregate_before:
            values=json.loads((fixture/'data'/(name+'.json')).read_text('utf-8'))
            after=(values['t1'] if name=='teacher_stats' else values)['total_attempts']
            check(after-aggregate_before[name]==64,'Extended totals lost concurrent results: '+name)

        for process, log, base in workers:
            log.flush()
        for logfile in fixture.glob('robots-worker-*.log'):
            log_text=logfile.read_text('utf-8')
            check(not any(word in log_text for word in ['PHP Warning','PHP Fatal','PHP Parse']),
                  'PHP runtime error in '+logfile.name+': '+log_text[-1500:])
        report={**counts,'pupils':32,'php_workers':4,'duration_seconds':round(time.monotonic()-started,2),
                'request_p95_ms':round(sorted(timings)[int(len(timings)*.95)]*1000),
                'scenarios':['concurrent quiz/card work','fact, glossary, image, sort and order quizzes plus glossary and sort flashcards','8 abandoned attempts','8 reconnecting pupils',
                             'duplicate/out-of-order delivery','16 authorized PDF downloads',
                             'cross-group denials','shared-device stale tabs','reset/removal revocation',
                             '32 modern and 128 legacy result saves']}
        print('PASS: classroom traffic robots '+json.dumps(report,ensure_ascii=False),flush=True)
        return report
    finally:
        for process, log, base in workers:
            process.terminate()
            process.wait(timeout=10)
            log.close()
