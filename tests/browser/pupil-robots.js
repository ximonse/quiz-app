// Browser pupil robots: log in like pupils and play every assigned quiz type to the end,
// against the synthetic classroom fixture from tests/classroom-http.test.py --serve.
const {chromium, devices}=require('playwright');
const fs=require('fs'),path=require('path'),{spawn}=require('child_process');
const {shot}=require('./fixture');
const repo=path.resolve(__dirname,'../..');
const sleep=ms=>new Promise(r=>setTimeout(r,ms));
let checks=0; const log=[];
const check=(c,m)=>{ if(!c) throw new Error(m); checks++; };

function startFixture() {
  return new Promise((resolve,reject)=>{
    const proc=spawn('python',[path.join(repo,'tests/classroom-http.test.py'),'--serve'],{cwd:repo});
    let out='';
    proc.stdout.on('data',d=>{ out+=d; const m=out.match(/^\{.*"url".*\}$/m); if (m) resolve({proc,info:JSON.parse(m[0])}); });
    proc.stderr.on('data',d=>process.stderr.write(d));
    proc.on('exit',code=>reject(new Error('fixture exited '+code+': '+out)));
  });
}

(async()=>{
  const {proc,info}=await startFixture();
  const base=info.url, fixture=info.fixture;
  const state=()=>JSON.parse(fs.readFileSync(path.join(fixture,'data/classroom.json'),'utf-8'));
  const quizzes=()=>JSON.parse(fs.readFileSync(path.join(fixture,'data/quizzes.json'),'utf-8'));
  const browser=await chromium.launch();
  try {
    // Teacher: assign the remaining quiz types and add three more pupils.
    const tctx=await browser.newContext(); const teacher=await tctx.newPage();
    await teacher.goto(base+'/index.php'); await teacher.fill('input[name=username]','fixture'); await teacher.fill('input[name=password]','fixture-pass');
    await Promise.all([teacher.waitForNavigation(), teacher.locator('form button, form input[type=submit]').first().click()]);
    const csrf=async()=>{ await teacher.goto(base+'/admin/classes.php'); return teacher.locator('input[name=csrf_token]').first().inputValue(); };
    const robin=Object.values(state().students).find(s=>s.name==='Robin Demo');
    const classId=robin.class_id;
    const post=async form=>{ const r=await teacher.request.post(base+'/admin/classes.php',{form:{csrf_token:await csrf(),...form}}); check(r.ok(),'teacher post '+form.action); };
    for (const content of ['quiz:q_gloss','quiz:q_img','cards:q_gloss']) await post({action:'assign',target:'classes:'+classId,content});
    await post({action:'students',class_id:classId,names:'Robot iPad\nRobot Mobil\nRobot Dator'});
    await teacher.goto(base+'/admin/cards.php?new=1');
    const cardText=await teacher.locator('body').innerText();
    const pins=[...cardText.matchAll(/PIN: ([0-9]{4})/g)].map(m=>m[1]);
    const added=Object.values(state().students).filter(s=>s.name.startsWith('Robot '));
    check(added.length===3 && pins.length===3,'three robot pupils with PIN cards');
    const pupils=[{student:robin,pin:info.student_pin,kind:'dator'},...added.map((s,i)=>({student:s,pin:pins[i],kind:['ipad','mobil','dator'][i]}))];
    const data=quizzes();

    async function playQuiz(page, quizId) {
      const quiz=data[quizId]; const answerFor=prompt=>{
        for (const item of quiz.items) {
          if (quiz.type==='fact') { if (prompt.includes(item.concept)) return item.description; if (prompt.includes(item.description)) return item.concept; }
          else { if (prompt.includes(item.sentence)) return item.translation; if (prompt.includes(item.translation)) return item.word; }
        }
        throw new Error('no answer for '+prompt);
      };
      let wrongOnce=false;
      for (let round=0; round<30; round++) {
        await page.waitForFunction(()=>/Bra jobbat|FLAWLESS/.test(document.body.innerText) || document.querySelectorAll('#root .space-y-2 button:not([disabled])').length || document.querySelector('#root input[type=text]'));
        if (/Bra jobbat|FLAWLESS/.test(await page.locator('body').innerText())) return;
        const prompt=await page.locator('#root .rounded-xl p').first().innerText();
        const correct=answerFor(prompt);
        const options=page.locator('#root .space-y-2 button:not([disabled])');
        if (await options.count()) {
          const texts=await options.allInnerTexts();
          const pick=!wrongOnce ? texts.find(t=>t.trim()!==correct) : correct; wrongOnce=true;
          await options.nth(texts.findIndex(t=>t.trim()===pick.trim())).click();
        } else { await page.fill('#root input[type=text]',correct); await page.click('#root button[type=submit]'); }
        await page.waitForFunction(()=>!document.querySelector('#root button[disabled]') || /Bra jobbat|FLAWLESS/.test(document.body.innerText),null,{timeout:6000});
      }
      throw new Error('quiz did not finish: '+quizId);
    }
    async function playImage(page) {
      const quiz=data.q_img; let first=true;
      for (let round=0; round<20; round++) {
        await page.waitForFunction(()=>document.getElementById('quiz-title').textContent==='Klart!' || !document.getElementById('question-card').hidden);
        if (await page.textContent('#quiz-title')==='Klart!') return;
        const q=await page.textContent('#question'); const item=quiz.items.find(i=>i.question===q);
        const pick=item.choices.find(c=>first ? !c.correct : c.correct); first=false;
        await page.click(`.choice[data-id="${pick.id}"]`);
        if (await page.isVisible('#check')) await page.click('#check');
        await page.click('#next');
      }
      throw new Error('image quiz did not finish');
    }
    async function drag(page, touch, from, to, end) {
      const a=await from.boundingBox(), b=await to.boundingBox();
      const p={x:a.x+a.width/2,y:a.y+a.height/2}, q=end==='y'?{x:b.x+b.width/2,y:b.y+b.height-6}:end==='x'?{x:b.x+b.width-6,y:b.y+b.height/2}:{x:b.x+b.width/2,y:b.y+b.height/2};
      if (touch) { // tap the object, then the target: the touch/motor-friendly path
        await from.tap(); await to.tap({position:{x:q.x-b.x,y:q.y-b.y}});
      } else { await page.mouse.move(p.x,p.y); await page.mouse.down(); await page.mouse.move(q.x,q.y,{steps:12}); await page.mouse.up(); }
      await sleep(350);
    }
    async function playSort(page, touch) {
      const quiz=data.q_sort; let first=true;
      for (let round=0; round<20; round++) {
        await page.waitForFunction(()=>document.getElementById('quiz-title').textContent==='Klart!' || (!document.getElementById('question-card').hidden && !document.getElementById('check').hidden));
        if (await page.textContent('#quiz-title')==='Klart!') return;
        const q=await page.textContent('#question'); const it=quiz.items.find(i=>i.question===q);
        for (const id of await page.locator('#tray .sort-object').evaluateAll(els=>els.map(e=>e.dataset.id))) {
          const obj=it.objects.find(o=>o.id===id);
          // First round: drop the distractor into a field on purpose.
          const field=obj.field || (first ? it.fields[0].id : null);
          if (field) await drag(page,touch,page.locator(`.sort-object[data-id="${id}"]`),page.locator(`.sort-zone[data-field="${field}"]`));
        }
        first=false; await page.click('#check'); await page.waitForSelector('#next:not([hidden])'); await page.click('#next');
      }
      throw new Error('sort quiz did not finish');
    }
    async function playOrder(page, touch) {
      const quiz=data.q_order; let first=true;
      for (let round=0; round<20; round++) {
        await page.waitForFunction(()=>document.getElementById('quiz-title').textContent==='Klart!' || (!document.getElementById('question-card').hidden && !document.getElementById('check').hidden));
        if (await page.textContent('#quiz-title')==='Klart!') return;
        const q=await page.textContent('#question'); const it=quiz.items.find(i=>i.question===q);
        const axis=it.orientation==='vertical'?'y':'x';
        const order=first ? [...it.objects].reverse() : it.objects; first=false;
        for (const o of order) await drag(page,touch,page.locator(`.sort-object[data-id="${o.id}"]`),page.locator('#line'),axis);
        await page.click('#check'); await page.waitForSelector('#next:not([hidden])'); await page.click('#next');
      }
      throw new Error('order quiz did not finish');
    }
    async function playCards(page, touch) {
      const total=Number((await page.textContent('#counter')).split('/')[1]);
      for (let i=0; i<total; i++) {
        if (touch) await page.tap('#card'); else await page.click('#card');
        await sleep(250); await page.click('button:has-text("Nästa")');
      }
      return total;
    }

    async function pupilRun({student,pin,kind}) {
      const opts=kind==='ipad'?{...devices['iPad (gen 7)']}:kind==='mobil'?{...devices['iPhone 13']}:{viewport:{width:1280,height:800}};
      const ctx=await browser.newContext(opts); const page=await ctx.newPage(); page.setDefaultTimeout(10000);
      const errors=[]; page.on('pageerror',e=>errors.push(e.message));
      const touch=kind!=='dator'; const done=[];
      await page.goto(base+'/student/'); await page.fill('input[name=code]',student.code); await page.fill('input[name=pin]',pin);
      await Promise.all([page.waitForNavigation(), page.locator('form button').first().click()]);
      check((await page.locator('body').innerText()).includes('Hej '+student.name),'login '+student.name);
      const links=await page.locator('a.button[href^="open.php"]').evaluateAll(as=>as.map(a=>({href:a.getAttribute('href'),title:a.closest('section').innerText.split('\n')[0]})));
      for (const link of links) {
        const a=state().assignments[new URLSearchParams(link.href.split('?')[1]).get('assignment')];
        if (a.kind==='material') continue;
        await page.goto(base+'/student/'+link.href); await page.waitForLoadState('networkidle');
        const url=page.url(); let what;
        if (url.includes('/play/sort-quiz.php')) { await playSort(page,touch); what='sorteringsquiz'; }
        else if (url.includes('/play/order-quiz.php')) { await playOrder(page,touch); what='ordningsquiz'; }
        else if (url.includes('/play/image-quiz.php')) { await playImage(page); what='bildvalsquiz'; }
        else if (url.includes('/play/flashcards.php')) { await playCards(page,touch); what='flashcards '+a.content_id; }
        else if (url.includes('/play/quiz.php')) { await playQuiz(page,a.content_id); what=data[a.content_id].type==='fact'?'faktaquiz':'glosquiz'; }
        else { what='äldre spelare ('+a.kind+')'; check(!/Fatal|Warning/.test(await page.content()),'legacy page error'); done.push({what,assignment:a.id,legacy:true}); continue; }
        await sleep(700);
        done.push({what,assignment:a.id});
      }
      await page.goto(base+'/student/'); await page.screenshot({path:shot(`robot-home-${kind}-${student.name.replace(/\W+/g,'')}.png`),fullPage:true});
      check(!errors.length,student.name+' page errors: '+errors.join('; '));
      await ctx.close();
      return {student,kind,done};
    }

    const runs=await Promise.all(pupils.map(pupilRun));
    const st=state();
    for (const run of runs) {
      for (const d of run.done.filter(d=>!d.legacy)) {
        const rows=Object.values(st.attempts).filter(r=>r.assignment_id===d.assignment && r.student_id===run.student.id);
        check(rows.some(r=>r.completed),`${run.student.name}: ${d.what} not recorded as done`);
      }
      log.push(`${run.student.name} (${run.kind}): `+run.done.map(d=>d.what).join(', '));
    }
    const q=quizzes();
    for (const id of ['q_fixture','q_gloss','q_img','q_sort','q_order']) {
      const saved=q[id].results.filter(r=>pupils.some(p=>p.student.id===r.student_id));
      log.push(`${id}: ${saved.length} sparade elevresultat`);
    }
    console.log(log.join('\n')); console.log(`PASS: ${checks} pupil robot checks`);
  } catch(e) { console.error('FAIL:',e.stack||e.message); process.exitCode=1; }
  finally {
    await browser.close();
    // The fixture's python process owns the PHP server; stop the whole tree.
    try { require('child_process').execFileSync('taskkill',['/PID',String(proc.pid),'/T','/F'],{stdio:'ignore'}); } catch { proc.kill(); }
    // A killed fixture cannot clean up after itself; remove its disposable folder here.
    if (path.basename(fixture).startsWith('quiz-classroom-') && path.resolve(path.dirname(fixture))===path.resolve(require('os').tmpdir()))
      setTimeout(()=>{ try { fs.rmSync(fixture,{recursive:true,force:true}); } catch {} },400);
    setTimeout(()=>process.exit(),800);
  }
})();
