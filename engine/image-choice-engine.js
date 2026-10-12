// One submission per question; feedback is keyed by stable picture ID.
function evaluateImageChoices(item, selectedIds) {
    if (!Array.isArray(selectedIds) || new Set(selectedIds).size !== selectedIds.length ||
        selectedIds.some(id => !item.choices.some(choice => choice.id === id))) throw new Error('Invalid selected picture IDs');
    const selected = new Set(selectedIds);
    const statuses = {}; let right = 0, wrong = 0, missed = 0;
    for (const choice of item.choices) {
        if (selected.has(choice.id) && choice.correct) { statuses[choice.id] = 'correct'; right++; }
        else if (selected.has(choice.id)) { statuses[choice.id] = 'wrong'; wrong++; }
        else if (choice.correct) { statuses[choice.id] = 'missed'; missed++; }
        else statuses[choice.id] = null;
    }
    return { statuses, right, wrong, missed, correct: wrong === 0 && missed === 0 };
}
function ImageChoiceEngine(items, settings = {}) {
    const queue = items.map((item,index) => ({...item, index}));
    const required = settings.quiz_mode === 'test' ? 1 : Math.max(1, settings.required_correct || 1);
    const mastery = {}; const errors = []; let mastered = 0, attempts = 0, mistakes = 0;
    function current() { return queue[0] || null; }
    function submit(selectedIds) {
        const item = current(); if (!item) throw new Error('Quiz complete');
        const result = evaluateImageChoices(item, selectedIds); attempts++;
        if (result.correct) {
            mastery[item.index] = (mastery[item.index] || 0) + 1;
            if (mastery[item.index] >= required) { mastered++; queue.shift(); }
            else queue.push(queue.shift());
        } else {
            mistakes++;
            errors.push({item_index:item.index, given:[...selectedIds], correct:item.choices.filter(c=>c.correct).map(c=>c.id),
                selected_correct:result.right, selected_wrong:result.wrong, missed:result.missed});
            if (settings.quiz_mode === 'test') queue.shift(); else queue.push(queue.shift());
        }
        return result;
    }
    function progress() { return {mastered,total:items.length,attempts,mistakes,required,complete:queue.length===0}; }
    function results() { return {score:mastered,total:attempts,errors:[...errors]}; }
    return {current,submit,progress,results};
}
if (typeof module !== 'undefined') module.exports = {evaluateImageChoices,ImageChoiceEngine};
