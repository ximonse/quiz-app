const {test}=require('node:test');
const assert=require('node:assert/strict');
const vm=require('node:vm');
const fs=require('node:fs');
const source=fs.readFileSync(require('node:path').join(__dirname,'../engine/result-save.js'),'utf8');
const tick=()=>new Promise(resolve=>setImmediate(resolve));
function fixture(fetch) {
    const notices=[], events={};
    function element() { return {style:{},children:[],listeners:{},setAttribute(){},append(...children){this.children.push(...children);},addEventListener(name,fn){this.listeners[name]=fn;},scrollIntoView(){},remove(){this.removed=true;}}; }
    const window={addEventListener(name,fn){events[name]=fn;}};
    const document={createElement:element,createTextNode(textContent){return {textContent};},body:{prepend(notice){notices.push(notice);}}};
    vm.runInNewContext(source,{window,document,fetch,crypto:require('node:crypto').webcrypto,Uint8Array,AbortController,setTimeout,clearTimeout,Set});
    return {save:window.QuizSave.send,notices,events};
}
for (const failure of ['network','http','invalid','session']) test(`retry after ${failure} preserves the request and confirms success`,async()=>{
    const bodies=[]; let calls=0;
    const fx=fixture(async(url,options)=>{
        bodies.push(options.body); calls++;
        if (calls===1) {
            if (failure==='network') throw new Error('Offline');
            return {ok:failure==='invalid',status:failure==='session'?401:503,json:async()=>({error:'Rejected'})};
        }
        return {ok:true,json:async()=>({saved:true})};
    });
    let confirmed=false;
    const result=fx.save('/save',{score:1}).then(()=>{confirmed=true;});
    await tick();
    const [text,retry]=fx.notices[0].children;
    assert.equal(confirmed,false);
    assert.match(text.textContent,/inte sparat/);
    assert.equal(retry.hidden,false);
    await retry.listeners.click(); await result;
    assert.equal(bodies[0],bodies[1]);
    assert.match(JSON.parse(bodies[0]).request_id,/^[a-f0-9]{32}$/);
    assert.match(text.textContent,/är sparat/);
});
test('online retries pending saves, but not already confirmed results',async()=>{
    let calls=0;
    const fx=fixture(async()=>{ if (++calls===1) throw new Error('Offline'); return {ok:true,json:async()=>({success:true})}; });
    const result=fx.save('/save',{score:1}); await tick();
    fx.events.online(); await result; fx.events.online(); await tick();
    assert.equal(calls,2);
});
test('training-only is acknowledged without claiming a saved result',async()=>{
    const fx=fixture(async()=>({ok:true,json:async()=>({saved:false,reason:'training_only'})}));
    await fx.save('/save',{});
    assert.match(fx.notices[0].children[0].textContent,/inget testresultat sparas/);
});
