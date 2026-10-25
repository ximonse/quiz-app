// Hold-to-listen in fact quiz, flashcards and image quiz. Synthetic data only.
const {chromium}=require('playwright');
const fs=require('fs'),path=require('path');
const {startFixture,shot}=require('./fixture');
const fx=startFixture(); const fixture=fx.dir, root=fx.root; let base;
let checks=0; const check=(c,m)=>{ if(!c) throw new Error(m); checks++; console.log('ok',m); };
const sleep=ms=>new Promise(r=>setTimeout(r,ms));
const stub=()=>{ window.__spoken=[]; const s=window.speechSynthesis;
  s.speak=u=>{ if(u.text.trim()) window.__spoken.push(u.text); }; s.cancel=()=>{}; s.getVoices=()=>[]; };
(async()=>{
  await fx.ready(); base=fx.base;
  const browser=await chromium.launch();
  for (const touch of [false,true]) {
    const context=await browser.newContext({viewport:{width:1024,height:768},hasTouch:touch}); await context.addInitScript(stub);
    const page=await context.newPage(); page.setDefaultTimeout(8000); const cdp=touch?await context.newCDPSession(page):null;
    page.on('pageerror',e=>console.log('pageerror',e.message));
    const spoken=()=>page.evaluate(()=>window.__spoken.splice(0));
    const center=async loc=>{ const b=await loc.boundingBox(); return {x:b.x+b.width/2,y:b.y+b.height/2}; };
    const hold=async(loc,ms=1250)=>{
      const p=await center(loc);
      if (touch) {
        await cdp.send('Input.dispatchTouchEvent',{type:'touchStart',touchPoints:[{x:p.x,y:p.y,id:1}]}); await sleep(ms);
        await cdp.send('Input.dispatchTouchEvent',{type:'touchEnd',touchPoints:[]});
      } else { await page.mouse.move(p.x,p.y); await page.mouse.down(); await sleep(ms); await page.mouse.up(); }
      await sleep(400);
    };
    const tag=touch?'touch':'mouse';
    const answered=()=>page.locator('.answer-correct-pulse, .answer-wrong').count();
    await page.goto(base+'/play/quiz.php?id=q_fact&name=Robot'); await page.waitForSelector('button:has-text("Växter gör socker")'); await sleep(500); await spoken();
    check(await page.locator('text=Lyssna').count()===0,tag+': no listen button in fact quiz');
    check(await page.locator('#root button:has-text("🔊")').count()===1,tag+': only header speaker remains');
    const opt=page.locator('button:has-text("Växter gör socker")');
    await hold(opt); check(JSON.stringify(await spoken())==='["Växter gör socker av ljus"]',tag+': hold on option reads it');
    check(await answered()===0,tag+': hold on option does not answer');
    await hold(page.locator('p:has-text("Fotosyntes")').first()); check(JSON.stringify(await spoken())==='["Fotosyntes"]',tag+': hold on question reads it');
    await page.locator('#root button:has-text("🔊")').click(); await hold(opt); check((await spoken()).length===0,tag+': muted hold is silent');
    check(await answered()===0,tag+': muted hold does not answer either');
    await page.locator('#root button:has-text("🔇")').click();
    await hold(opt,500); check((await spoken()).length===0,tag+': half-second press is silent');
    await page.waitForFunction(()=>document.querySelector('.answer-correct-pulse') || /Bra jobbat|FLAWLESS/.test(document.body.textContent)); check(true,tag+': short press answers');

    await page.goto(base+'/play/flashcards.php?id=q_fact'); await page.waitForFunction(()=>document.getElementById('front-text').textContent); await sleep(400); await spoken();
    check(await page.locator('.card button').count()===0,tag+': no speaker buttons on card');
    await hold(page.locator('#card')); check(JSON.stringify(await spoken())==='["Fotosyntes"]',tag+': hold reads card front');
    check(!(await page.$eval('#card',e=>e.classList.contains('flipped'))),tag+': hold does not flip');
    await hold(page.locator('#card'),100); await sleep(300);
    check(await page.$eval('#card',e=>e.classList.contains('flipped')),tag+': tap flips');
    await spoken(); await hold(page.locator('#card')); check(JSON.stringify(await spoken())==='["Växter gör socker av ljus"]',tag+': hold reads card back');
    check(await page.$eval('#card',e=>e.classList.contains('flipped')),tag+': hold on back keeps it flipped');

    await page.goto(base+'/play/image-quiz.php?id=q_img&name=Robot'); await page.waitForSelector('#question:not(:empty)'); await sleep(500);
    check(JSON.stringify(await spoken())==='["Vilken är en katt?"]',tag+': image quiz reads question on show');
    check(await page.locator('#listen').count()===0 && await page.isVisible('#mute'),tag+': header mute instead of listen button');
    await hold(page.locator('#question')); check(JSON.stringify(await spoken())==='["Vilken är en katt?"]',tag+': hold on image question');
    await page.click('#mute'); await hold(page.locator('#question')); check((await spoken()).length===0,tag+': image quiz muted hold silent');
    await page.click('#mute');
    if (!touch) {
      await page.screenshot({path:shot('hold-image.png')});
      await page.goto(base+'/play/quiz.php?id=q_fact&name=Robot'); await page.waitForSelector('button:has-text("Växter gör socker")'); await sleep(400);
      await page.screenshot({path:shot('hold-fact.png')});
      await page.goto(base+'/play/flashcards.php?id=q_fact'); await sleep(800);
      await page.screenshot({path:shot('hold-cards.png')});
    }
    await context.close();
  }
  await browser.close(); console.log(`PASS: ${checks} hold checks`);
})().catch(e=>{ console.error('FAIL:',e.message); process.exitCode=1; }).finally(()=>{ fx.stop(); setTimeout(()=>process.exit(),800); });
