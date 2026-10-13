const {test}=require('node:test');
const assert=require('node:assert/strict');
const {evaluateImageChoices,ImageChoiceEngine}=require('../engine/image-choice-engine.js');
const item={question:'Vilka visar lutande plan?',choices:[
    {id:'ramp_a',correct:true},{id:'wheel',correct:false},{id:'ramp_b',correct:true},
    {id:'lever',correct:false},{id:'screw',correct:false},{id:'ramp_c',correct:true}
]};
test('six pictures report selected right, selected wrong, missed and untouched wrong independently',()=>{
    const result=evaluateImageChoices(item,['ramp_a','wheel']);
    assert.deepEqual(result,{statuses:{ramp_a:'correct',wheel:'wrong',ramp_b:'missed',lever:null,screw:null,ramp_c:'missed'},right:1,wrong:1,missed:2,correct:false});
});
test('all and only correct pictures is a correct answer; selecting everything is wrong',()=>{
    assert.equal(evaluateImageChoices(item,['ramp_c','ramp_a','ramp_b']).correct,true);
    assert.equal(evaluateImageChoices(item,item.choices.map(c=>c.id)).wrong,3);
    assert.equal(evaluateImageChoices(item,[]).missed,3);
});
test('duplicate or foreign picture IDs are rejected',()=>{
    assert.throws(()=>evaluateImageChoices(item,['wheel','wheel']));
    assert.throws(()=>evaluateImageChoices(item,['unknown']));
});
test('training repeats wrong questions and honors required correct repetitions',()=>{
    const engine=ImageChoiceEngine([item],{required_correct:2});
    engine.submit(['ramp_a']); assert.equal(engine.progress().complete,false);
    engine.submit(['ramp_a','ramp_b','ramp_c']); assert.equal(engine.progress().complete,false);
    engine.submit(['ramp_a','ramp_b','ramp_c']); assert.equal(engine.progress().complete,true);
    assert.equal(engine.results().errors[0].missed,2);
    assert.equal(engine.results().score,1);
    assert.deepEqual(engine.results().choice_counts,{right:7,wrong:0,missed:2});
});
test('single-choice test completes after one attempt and preserves input/info image',()=>{
    const single={question:'Vilken är en hävstång?',image:{file:'info.png',alt:'Infobild'},choices:[{id:'lever',correct:true},{id:'ramp',correct:false}]};
    const before=JSON.stringify(single); const engine=ImageChoiceEngine([single],{quiz_mode:'test',required_correct:10});
    assert.equal(engine.current().image.alt,'Infobild'); engine.submit(['ramp']);
    assert.equal(engine.progress().complete,true); assert.equal(engine.results().score,0);
    assert.equal(engine.results().errors[0].selected_wrong,1); assert.equal(engine.results().errors[0].missed,1);
    assert.deepEqual(engine.results().choice_counts,{right:0,wrong:1,missed:1});
    assert.equal(JSON.stringify(single),before);
});
