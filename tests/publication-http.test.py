"""HTTP regression checks against an explicitly supplied SYNTHETIC fixture only."""
from pathlib import Path
import json,re,sys,uuid,urllib.request,urllib.error,http.cookiejar
from urllib.parse import urlencode,urlparse
fixture=Path(sys.argv[1]); base=sys.argv[2].rstrip('/')
if urlparse(base).hostname not in ('127.0.0.1','localhost','::1') or fixture.name!='fixture.json':
    raise SystemExit('Use a loopback server and its synthetic fixture.json only.')
data=json.loads(fixture.read_text()); checks=0; run_id=uuid.uuid4().hex
def request(path,body=None,auth=False):
    headers={'Content-Type':'application/json'}
    if auth:headers['Authorization']='Bearer '+data['token']
    req=urllib.request.Request(base+path,json.dumps(body).encode() if body is not None else None,headers=headers)
    try:
        with urllib.request.urlopen(req) as response:return response.status,json.loads(response.read())
    except urllib.error.HTTPError as error:return error.code,json.loads(error.read())
def check(condition,message):
    global checks
    if not condition:raise AssertionError(message)
    checks+=1
def tool(name,args):
    status,value=request('/api/ai.php',{'tool':name,'arguments':args},True)
    check(status==200,name+' failed: '+str(value));return value
id=data['picture_id']
check(request('/api/quiz-data.php?id='+id)[0]==403,'Draft available to students')
check(request('/api/quiz-data.php?id='+id+'&preview=1')[0]==403,'Anonymous preview allowed')
check(request('/api/save-result.php',{'quiz_id':id,'student_name':'Test','score':1,'total':1})[0]==403,'Draft accepted student result')
opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
html=opener.open(base+'/').read().decode();csrf=re.search(r'name="csrf_token" value="([^"]+)"',html).group(1)
opener.open(urllib.request.Request(base+'/index.php',urlencode({'username':'fixture','password':'fixture-pass','csrf_token':csrf}).encode())).read()
preview=json.loads(opener.open(base+'/api/quiz-data.php?id='+id+'&preview=1').read())
check(preview['type']=='image_choice' and len(preview['items'][0]['choices'])==6,'Owner preview failed')
text=tool('quiz_get',{'id':data['text_id']}); old_title=text['quiz']['title'];text['quiz']['title']='Faktaquiz med granskade ändringar'
pending=tool('quiz_update',{'id':text['id'],'revision':text['revision'],'quiz':text['quiz'],'request_id':run_id})
check(pending['publication']=='pending' and pending['student_url'] is None,'Published edit did not become pending')
check(request('/api/quiz-data.php?id='+text['id'])[1]['title']==old_title,'Student saw pending changes')
check(json.loads(opener.open(base+'/api/quiz-data.php?id='+text['id']+'&preview=1').read())['title']==text['quiz']['title'],'Preview did not show pending changes')
review=opener.open(base+'/admin/review.php?id='+text['id']).read().decode();rev=re.search(r'name="revision" value="([^"]+)"',review).group(1)
published=opener.open(urllib.request.Request(base+'/admin/review.php?id='+text['id'],urlencode({'csrf_token':csrf,'revision':rev}).encode())).read().decode()
check('Publicerat!' in published,'Publish form failed')
check(request('/api/quiz-data.php?id='+text['id'])[1]['title']==text['quiz']['title'],'Published change unavailable')
try:stale=opener.open(urllib.request.Request(base+'/admin/review.php?id='+text['id'],urlencode({'csrf_token':csrf,'revision':rev}).encode())).read().decode()
except urllib.error.HTTPError as error:check(error.code==409,'Stale publication status')
else:check('Quizet ändrades' in stale,'Stale publication was accepted')
# The image editor must accept real multipart file bytes and save an unpublished quiz.
editor=opener.open(base+'/admin/image-edit.php').read().decode();key=re.search(r'name="request_id" value="([^"]+)"',editor).group(1)
items=[{'question':'Vilken är en hävstång?','choices':[{'id':'lever','image':{'file':'','alt':'Val A'},'correct':True},{'id':'ramp','image':{'file':'','alt':'Val B'},'correct':False}],'image':None}]
boundary='OnlySyntheticQuizBoundary';parts=[]
fields={'csrf_token':csrf,'request_id':key,'title':'Manuellt bildval','quiz_mode':'test','required_correct':'1','language':'sv','items_json':json.dumps(items)}
for key,value in fields.items():parts.append(('--'+boundary+'\r\nContent-Disposition: form-data; name="'+key+'"\r\n\r\n'+value+'\r\n').encode())
for j,source in enumerate([1,0]):
    parts.append(('--'+boundary+'\r\nContent-Disposition: form-data; name="choice_files[0]['+str(j)+']"; filename="test.png"\r\nContent-Type: image/png\r\n\r\n').encode()+(fixture.parent/f'picture-{source}.png').read_bytes()+b'\r\n')
