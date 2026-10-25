// Screenshots of the fact quiz in every theme and of the dashboard, for visual review.
const fs=require('fs'),path=require('path');
const {startFixture,shot,launch}=require('./fixture');
const fx=startFixture(); const fixture=fx.dir, root=fx.root; let base;
const sleep=ms=>new Promise(r=>setTimeout(r,ms));
(async()=>{
  await fx.ready(); base=fx.base; const browser=await launch(); const page=await browser.newPage({viewport:{width:900,height:620}}); page.setDefaultTimeout(10000);
  await page.goto(base+'/play/quiz.php?id=q_fact&name=Robot'); await sleep(2500);
  const sel=page.locator('select').first(); const opts=await sel.locator('option').evaluateAll(o=>o.map(x=>x.value)); console.log('themes',opts);
  for (const t of opts) { await sel.selectOption(t); await sleep(400); await page.screenshot({path:shot(`theme-${t}.png`)}); }
  await page.goto(base+'/index.php'); await page.fill('[name=username]','fixture'); await page.fill('[name=password]','pw');
  await Promise.all([page.waitForNavigation(),page.click('form button')]);
  await page.setViewportSize({width:1100,height:900}); await page.goto(base+'/admin/dashboard.php'); await sleep(1500);
  await page.screenshot({path:shot('dashboard.png'),fullPage:false});
  await browser.close(); console.log('done');
})().catch(e=>{ console.error('FAIL:',e.message); process.exitCode=1; }).finally(()=>{ fx.stop(); setTimeout(()=>process.exit(),800); });
