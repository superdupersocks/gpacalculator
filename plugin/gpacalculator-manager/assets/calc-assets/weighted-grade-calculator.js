/*! Weighted Grade Calculator v2 — gpacalculator.net
 * Drop-in replacement for calc-assets/weighted-grade-calculator.js. Mounts into #root.
 * Vanilla JS, no dependencies, fully wrapped in an IIFE so no names leak into
 * window (the old React bundle leaked `au`, which an ad script overwrote and
 * broke every input on the page).
 */
(function () {
  'use strict';

  var SCALE = [
    ['A+', 97, 4.0], ['A', 93, 4.0], ['A-', 90, 3.7], ['B+', 87, 3.3], ['B', 83, 3.0], ['B-', 80, 2.7],
    ['C+', 77, 2.3], ['C', 73, 2.0], ['C-', 70, 1.7], ['D+', 67, 1.3], ['D', 65, 1.0], ['F', 0, 0.0]
  ];
  var GOALS = [['A', 93], ['A-', 90], ['B', 83], ['C', 73], ['Pass', 65]];
  var EXAMPLES = [['Homework', '94', '20'], ['Quizzes', '86', '15'], ['Midterm', '81', '25'], ['Final exam', '78', '30'], ['Project', '90', '10'], ['Participation', '100', '5'], ['Labs', '88', '10'], ['Essay', '85', '15']];
  var STORE_KEY = 'wgc:v2';
  var uid = 0;

  function newRow(r) {
    r = r || {};
    return { id: ++uid, name: r.name || '', grade: r.grade || '', earned: r.earned || '', possible: r.possible || '', weight: r.weight || '' };
  }

  function letterFor(p) {
    for (var i = 0; i < SCALE.length; i++) if (p >= SCALE[i][1] - 1e-9) return SCALE[i];
    return SCALE[SCALE.length - 1];
  }
  function gradeClass(l) { return 'wgc-g-' + l.charAt(0).toLowerCase(); }
  function num(v) { if (v === '' || v == null) return NaN; var n = parseFloat(v); return isFinite(n) ? n : NaN; }
  function fmt(n, d) {
    d = d == null ? 2 : d;
    var s = (Math.round(n * Math.pow(10, d)) / Math.pow(10, d)).toFixed(d);
    return s.replace(/\.?0+$/, '');
  }
  function clean(v) {
    v = String(v).replace(/,/g, '.').replace(/[^\d.]/g, '');
    var i = v.indexOf('.');
    if (i !== -1) v = v.slice(0, i + 1) + v.slice(i + 1).replace(/\./g, '');
    return v.slice(0, 7);
  }
  function el(tag, attrs, html) {
    var e = document.createElement(tag);
    if (attrs) for (var k in attrs) {
      if (k === 'class') e.className = attrs[k];
      else if (attrs[k] !== false && attrs[k] != null) e.setAttribute(k, attrs[k]);
    }
    if (html != null) e.innerHTML = html;
    return e;
  }
  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

  var ICON_X = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>';
  var ICON_PLUS = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>';

  /* ------------------------------------------------------------ state */
  var state = { mode: 'pct', rows: [newRow(), newRow(), newRow()], goal: 90 };

  function loadState() {
    var raw = null;
    try {
      var m = location.hash.match(/wgc=([^&]+)/);
      if (m) raw = decodeURIComponent(escape(atob(decodeURIComponent(m[1]))));
    } catch (e) { raw = null; }
    if (!raw) { try { raw = window.localStorage.getItem(STORE_KEY); } catch (e) { raw = null; } }
    if (!raw) return;
    try {
      var s = JSON.parse(raw);
      if (!s || !s.rows || !s.rows.length) return;
      state.mode = s.mode === 'pts' ? 'pts' : 'pct';
      state.goal = isFinite(s.goal) ? s.goal : 90;
      state.rows = s.rows.slice(0, 40).map(newRow);
    } catch (e) { /* ignore */ }
  }
  function serial() {
    return JSON.stringify({
      mode: state.mode, goal: state.goal,
      rows: state.rows.map(function (r) { return { name: r.name, grade: r.grade, earned: r.earned, possible: r.possible, weight: r.weight }; })
    });
  }
  var saveT;
  function save() {
    clearTimeout(saveT);
    saveT = setTimeout(function () { try { window.localStorage.setItem(STORE_KEY, serial()); } catch (e) { } }, 250);
  }

  /* ------------------------------------------------------------ math */
  function rowPct(r) {
    if (state.mode === 'pts') {
      var e = num(r.earned), p = num(r.possible);
      return (isFinite(e) && isFinite(p) && p > 0) ? e / p * 100 : NaN;
    }
    return num(r.grade);
  }
  function compute() {
    var enteredW = 0, gradedW = 0, sum = 0, graded = 0, plainSum = 0, anyWeight = false;
    state.rows.forEach(function (r) {
      var w = num(r.weight), g = rowPct(r);
      if (isFinite(w)) { enteredW += w; anyWeight = true; }
      if (isFinite(g)) {
        graded++; plainSum += g;
        if (isFinite(w) && w > 0) { gradedW += w; sum += g * w; }
      }
    });
    var res = { enteredW: enteredW, gradedW: gradedW, graded: graded, unweighted: false, current: NaN };
    if (gradedW > 0) res.current = sum / gradedW;
    else if (graded > 0 && !anyWeight) { res.current = plainSum / graded; res.unweighted = true; }
    res.remaining = Math.max(0, 100 - gradedW);
    res.complete = Math.abs(gradedW - 100) < 0.005;
    res.over = enteredW > 100.005;
    return res;
  }

  /* ------------------------------------------------------------ view */
  var root, wrap, rowsEl, headEl, meterEl, resEl, hintEl;

  function build() {
    root.innerHTML = '';
    root.classList.add('wgc-root');
    wrap = el('div', { class: 'wgc' + (state.mode === 'pts' ? ' is-points' : ''), role: 'form', 'aria-label': 'Weighted grade calculator' });

    var top = el('div', { class: 'wgc-top' });
    var seg = el('div', { class: 'wgc-seg', role: 'group', 'aria-label': 'Grade entry format' });
    [['pct', 'Percent'], ['pts', 'Points']].forEach(function (m) {
      var b = el('button', { type: 'button', 'aria-pressed': String(state.mode === m[0]), 'data-mode': m[0] }, m[1]);
      seg.appendChild(b);
    });
    seg.addEventListener('click', function (e) {
      var b = e.target.closest('button'); if (!b || b.dataset.mode === state.mode) return;
      // convert values so switching doesn't wipe the user's work
      state.rows.forEach(function (r) {
        if (b.dataset.mode === 'pts') { if (r.grade !== '' && r.earned === '') { r.earned = r.grade; r.possible = '100'; } }
        else { var p = rowPct(r); if (isFinite(p)) r.grade = fmt(p, 2); }
      });
      state.mode = b.dataset.mode; save(); build();
    });
    var reset = el('button', { type: 'button', class: 'wgc-link' }, 'Reset');
    reset.addEventListener('click', function () {
      state.rows = [newRow(), newRow(), newRow()];
      try { window.localStorage.removeItem(STORE_KEY); } catch (e) { }
      if (/wgc=/.test(location.hash)) history.replaceState(null, '', location.pathname + location.search);
      build(); focusRow(0);
    });
    top.appendChild(seg); top.appendChild(reset);

    var body = el('div', { class: 'wgc-body' });
    headEl = el('div', { class: 'wgc-head', 'aria-hidden': 'true' },
      '<div>Category or assignment</div><div style="text-align:right">' + (state.mode === 'pts' ? 'Points earned / possible' : 'Grade') + '</div><div style="text-align:right">Weight</div><div></div>');
    rowsEl = el('div', { class: 'wgc-rows' });
    state.rows.forEach(function (r, i) { rowsEl.appendChild(rowView(r, i)); });
    var add = el('button', { type: 'button', class: 'wgc-add' }, ICON_PLUS + 'Add category');
    add.addEventListener('click', function () { addRow(true); });
    body.appendChild(headEl); body.appendChild(rowsEl); body.appendChild(add);

    meterEl = el('div', { class: 'wgc-meter' });
    resEl = el('div', { class: 'wgc-res-slot', 'aria-live': 'polite' });
    hintEl = el('div', { class: 'wgc-hint' }, 'Enter a grade and its weight — your result appears here instantly.');

    wrap.appendChild(top); wrap.appendChild(body); wrap.appendChild(meterEl); wrap.appendChild(hintEl); wrap.appendChild(resEl);
    root.appendChild(wrap);
    update();
  }

  function numField(cls, key, r, label, ph, suffix, max) {
    var f = el('div', { class: 'wgc-field ' + cls });
    var id = 'wgc-' + key + '-' + r.id;
    f.appendChild(el('label', { class: 'wgc-lbl', for: id }, label));
    var inp = el('input', {
      id: id, class: 'wgc-in is-num', type: 'text', inputmode: 'decimal', autocomplete: 'off',
      placeholder: ph, 'aria-label': label, 'data-key': key, 'data-max': max
    });
    inp.value = r[key];
    f.appendChild(inp);
    if (suffix) f.appendChild(el('span', { class: 'wgc-suffix', 'aria-hidden': 'true' }, suffix));
    return f;
  }

  function rowView(r, i) {
    var row = el('div', { class: 'wgc-row', 'data-id': r.id });
    var nf = el('div', { class: 'wgc-field f-name' });
    var nid = 'wgc-name-' + r.id;
    nf.appendChild(el('label', { class: 'wgc-lbl', for: nid }, 'Category'));
    var ni = el('input', { id: nid, class: 'wgc-in', type: 'text', autocomplete: 'off', maxlength: '60',
      placeholder: 'e.g. ' + EXAMPLES[i % EXAMPLES.length][0], 'aria-label': 'Category name', 'data-key': 'name' });
    ni.value = r.name; nf.appendChild(ni);
    row.appendChild(nf);

    if (state.mode === 'pts') {
      var g = el('div', { class: 'wgc-field f-grade' });
      g.appendChild(el('label', { class: 'wgc-lbl' }, 'Points earned / possible'));
      var pts = el('div', { class: 'wgc-pts' });
      var a = el('input', { class: 'wgc-in is-num', type: 'text', inputmode: 'decimal', autocomplete: 'off', placeholder: '45', 'aria-label': 'Points earned', 'data-key': 'earned' });
      var b = el('input', { class: 'wgc-in is-num', type: 'text', inputmode: 'decimal', autocomplete: 'off', placeholder: '50', 'aria-label': 'Points possible', 'data-key': 'possible' });
      a.value = r.earned; b.value = r.possible;
      pts.appendChild(a); pts.appendChild(el('span', null, '/')); pts.appendChild(b);
      g.appendChild(pts); row.appendChild(g);
    } else {
      row.appendChild(numField('f-grade', 'grade', r, 'Grade', EXAMPLES[i % EXAMPLES.length][1], '%', 200));
    }
    row.appendChild(numField('f-weight', 'weight', r, 'Weight', EXAMPLES[i % EXAMPLES.length][2], '%', 100));

    var del = el('button', { type: 'button', class: 'wgc-del', 'aria-label': 'Remove row' }, ICON_X);
    del.disabled = state.rows.length <= 1;
    row.appendChild(del);
    return row;
  }

  function findRow(node) {
    var rowEl = node.closest('.wgc-row'); if (!rowEl) return null;
    var id = +rowEl.dataset.id;
    for (var i = 0; i < state.rows.length; i++) if (state.rows[i].id === id) return { row: state.rows[i], index: i, el: rowEl };
    return null;
  }

  function addRow(focus) {
    var r = newRow(); state.rows.push(r);
    rowsEl.appendChild(rowView(r, state.rows.length - 1));
    refreshDel(); save(); update();
    if (focus) focusRow(state.rows.length - 1);
  }
  function refreshDel() {
    var dels = rowsEl.querySelectorAll('.wgc-del');
    for (var i = 0; i < dels.length; i++) dels[i].disabled = state.rows.length <= 1;
  }
  function focusRow(i) {
    var rows = rowsEl.querySelectorAll('.wgc-row');
    if (rows[i]) rows[i].querySelector('input').focus();
  }

  function update() {
    var c = compute();

    // weight meter
    var w = c.enteredW;
    meterEl.className = 'wgc-meter' + (c.over ? ' is-over' : '');
    meterEl.innerHTML =
      '<div class="wgc-meter-row"><span>Weights entered</span><b>' + fmt(w, 2) + '% of 100%</b></div>' +
      '<div class="wgc-bar" role="progressbar" aria-label="Total weight" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' + fmt(Math.min(w, 100), 0) + '"><i style="width:' + Math.min(w, 100) + '%"></i></div>' +
      (c.over ? '<div class="wgc-note">Your weights add up to more than 100%. Double-check your syllabus — results are scaled to the weights you entered.</div>' : '');

    // results: not rendered at all until there is something to show
    if (!isFinite(c.current)) { resEl.innerHTML = ''; hintEl.style.display = ''; return; }
    hintEl.style.display = 'none';

    var L = letterFor(c.current);
    var basis;
    if (c.unweighted) basis = 'No weights entered, so this is a <b>simple average</b> of ' + c.graded + ' grade' + (c.graded > 1 ? 's' : '') + '. Add weights for a weighted result.';
    else if (c.complete) basis = 'This is your <b>final course grade</b> — graded work covers 100% of the course.';
    else if (c.gradedW > 100) basis = 'Based on <b>' + fmt(c.gradedW) + '%</b> of weight entered, scaled to 100%.';
    else basis = 'Your <b>current grade</b>, based on the ' + fmt(c.gradedW) + '% of the course graded so far.';

    var html =
      '<div class="wgc-res">' +
      '<div class="wgc-main">' +
      '<div class="wgc-score">' + fmt(c.current, 2) + '<small>%</small></div>' +
      '<div class="wgc-grade ' + gradeClass(L[0]) + '">' + L[0] + '<em>' + L[2].toFixed(1) + ' GPA</em></div>' +
      '</div>' +
      '<p class="wgc-sub">' + basis + '</p>';

    if (!c.unweighted && !c.complete && c.gradedW < 100) {
      var goal = state.goal;
      var need = (goal * 100 - c.current * c.gradedW) / c.remaining;
      var cls = '', ans;
      if (need <= 0) { cls = 'is-ok'; ans = 'You’ve already locked in <b>' + fmt(goal) + '%</b> — even a 0% on the remaining ' + fmt(c.remaining) + '% keeps you there.'; }
      else if (need > 100) { cls = 'is-bad'; ans = 'You’d need <strong>' + fmt(need, 1) + '%</strong> on the remaining ' + fmt(c.remaining) + '% — not possible without extra credit. The highest you can finish with is <b>' + fmt((c.current * c.gradedW + 100 * c.remaining) / 100, 1) + '%</b>.'; }
      else { ans = 'You need an average of <strong>' + fmt(need, 1) + '%</strong> on the remaining ' + fmt(c.remaining) + '% of the course.'; }
      html +=
        '<div class="wgc-goal">' +
        '<div class="wgc-goal-q"><span>What do I need to finish with</span><div class="wgc-chips">' +
        GOALS.map(function (g) { return '<button type="button" class="wgc-chip" data-goal="' + g[1] + '" aria-pressed="' + (goal === g[1]) + '">' + g[0] + '<span> (' + g[1] + '%)</span></button>'; }).join('') +
        '</div><div class="wgc-field"><input class="wgc-in is-num wgc-goal-in" type="text" inputmode="decimal" aria-label="Target grade percent" value="' + esc(fmt(goal)) + '"><span class="wgc-suffix">%</span></div><span>?</span></div>' +
        '<p class="wgc-goal-a ' + cls + '">' + ans + '</p>' +
        '</div>';
    }

    html +=
      '<div class="wgc-foot">' +
      '<details class="wgc-scale"><summary>Grade scale</summary><div class="wgc-scale-grid">' +
      SCALE.map(function (s) { return '<div' + (s[0] === L[0] ? ' class="is-cur"' : '') + '><span>' + s[0] + '</span><span>' + s[1] + '%+ · ' + s[2].toFixed(1) + '</span></div>'; }).join('') +
      '</div></details>' +
      '<button type="button" class="wgc-copy">Copy result link</button>' +
      '</div></div>';

    // preserve focus/caret if user is typing in the goal box
    var active = document.activeElement, goalFocused = active && active.classList && active.classList.contains('wgc-goal-in');
    var caret = goalFocused ? active.selectionStart : null;
    var scaleOpen = resEl.querySelector('.wgc-scale') && resEl.querySelector('.wgc-scale').open;
    resEl.innerHTML = html;
    if (scaleOpen) resEl.querySelector('.wgc-scale').open = true;
    if (goalFocused) {
      var gi = resEl.querySelector('.wgc-goal-in');
      if (gi) { gi.value = goalDraft; gi.focus(); try { gi.setSelectionRange(caret, caret); } catch (e) { } }
    }
  }

  var goalDraft = '';

  /* ------------------------------------------------------------ events (delegated) */
  function bind() {
    root.addEventListener('input', function (e) {
      var t = e.target;
      if (t.classList.contains('wgc-goal-in')) {
        var v = clean(t.value); t.value = v; goalDraft = v;
        var n = num(v);
        if (isFinite(n) && n <= 150) { state.goal = n; save(); update(); }
        return;
      }
      var hit = findRow(t); if (!hit) return;
      var key = t.dataset.key;
      if (key !== 'name') {
        var start = t.selectionStart, before = t.value.length;
        var v2 = clean(t.value);
        if (v2 !== t.value) { t.value = v2; try { t.setSelectionRange(start - (before - v2.length), start - (before - v2.length)); } catch (x) { } }
        var n2 = num(v2), max = +t.dataset.max || Infinity;
        t.setAttribute('aria-invalid', String(isFinite(n2) && n2 > max));
      }
      hit.row[key] = t.value;
      save(); update();
    });

    root.addEventListener('click', function (e) {
      var del = e.target.closest('.wgc-del');
      if (del) {
        var hit = findRow(del); if (!hit || state.rows.length <= 1) return;
        state.rows.splice(hit.index, 1); hit.el.remove(); refreshDel(); save(); update();
        focusRow(Math.min(hit.index, state.rows.length - 1));
        return;
      }
      var chip = e.target.closest('.wgc-chip');
      if (chip) { state.goal = +chip.dataset.goal; save(); update(); return; }
      var copy = e.target.closest('.wgc-copy');
      if (copy) {
        var url = location.origin + location.pathname + '#wgc=' + encodeURIComponent(btoa(unescape(encodeURIComponent(serial()))));
        var done = function () { copy.textContent = 'Link copied ✓'; setTimeout(function () { copy.textContent = 'Copy result link'; }, 1800); };
        if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(url).then(done, function () { prompt('Copy this link:', url); });
        else prompt('Copy this link:', url);
      }
    });

    root.addEventListener('keydown', function (e) {
      if (e.key !== 'Enter') return;
      var hit = findRow(e.target); if (!hit) return;
      e.preventDefault();
      if (hit.index === state.rows.length - 1) addRow(true); else focusRow(hit.index + 1);
    });

    root.addEventListener('focusin', function (e) {
      if (e.target.classList.contains('wgc-goal-in')) goalDraft = e.target.value;
    });
  }

  function mount() {
    root = document.getElementById('root');
    if (!root || root.dataset.wgc) return;
    root.dataset.wgc = '1';
    loadState();
    build(); bind();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount);
  else mount();
})();