parts.append(('--'+boundary+'--\r\n').encode())
saved=opener.open(urllib.request.Request(base+'/admin/image-edit.php',b''.join(parts),headers={'Content-Type':'multipart/form-data; boundary='+boundary}))
new_id=re.search(r'id=([^&]+)',saved.url).group(1);content=saved.read().decode();check('Sparat för granskning' in content,'Manual image editor did not save')
new=tool('quiz_get',{'id':new_id});check(new['publication']=='draft' and all(c['image']['file'].startswith('ai_') for c in new['quiz']['items'][0]['choices']),'Multipart choices lost uploaded files')
# Adding an information image is independent of answer pictures and question type.
info=opener.open(base+'/admin/images.php?id='+new_id).read().decode();rev=re.search(r'name="revision" value="([^"]+)"',info).group(1);parts=[]
for key,value in {'csrf_token':csrf,'revision':rev,'index':'0','alt':'Extra infobild'}.items():parts.append(('--'+boundary+'\r\nContent-Disposition: form-data; name="'+key+'"\r\n\r\n'+value+'\r\n').encode())
parts.append(('--'+boundary+'\r\nContent-Disposition: form-data; name="image"; filename="info.png"\r\nContent-Type: image/png\r\n\r\n').encode()+(fixture.parent/'picture-0.png').read_bytes()+b'\r\n');parts.append(('--'+boundary+'--\r\n').encode())
info_response=opener.open(urllib.request.Request(base+'/admin/images.php?id='+new_id,b''.join(parts),headers={'Content-Type':'multipart/form-data; boundary='+boundary})).read().decode()
check('Infobilden sparades' in info_response,'Information image form failed');after=tool('quiz_get',{'id':new_id})
check(after['quiz']['items'][0]['image']['alt']=='Extra infobild' and after['quiz']['items'][0]['choices']==new['quiz']['items'][0]['choices'],'Info image altered answer choices')
check(request('/api/quiz-data.php?id='+new_id)[0]==403,'Info image accidentally published quiz')
text=tool('quiz_get',{'id':data['text_id']});live_title=text['quiz']['title']
editor=opener.open(base+'/admin/edit.php?id='+text['id']).read().decode()
revision=re.search(r'name="edit_revision" value="([^"]+)"',editor).group(1)
form={'csrf_token':csrf,'edit_revision':revision,'title':live_title+' — justerad i appen','answer_mode':'multiple_choice',
      'question_direction':'concept','required_correct':'1','quiz_mode':'training','language':'sv','spelling_mode':'student_choice',
      'item_images':'[0]','csv_data':'Vilken maskin är en ramp?;Lutande plan;Hjul;Skruv;Hävstång'}
opener.open(urllib.request.Request(base+'/admin/edit.php?id='+text['id'],urlencode(form).encode())).read()
text_pending=tool('quiz_get',{'id':text['id']})
check(text_pending['publication']=='pending' and text_pending['quiz']['title']==form['title'],'Text editor did not stage changes')
check(request('/api/quiz-data.php?id='+text['id'])[1]['title']==live_title,'Text editor changed live quiz before publication')
info=opener.open(base+'/admin/images.php?id='+text['id']).read().decode();rev=re.search(r'name="revision" value="([^"]+)"',info).group(1);parts=[]
for key,value in {'csrf_token':csrf,'revision':rev,'index':'0','alt':'Infobild till textfrågan'}.items():parts.append(('--'+boundary+'\r\nContent-Disposition: form-data; name="'+key+'"\r\n\r\n'+value+'\r\n').encode())
parts.append(('--'+boundary+'\r\nContent-Disposition: form-data; name="image"; filename="info.png"\r\nContent-Type: image/png\r\n\r\n').encode()+(fixture.parent/'picture-0.png').read_bytes()+b'\r\n');parts.append(('--'+boundary+'--\r\n').encode())
opener.open(urllib.request.Request(base+'/admin/images.php?id='+text['id'],b''.join(parts),headers={'Content-Type':'multipart/form-data; boundary='+boundary})).read()
text_after=tool('quiz_get',{'id':text['id']})
check(text_after['quiz']['type']=='fact' and text_after['quiz']['items'][0]['image']['alt']=='Infobild till textfrågan','Text information image failed or converted question type')
check(request('/api/quiz-data.php?id='+text['id'])[1]['items'][0].get('image') is None,'Pending information image leaked to students')
print(f'PASS: {checks} HTTP checks: draft isolation, owner preview, staged edits, publication, multipart choices and later information image.')
