// Shared drag-and-drop for sort and order quizzes.
// Items always live inside flex containers, so they can never overlap: a drop
// only picks a container and an index, and the others make room (FLIP animated).
// Pointer Events work for mouse, pen and iPad touch; tap-to-select is the
// keyboard/motor-friendly alternative. Holding still on an item speaks it.
const LONG_PRESS_MS = 900, DRAG_THRESHOLD = 8;
function flipAnimate(root, change) {
    const nodes = [...root.querySelectorAll('[data-flip]')];
    const before = new Map(nodes.map(n => [n, n.getBoundingClientRect()]));
    change();
    for (const node of nodes) {
        const a = before.get(node), b = node.getBoundingClientRect();
        const dx = a.left - b.left, dy = a.top - b.top;
        if ((dx || dy) && node.animate) node.animate([{transform: `translate(${dx}px,${dy}px)`}, {transform: 'none'}], {duration: 260, easing: 'ease-out'});
    }
}
function insertionIndex(container, x, y, dragged, axis) {
    const children = [...container.children].filter(c => c !== dragged && c.matches('[data-flip]'));
    for (let i = 0; i < children.length; i++) {
        const r = children[i].getBoundingClientRect();
        if (axis === 'x' && x < r.left + r.width / 2) return i;
        if (axis === 'y' && y < r.top + r.height / 2) return i;
        if (!axis && (y < r.top || (y <= r.bottom && x < r.left + r.width / 2))) return i;
    }
    return children.length;
}
// Speaks after the pointer stays still; movement or release cancels it.
function attachLongPress(el, speak) {
    let timer = null, start = null;
    const cancel = () => { clearTimeout(timer); timer = null; };
    el.addEventListener('pointerdown', e => { start = [e.clientX, e.clientY]; cancel(); timer = setTimeout(() => { timer = null; speak(); }, LONG_PRESS_MS); });
    el.addEventListener('pointermove', e => { if (timer && Math.hypot(e.clientX - start[0], e.clientY - start[1]) > DRAG_THRESHOLD) cancel(); });
    for (const type of ['pointerup', 'pointercancel', 'pointerleave']) el.addEventListener(type, cancel);
    el.addEventListener('contextmenu', e => e.preventDefault());
}
// A container may set data-axis="x"|"y" for single-row/column lines (order quiz).
function DragBoard({root, containerSelector, onSpeak = () => {}, onChange = () => {}}) {
    const axisOf = container => container.dataset.axis || null;
    let locked = false, selected = null, drag = null;
    function containerOf(el) { return el.closest(containerSelector); }
    function place(el, container, index) {
        flipAnimate(root, () => {
            const siblings = [...container.children].filter(c => c !== el && c.matches('[data-flip]'));
            container.insertBefore(el, siblings[index] || null);
        });
        onChange();
    }
    function select(el) {
        if (selected) selected.setAttribute('aria-pressed', 'false');
        selected = selected === el ? null : el;
        if (selected) selected.setAttribute('aria-pressed', 'true');
        root.classList.toggle('has-selection', !!selected);
    }
    function containerAt(x, y) {
        const hit = document.elementFromPoint(x, y);
        return hit && hit.closest(containerSelector) && root.contains(hit) ? hit.closest(containerSelector) : null;
    }
    function startDrag(state, e) {
        const el = state.el, rect = el.getBoundingClientRect();
        const ghost = el.cloneNode(true); ghost.removeAttribute('data-flip'); ghost.classList.add('drag-ghost');
        Object.assign(ghost.style, {width: rect.width + 'px', height: rect.height + 'px', left: rect.left + 'px', top: rect.top + 'px'});
        document.body.append(ghost); el.classList.add('drag-placeholder');
        drag = {...state, ghost, offX: e.clientX - rect.left, offY: e.clientY - rect.top};
        if (selected) select(selected);
    }
    function moveDrag(e) {
        drag.ghost.style.left = (e.clientX - drag.offX) + 'px'; drag.ghost.style.top = (e.clientY - drag.offY) + 'px';
        if (e.clientY < 50) window.scrollBy(0, -12); else if (e.clientY > innerHeight - 50) window.scrollBy(0, 12);
        const target = containerAt(e.clientX, e.clientY);
        if (!target) return;
        const index = insertionIndex(target, e.clientX, e.clientY, drag.el, axisOf(target));
        const position = [...target.children].filter(c => c.matches('[data-flip]')).indexOf(drag.el);
        if (containerOf(drag.el) !== target || position !== index) place(drag.el, target, index);
    }
    function endDrag() {
        const {ghost, el} = drag; drag = null;
        const r = el.getBoundingClientRect();
        const done = () => { ghost.remove(); el.classList.remove('drag-placeholder'); };
        if (ghost.animate) ghost.animate([{left: ghost.style.left, top: ghost.style.top}, {left: r.left + 'px', top: r.top + 'px'}], {duration: 140}).onfinish = done; else done();
    }
    root.addEventListener('pointerdown', e => {
        const el = e.target.closest('[data-flip]');
        if (!el || !root.contains(el) || e.button > 0) return;
        const state = {el, x: e.clientX, y: e.clientY, moved: false, spoke: false};
        state.timer = setTimeout(() => { state.spoke = true; onSpeak(el); }, LONG_PRESS_MS);
        const move = ev => {
            if (!state.moved && Math.hypot(ev.clientX - state.x, ev.clientY - state.y) > DRAG_THRESHOLD) {
                state.moved = true; clearTimeout(state.timer);
                if (!locked) startDrag(state, ev);
            }
            if (drag) { ev.preventDefault(); moveDrag(ev); }
        };
        const up = () => {
            clearTimeout(state.timer); window.removeEventListener('pointermove', move); window.removeEventListener('pointerup', up); window.removeEventListener('pointercancel', up);
            if (drag) endDrag();
            else if (!state.moved && !state.spoke && !locked) state.tapped = true;
        };
        window.addEventListener('pointermove', move, {passive: false}); window.addEventListener('pointerup', up); window.addEventListener('pointercancel', up);
        el.addEventListener('click', ev => { ev.stopPropagation(); if (state.tapped) select(el); }, {once: true});
    });
    // Keyboard: Enter/Space on an item triggers click without pointer events.
    root.addEventListener('keydown', e => {
        if ((e.key === 'Enter' || e.key === ' ') && !locked) {
            const item = e.target.closest('[data-flip]'), container = e.target.matches(containerSelector) ? e.target : null;
            if (item) { e.preventDefault(); select(item); }
            else if (container && selected) { e.preventDefault(); const el = selected; select(el); place(el, container, container.children.length); }
        }
    });
    root.addEventListener('click', e => {
        if (locked || !selected || e.target.closest('[data-flip]')) return;
        const container = e.target.closest('[data-drop-target]')?.querySelector(containerSelector) || e.target.closest(containerSelector);
        if (!container || !root.contains(container)) return;
        const el = selected; select(el); place(el, container, insertionIndex(container, e.clientX, e.clientY, el, axisOf(container)));
    });
    return {
        place, flip: change => flipAnimate(root, change),
        lock(value) { locked = value; if (selected) select(selected); },
    };
}
if (typeof module !== 'undefined') module.exports = {insertionIndex};
