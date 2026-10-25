// Order quiz: the whole order is graded. Only objects that break the order are
// marked wrong: everything outside the longest correctly ordered subsequence,
// plus objects left off the line. One shifted object does not mark all others.
function longestOrderedIds(ids, rank) {
    const n = ids.length, length = Array(n).fill(1), prev = Array(n).fill(-1);
    let best = -1;
    for (let i = 0; i < n; i++) {
        for (let j = 0; j < i; j++) if (rank[ids[j]] < rank[ids[i]] && length[j] + 1 > length[i]) { length[i] = length[j] + 1; prev[i] = j; }
        if (best < 0 || length[i] > length[best]) best = i;
    }
    const keep = new Set();
    for (let i = best; i >= 0; i = prev[i]) keep.add(ids[i]);
    return keep;
}
function evaluateOrder(item, placedIds) {
    const rank = Object.fromEntries(item.objects.map((o, i) => [o.id, i]));
    if (new Set(placedIds).size !== placedIds.length || placedIds.some(id => !(id in rank))) throw new Error('Invalid object IDs');
    const keep = longestOrderedIds(placedIds, rank);
    const statuses = {};
    for (const object of item.objects) statuses[object.id] = keep.has(object.id) ? 'correct' : 'wrong';
    const wrong = Object.values(statuses).filter(s => s === 'wrong').length;
    return {statuses, wrong, correct: wrong === 0};
}
function OrderEngine(items, settings = {}) {
    const test = settings.quiz_mode === 'test';
    const required = test ? 1 : Math.max(1, settings.required_correct || 1);
    const queue = items.map((_, index) => index), mastery = {}, shown = items.map(() => 0);
    const errors = []; let mastered = 0, attempts = 0, mistakes = 0;
    function current() {
        if (!queue.length) return null;
        const index = queue[0];
        return {index, item: items[index], reveal: test || shown[index] === 0};
    }
    function submit(placedIds) {
        const presentation = current(); if (!presentation) throw new Error('Quiz complete');
        const {index, item} = presentation;
        const result = evaluateOrder(item, placedIds); attempts++; shown[index]++;
        queue.shift();
        if (result.correct) {
            mastery[index] = (mastery[index] || 0) + 1;
            if (mastery[index] >= required) mastered++; else queue.push(index);
        } else {
            mistakes++;
            errors.push({item_index: index, given: [...placedIds], wrong: Object.keys(result.statuses).filter(id => result.statuses[id] === 'wrong')});
            if (!test) queue.push(index);
        }
        return {...result, reveal: presentation.reveal};
    }
    function progress() { return {mastered, total: items.length, attempts, mistakes, required, answered: shown.filter(n => n > 0).length, complete: queue.length === 0}; }
    function results() { return {score: mastered, total: attempts, errors: [...errors]}; }
    return {current, submit, progress, results};
}
if (typeof module !== 'undefined') module.exports = {evaluateOrder, OrderEngine};
