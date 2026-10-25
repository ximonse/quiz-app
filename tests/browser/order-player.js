// Synthetic browser check of the order player and editor. Never touches real data.
const fs=require('fs'),path=require('path');
const {startFixture,shot,launch}=require('./fixture');
const fx=startFixture(); const fixture=fx.dir, root=fx.root; let base;
let checks=0; const check=(c,m)=>{ if(!c) throw new Error(m); checks++; };
const sleep=ms=>new Promise(r=>setTimeout(r,ms));
(async()=>{
  await fx.ready(); base=fx.base;
  const browser=await launch(); const page=await browser.newPage({viewport:{width:1024,height:768}});
  const errors=[]; page.on('pageerror',e=>errors.push(e.message)); page.on('console',m=>{ if(m.type()==='error') errors.push(m.text()); });
  const noOverlap=async label=>{
    const boxes=await page.$$eval('.sort-object',els=>els.map(e=>{const r=e.getBoundingClientRect();return [r.left,r.top,r.right,r.bottom,e.dataset.id];}));
    for (let i=0;i<boxes.length;i++) for (let j=i+1;j<boxes.length;j++) { const a=boxes[i],b=boxes[j];
      check(!(a[0]<b[2]-1&&b[0]<a[2]-1&&a[1]<b[3]-1&&b[1]<a[3]-1),`${label}: ${a[4]} overlaps ${b[4]}`); }
  };
  // Drop at the end of the line (after existing objects), or before a given object.
  const drop=async(id,beforeId)=>{
    const item=page.locator(`.sort-object[data-id="${id}"]`); await item.scrollIntoViewIfNeeded(); const from=await item.boundingBox();
    await page.mouse.move(from.x+from.width/2,from.y+from.height/2); await page.mouse.down(); await page.mouse.move(from.x+from.width/2+12,from.y+from.height/2+12,{steps:3});
    let x,y; const vertical=await page.$eval('#line',l=>l.dataset.axis==='y');
    if (beforeId) { const b=await page.locator(`#line .sort-object[data-id="${beforeId}"]`).boundingBox(); x=vertical?b.x+b.width/2:b.x+4; y=vertical?b.y+4:b.y+b.height/2; }
    else { const l=await page.locator('#line').boundingBox(); x=vertical?l.x+l.width/2:l.x+l.width-40; y=vertical?l.y+l.height-40:l.y+l.height/2; }
    await page.mouse.move(x,y,{steps:14}); await page.mouse.up(); await sleep(350);
  };
  const lineOrder=()=>page.$$eval('#line .sort-object',els=>els.map(e=>e.dataset.id));
  await page.goto(base+'/play/order-quiz.php?id=q_order&name=Robot'); await page.waitForSelector('.sort-object');
  check(await page.textContent('[data-label="start"]')==='Först' && await page.textContent('[data-label="end"]')==='Sist','labels');
  check(await page.$eval('#line',l=>l.dataset.axis)==='x','horizontal axis');
  // b, c, d then a placed before b → a,b,c,d? No: build b,c,d,a (a shifted to the end).
  for (const id of ['b','c','d','a']) await drop(id);
  check(JSON.stringify(await lineOrder())==='["b","c","d","a"]','line order '+JSON.stringify(await lineOrder()));
  await noOverlap('horizontal filled');
  await page.screenshot({path:shot('order-before.png')});
  await page.click('#check'); await sleep(300);
  check(await page.locator('.sort-object.blink-wrong').count()===1 && await page.locator('.sort-object[data-id="a"].blink-wrong').count()===1,'only the shifted object blinks');
  await sleep(1800);
  check(JSON.stringify(await lineOrder())==='["a","b","c","d"]','first time: line reordered correctly');
  check(await page.locator('.sort-object.correct').count()===4,'all green after reveal');
  check((await page.textContent('#feedback')).includes('1 objekt på fel plats'),'feedback');
  await page.screenshot({path:shot('order-reveal.png')});
  await page.click('#next'); await page.waitForSelector('#line[data-axis="y"]');
  check(await page.$eval('#track',t=>t.classList.contains('vertical')),'vertical track');
  for (const id of ['m','s','g']) await drop(id);
  check(JSON.stringify(await lineOrder())==='["m","s","g"]','vertical order '+JSON.stringify(await lineOrder()));
  await noOverlap('vertical');
  await page.screenshot({path:shot('order-vertical.png')});
  await page.click('#check'); await sleep(600);
  check((await page.textContent('#feedback'))==='Rätt ordning!','vertical correct');
  await page.click('#next'); await page.waitForSelector('#line[data-axis="x"]');
  // Repetition of the horizontal question: wrong objects stay where they are.
  for (const id of ['b','a']) await drop(id);
  await page.click('#check'); await sleep(2000);
  check(JSON.stringify(await lineOrder())==='["b","a"]','repetition: objects stay');
  check(await page.locator('#tray .sort-object').count()===2,'repetition: unplaced stay in tray');
  await page.click('#next'); await page.waitForSelector('.sort-object');
  for (const id of ['a','b','c','d']) await drop(id);
  await page.click('#check'); await sleep(500); await page.click('#next'); await sleep(800);
  check((await page.textContent('#quiz-title'))==='Klart!','finished');
  const saved=JSON.parse(fs.readFileSync(path.join(fixture,'data/quizzes.json'),'utf8')).q_order.results[0];
  check(saved.score===2 && saved.total===4 && saved.errors.length===2 && JSON.stringify(saved.errors[0].wrong)==='["a"]','saved '+JSON.stringify(saved));
  // Teacher: import template, save, review, publish, stats and routing.
  await page.goto(base+'/index.php'); await page.fill('[name=username]','fixture'); await page.fill('[name=password]','pw');
  await Promise.all([page.waitForNavigation(),page.click('form button')]);
  await page.goto(base+'/admin/order-edit.php'); await page.click('summary');
  await page.fill('#import-json',fs.readFileSync(path.join(root,'assets/templates/ordningsquiz-mall.json'),'utf8')); await page.click('#import-replace');
  check(await page.locator('.question-row').count()===2 && await page.locator('.order-edit-object').count()===8,'order import');
  check(await page.locator('#generate-flashcards').count()===0,'no flashcard option for order');
  // Move "Fiskar" up: the order follows the arrows.
  await page.locator('.order-edit-object').nth(1).locator('button[aria-label="Flytta upp"]').click();
  check((await page.locator('.order-edit-object').nth(0).locator('[data-field=text]').inputValue())==='Fiskar','move up');
  await page.locator('.order-edit-object').nth(0).locator('button[aria-label="Flytta ner"]').click();
  check((await page.locator('.order-edit-object').nth(1).locator('[data-field=text]').inputValue())==='Fiskar','move down');
  await page.screenshot({path:shot('order-editor.png'),fullPage:true});
  await Promise.all([page.waitForNavigation(),page.click('.publish-bar button')]);
  check(page.url().includes('saved=1'),'order save: '+await page.locator('.error').textContent().catch(()=>''));
  const id=new URL(page.url()).searchParams.get('id');
  await page.goto(base+'/admin/review.php?id='+id); check(await page.locator('.review-order').count()===2,'review lists');
  await Promise.all([page.waitForNavigation(),page.click('.publish-bar button')]);
  await page.goto(base+'/play/index.php?id='+id); await page.fill('#name-input','Elev'); await page.keyboard.press('Enter'); await sleep(400);
  check((await page.getAttribute('#quiz-link','href')).startsWith('order-quiz.php?id='),'student start routes to order player');
  check(!(await page.locator('#flashcard-section').isVisible()),'no flashcards for order');
  await page.goto(base+'/admin/stats.php?id=q_order'); const stats=await page.textContent('body');
  check(stats.includes('Objekt som oftast står på fel plats') && /Bakterier\s*→ rätt plats: 1/.test(stats),'order stats');
  await page.goto(base+'/admin/sort-edit.php'); check(await page.locator('#generate-flashcards').count()===1,'sort editor still renders');
  check(errors.length===0,'browser errors: '+errors.join(' | '));
  await browser.close(); console.log(`PASS: ${checks} order checks`);
})().catch(e=>{ console.error('FAIL:',e.message); process.exitCode=1; }).finally(()=>{ fx.stop(); setTimeout(()=>process.exit(),800); });
