// Hold-to-listen and mute, with a recording speechSynthesis stub. Synthetic data only.
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
  for (const touch of ((process.argv[2]||'mouse')==='touch'?[true]:[false])) {
    const context=await browser.newContext({viewport:{width:1024,height:768},hasTouch:touch}); await context.addInitScript(stub);
    const page=await context.newPage(); page.setDefaultTimeout(8000); const cdp=touch?await context.newCDPSession(page):null;
    const spoken=()=>page.evaluate(()=>window.__spoken.splice(0));
    const center=async sel=>{ const b=await page.locator(sel).first().boundingBox(); return {x:b.x+b.width/2,y:b.y+b.height/2}; };
    const hold=async(sel,ms=1150,moveTo=null)=>{
      const p=await center(sel);
      if (touch) {
        const tp=pt=>[{x:pt.x,y:pt.y,id:1}];
        await cdp.send('Input.dispatchTouchEvent',{type:'touchStart',touchPoints:tp(p)}); await sleep(ms);
        if (moveTo) { const t=await center(moveTo); for (let i=1;i<=10;i++) await cdp.send('Input.dispatchTouchEvent',{type:'touchMove',touchPoints:tp({x:p.x+(t.x-p.x)*i/10,y:p.y+(t.y-p.y)*i/10})}); }
        await cdp.send('Input.dispatchTouchEvent',{type:'touchEnd',touchPoints:[]});
      } else {
        await page.mouse.move(p.x,p.y); await page.mouse.down(); await sleep(ms);
        if (moveTo) { const t=await center(moveTo); await page.mouse.move(t.x,t.y,{steps:10}); }
        await page.mouse.up();
      }
      await sleep(450);
    };
    const tag=touch?'touch':'mouse';
    await page.goto(base+'/play/sort-quiz.php?id=q_sort&name=Robot'); await page.waitForSelector('.sort-object'); await sleep(600);
    await spoken();
    await hold('.sort-object[data-id="ek"]');
    { const got=await spoken(); check(JSON.stringify(got)==='["Ek"]',tag+': hold on object reads it '+JSON.stringify(got)); }
    check(await page.getAttribute('.sort-object[data-id="ek"]','aria-pressed')==='false',tag+': hold does not select');
    await hold('.sort-field-head >> nth=0'); check(JSON.stringify(await spoken())==='["Svampar. Bryter ner"]',tag+': hold on field head');
    await hold('#question'); check(JSON.stringify(await spoken())==='["Vilket rike tillhör?"]',tag+': hold on question');
    await hold('.sort-object[data-id="ek"]',200); check((await spoken()).length===0,tag+': short press is silent');
    await hold('.sort-object[data-id="maskros"]',1150,'.sort-zone[data-field="vaxt"]');
    check(await page.$eval('.sort-object[data-id="maskros"]',e=>e.closest('[data-field]').dataset.field)==='vaxt',tag+': hold then move still drags');
    await spoken();
    await hold('.sort-object[data-id="rav"]',100,'.sort-zone[data-field="djur"]'); await spoken();
    check(await page.$eval('.sort-object[data-id="rav"]',e=>e.closest('[data-field]').dataset.field)==='djur',tag+': quick drag works');
    await page.click('#mute'); check(await page.getAttribute('#mute','aria-pressed')==='true',tag+': mute on');
    await hold('.sort-object[data-id="ek"]'); await hold('#question'); check((await spoken()).length===0,tag+': muted hold is silent');
    await page.click('#mute');
    await page.goto(base+'/play/order-quiz.php?id=q_order&name=Robot'); await page.waitForSelector('.sort-object'); await sleep(600); await spoken();
    await hold('[data-label="end"]'); check(JSON.stringify(await spoken())==='["Sist"]',tag+': hold on order label');
    await hold('.sort-object[data-id="c"]'); check(JSON.stringify(await spoken())==='["Dinosaurier"]',tag+': hold on order object');
    await hold('.sort-object[data-id="c"]',100,'#line');
    check(await page.locator('#line .sort-object[data-id="c"]').count()===1,tag+': drag onto line');
    await context.close();
  }
  await browser.close(); console.log(`PASS: ${checks} speech checks`);
})().catch(e=>{ console.error('FAIL:',e.message); process.exitCode=1; }).finally(()=>{ fx.stop(); setTimeout(()=>process.exit(),800); });
