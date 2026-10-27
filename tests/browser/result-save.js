// Real HTTP writes followed by lost responses must remain one result after retry.
const fs=require('node:fs'), path=require('node:path');
const {startFixture,shot,launch}=require('./fixture');
const fx=startFixture(); let browser;
(async()=>{
    await fx.ready(); browser=await launch(); const page=await browser.newPage();
    fs.writeFileSync(path.join(fx.dir,'save-check.html'),`<!doctype html><html lang="sv"><head><meta charset="utf-8"><link rel="stylesheet" href="assets/classroom.css"><script src="engine/result-save.js"></script></head><body class="classroom"><main><h1>Syntetisk sparningskontroll</h1><button id="save">Spara resultat</button></main><script>
    document.getElementById('save').onclick=()=>QuizSave.send('api/save-result.php',{quiz_id:'q_sort',student_name:'Robot',student_id:null,score:1,total:1,errors:[]});
    </script></body></html>`);
    let checks=0;
    function check(value,message){if(!value)throw new Error(message);checks++;}
    const results=()=>JSON.parse(fs.readFileSync(path.join(fx.dir,'data/quizzes.json'),'utf8')).q_sort.results;
    for(const failure of ['lost-response','server','session']) {
        await page.goto(fx.base+'/save-check.html'); let calls=0; const bodies=[];
        await page.route('**/api/save-result.php',async route=>{
            bodies.push(route.request().postData());
            if(++calls===1){
                if(failure==='lost-response'){await route.fetch();await route.abort();}
                else await route.fulfill({status:failure==='session'?401:503,contentType:'application/json',body:'{"error":"Synthetic failure"}'});
            } else await route.continue();
        });
        const before=results().length;
        await page.click('#save');
        await page.getByText('Resultatet är inte sparat.',{exact:false}).waitFor();
        if(failure==='lost-response')check(results().length===before+1,'Lost response did not commit upstream');
        await page.screenshot({path:shot('save-'+failure+'.png'),fullPage:true});
        await page.getByRole('button',{name:'Försök spara igen'}).click();
        await page.getByText('Resultatet är sparat.',{exact:true}).waitFor();
        check(results().length===before+1,'Retry duplicated result: '+failure);
        check(bodies.length===2 && bodies[0]===bodies[1],'Retry changed request identity');
        await page.unroute('**/api/save-result.php');
    }
    await page.screenshot({path:shot('save-confirmed.png'),fullPage:true});
    await page.goto(fx.base+'/index.php');
    await page.fill('input[name=username]','fixture'); await page.fill('input[name=password]','pw');
    await Promise.all([page.waitForNavigation(),page.locator('form button').click()]);
    await page.getByText('Skapa nytt quiz',{exact:true}).waitFor();
    await page.screenshot({path:shot('dashboard-current.png'),fullPage:true});
    check(await page.locator('link[href="../assets/vendor/tailwind-3.4.19.css"]').count()===1,'Dashboard did not use local CSS');
    await browser.close(); console.log(`PASS: ${checks} result retry browser checks`);
})().catch(error=>{console.error('FAIL:',error);process.exitCode=1;}).finally(async()=>{if(browser)await browser.close();fx.stop();});
