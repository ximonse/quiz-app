// Synthetic browser check of the sort player. Never touches real data.
const fs=require('fs'),path=require('path');
const {startFixture,shot,launch}=require('./fixture');
const fx=startFixture(); const fixture=fx.dir, root=fx.root; let base;
let checks=0; const check=(c,m)=>{ if(!c) throw new Error(m); checks++; };
const sleep=ms=>new Promise(r=>setTimeout(r,ms));
(async()=>{
  await fx.ready(); base=fx.base;
  const browser=await launch(); const page=await browser.newPage({viewport:{width:1024,height:768}}); page.setDefaultTimeout(8000);
  const errors=[]; page.on('pageerror',e=>errors.push(e.message)); page.on('console',m=>{ if(m.type()==='error') errors.push(m.text()); });
  await page.goto(base+'/play/sort-quiz.php?id=q_sort&name=Robot');
  await page.waitForSelector('.sort-object');
  check(await page.locator('#mute').isVisible(),'mute button hidden');
  const noOverlap=async label=>{
    const boxes=await page.$$eval('.sort-object',els=>els.map(e=>{const r=e.getBoundingClientRect();return [r.left,r.top,r.right,r.bottom,e.dataset.id];}));
    for (let i=0;i<boxes.length;i++) for (let j=i+1;j<boxes.length;j++) {
      const a=boxes[i],b=boxes[j]; const overlap=a[0]<b[2]-1&&b[0]<a[2]-1&&a[1]<b[3]-1&&b[1]<a[3]-1;
      check(!overlap,`${label}: ${a[4]} overlaps ${b[4]}`);
    }
  };
  const drag=async(id,field)=>{
    const item=page.locator(`.sort-object[data-id="${id}"]`); await item.scrollIntoViewIfNeeded();
    const from=await item.boundingBox();
    await page.mouse.move(from.x+from.width/2,from.y+from.height/2); await page.mouse.down();
    await page.mouse.move(from.x+from.width/2+15,from.y+from.height/2+15,{steps:3});
    const to=await page.locator(field?`.sort-zone[data-field="${field}"]`:'#tray').boundingBox();
    await page.mouse.move(to.x+to.width-10,to.y+to.height-10,{steps:12}); await page.mouse.up(); await sleep(350);
  };
  const zoneOf=id=>page.$eval(`.sort-object[data-id="${id}"]`,e=>e.closest('[data-field]').dataset.field);
  await noOverlap('start');
  await drag('champinjon','svamp'); await drag('ek','svamp'); await drag('rav','djur'); await drag('myra','djur'); await drag('maskros','vaxt');
  check(await zoneOf('ek')==='svamp','drag ek failed'); check(await zoneOf('champinjon')==='svamp','drag champinjon failed');
  await noOverlap('after drags');
  await page.click('.sort-object[data-id="sten"]'); check(await page.getAttribute('.sort-object[data-id="sten"]','aria-pressed')==='true','tap did not select');
  await page.click('.sort-field:has([data-field="svamp"]) .sort-field-head'); await sleep(300);
  check(await zoneOf('sten')==='svamp','tap-to-place failed'); await drag('sten',null); check(await zoneOf('sten')==='','drag back to tray failed');
  await page.screenshot({path:shot('before-check.png')});
  await page.click('#check'); await sleep(300);
  check(await page.locator('.sort-object[data-id="ek"].blink-wrong').count()===1,'wrong object did not blink');
  await sleep(2200);
  check(await zoneOf('ek')==='vaxt','first time: wrong object did not glide to correct field');
  check(await page.locator('.sort-object.correct').count()===6,'all objects should be green after reveal');
  await noOverlap('after reveal');
  await page.screenshot({path:shot('after-reveal.png')});
  check((await page.textContent('#feedback')).includes('5 rätt · 1 fel'),'feedback text');
  await page.click('#next'); await page.waitForSelector('.sort-object');
  check(await page.locator('.sort-object').count()===1,'repetition should only contain the wrong object');
  await drag('ek','djur'); await page.click('#check'); await sleep(2000);
  check(await zoneOf('ek')==='djur','repetition: wrong object must stay where placed');
  check(await page.locator('.sort-object.correct').count()===0,'repetition: wrong object must not turn green');
  await page.click('#next'); await page.waitForSelector('.sort-object');
  await drag('ek','vaxt'); await page.click('#check'); await sleep(500);
  check((await page.textContent('#next')).includes('Visa resultat'),'should be complete'); await page.click('#next'); await sleep(800);
  check((await page.textContent('#quiz-title'))==='Klart!','not finished');
  const saved=JSON.parse(fs.readFileSync(path.join(fixture,'data/quizzes.json'),'utf8')).q_sort.results;
  check(saved.length===1 && saved[0].score===6 && saved[0].total===8 && saved[0].errors.length===2,'saved result: '+JSON.stringify(saved));
  check(saved[0].errors[0].object_id==='ek' && saved[0].errors[1].given==='djur','saved errors');
  await page.goto(base+'/play/flashcards.php?id=q_sort&name=Robot'); await sleep(800);
  check((await page.textContent('#counter'))==='1/5','flashcard count '+await page.textContent('#counter'));
  check((await page.textContent('#front-hint'))==='Vilket rike tillhör','flashcard hint');
  const front=await page.textContent('#front-text'), back=await page.textContent('#back-text');
  check(['Champinjon','Ek','Räv','Myra','Maskros'].includes(front) && ['Svampar','Växter','Djur'].includes(back),'flashcard texts '+front+'/'+back);
  await page.setViewportSize({width:390,height:844}); await page.goto(base+'/play/sort-quiz.php?id=q_sort&name=Robot'); await page.waitForSelector('.sort-object');
  await drag('champinjon','svamp'); await drag('rav','djur'); await noOverlap('narrow'); await page.screenshot({path:shot('narrow.png'),fullPage:true});
  check(errors.length===0,'browser errors: '+errors.join(' | '));
  await browser.close(); console.log(`PASS: ${checks} browser checks`);
})().catch(e=>{ console.error('FAIL:',e.message); process.exitCode=1; }).finally(()=>{ fx.stop(); setTimeout(()=>process.exit(),800); });
