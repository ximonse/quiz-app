const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const source = fs.readFileSync(require('node:path').join(__dirname, '../engine/classroom.js'), 'utf8');
function setup(fetch) {
    const events = {}, elements = [];
    function element() {
        const el = {style:{}, hidden:false, children:[], append(child){this.children.push(child);}, prepend(child){this.firstChild=child;}};
        elements.push(el); return el;
    }
    const sandbox = {
        window:{classroomContext:{assignment_id:'task',student_id:'pupil',csrf:'token'}, addEventListener(name,fn){events[name]=fn;}},
        document:{createElement:element,createTextNode:text=>({nodeType:3,textContent:text}),addEventListener(name,fn){events[name]=fn;},body:{append(){}}},
        crypto:require('node:crypto').webcrypto, Uint8Array, Set, fetch
    };
    // Minimal DOM includes the accessibility attribute used by the tracker notice.
    sandbox.document.createElement=()=>Object.assign(element(),{setAttribute(){}});
    vm.runInNewContext(source,sandbox);
    return {tracker:sandbox.window.ClassroomTracker,events,elements};
}
const tick=()=>new Promise(resolve=>setImmediate(resolve));
test('quiz progress persists before completion; a single attempt retains pupil identity',async()=>{
    const values=[];
    const {tracker}=setup(async(url,options)=>{values.push(JSON.parse(options.body));return {ok:true};});
    tracker.answer(false); await tick();
    tracker.answer(true,true); await tick();
    assert.equal(values.length,2);
    assert.equal(values[0].completed,false);
    assert.equal(values[0].wrong,1);
    assert.equal(values[1].right,1);
    assert.equal(values[1].wrong,1);
    assert.equal(values[1].completed,true);
    assert.equal(values[0].student_id,'pupil');
    assert.equal(values[0].attempt_id,values[1].attempt_id);
    assert.equal(values[1].seq,2);
});
test('opening a player alone is not practice; repeated flips count once',async()=>{
    const values=[];
    const {tracker}=setup(async(url,options)=>{values.push(JSON.parse(options.body));return {ok:true};});
    assert.equal(values.length,0);
    tracker.card(0,2);await tick();tracker.card(0,2);await tick();tracker.card(1,2);await tick();
    assert.deepEqual(values.map(v=>v.cards),[1,1,2]);
    assert.equal(values[2].completed,true);
});
test('failed save is retried with its attempt ID and sequence intact',async()=>{
    const values=[];let online=false;
    const {tracker,events}=setup(async(url,options)=>{values.push(JSON.parse(options.body));if(!online)throw Error('offline');return {ok:true};});
    tracker.answer(true);await tick();online=true;events.online();await tick();
    assert.equal(values.length,2);
    assert.deepEqual(values[0],values[1]);
});
test('new progress during an in-flight request is sent after it',async()=>{
    const values=[];let release;
    const {tracker}=setup(async(url,options)=>{values.push(JSON.parse(options.body));if(values.length===1)await new Promise(r=>release=r);return {ok:true};});
    tracker.answer(true);tracker.answer(false,true);release();await tick();await tick();
    assert.equal(values.length,2);
    assert.equal(values[1].seq,2);
    assert.equal(values[1].completed,true);
});
