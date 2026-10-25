// Synthetic browser check of the teacher flow: import template JSON, save, review, publish, stats.
const {chromium}=require('playwright');
const fs=require('fs'),path=require('path');
const {startFixture,shot}=require('./fixture');
const fx=startFixture(); const fixture=fx.dir, root=fx.root; let base;
const png=path.join(fixture,'pixel.png'); fs.writeFileSync(png,Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==','base64'));
let checks=0; const check=(c,m)=>{ if(!c) throw new Error(m); checks++; };
const sleep=ms=>new Promise(r=>setTimeout(r,ms));
(async()=>{
  await fx.ready(); base=fx.base;
  const browser=await chromium.launch(); const page=await browser.newPage({viewport:{width:1100,height:900}});
  const errors=[]; page.on('pageerror',e=>errors.push(e.message));
  await page.goto(base+'/index.php'); await page.fill('[name=username]','fixture'); await page.fill('[name=password]','pw');
  await Promise.all([page.waitForNavigation(),page.click('form button[type=submit], form button')]);
  await page.goto(base+'/admin/dashboard.php'); check(await page.locator('a[href="sort-edit.php"]').count()===1,'dashboard link');
  check((await page.content()).includes('sorteringsquiz'),'dashboard badge');
  await page.goto(base+'/admin/sort-edit.php');
  await page.click('summary'); await page.fill('#import-json','```json\n'+fs.readFileSync(path.join(root,'assets/templates/sorteringsquiz-mall.json'),'utf8')+'\n```');
  await page.click('#import-replace');
  check((await page.textContent('#import-status')).startsWith('1 frågor'),'import status');
  check(await page.inputValue('#title')==='Rikena i naturen','import title');
  check(await page.locator('.sort-edit-field').count()===3 && await page.locator('.sort-edit-object').count()===7,'import counts');
  check(await page.locator('.sort-edit-object').nth(6).locator('select').inputValue()==='','distractor select');
  // Give the first object an image and rename a field; the object select follows the field title.
  await page.locator('.sort-edit-object').nth(0).locator('input[type=file]').setInputFiles(png);
  await page.locator('.sort-edit-field').nth(0).locator('[data-field=title]').fill('Svamparna');
  check((await page.locator('.sort-edit-object').nth(0).locator('select option:checked').textContent())==='Svamparna','select follows title');
  await page.screenshot({path:shot('editor.png'),fullPage:true});
  await Promise.all([page.waitForNavigation(),page.click('.publish-bar button')]);
  check(page.url().includes('saved=1'),'save failed: '+await page.locator('.error').textContent().catch(()=>''));
  const id=new URL(page.url()).searchParams.get('id');
  const stored=JSON.parse(fs.readFileSync(path.join(fixture,'data/quizzes.json'),'utf8'))[id];
  const doc=stored.pending_document||stored;
  const obj=(doc.items||stored.items)[0].objects[0];
  check(obj.id==='champinjon' && obj.image && obj.image.file.startsWith('ai_') && obj.image.alt==='Champinjon','uploaded object image '+JSON.stringify(obj));
  check(stored.publication==='draft','should be draft');
  // Reopen the editor: existing image survives a save without a new upload.
  await Promise.all([page.waitForNavigation(),page.click('.publish-bar button')]);
  check(page.url().includes('saved=1'),'resave failed');
  await page.goto(base+'/admin/review.php?id='+id);
  check(await page.locator('.review-field').count()===4,'review fields incl. loose');
  check((await page.textContent('.review-field.loose')).includes('Sten'),'review distractor');
  await page.screenshot({path:shot('review.png'),fullPage:true});
  await Promise.all([page.waitForNavigation(),page.click('.publish-bar button')]);
  check(JSON.parse(fs.readFileSync(path.join(fixture,'data/quizzes.json'),'utf8'))[id].publication==='published','publish');
  await page.goto(base+'/play/index.php?id='+id); await page.fill('#name-input','Elev'); await page.keyboard.press('Enter'); await sleep(400);
  check((await page.getAttribute('#quiz-link','href')).startsWith('sort-quiz.php?id='),'student start routes to sort player');
  check(await page.locator('#flashcard-section').isVisible(),'flashcards offered');
  await page.goto(base+'/play/quiz.php?id='+id+'&name=Elev'); await page.waitForURL(/sort-quiz\.php/);
  check(true,'quiz.php redirects to sort player');
  await page.evaluate(async()=>fetch('../api/save-result.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({quiz_id:'q_sort',student_name:'Elev',score:4,total:6,
    errors:[{item_index:0,object_id:'ek',given:'svamp',correct:'vaxt'},{item_index:0,object_id:'ek',given:null,correct:'vaxt'},{item_index:0,object_id:'sten',given:'djur',correct:null}]})}));
  await page.goto(base+'/admin/stats.php?id=q_sort');
  const stats=await page.textContent('body');
  check(stats.includes('Objekt som oftast hamnar fel') && /Ek\s*→ rätt: Växter/.test(stats) && stats.includes('Svampar 1×') && stats.includes('utanför fälten 1×') && /Sten\s*→ rätt: utanför fälten/.test(stats),'stats per object');
  await page.locator('h2:has-text("Objekt som oftast")').screenshot({path:shot('stats-head.png')});
  check(errors.length===0,'browser errors: '+errors.join(' | '));
  await browser.close(); console.log(`PASS: ${checks} editor checks`);
})().catch(e=>{ console.error('FAIL:',e.message); process.exitCode=1; }).finally(()=>{ fx.stop(); setTimeout(()=>process.exit(),800); });
