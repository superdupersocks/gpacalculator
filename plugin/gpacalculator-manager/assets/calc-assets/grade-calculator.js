/*! Grade Calculator v3 — gpacalculator.net
 * Drop-in replacement for calc-assets/grade-calculator.js. Mounts into #root.
 * Vanilla JS, no dependencies, wrapped in an IIFE (nothing leaks into window).
 * Flow: 1 Grades → 2 Your grade → 3 Final planner, with samples, autosave and named saves.
 */
(function () {
  'use strict';

  /* ------------------------------------------------------------ config */
  var SCALES = {
    std: { name: 'Standard (A = 93)', bands: [['A+', 97, 4.0], ['A', 93, 4.0], ['A-', 90, 3.7], ['B+', 87, 3.3], ['B', 83, 3.0], ['B-', 80, 2.7], ['C+', 77, 2.3], ['C', 73, 2.0], ['C-', 70, 1.7], ['D+', 67, 1.3], ['D', 65, 1.0], ['F', 0, 0.0]] },
    ten: { name: '10-point (A = 90)', bands: [['A', 90, 4.0], ['B', 80, 3.0], ['C', 70, 2.0], ['D', 60, 1.0], ['F', 0, 0.0]] },
    seven: { name: '7-point (A = 93)', bands: [['A', 93, 4.0], ['B', 85, 3.0], ['C', 77, 2.0], ['D', 70, 1.0], ['F', 0, 0.0]] }
  };
  var NAMES = ['Homework', 'Quizzes', 'Tests', 'Midterm', 'Final exam', 'Project', 'Essay', 'Labs', 'Participation', 'Attendance', 'Presentation', 'Discussion posts', 'Extra credit'];
  var PH = [['Homework', '95', '20'], ['Quizzes', '42/50', '15'], ['Midterm', 'B+', '25'], ['Project', '88', '15']];
  var SAMPLES = {
    weighted: { name: 'Sample: Biology 101', finalW: '25', target: 90,
      rows: [['Homework', '94', '20'], ['Quizzes', '42/50', '15'], ['Midterm', 'B', '25'], ['Lab reports', '88', '15']] },
    points: { name: 'Sample: History 210', finalW: '', finalPts: '100', target: 80,
      rows: [['Essay 1', '41/50', ''], ['Quiz 1', '18/20', ''], ['Midterm', '72/100', ''], ['Project', '45/50', '']] }
  };
  var K_DRAFT = 'gc:v3', K_SAVES = 'gc:saves', MAX_SAVES = 30;
  var returning = false, seen = {};
  // GA4 events (site already loads gtag). Each event fires at most once per page view unless `repeat`.
  function track(name, params, repeat) {
    if (!repeat) { if (seen[name]) return; seen[name] = 1; }
    try { if (typeof window.gtag === 'function') window.gtag('event', name, params || {}); } catch (e) { }
  }
  var uid = 0;

  /* ------------------------------------------------------------ helpers */
  function $(sel, ctx) { return (ctx || root).querySelector(sel); }
  function $$(sel, ctx) { return Array.prototype.slice.call((ctx || root).querySelectorAll(sel)); }
  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function num(v) { if (v === '' || v == null) return NaN; var n = parseFloat(String(v).replace(',', '.')); return isFinite(n) ? n : NaN; }
  function fmt(n, d) { d = d == null ? 2 : d; var p = Math.pow(10, d); return String(Math.round(n * p) / p); }
  function clamp(n, a, b) { return Math.max(a, Math.min(b, n)); }
  function newRow(r) { r = r || {}; return { id: ++uid, name: r.name || '', grade: r.grade || '', weight: r.weight || '' }; }
  function blankRows() { return [newRow(), newRow(), newRow()]; }
  function lsGet(k) { try { return window.localStorage.getItem(k); } catch (e) { return null; } }
  function lsSet(k, v) { try { window.localStorage.setItem(k, v); return true; } catch (e) { return false; } }
  function cleanNum(v) {
    v = String(v).replace(/,/g, '.').replace(/[^\d.]/g, '');
    var i = v.indexOf('.'); if (i !== -1) v = v.slice(0, i + 1) + v.slice(i + 1).replace(/\./g, '');
    return v.slice(0, 7);
  }
  function article(L) { return /^[AEF]/.test(L) ? 'an' : 'a'; }
  function niceDate(ts) { try { return new Date(ts).toLocaleDateString(undefined, { month: 'short', day: 'numeric' }); } catch (e) { return ''; } }

  /* ------------------------------------------------------------ state */
  function fresh() { return { name: '', scale: 'std', rows: blankRows(), finalW: '', finalPts: '', target: 90, whatIf: 85, step: 1, sample: false, saveId: null }; }
  var state = fresh();

  function bands() { return SCALES[state.scale].bands; }
  function letterFor(p) { var b = bands(); for (var i = 0; i < b.length; i++) if (p >= b[i][1] - 1e-9) return b[i]; return b[b.length - 1]; }
  function letterToPct(L) {
    var b = bands(), up = L.toUpperCase();
    for (var i = 0; i < b.length; i++) if (b[i][0] === up) { var lo = b[i][1], hi = i === 0 ? 100 : b[i - 1][1]; return up === 'F' ? 50 : (lo + hi) / 2; }
    if (up.length > 1) return letterToPct(up.charAt(0));
    return NaN;
  }
  function parseGrade(raw) {
    var s = String(raw || '').trim();
    if (!s) return null;
    var m = s.match(/^(\d+(?:[.,]\d+)?)\s*(?:\/|of|out of)\s*(\d+(?:[.,]\d+)?)$/i);
    if (m) { var e = num(m[1]), p = num(m[2]); return p > 0 ? { pct: e / p * 100, earned: e, possible: p, kind: 'pts' } : { err: 'Points possible must be above 0' }; }
    m = s.match(/^(\d+(?:[.,]\d+)?)\s*%?$/);
    if (m) { var v = num(m[1]); return v > 150 ? { err: 'Too high — try points like 45/50' } : { pct: v, kind: 'pct' }; }
    m = s.match(/^([A-DFa-df])\s*([+\-−])?$/);
    if (m) { var L = m[1].toUpperCase() + (m[2] ? m[2].replace('−', '-') : ''); var pc = letterToPct(L); if (isFinite(pc)) return { pct: pc, kind: 'letter' }; }
    return { err: 'Try 92, 45/50 or B+' };
  }

  function compute() {
    var rows = [], anyWeight = false, enteredW = 0;
    state.rows.forEach(function (r) {
      var g = parseGrade(r.grade), w = num(r.weight);
      if (isFinite(w)) { anyWeight = true; enteredW += w; }
      if (g && !g.err) rows.push({ r: r, g: g, w: w });
    });
    var res = { rows: rows, enteredW: enteredW, method: null, current: NaN, gradedW: 0, missingW: 0 };
    if (!rows.length) return res;
    if (anyWeight) {
      var sum = 0, W = 0;
      rows.forEach(function (x) { if (isFinite(x.w) && x.w > 0) { sum += x.g.pct * x.w; W += x.w; } else res.missingW++; });
      if (W > 0) { res.method = 'weighted'; res.current = sum / W; res.gradedW = W; }
      rows.forEach(function (x) { x.share = isFinite(x.w) && W > 0 ? x.w / W : 0; x.contrib = x.share * x.g.pct; });
    } else if (rows.every(function (x) { return x.g.kind === 'pts'; })) {
      var e = 0, p = 0; rows.forEach(function (x) { e += x.g.earned; p += x.g.possible; });
      res.method = 'points'; res.current = e / p * 100; res.earned = e; res.possible = p;
      rows.forEach(function (x) { x.share = x.g.possible / p; x.contrib = x.g.earned / p * 100; });
    } else {
      var t = 0; rows.forEach(function (x) { t += x.g.pct; });
      res.method = 'average'; res.current = t / rows.length;
      rows.forEach(function (x) { x.share = 1 / rows.length; x.contrib = x.g.pct / rows.length; });
    }
    return res;
  }
  // Final-exam model. Weighted/average classes: the final is a % of the grade.
  // Points classes: the final's points are added to the class total.
  // Either way: course grade = base + finalScore × k.
  function planModel(c) {
    if (!c || !isFinite(c.current)) return null;
    if (c.method === 'points') {
      var F = num(state.finalPts); if (!(F > 0)) return null;
      var T = c.possible + F;
      return { k: F / T, base: c.earned / T * 100, desc: fmt(F) + ' points' };
    }
    var f = num(state.finalW); if (!(f > 0 && f < 100)) return null;
    return { k: f / 100, base: c.current * (1 - f / 100), desc: fmt(f) + '% of the grade' };
  }
  function needOn(m, t) { return (t - m.base) / m.k; }
  function gradeWith(m, s) { return m.base + s * m.k; }
  function finalOK() { return !!planModel(compute()); }
  function isPtsMode() { return compute().method === 'points'; }

  /* ------------------------------------------------------------ persistence */
  function snapshot() {
    return { v: 3, name: state.name, scale: state.scale, finalW: state.finalW, finalPts: state.finalPts, target: state.target, whatIf: state.whatIf,
      rows: state.rows.map(function (r) { return { name: r.name, grade: r.grade, weight: r.weight }; }) };
  }
  function apply(s, keep) {
    state = fresh();
    state.name = s.name || ''; state.scale = SCALES[s.scale] ? s.scale : 'std';
    state.finalW = s.finalW || ''; state.finalPts = s.finalPts || ''; state.target = isFinite(s.target) ? s.target : 90; state.whatIf = isFinite(s.whatIf) ? s.whatIf : 85;
    state.rows = (s.rows && s.rows.length ? s.rows : [{}, {}, {}]).slice(0, 50).map(newRow);
    if (keep) { state.saveId = keep.saveId || null; state.sample = !!keep.sample; state.step = Math.min(keep.step || 1, 2); }
  }
  function loadInitial() {
    returning = !!lsGet(K_DRAFT) || getSaves().length > 0;
    var raw = null;
    try { var m = location.hash.match(/gc=([^&]+)/); if (m) raw = decodeURIComponent(escape(atob(decodeURIComponent(m[1])))); } catch (e) { raw = null; }
    var fromLink = !!raw;
    if (!raw) raw = lsGet(K_DRAFT);
    if (!raw) return;
    try {
      var s = JSON.parse(raw); if (!s || !s.rows) return;
      apply(s, { saveId: fromLink ? null : s.saveId, sample: !fromLink && s.sample });
    } catch (e) { }
  }
  var saveT = null;
  function flushDraft() { clearTimeout(saveT); saveT = null; var s = snapshot(); s.saveId = state.saveId; s.sample = state.sample; lsSet(K_DRAFT, JSON.stringify(s)); }
  function saveDraft() { clearTimeout(saveT); saveT = setTimeout(flushDraft, 300); }
  function getSaves() { try { var a = JSON.parse(lsGet(K_SAVES) || '[]'); return Array.isArray(a) ? a : []; } catch (e) { return []; } }
  function putSaves(a) { return lsSet(K_SAVES, JSON.stringify(a.slice(0, MAX_SAVES))); }
  function shareUrl() { return location.origin + location.pathname + '#gc=' + encodeURIComponent(btoa(unescape(encodeURIComponent(JSON.stringify(snapshot()))))); }

  /* ------------------------------------------------------------ icons */
  function svg(d, w) { return '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="' + (w || 2) + '" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + d + '</svg>'; }
  var IC = {
    x: svg('<path d="M18 6 6 18M6 6l12 12"/>'),
    plus: svg('<path d="M12 5v14M5 12h14"/>', 2.4),
    check: svg('<path d="M20 6 9 17l-5-5"/>', 3),
    arrow: svg('<path d="M5 12h14M13 6l6 6-6 6"/>', 2.4),
    back: svg('<path d="M19 12H5M11 18l-6-6 6-6"/>', 2.4),
    save: svg('<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><path d="M17 21v-8H7v8M7 3v5h8"/>'),
    folder: svg('<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>'),
    spark: svg('<path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8z"/>'),
    link: svg('<path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/>'),
    copy: svg('<rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v1"/>'),
    dl: svg('<path d="M12 3v12M7 10l5 5 5-5M5 21h14"/>')
  };

  /* ------------------------------------------------------------ shell */
  var root;

  function step(n, label) {
    return '<button type="button" class="gc-step gc-go" data-go="' + n + '"><span class="gc-dotn">' + n + '</span><span class="gc-stl">' + label + '</span></button>';
  }

  function build() {
    root.classList.add('gc-root');
    var scaleOpts = Object.keys(SCALES).map(function (k) { return '<option value="' + k + '">' + SCALES[k].name + '</option>'; }).join('');
    root.innerHTML =
      '<div class="gcx">' +
        '<header class="gc-app">' +
          '<input class="gc-title" type="text" maxlength="60" placeholder="Name this class (e.g. Biology 101)" aria-label="Class name">' +
          '<div class="gc-app-act">' +
            '<div class="gc-menuwrap"><button type="button" class="gc-ghost gc-classes" aria-haspopup="true" aria-expanded="false">' + IC.folder + '<span>My classes</span><b class="gc-count"></b></button>' +
              '<div class="gc-menu" hidden></div></div>' +
            '<button type="button" class="gc-ghost gc-save">' + IC.save + '<span>Save</span></button>' +
          '</div>' +
        '</header>' +
        '<nav class="gc-steps" aria-label="Steps">' + step(1, 'Your grade') + step(2, 'Final planner') + '</nav>' +
        '<div class="gc-banner" hidden></div>' +
        '<div class="gc-strip" hidden></div>' +

        /* ---------- step 1: grades + live result ---------- */
        '<section class="gc-panel" data-panel="1">' +
          '<div class="gc-empty">' +
            '<div class="gc-empty-t"><b>New here?</b> Load a sample class to see how it works, then clear it and add your own.</div>' +
            '<div class="gc-sbtns"><button type="button" class="gc-soft gc-sample" data-s="weighted">' + IC.spark + 'Try a weighted class</button>' +
            '<button type="button" class="gc-soft gc-sample" data-s="points">' + IC.spark + 'Try a points class</button></div>' +
          '</div>' +
          '<div class="gc-head" aria-hidden="true"><div>Assignment or category</div><div>Grade</div><div>Weight <em>optional</em></div><div></div></div>' +
          '<div class="gc-rows"></div>' +
          '<datalist id="gc-names">' + NAMES.map(function (n) { return '<option value="' + n + '">'; }).join('') + '</datalist>' +
          '<div class="gc-addbar"><div class="gc-addl"><button type="button" class="gc-add">' + IC.plus + 'Add row</button><button type="button" class="gc-link gc-sample gc-exlink" data-s="weighted" hidden>' + IC.spark + 'Show an example</button></div>' +
            '<label class="gc-scale"><span>Scale</span><select class="gc-scale-sel" aria-label="Grading scale">' + scaleOpts + '</select></label></div>' +
          '<div class="gc-meter" hidden></div>' +
          '<ul class="gc-howto">' +
            '<li><b>Grade:</b> type <code>92</code>, <code>45/50</code> or a letter like <code>B+</code>. Your grade appears below as you type.</li>' +
            '<li><b>Weight:</b> from your syllabus (e.g. Homework 20%). No weights? Leave it blank.</li>' +
            '<li>Press <b>Enter</b> to jump to the next row.</li>' +
          '</ul>' +
          '<div class="gc-live" hidden><button type="button" class="gc-toresult"><span class="gc-live-l">Your grade</span><b class="gc-live-v"></b><span class="gc-mini gc-live-m"></span><span class="gc-live-go">See breakdown ' + IC.arrow + '</span></button></div>' +
          '<div class="gc-result" hidden aria-live="polite">' +
            '<div class="gc-hero"><div class="gc-hero-top"><div class="gc-hero-l"><span class="gc-kicker"></span><div class="gc-score"></div><p class="gc-verdict"></p></div><div class="gc-ring"></div></div><div class="gc-track"></div></div>' +
            '<p class="gc-basis"></p>' +
            '<div class="gc-stack" aria-hidden="true"></div>' +
            '<div class="gc-cards"></div>' +
            '<h4 class="gc-h4">Breakdown</h4>' +
            '<div class="gc-break"></div>' +
            '<div class="gc-share">' +
              '<button type="button" class="gc-chipbtn gc-copylink">' + IC.link + '<span>Copy link</span></button>' +
              '<button type="button" class="gc-chipbtn gc-copytext">' + IC.copy + '<span>Copy summary</span></button>' +
              '<button type="button" class="gc-chipbtn gc-csv">' + IC.dl + '<span>Download CSV</span></button>' +
            '</div>' +
          '</div>' +
          '<div class="gc-foot"><button type="button" class="gc-link gc-reset">Start over</button>' +
            '<button type="button" class="gc-primary gc-go gc-next1" data-go="2" hidden>What do I need on the final? ' + IC.arrow + '</button></div>' +
        '</section>' +

        /* ---------- step 2: final planner ---------- */
        '<section class="gc-panel" data-panel="2" hidden>' +
          '<div class="gc-plan-in">' +
            '<label class="gc-field gc-fw"><span class="gc-flbl">My final (or next big test) is worth</span><input class="gc-in is-num gc-finalw" type="text" inputmode="decimal" placeholder="e.g. 20" aria-label="Final exam weight percent"><span class="gc-suffix gc-fw-suf">%</span></label>' +
            '<div class="gc-goal"><span class="gc-flbl">I want to finish with</span><div class="gc-goalrow"><div class="gc-chips"></div>' +
            '<label class="gc-field gc-tg"><input class="gc-in is-num gc-target" type="text" inputmode="decimal" aria-label="Target course grade percent"><span class="gc-suffix">%</span></label></div></div>' +
          '</div>' +
          '<p class="gc-note gc-plannote"></p>' +
          '<div class="gc-plan-out"></div>' +
          '<div class="gc-foot"><button type="button" class="gc-link gc-go" data-go="1">' + IC.back + 'Back to my grades</button>' +
            '<button type="button" class="gc-primary gc-save2">' + IC.save + 'Save this class</button></div>' +
        '</section>' +
        '<div class="gc-toast" role="status" aria-live="polite"></div>' +
      '</div>';
    render();
  }

  /* ------------------------------------------------------------ render */
  function render() {
    var sc = $('.gc-score'); if (sc) delete sc.dataset.shown;
    $('.gc-title').value = state.name;
    $('.gc-scale-sel').value = state.scale;
    fwMode = null;
    $('.gc-target').value = fmt(state.target);
    var rowsEl = $('.gc-rows'); rowsEl.innerHTML = '';
    state.rows.forEach(function (r, i) { rowsEl.appendChild(rowView(r, i)); });
    refreshDel(); renderChips(); renderCount(); update();
  }

  function rowView(r, i) {
    var ph = PH[i % PH.length];
    var row = document.createElement('div');
    row.className = 'gc-row'; row.setAttribute('data-id', r.id);
    row.innerHTML =
      '<div class="gc-field f-name"><input class="gc-in" type="text" list="gc-names" autocomplete="off" maxlength="60" data-k="name" aria-label="Assignment or category" placeholder="e.g. ' + ph[0] + '"></div>' +
      '<div class="gc-field f-grade"><span class="gc-ml">Grade</span><input class="gc-in is-num" type="text" autocomplete="off" data-k="grade" aria-label="Grade: percent, points like 45/50, or letter" placeholder="' + ph[1] + '"><span class="gc-conv"></span></div>' +
      '<div class="gc-field f-weight"><span class="gc-ml">Weight</span><input class="gc-in is-num" type="text" inputmode="decimal" autocomplete="off" data-k="weight" aria-label="Weight percent (optional)" placeholder="' + ph[2] + '"><span class="gc-suffix">%</span></div>' +
      '<button type="button" class="gc-del" aria-label="Remove row">' + IC.x + '</button>';
    row.querySelector('[data-k=name]').value = r.name;
    row.querySelector('[data-k=grade]').value = r.grade;
    row.querySelector('[data-k=weight]').value = r.weight;
    paintConv(row, r);
    return row;
  }
  function paintConv(rowEl, r) {
    var g = parseGrade(r.grade), c = rowEl.querySelector('.gc-conv'), inp = rowEl.querySelector('[data-k=grade]');
    if (!g) { c.textContent = ''; c.className = 'gc-conv'; inp.removeAttribute('aria-invalid'); return; }
    if (g.err) { c.textContent = g.err; c.className = 'gc-conv is-err'; inp.setAttribute('aria-invalid', 'true'); return; }
    inp.removeAttribute('aria-invalid'); c.className = 'gc-conv';
    c.textContent = g.kind === 'pct' ? '' : '= ' + fmt(g.pct, 1) + '%';
  }
  function refreshDel() { $$('.gc-del').forEach(function (d) { d.disabled = state.rows.length <= 1; }); }
  function renderChips() {
    var all = bands().filter(function (b) { return b[0] !== 'F' && b[0] !== 'A+'; });
    var pass = all[all.length - 1], opts = all.slice(0, 4);
    if (opts.indexOf(pass) === -1) opts.push(pass);
    $('.gc-chips').innerHTML = opts.map(function (b) {
      return '<button type="button" class="gc-chip" data-goal="' + b[1] + '" aria-pressed="' + (Math.abs(state.target - b[1]) < 1e-9) + '">' + (b === pass ? 'Pass' : b[0]) + '<small>' + b[1] + '%</small></button>';
    }).join('');
  }
  function renderCount() { var n = getSaves().length; $('.gc-count').textContent = n ? String(n) : ''; }

  function update() {
    var c = compute(), has = isFinite(c.current);
    if (!has && state.step > 1) state.step = 1;
    var planned = has && finalOK();

    $$('.gc-step').forEach(function (b) {
      var n = +b.dataset.go, done = n === 1 ? has : planned;
      var on = n === state.step;
      b.classList.toggle('is-on', on);
      b.classList.toggle('is-done', done && !on);
      b.disabled = n > 1 && !has;
      b.querySelector('.gc-dotn').innerHTML = done && !on ? IC.check : String(n);
      if (on) b.setAttribute('aria-current', 'step'); else b.removeAttribute('aria-current');
    });
    $$('.gc-panel').forEach(function (p) { p.hidden = +p.dataset.panel !== state.step; });

    var anyInput = state.rows.some(function (r) { return r.name || r.grade || r.weight; });
    $('.gc-empty').hidden = anyInput || returning;
    $('.gc-exlink').hidden = anyInput || !returning;
    var ban = $('.gc-banner');
    if (state.sample) {
      ban.hidden = false;
      ban.innerHTML = '<span class="gc-ban-t">' + IC.spark + '<span>You\'re viewing <b>sample data</b>. Walk through the steps, then enter your own grades.</span></span><button type="button" class="gc-link gc-clear-sample">Clear sample</button>';
    } else ban.hidden = true;

    var strip = $('.gc-strip');
    if (has && state.step === 2) {
      var L0 = letterFor(c.current), partial0 = c.method === 'weighted' && c.gradedW < 99.995;
      strip.hidden = false;
      strip.innerHTML = '<span class="gc-strip-l">' + (partial0 ? 'Current grade' : 'Your grade') + '</span>' +
        '<b class="gc-strip-v">' + fmt(c.current, 2) + '%</b><span class="gc-mini gc-g-' + L0[0].charAt(0).toLowerCase() + '">' + L0[0] + '</span>' +
        '';
    } else strip.hidden = true;

    $('.gc-next1').hidden = !has; $('.gc-result').hidden = !has; $('.gc-howto').hidden = has;
    if (has) track('gc_result', { method: c.method });
    var live = $('.gc-live'); live.hidden = !has;
    if (has) { var Lv = letterFor(c.current); $('.gc-live-l').textContent = c.method === 'weighted' && c.gradedW < 99.995 ? 'Current grade' : 'Your grade';
      $('.gc-live-v').textContent = fmt(c.current, 2) + '%'; var m = $('.gc-live-m'); m.textContent = Lv[0]; m.className = 'gc-mini gc-live-m gc-g-' + Lv[0].charAt(0).toLowerCase(); }
    paintLive();
    renderMeter(c);
    if (has) { renderResult(c); renderPlan(c); }
  }

  function renderMeter(c) {
    var m = $('.gc-meter');
    if (!(c.enteredW > 0)) { m.hidden = true; return; }
    var over = c.enteredW > 100.005;
    m.hidden = false; m.className = 'gc-meter' + (over ? ' is-over' : '');
    m.innerHTML = '<div class="gc-meter-row"><span>Weights entered</span><b>' + fmt(c.enteredW) + '% of 100%</b></div>' +
      '<div class="gc-bar"><i style="width:' + Math.min(c.enteredW, 100) + '%"></i></div>' +
      (over ? '<p class="gc-warn">Weights add up to more than 100%. Check your syllabus — results are scaled to what you entered.</p>' : '') +
      (c.missingW ? '<p class="gc-warn">' + c.missingW + ' graded row' + (c.missingW > 1 ? 's have' : ' has') + ' no weight, so ' + (c.missingW > 1 ? 'they\'re' : 'it\'s') + ' not counted. Add a weight, or clear every weight for a simple average.</p>' : '');
  }

  var COLORS = ['#7c3aed', '#4f46e5', '#0ea5e9', '#14b8a6', '#f59e0b', '#ec4899', '#84cc16', '#64748b'];
  function nextBand(p) { var b = bands(); for (var i = b.length - 1; i >= 0; i--) if (b[i][1] > p + 1e-9 && b[i][0] !== 'A+') return b[i]; return null; }
  function rowLabel(x) { return esc(x.r.name || 'Row ' + (state.rows.indexOf(x.r) + 1)); }

  var RING_C = { a: '#16a34a', b: '#2563eb', c: '#d97706', d: '#ea580c', f: '#dc2626' };
  function renderRing(p, L) {
    var k = L[0].charAt(0).toLowerCase(), r = 42, C = 2 * Math.PI * r, v = clamp(p, 0, 100) / 100;
    $('.gc-ring').className = 'gc-ring gc-g-' + k;
    $('.gc-ring').innerHTML = '<svg viewBox="0 0 100 100" aria-hidden="true"><circle cx="50" cy="50" r="' + r + '" class="gc-ring-bg"/>' +
      '<circle cx="50" cy="50" r="' + r + '" class="gc-ring-fg" stroke="' + RING_C[k] + '" stroke-dasharray="' + (C * v).toFixed(1) + ' ' + C.toFixed(1) + '"/></svg>' +
      '<div class="gc-ring-in"><b>' + L[0] + '</b><span>' + L[2].toFixed(1) + ' GPA</span></div>';
  }
  function renderTrack(p) {
    // letter families (A, B, C, D, F) laid out on a 50–100 track so the useful range isn't squashed
    var fam = [], b = bands();
    b.forEach(function (x, i) { var f = x[0].charAt(0), hi = i === 0 ? 100 : b[i - 1][1]; var last = fam[fam.length - 1];
      if (last && last.f === f) last.lo = x[1]; else fam.push({ f: f, lo: x[1], hi: hi }); });
    var MIN = 50, span = 50, pos = function (v) { return (clamp(v, MIN, 100) - MIN) / span * 100; };
    $('.gc-track').innerHTML = '<div class="gc-track-bar">' + fam.map(function (s) {
      var w = pos(s.hi) - pos(Math.max(s.lo, MIN)); if (w <= 0) return '';
      return '<i class="gc-tk-' + s.f.toLowerCase() + '" style="width:' + w + '%"><em>' + s.f + '</em></i>';
    }).reverse().join('') + '<span class="gc-track-pin" style="left:' + pos(p) + '%"><b>You</b></span></div>';
  }
  var countT, heroInView = true;
  function paintLive() { var l = root && $('.gc-live'); if (l) l.classList.toggle('is-off', heroInView || state.step !== 1); }
  function countUp(elm, to) {
    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var show = function (v) { elm.innerHTML = fmt(v, 2) + '<small>%</small>'; };
    cancelAnimationFrame(countT);
    if (reduce || elm.dataset.shown) { show(to); elm.dataset.shown = String(to); return; }
    var from = 0;
    elm.dataset.shown = String(to);
    var t0 = performance.now(), d = 550;
    (function tick(now) { var k = Math.min(1, (now - t0) / d), e = 1 - Math.pow(1 - k, 3); show(from + (to - from) * e); if (k < 1) countT = requestAnimationFrame(tick); })(t0);
  }
  function renderResult(c) {
    var L = letterFor(c.current), partial = c.method === 'weighted' && c.gradedW < 99.995;
    $('.gc-kicker').textContent = partial ? 'Current grade' : c.method === 'weighted' ? 'Final grade' : 'Your grade';
    countUp($('.gc-score'), c.current);
    renderRing(c.current, L);
    renderTrack(c.current);
    var b = bands(), passing = c.current >= b[b.length - 2][1];
    var passLine = b[b.length - 2][1], atRisk = c.current < passLine + 5;
    $('.gc-verdict').innerHTML = (partial ? 'Right now you\'re ' : 'You\'re ') + (passing ? '<b>passing</b>' : '<b>below passing</b>') + ' with ' + article(L[0]) + ' <b>' + L[0] + '</b>.' +
      (atRisk ? (passing ? ' You\'re close to the line, so let\'s make sure you stay above it.' : ' Let\'s work out exactly what it takes to pass.') : '');
    var go3 = $('.gc-next1');
    go3.innerHTML = (atRisk ? 'What do I need to pass? ' : 'What do I need on the final? ') + IC.arrow;
    go3.dataset.pass = atRisk ? String(passLine) : '';

    var basis;
    if (c.method === 'weighted') basis = partial ? 'Weighted grade from the <b>' + fmt(c.gradedW) + '%</b> of the course graded so far.' : c.gradedW > 100 ? 'Weighted grade, scaled from ' + fmt(c.gradedW) + '% of weight.' : 'Final weighted grade — your weights cover the whole course.';
    else if (c.method === 'points') basis = 'Total points: <b>' + fmt(c.earned) + ' of ' + fmt(c.possible) + '</b>. Bigger assignments count for more automatically.';
    else basis = 'Simple average of ' + c.rows.length + ' grade' + (c.rows.length > 1 ? 's' : '') + ' — each counts equally. Add weights if your class uses categories.';
    $('.gc-basis').innerHTML = basis;

    var rows = c.rows.filter(function (x) { return x.share > 0; });
    $('.gc-stack').innerHTML = rows.map(function (x, i) {
      return '<i style="flex:' + x.share + ';background:' + COLORS[i % COLORS.length] + '" title="' + rowLabel(x) + '"></i>';
    }).join('');

    var cards = [], up = nextBand(c.current), best = null;
    if (up) cards.push(['Next letter', fmt(up[1] - c.current, 1) + '% to ' + article(up[0]) + ' ' + up[0], 'You need ' + fmt(up[1], 0) + '% overall.']);
    else cards.push(['Top of the scale', 'Keep it up', 'You\'re in the highest letter band.']);
    rows.forEach(function (x) { var gain = x.share * Math.max(0, 100 - x.g.pct); if (!best || gain > best.gain) best = { x: x, gain: gain }; });
    if (best && rows.length > 1 && best.gain > 0.05) cards.push(['Biggest lever', rowLabel(best.x), '+10 points here adds ' + fmt(best.x.share * 10, 2) + '% to your grade.']);
    if (partial) cards.push(['Still to come', fmt(100 - c.gradedW) + '% of the course', 'Your grade can still move. Plan your final next.']);
    else if (c.method !== 'weighted') cards.push(['Tip', 'Add weights', 'If your syllabus uses categories, weights make this exact.']);
    $('.gc-cards').innerHTML = cards.map(function (k) { return '<div class="gc-card"><span>' + k[0] + '</span><p>' + k[1] + '</p><small>' + k[2] + '</small></div>'; }).join('');

    $('.gc-break').innerHTML = '<div class="gc-tblwrap"><table class="gc-tbl"><thead><tr><th>Item</th><th>Grade</th><th>Counts for</th><th>Adds</th></tr></thead><tbody>' +
      rows.map(function (x, i) {
        return '<tr><td><span class="gc-dot" style="background:' + COLORS[i % COLORS.length] + '"></span>' + rowLabel(x) + '</td>' +
          '<td>' + fmt(x.g.pct, 1) + '%</td><td>' + fmt(x.share * 100, 1) + '%</td><td>' + fmt(x.contrib, 2) + ' pts</td></tr>';
      }).join('') + '</tbody></table></div>';
  }

  function wiText(g, L) { return 'You\'d finish the class with <b>' + fmt(g, 1) + '%</b> — ' + article(L[0]) + ' <b>' + L[0] + '</b>.'; }

  var fwMode = null;
  function syncFinalField(c) {
    var pts = c.method === 'points', mode = pts ? 'pts' : 'pct', inp = $('.gc-finalw');
    if (fwMode !== mode) {
      fwMode = mode;
      inp.value = pts ? state.finalPts : state.finalW;
      inp.placeholder = pts ? 'e.g. 100' : 'e.g. 20';
      inp.setAttribute('aria-label', pts ? 'Final exam points possible' : 'Final exam weight percent');
      $('.gc-fw-suf').textContent = pts ? 'pts' : '%';
      inp.classList.toggle('is-pts', pts);
      $('.gc-plannote').textContent = pts
        ? 'Points class: your final\'s points are added to the class total, so a bigger final counts for more.'
        : 'Assumes your current grade covers everything except the final. The final\'s weight is in your syllabus.';
    }
  }

  function renderPlan(c) {
    var out = $('.gc-plan-out');
    syncFinalField(c);
    var m = planModel(c);
    if (!m) {
      var pts = c.method === 'points', raw = num(pts ? state.finalPts : state.finalW);
      var rem = c.method === 'weighted' && c.gradedW < 99.995 ? 100 - c.gradedW : 0;
      var msg = pts
        ? (isFinite(raw) ? 'Points need to be more than 0.' : 'Enter how many points your final is worth (e.g. 100) to see the exact score you need.')
        : (isFinite(raw) ? 'The weight needs to be between 0% and 100%.' : 'Enter how much your final is worth to see the exact score you need. It\'s usually 15–40% and listed in your syllabus.');
      out.innerHTML = '<div class="gc-placeholder">' + msg +
        (rem > 0 && rem < 100 ? '<button type="button" class="gc-soft gc-userem" data-w="' + fmt(rem) + '">Use the ungraded ' + fmt(rem) + '% of my course</button>' : '') + '</div>';
      return;
    }
    var t = state.target, n = needOn(m, t);
    if (state.step === 2) track('gc_plan', { method: c.method });
    var lv = n <= 0 ? ['ok', 'Already secured'] : n <= 70 ? ['ok', 'Comfortable'] : n <= 85 ? ['mid', 'Doable'] : n <= 100 ? ['hard', 'Stretch'] : ['bad', 'Out of reach'];
    var bb = bands(), isPass = Math.abs(t - bb[bb.length - 2][1]) < 1e-9;
    var tL = letterFor(t)[0], goalTxt = isPass ? 'pass the class (' + fmt(t, 1) + '%)' : 'finish with ' + fmt(t, 1) + '% (' + tL + ')';
    var head = n <= 0 ? 'You\'ll ' + goalTxt + ' even with a 0 on the final.'
      : n > 100 ? 'To ' + goalTxt + ' you\'d need ' + fmt(n, 1) + '% on the final — not possible without extra credit.'
      : 'Score at least this on the final to <b>' + goalTxt + '</b>.';
    if (c.method === 'points' && n > 0 && n <= 100) head += ' That\'s about ' + fmt(n / 100 * num(state.finalPts), 1) + ' of ' + fmt(num(state.finalPts)) + ' points.';
    var ladder = bands().filter(function (x) { return x[0] !== 'F'; }).map(function (x) {
      var v = needOn(m, x[1]), txt = v <= 0 ? 'secured' : v > 100 ? '—' : fmt(v, 1) + '%';
      return '<li class="' + (v <= 0 ? 'is-ok' : v > 100 ? 'is-bad' : '') + (Math.abs(x[1] - t) < 1e-9 ? ' is-cur' : '') + '"' + (v > 100 ? ' title="Needs over 100% on the final"' : '') + '><span>' + x[0] + '</span><b>' + txt + '</b></li>';
    }).join('');
    var wi = clamp(state.whatIf, 0, 100), g = gradeWith(m, wi);
    out.innerHTML =
      '<div class="gc-need gc-lv-' + lv[0] + '"><div class="gc-need-n">' + (n <= 0 ? '✓' : n > 100 ? fmt(n, 0) + '<small>%</small>' : fmt(n, 1) + '<small>%</small>') + '</div>' +
        '<div class="gc-need-r"><p>' + head + '</p><div class="gc-diff"><div class="gc-diff-bar"><i style="width:' + clamp(n, 3, 100) + '%"></i></div><span>' + lv[1] + '</span></div></div></div>' +
      '<div class="gc-sub2"><span class="gc-flbl">Needed on the final for each grade <em>(— means out of reach)</em></span><ul class="gc-ladder">' + ladder + '</ul></div>' +
      '<div class="gc-whatif"><label class="gc-flbl" for="gc-wi">What if I score <b class="gc-wi-v">' + fmt(wi, 0) + '%</b> on the final? <em>Already took it? Slide to your score.</em></label>' +
        '<input id="gc-wi" class="gc-range" type="range" min="0" max="100" step="1" value="' + wi + '">' +
        '<p class="gc-wi-out">' + wiText(g, letterFor(g)) + '</p>' +
        '<p class="gc-note">Your final grade will land between <b>' + fmt(gradeWith(m, 0), 1) + '%</b> (0 on the final) and <b>' + fmt(gradeWith(m, 100), 1) + '%</b> (100 on the final).</p></div>' +
      '<div class="gc-next"><span>Keep going</span><a href="https://gpacalculator.net/">Turn your grades into a GPA</a><a href="https://gpacalculator.net/how-to-raise-gpa/">Raise your GPA calculator</a></div>';
  }

  /* ------------------------------------------------------------ saves */
  function openMenu(open) {
    var m = $('.gc-menu'), b = $('.gc-classes');
    if (open === undefined) open = m.hidden;
    m.hidden = !open; b.setAttribute('aria-expanded', String(open));
    if (!open) return;
    var saves = getSaves();
    m.innerHTML = '<div class="gc-menu-h">My saved classes <small>on this device</small></div>' +
      (saves.length ? saves.map(function (s) {
        return '<div class="gc-mi' + (s.id === state.saveId ? ' is-cur' : '') + '"><button type="button" class="gc-mi-open" data-id="' + esc(s.id) + '"><b>' + esc(s.name || 'Untitled class') + '</b><small>' +
          (s.grade != null && isFinite(s.grade) ? fmt(s.grade, 1) + '% · ' : '') + 'saved ' + niceDate(s.savedAt) + '</small></button>' +
          '<button type="button" class="gc-mi-del" data-id="' + esc(s.id) + '" aria-label="Delete ' + esc(s.name || 'class') + '">' + IC.x + '</button></div>';
      }).join('') : '<p class="gc-menu-empty">Nothing saved yet. Press <b>Save</b> to keep this class and pick up where you left off.</p>') +
      '<button type="button" class="gc-mi-new">' + IC.plus + 'New class</button>';
  }
  function doSave() {
    if (state.sample) { toast('This is sample data — clear it and enter your own grades to save.'); return; }
    if (!state.rows.some(function (r) { return r.name || r.grade; })) { toast('Add a grade first, then save.'); return; }
    if (!state.name.trim()) { state.name = 'My class (' + niceDate(Date.now()) + ')'; $('.gc-title').value = state.name; }
    var saves = getSaves(), c = compute();
    var rec = { id: state.saveId || String(Date.now()), name: state.name.trim(), savedAt: Date.now(), grade: isFinite(c.current) ? c.current : null, data: snapshot() };
    saves = saves.filter(function (s) { return s.id !== rec.id; });
    saves.unshift(rec);
    if (!putSaves(saves)) { toast('Couldn\'t save — your browser is blocking storage.'); return; }
    state.saveId = rec.id; flushDraft(); renderCount();
    track('gc_save', { rows: state.rows.length }, true);
    toast('Saved “' + rec.name + '”. Find it anytime under My classes.');
  }
  var toastT;
  function toast(msg) { var t = $('.gc-toast'); t.textContent = msg; t.classList.add('is-on'); clearTimeout(toastT); toastT = setTimeout(function () { t.classList.remove('is-on'); }, 3000); }

  /* ------------------------------------------------------------ export */
  function summaryText() {
    var c = compute(); if (!isFinite(c.current)) return '';
    var L = letterFor(c.current), lines = [(state.name ? state.name + ' — ' : '') + 'My grade: ' + fmt(c.current, 2) + '% (' + L[0] + ')'];
    c.rows.forEach(function (x) { lines.push('• ' + (x.r.name || 'Item') + ': ' + fmt(x.g.pct, 1) + '%' + (isFinite(x.w) ? ' (weight ' + x.w + '%)' : '')); });
    var pm = planModel(c); if (pm) lines.push('Need ' + fmt(needOn(pm, state.target), 1) + '% on the final (worth ' + pm.desc + ') to finish with ' + fmt(state.target, 1) + '%');
    lines.push('Calculated at ' + location.origin + location.pathname);
    return lines.join('\n');
  }
  function csv() {
    var c = compute(), q = function (v) { return '"' + String(v).replace(/"/g, '""') + '"'; };
    var out = [['Item', 'Grade entered', 'Grade %', 'Weight %', 'Counts for %', 'Adds (pts)'].map(q).join(',')];
    c.rows.forEach(function (x) { out.push([x.r.name || 'Item', x.r.grade, fmt(x.g.pct, 2), isFinite(x.w) ? x.w : '', fmt(x.share * 100, 2), fmt(x.contrib, 2)].map(q).join(',')); });
    if (isFinite(c.current)) out.push(['Overall', '', fmt(c.current, 2), '', '', letterFor(c.current)[0]].map(q).join(','));
    var a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([out.join('\n')], { type: 'text/csv' }));
    a.download = (state.name ? state.name.replace(/[^\w\- ]+/g, '').trim().replace(/\s+/g, '-') : 'grades') + '-' + new Date().toISOString().slice(0, 10) + '.csv';
    document.body.appendChild(a); a.click(); a.remove(); setTimeout(function () { URL.revokeObjectURL(a.href); }, 1000);
  }
  function copy(text, okMsg) {
    if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(text).then(function () { toast(okMsg); }, function () { window.prompt('Copy this:', text); });
    else window.prompt('Copy this:', text);
  }

  /* ------------------------------------------------------------ actions */
  function findRow(node) {
    var r = node.closest('.gc-row'); if (!r) return null;
    var id = +r.dataset.id;
    for (var i = 0; i < state.rows.length; i++) if (state.rows[i].id === id) return { row: state.rows[i], i: i, el: r };
    return null;
  }
  function addRow(focus) {
    var r = newRow(); state.rows.push(r);
    var rowsEl = $('.gc-rows'); rowsEl.appendChild(rowView(r, state.rows.length - 1));
    refreshDel(); saveDraft(); update();
    if (focus) rowsEl.lastChild.querySelector('input').focus();
  }
  function goStep(n) {
    if (n > 1 && !isFinite(compute().current)) return;
    state.step = n; update(); saveDraft();
    track('gc_step_' + n, { sample: state.sample });
    var top = root.getBoundingClientRect().top;
    if (top < -20 || top > window.innerHeight * 0.6) root.scrollIntoView({ behavior: 'smooth', block: 'start' });
    if (n === 2 && !finalOK()) setTimeout(function () {
      var ae = document.activeElement; if (ae && /INPUT|SELECT|TEXTAREA/.test(ae.tagName)) return; // never steal focus from a field the user picked
      try { $('.gc-finalw').focus({ preventScroll: true }); } catch (e) { $('.gc-finalw').focus(); }
    }, 60);
  }
  function loadSample(key) {
    var s = SAMPLES[key];
    apply({ name: s.name, scale: 'std', finalW: s.finalW, finalPts: s.finalPts || '', target: s.target, whatIf: 85,
      rows: s.rows.map(function (r) { return { name: r[0], grade: r[1], weight: r[2] }; }) }, { sample: true, step: 1 });
    track('gc_sample', { kind: key }, true);
    render(); flushDraft(); toast('Sample loaded — the grade is below. Then try the final planner.');
  }
  function startOver() {
    state = fresh();
    if (/gc=/.test(location.hash)) history.replaceState(null, '', location.pathname + location.search);
    render(); flushDraft();
    $('.gc-rows input').focus();
  }

  function bind() {
    root.addEventListener('input', function (e) {
      var t = e.target;
      if (t.classList.contains('gc-title')) { state.name = t.value; saveDraft(); return; }
      if (t.classList.contains('gc-finalw')) { t.value = cleanNum(t.value); if (isPtsMode()) state.finalPts = t.value; else state.finalW = t.value; saveDraft(); update(); return; }
      if (t.classList.contains('gc-target')) {
        t.value = cleanNum(t.value); var v = num(t.value);
        if (isFinite(v) && v <= 150) { state.targetTouched = true; state.target = v; renderChips(); saveDraft(); update(); }
        return;
      }
      if (t.classList.contains('gc-range')) {
        state.whatIf = +t.value; saveDraft();
        var m = planModel(compute()); if (!m) return; var g = gradeWith(m, state.whatIf);
        $('.gc-wi-v').textContent = state.whatIf + '%'; $('.gc-wi-out').innerHTML = wiText(g, letterFor(g));
        return;
      }
      var hit = findRow(t); if (!hit) return;
      var key = t.dataset.k;
      if (key === 'weight') t.value = cleanNum(t.value);
      hit.row[key] = t.value; state.sample = false;
      if (key === 'grade') paintConv(hit.el, hit.row);
      saveDraft(); update();
    });

    root.addEventListener('change', function (e) {
      if (e.target.classList.contains('gc-scale-sel')) {
        state.scale = e.target.value;
        $$('.gc-row').forEach(function (rEl) { var h = findRow(rEl); if (h) paintConv(rEl, h.row); });
        renderChips(); saveDraft(); update();
      }
    });

    root.addEventListener('click', function (e) {
      var t = e.target, b;
      if (!t.closest('.gc-menuwrap') && !$('.gc-menu').hidden) openMenu(false);
      if ((b = t.closest('.gc-go'))) {
        if (b.disabled) return;
        if (b.dataset.pass && !state.targetTouched) { state.target = +b.dataset.pass; $('.gc-target').value = fmt(state.target); renderChips(); }
        goStep(+b.dataset.go); return;
      }
      if ((b = t.closest('.gc-del'))) {
        var h = findRow(b); if (!h || state.rows.length <= 1) return;
        state.rows.splice(h.i, 1); h.el.remove(); state.sample = false; refreshDel(); saveDraft(); update();
        var nx = $('.gc-rows').children[Math.min(h.i, state.rows.length - 1)]; if (nx) nx.querySelector('input').focus();
        return;
      }
      if (t.closest('.gc-toresult')) { $('.gc-result').scrollIntoView({ behavior: 'smooth', block: 'start' }); return; }
      if (t.closest('.gc-add')) { addRow(true); return; }
      if ((b = t.closest('.gc-userem'))) { state.finalW = b.dataset.w; $('.gc-finalw').value = state.finalW; saveDraft(); update(); return; }
      if ((b = t.closest('.gc-sample'))) { loadSample(b.dataset.s); return; }
      if (t.closest('.gc-clear-sample') || t.closest('.gc-reset')) { startOver(); return; }
      if ((b = t.closest('.gc-chip'))) { state.targetTouched = true; state.target = +b.dataset.goal; $('.gc-target').value = fmt(state.target); renderChips(); saveDraft(); update(); return; }
      if (t.closest('.gc-save') || t.closest('.gc-save2')) { doSave(); return; }
      if (t.closest('.gc-classes')) { openMenu(); return; }
      if ((b = t.closest('.gc-mi-open'))) {
        track('gc_open_saved', {}, true);
        var s = getSaves().filter(function (x) { return x.id === b.dataset.id; })[0];
        if (s) {
          apply(s.data, { saveId: s.id, step: 1 }); render(); flushDraft(); openMenu(false);
          toast('Opened “' + (s.name || 'class') + '”.');
        }
        return;
      }
      if ((b = t.closest('.gc-mi-del'))) {
        var id = b.dataset.id;
        putSaves(getSaves().filter(function (x) { return x.id !== id; }));
        if (state.saveId === id) state.saveId = null;
        renderCount(); openMenu(true);
        return;
      }
      if (t.closest('.gc-mi-new')) { openMenu(false); startOver(); toast('New class started. Saved classes are still under My classes.'); return; }
      if (t.closest('.gc-copylink, .gc-copytext, .gc-csv')) track('gc_share', { how: t.closest('button').className.split(' ').pop() }, true);
      if (t.closest('.gc-copylink')) { copy(shareUrl(), 'Link copied — anyone with it sees these grades.'); return; }
      if (t.closest('.gc-copytext')) { copy(summaryText(), 'Summary copied.'); return; }
      if (t.closest('.gc-csv')) { csv(); }
    });

    root.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !$('.gc-menu').hidden) { openMenu(false); $('.gc-classes').focus(); return; }
      if (e.key !== 'Enter') return;
      var h = findRow(e.target); if (!h) return;
      e.preventDefault();
      if (h.i === state.rows.length - 1) addRow(true); else $('.gc-rows').children[h.i + 1].querySelector('input').focus();
    });

    window.addEventListener('pagehide', function () { if (saveT) flushDraft(); });
    document.addEventListener('visibilitychange', function () { if (document.hidden && saveT) flushDraft(); });
  }

  function mount() {
    root = document.getElementById('root');
    if (!root || root.dataset.gc) return;
    root.dataset.gc = '1';
    loadInitial(); build(); bind();
    if ('IntersectionObserver' in window) {
      new IntersectionObserver(function (en) { heroInView = en[0].isIntersecting; paintLive(); }, { threshold: 0, rootMargin: '0px 0px -30% 0px' }).observe($('.gc-hero'));
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount); else mount();
})();
