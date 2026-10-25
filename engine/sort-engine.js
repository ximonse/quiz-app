// Sorting quiz: mastery is tracked per object. A question returns with only
// the objects that still need correct answers. Distractors (field:null) are
// correct when left outside every field.
function evaluateSort(item, objectIds, placements) {
    const fieldIds = new Set(item.fields.map(f => f.id));
    const statuses = {}; let right = 0, wrong = 0;
    for (const id of objectIds) {
        const object = item.objects.find(o => o.id === id);
        if (!object) throw new Error('Unknown object ID');
        const given = placements[id] ?? null;
        if (given !== null && !fieldIds.has(given)) throw new Error('Unknown field ID');
        const ok = given === (object.field ?? null);
        statuses[id] = ok ? 'correct' : 'wrong';
        ok ? right++ : wrong++;
    }
    return {statuses, right, wrong, correct: wrong === 0};
}
function SortEngine(items, settings = {}) {
    const test = settings.quiz_mode === 'test';
    const required = test ? 1 : Math.max(1, settings.required_correct || 1);
    const mastery = items.map(() => ({}));
    const shown = items.map(() => 0);
    const queue = items.map((item, index) => index);
    const errors = []; let right = 0, wrong = 0;
    function pending(index) { return items[index].objects.filter(o => (mastery[index][o.id] || 0) < required).map(o => o.id); }
    function current() {
        if (!queue.length) return null;
        const index = queue[0];
        // Correct places are revealed only the first time a question is shown.
        return {index, item: items[index], objectIds: pending(index), reveal: test || shown[index] === 0};
    }
    function submit(placements) {
        const presentation = current(); if (!presentation) throw new Error('Quiz complete');
        const {index, item, objectIds} = presentation;
        const result = evaluateSort(item, objectIds, placements);
        for (const id of objectIds) {
            if (result.statuses[id] === 'correct') { mastery[index][id] = (mastery[index][id] || 0) + 1; right++; }
            else {
                wrong++;
                errors.push({item_index: index, object_id: id, given: placements[id] ?? null,
                    correct: item.objects.find(o => o.id === id).field ?? null});
            }
        }
        shown[index]++;
        queue.shift();
        if (!test && pending(index).length) queue.push(index);
        return {...result, reveal: presentation.reveal};
    }
    function progress() {
        const mastered = items.filter((_, i) => !pending(i).length).length;
        return {mastered, answered: shown.filter(n => n > 0).length, total: items.length, right, wrong, required, complete: queue.length === 0};
    }
    function results() { return {score: right, total: right + wrong, errors: [...errors]}; }
    return {current, submit, progress, results};
}
if (typeof module !== 'undefined') module.exports = {evaluateSort, SortEngine};
