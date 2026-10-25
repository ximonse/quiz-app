const {test}=require('node:test');
const assert=require('node:assert/strict');
const {evaluateSort,SortEngine}=require('../engine/sort-engine.js');
const item={question:'Vilket rike tillhör…?',fields:[{id:'svamp',title:'Svampar'},{id:'vaxt',title:'Växter'}],objects:[
    {id:'champinjon',text:'Champinjon',field:'svamp'},{id:'ek',text:'Ek',field:'vaxt'},
    {id:'flugsvamp',text:'Flugsvamp',field:'svamp'},{id:'sten',text:'Sten',field:null}
]};
const all=item.objects.map(o=>o.id);
test('placements are judged per object; distractors are right only outside fields',()=>{
    const result=evaluateSort(item,all,{champinjon:'svamp',ek:'svamp',flugsvamp:'svamp'});
    assert.deepEqual(result,{statuses:{champinjon:'correct',ek:'wrong',flugsvamp:'correct',sten:'correct'},right:3,wrong:1,correct:false});
    assert.equal(evaluateSort(item,['sten'],{sten:'vaxt'}).statuses.sten,'wrong');
});
test('unknown objects or fields are rejected',()=>{
    assert.throws(()=>evaluateSort(item,['okand'],{}));
    assert.throws(()=>evaluateSort(item,['ek'],{ek:'djur'}));
});
test('a wrong answer returns with only the wrong objects, revealed only the first time',()=>{
    const engine=SortEngine([item],{required_correct:1});
    let now=engine.current(); assert.deepEqual(now.objectIds,all); assert.equal(now.reveal,true);
    const first=engine.submit({champinjon:'svamp',ek:'svamp',flugsvamp:'vaxt'});
    assert.equal(first.reveal,true); assert.equal(engine.progress().complete,false);
    now=engine.current(); assert.deepEqual(now.objectIds,['ek','flugsvamp']); assert.equal(now.reveal,false);
    engine.submit({ek:'vaxt',flugsvamp:'vaxt'});
    assert.deepEqual(engine.current().objectIds,['flugsvamp']);
    engine.submit({flugsvamp:'svamp'});
    assert.equal(engine.progress().complete,true);
    const results=engine.results();
    assert.deepEqual([results.score,results.total],[4,7]);
    assert.deepEqual(results.errors[0],{item_index:0,object_id:'ek',given:'svamp',correct:'vaxt'});
});
test('required correct repeats objects, including right ones, and other questions come in between',()=>{
    const second={question:'B',fields:[{id:'a',title:'A'}],objects:[{id:'x',text:'X',field:'a'}]};
    const engine=SortEngine([item,second],{required_correct:2});
    engine.submit({champinjon:'svamp',ek:'vaxt',flugsvamp:'svamp'});
    assert.equal(engine.current().index,1);
    engine.submit({x:'a'});
    const back=engine.current(); assert.equal(back.index,0); assert.deepEqual(back.objectIds,all); assert.equal(back.reveal,false);
    engine.submit({champinjon:'svamp',ek:'vaxt',flugsvamp:'svamp'}); engine.submit({x:'a'});
    assert.equal(engine.progress().complete,true); assert.equal(engine.progress().mastered,2);
});
test('test mode shows each question once and always reveals',()=>{
    const engine=SortEngine([item],{quiz_mode:'test',required_correct:5});
    assert.equal(engine.current().reveal,true);
    engine.submit({});
    assert.equal(engine.progress().complete,true); assert.equal(engine.progress().mastered,0);
    assert.equal(engine.results().errors.length,3);
});
