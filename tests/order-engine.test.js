const {test}=require('node:test');
const assert=require('node:assert/strict');
const {evaluateOrder,OrderEngine}=require('../engine/order-engine.js');
const item={question:'Ordna från äldst till yngst',objects:['a','b','c','d','e'].map(id=>({id,text:id.toUpperCase()}))};
test('correct order is correct; one shifted object marks only that object',()=>{
    assert.equal(evaluateOrder(item,['a','b','c','d','e']).correct,true);
    const shifted=evaluateOrder(item,['b','c','d','e','a']);
    assert.equal(shifted.correct,false); assert.equal(shifted.wrong,1); assert.equal(shifted.statuses.a,'wrong');
    assert.equal(evaluateOrder(item,['a','c','b','d','e']).wrong,1);
});
test('objects left off the line are wrong; empty line is all wrong',()=>{
    const partial=evaluateOrder(item,['a','b','d']);
    assert.deepEqual([partial.statuses.c,partial.statuses.e,partial.statuses.d],['wrong','wrong','correct']);
    assert.equal(evaluateOrder(item,[]).wrong,5);
});
test('duplicate or unknown IDs are rejected',()=>{
    assert.throws(()=>evaluateOrder(item,['a','a']));
    assert.throws(()=>evaluateOrder(item,['x']));
});
test('a wrong order returns the whole question; reveal only the first time',()=>{
    const engine=OrderEngine([item],{required_correct:1});
    assert.equal(engine.current().reveal,true);
    const first=engine.submit(['b','a','c','d','e']); assert.equal(first.reveal,true); assert.equal(first.correct,false);
    assert.equal(engine.current().reveal,false);
    engine.submit(['a','b','c','d','e']);
    assert.equal(engine.progress().complete,true);
    assert.deepEqual(engine.results(),{score:1,total:2,errors:[{item_index:0,given:['b','a','c','d','e'],wrong:['a']}]});
});
test('required correct repeats; test mode is one pass',()=>{
    const training=OrderEngine([item],{required_correct:2}); const order=['a','b','c','d','e'];
    training.submit(order); assert.equal(training.progress().complete,false); training.submit(order); assert.equal(training.progress().complete,true);
    const exam=OrderEngine([item],{quiz_mode:'test',required_correct:3}); exam.submit(['e']);
    assert.equal(exam.progress().complete,true); assert.equal(exam.results().score,0);
});
