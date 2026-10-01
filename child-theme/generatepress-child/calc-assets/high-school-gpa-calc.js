/*! High School GPA Calculator v3.2 — gpacalculator.net
 * Drop-in replacement for calc-assets/high-school-gpa-calc.js. Mounts into #root.
 * Vanilla JS, no dependencies, wrapped in an IIFE (nothing leaks into window).
 * Flow: 1 Your GPA (classes + live weighted/unweighted result) → 2 Target planner.
 */
(function () {
  'use strict';

  /* ------------------------------------------------------------ config */
  var GRADES = [['A+', 4.0], ['A', 4.0], ['A-', 3.7], ['B+', 3.3], ['B', 3.0], ['B-', 2.7], ['C+', 2.3], ['C', 2.0], ['C-', 1.7], ['D+', 1.3], ['D', 1.0], ['D-', 0.7], ['F', 0.0]];
  var GP = {}; GRADES.forEach(function (g) { GP[g[0]] = g[1]; });
  var LEVELS = [['reg', 'Regular', 0], ['hon', 'Honors', 0.5], ['ap', 'AP', 1.0], ['ib', 'IB', 1.0], ['de', 'Dual Enroll.', 1.0]];
  var BONUS = {}, LNAME = {}; LEVELS.forEach(function (l) { BONUS[l[0]] = l[2]; LNAME[l[0]] = l[1]; });
  var LETTERS = [['A', 4.0], ['A-', 3.7], ['B+', 3.3], ['B', 3.0], ['B-', 2.7], ['C+', 2.3], ['C', 2.0], ['C-', 1.7], ['D+', 1.3], ['D', 1.0], ['D-', 0.7], ['F', 0.0]];
  // Course catalog for the class-name suggestions. Level comes from the name prefix (AP / IB / Honors / Dual Enrollment).
  // '*' = IB course offered at SL and HL. Honors/AP/IB/DE lists get their prefix added automatically.
  var CAT = [
    ['English', 'English 9|English 10|English 11|English 12|English Language Arts|Creative Writing|Journalism|Speech and Debate|American Literature|British Literature|World Literature|Composition|Public Speaking',
      'English 9|English 10|English 11|English 12|American Literature', 'English Language and Composition|English Literature and Composition|Seminar|Research', 'English A: Language and Literature*|English A: Literature*'],
    ['Math', 'Pre-Algebra|Algebra 1|Geometry|Algebra 2|Trigonometry|Pre-Calculus|Calculus|Statistics|Integrated Math 1|Integrated Math 2|Integrated Math 3|Math Analysis|Financial Algebra|Consumer Math|Discrete Math',
      'Algebra 1|Geometry|Algebra 2|Pre-Calculus|Calculus|Integrated Math 2|Integrated Math 3', 'Precalculus|Calculus AB|Calculus BC|Statistics', 'Math: Analysis and Approaches*|Math: Applications and Interpretation*'],
    ['Science', 'Biology|Chemistry|Physics|Earth Science|Physical Science|Environmental Science|Anatomy and Physiology|Astronomy|Forensic Science|Marine Biology|Integrated Science|Biotechnology|Geology|Zoology',
      'Biology|Chemistry|Physics|Anatomy and Physiology|Earth Science|Environmental Science', 'Biology|Chemistry|Environmental Science|Physics 1|Physics 2|Physics C: Mechanics|Physics C: Electricity and Magnetism', 'Biology*|Chemistry*|Physics*|Environmental Systems and Societies*|Sports, Exercise and Health Science*'],
    ['Social Studies', 'World History|US History|World Geography|Government|Civics|Economics|Psychology|Sociology|Personal Finance|Ethnic Studies|Law|Current Events|State History',
      'World History|US History|Government|Economics|World Geography', 'United States History|World History: Modern|European History|Human Geography|United States Government and Politics|Comparative Government and Politics|Macroeconomics|Microeconomics|Psychology|African American Studies',
      'History*|Geography*|Economics*|Psychology*|Global Politics*|Business Management*|Philosophy*|Theory of Knowledge'],
    ['World Language', 'Spanish 1|Spanish 2|Spanish 3|Spanish 4|French 1|French 2|French 3|French 4|German 1|German 2|German 3|Chinese 1|Chinese 2|Chinese 3|Japanese 1|Japanese 2|Japanese 3|Latin 1|Latin 2|Latin 3|Italian 1|Italian 2|Korean 1|Korean 2|ASL 1|ASL 2|ASL 3|Spanish for Native Speakers',
      'Spanish 3|Spanish 4|French 3|French 4|German 3|Chinese 3|Japanese 3|Latin 3', 'Spanish Language and Culture|Spanish Literature and Culture|French Language and Culture|German Language and Culture|Chinese Language and Culture|Japanese Language and Culture|Italian Language and Culture|Latin',
      'Spanish B*|French B*|German B*|Chinese B*|Spanish ab initio|French ab initio|Mandarin ab initio'],
    ['Arts', 'Art 1|Art 2|Drawing|Painting|Ceramics|Sculpture|Photography|Digital Art|Graphic Design|Band|Concert Band|Jazz Band|Orchestra|Choir|Music Theory|Guitar|Piano|Theater|Drama|Dance|Film Studies',
      'Art 3|Choir|Orchestra|Band', 'Art History|Music Theory|Drawing|2-D Art and Design|3-D Art and Design', 'Visual Arts*|Music*|Theatre*|Film*|Dance*'],
    ['Tech & Careers', 'Computer Science|Intro to Programming|Web Design|Robotics|Engineering Design|Business|Accounting|Marketing|Entrepreneurship|Culinary Arts|Automotive Technology|Health Science|Medical Terminology|Video Production|Yearbook|Woodworking|Child Development',
      'Computer Science|Engineering Design', 'Computer Science A|Computer Science Principles', 'Computer Science*|Design Technology*'],
    ['PE & Health', 'PE|Physical Education|Health|Weight Training|Team Sports|Yoga|Driver Education'],
    ['Electives', 'AVID|Leadership|Study Skills|Peer Tutoring|Teacher Assistant|Library Aide'],
    ['Dual Enrollment', '', '', '', '', 'English Composition|College Algebra|Statistics|Psychology|US History|Biology|Public Speaking|Sociology|Chemistry|Calculus']
  ];
  var COURSES = (function () {
    var out = [], seen = {};
    function add(n, subj) { if (n && !seen[n]) { seen[n] = 1; out.push([n, subj, guessLevel(n) || 'reg']); } }
    CAT.forEach(function (c) {
      var sp = function (i) { return c[i] ? c[i].split('|') : []; };
      sp(1).forEach(function (n) { add(n, c[0]); });
      sp(2).forEach(function (n) { add('Honors ' + n, c[0]); });
      sp(3).forEach(function (n) { add('AP ' + n, c[0]); });
      sp(4).forEach(function (n) { if (n.slice(-1) === '*') { add('IB ' + n.slice(0, -1) + ' HL', c[0]); add('IB ' + n.slice(0, -1) + ' SL', c[0]); } else add('IB ' + n, c[0]); });
      sp(5).forEach(function (n) { add('Dual Enrollment ' + n, c[0]); });
    });
    return out;
  })();
  function words(s) { return String(s).toLowerCase().split(/[^a-z0-9]+/).filter(Boolean); }
  var ALIAS = { 'AP United States History': 'apush us', 'AP United States Government and Politics': 'us gov apgov', 'AP World History: Modern': 'apwh', 'AP European History': 'apeuro',
    'AP English Language and Composition': 'lang apl', 'AP English Literature and Composition': 'lit', 'AP Computer Science A': 'csa', 'AP Computer Science Principles': 'csp', 'AP Environmental Science': 'apes',
    'AP Human Geography': 'aphg', 'AP Psychology': 'psych', 'Psychology': 'psych', 'US History': 'american united states', 'Government': 'gov', 'Physical Education': 'pe', 'Anatomy and Physiology': 'a&p', 'Pre-Calculus': 'precalc', 'Precalculus': 'precalc' };
  COURSES.forEach(function (c) { c[3] = words(c[0] + ' ' + (ALIAS[c[0].replace(/^AP /, 'AP ')] || '')); c[4] = c[0].toLowerCase(); });
  function suggest(q) {
    var qw = words(q), ql = String(q).trim().toLowerCase(); if (!qw.length) return [];
    var res = [];
    COURSES.forEach(function (c) {
      if (c[4] === ql) return;
      var ok = qw.every(function (w, i) { var last = i === qw.length - 1; return c[3].some(function (x) { return last ? x.indexOf(w) === 0 : x === w || x.indexOf(w) === 0; }); });
      if (!ok) return;
      var sc = c[4].indexOf(ql) === 0 ? 0 : 1;
      res.push([sc, c[0].length, c]);
    });
    res.sort(function (a, b) { return a[0] - b[0] || a[1] - b[1]; });
    return res.slice(0, 8).map(function (r) { return r[2]; });
  }
  var PH = ['e.g. English 10', 'e.g. AP Biology', 'e.g. Algebra 2', 'e.g. Honors Chemistry', 'e.g. US History', 'e.g. Spanish 2'];
  var SAMPLES = {
    fresh: { name: 'Sample: 9th grade', target: 3.5, basis: 'uw', future: '18', terms: [
      { name: '9th grade · Fall', rows: [['English 9', 'B+', 'reg'], ['Algebra 1', 'B', 'reg'], ['Honors Biology', 'A-', 'hon'], ['World History', 'A', 'reg'], ['Spanish 1', 'B+', 'reg'], ['PE', 'A', 'reg']] }] },
    junior: { name: 'Sample: AP junior', target: 4.0, basis: 'w', future: '12', mix: { ap: '4', hon: '2' }, terms: [
      { name: '9th grade', rows: [['English 9', 'A-', 'reg'], ['Algebra 1', 'B+', 'reg'], ['Honors Biology', 'A', 'hon'], ['World History', 'B', 'reg'], ['Spanish 1', 'A', 'reg'], ['Art 1', 'A', 'reg']] },
      { name: '10th grade', rows: [['Honors English 10', 'B+', 'hon'], ['Geometry', 'B', 'reg'], ['AP World History: Modern', 'A-', 'ap'], ['Chemistry', 'B+', 'reg'], ['Spanish 2', 'A-', 'reg'], ['PE', 'A', 'reg']] },
      { name: '11th grade', rows: [['AP English Language and Composition', 'A-', 'ap'], ['Algebra 2', 'B+', 'reg'], ['AP Biology', 'B+', 'ap'], ['AP United States History', 'A', 'ap'], ['Honors Spanish 3', 'A-', 'hon'], ['Computer Science', 'A', 'reg']] }] }
  };
  var SITE = 'https://gpacalculator.net'; // absolute links so they work anywhere the calculator is embedded
  var K_DRAFT = 'hs:v2', K_SAVES = 'hs:saves', K_IMPORT = 'hs:imported', MAX_SAVES = 30;
  var OLD_DRAFT = 'gpa_calc_draft_v1', OLD_SAVES = 'gpa_calc_saved_v1';
  var returning = false, restored = false, seen = {};
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
  function f2(n) { return (Math.round(n * 100 + 1e-9) / 100).toFixed(2); }
  function fmt(n) { return String(Math.round(n * 100) / 100); }
  function fmtT(n) { var v = Math.round(n * 100) / 100; return v % 1 === 0 ? v.toFixed(1) : String(v); }
  function clamp(n, a, b) { return Math.max(a, Math.min(b, n)); }
  function lsGet(k) { try { return window.localStorage.getItem(k); } catch (e) { return null; } }
  function lsSet(k, v) { try { window.localStorage.setItem(k, v); return true; } catch (e) { return false; } }
  function lsDel(k) { try { window.localStorage.removeItem(k); } catch (e) { } }
  function cleanNum(v, max) { v = String(v).replace(/,/g, '.').replace(/[^\d.]/g, ''); var i = v.indexOf('.'); if (i !== -1) v = v.slice(0, i + 1) + v.slice(i + 1).replace(/\./g, ''); return v.slice(0, max || 5); }
  function article(L) { return /^[AEF]/.test(L) ? 'an' : 'a'; }
  function when(ts) { var d = new Date(ts), n = new Date(); var day = function (x) { return new Date(x.getFullYear(), x.getMonth(), x.getDate()).getTime(); }; var k = Math.round((day(n) - day(d)) / 864e5); return k === 0 ? 'today' : k === 1 ? 'yesterday' : niceDate(ts); }
  function niceDate(ts) { try { return new Date(ts).toLocaleDateString(undefined, { month: 'short', day: 'numeric' }); } catch (e) { return ''; } }
  function letterOf(g) { var best = LETTERS[0], d = 9; LETTERS.forEach(function (L) { var x = Math.abs(L[1] - g); if (x <= d + 1e-9) { d = x; best = L; } }); return best[0]; }
  function dL(L) { return String(L).replace('-', '−'); }
  function fam(L) { return L.charAt(0).toLowerCase(); }
  function guessLevel(name) {
    var s = String(name || '').trim();
    if (/^AP\b/i.test(s)) return 'ap';
    if (/^IB\b/i.test(s)) return 'ib';
    if (/^hon(ors|\.)?\b|\bhonors\b/i.test(s)) return 'hon';
    if (/^(DE|DC)\b|^dual\b|dual (enrollment|credit)/i.test(s)) return 'de';
    return null;
  }

  /* ------------------------------------------------------------ state */
  function newRow(r) { r = r || {}; return { id: ++uid, name: r.name || '', grade: GP[r.grade] != null ? r.grade : '', level: BONUS[r.level] != null ? r.level : 'reg', credits: r.credits || '', auto: !!r.auto }; }
  function newTerm(t, n) { t = t || {}; var rows = (t.rows && t.rows.length ? t.rows : [{}, {}, {}]).slice(0, 20).map(newRow); return { id: ++uid, name: t.name || '', open: t.open !== false, rows: rows }; }
  function fresh() { return { name: '', terms: [newTerm()], showCr: false, prior: { on: false, uw: '', w: '', n: '' }, target: 3.5, basis: 'uw', future: '', mix: { hon: '', ap: '', ib: '', de: '' }, whatIf: 3.5, step: 1, sample: false, saveId: null }; }
  var state = fresh();

  /* ------------------------------------------------------------ math (pure) */
  // Grade points: unweighted = 4.0 scale. Weighted adds the level bonus (Honors +0.5, AP/IB/DE +1.0), except on an F.
  function rowPts(r) { var g = GP[r.grade]; if (g == null) return null; var b = g > 0 ? BONUS[r.level] || 0 : 0; return { uw: g, w: g + b, b: b }; }
  function credOf(r) { if (!state.showCr) return 1; var c = num(r.credits); return c > 0 ? c : 1; }
  function compute() {
    var res = { terms: [], uwP: 0, wP: 0, C: 0, n: 0, adv: 0, rows: [], prior: null };
    state.terms.forEach(function (t) {
      var tu = 0, tw = 0, tc = 0, tn = 0;
      t.rows.forEach(function (r) {
        var p = rowPts(r); if (!p) return;
        var c = credOf(r);
        tu += p.uw * c; tw += p.w * c; tc += c; tn++;
        if (p.b > 0) res.adv++;
        res.rows.push({ r: r, t: t, p: p, c: c });
      });
      res.terms.push({ t: t, uw: tc ? tu / tc : NaN, w: tc ? tw / tc : NaN, n: tn, C: tc });
      res.uwP += tu; res.wP += tw; res.C += tc; res.n += tn;
    });
    res.curC = res.C; res.curUw = res.C ? res.uwP / res.C : NaN; res.curW = res.C ? res.wP / res.C : NaN;
    var P = state.prior, pu = num(P.uw), pw = num(P.w), pn = num(P.n);
    if (P.on && pu >= 0 && pu <= 4.0001 && pn > 0) {
      if (!(pw >= 0) || pw > 6) pw = pu;
      res.prior = { uw: pu, w: pw, n: pn };
      res.uwP += pu * pn; res.wP += pw * pn; res.C += pn;
    }
    res.uw = res.C ? res.uwP / res.C : NaN; res.w = res.C ? res.wP / res.C : NaN;
    res.cum = !!res.prior || res.terms.filter(function (x) { return x.n; }).length > 1;
    return res;
  }
  // Average needed across F future classes (or credits) to reach target T.
  function need(c, T, F, basis) { var P = basis === 'w' ? c.wP : c.uwP; return (T * (c.C + F) - P) / F; }
  // Upcoming class levels (weighted planning): average bonus and how many were specified.
  function mixInfo(F) {
    var m = state.mix, n = 0, b = 0;
    ['hon', 'ap', 'ib', 'de'].forEach(function (k) { var v = num(m[k]); if (v > 0) { n += v; b += v * BONUS[k]; } });
    return { n: n, over: n > F + 1e-9, bonus: F > 0 && n <= F + 1e-9 ? b / F : 0, set: n > 0 };
  }
  function finishWith(c, avg, F, basis) { var P = basis === 'w' ? c.wP : c.uwP; return (P + avg * F) / (c.C + F); }

  /* ------------------------------------------------------------ persistence */
  function snapshot() {
    return { v: 2, name: state.name, showCr: state.showCr, prior: state.prior, target: state.target, basis: state.basis, future: state.future, mix: state.mix, whatIf: state.whatIf, wiTouched: !!state.wiTouched,
      terms: state.terms.map(function (t) { return { name: t.name, open: t.open, rows: t.rows.map(function (r) { return { name: r.name, grade: r.grade, level: r.level, credits: r.credits, auto: r.auto }; }) }; }) };
  }
  function apply(s, keep) {
    state = fresh();
    state.name = s.name || ''; state.showCr = !!s.showCr;
    var p = s.prior || {}; state.prior = { on: !!p.on, uw: p.uw || '', w: p.w || '', n: p.n || '' };
    state.target = isFinite(s.target) ? s.target : 3.5; state.basis = s.basis === 'w' ? 'w' : 'uw';
    state.future = s.future || ''; state.whatIf = isFinite(s.whatIf) ? clamp(s.whatIf, 0, 4) : 3.5;
    state.wiTouched = !!s.wiTouched;
    var mx = s.mix || {}; state.mix = { hon: mx.hon || '', ap: mx.ap || '', ib: mx.ib || '', de: mx.de || '' };
    state.terms = (s.terms && s.terms.length ? s.terms : [{}]).slice(0, 12).map(newTerm);
    if (keep) { state.saveId = keep.saveId || null; state.sample = !!keep.sample; }
  }
  // Old React calculator data (shared keys gpa_calc_*): only records with courseType are high-school ones.
  function fromOld(o) {
    if (!o || !o.semesters || !o.semesters.length) return null;
    var hs = o.semesters.some(function (s) { return (s.courses || []).some(function (c) { return 'courseType' in c; }); });
    if (!hs) return null;
    var map = { Regular: 'reg', Honors: 'hon', AP: 'ap', IB: 'ib' };
    var eq = o.useEqualCredits !== false && !o.showCredits;
    return { v: 2, name: o.name || '', showCr: !eq,
      terms: o.semesters.map(function (s, i) { return { name: o.semesters.length > 1 ? (s.name || '') : '', rows: (s.courses || []).map(function (c) {
        return { name: c.name || '', grade: c.grade || '', level: map[c.courseType] || 'reg', credits: !eq && c.credits ? String(c.credits) : '' }; }) }; }) };
  }
  function importOld() {
    if (lsGet(K_IMPORT)) return;
    lsSet(K_IMPORT, '1');
    try {
      var old = JSON.parse(lsGet(OLD_SAVES) || 'null'), list = old && old.calculations || [], add = [];
      list.forEach(function (o) { var s = fromOld(o); if (s) add.push({ id: 'old-' + o.id, name: o.name || 'My classes', ts: Date.parse(o.updatedAt) || Date.now(), data: s }); });
      if (add.length) putSaves(getSaves().concat(add));
    } catch (e) { }
  }
  function loadInitial() {
    importOld();
    returning = !!lsGet(K_DRAFT) || getSaves().length > 0 || !!lsGet(OLD_DRAFT);
    var raw = null;
    try { var m = location.hash.match(/hs=([^&]+)/); if (m) raw = decodeURIComponent(escape(atob(decodeURIComponent(m[1])))); } catch (e) { raw = null; }
    var fromLink = !!raw;
    if (!raw) raw = lsGet(K_DRAFT);
    if (!raw) { try { var od = fromOld(JSON.parse(lsGet(OLD_DRAFT) || 'null')); if (od) raw = JSON.stringify(od); } catch (e) { } }
    if (!raw) return;
    try {
      var s = JSON.parse(raw); if (!s || !s.terms) return;
      apply(s, { saveId: fromLink ? null : s.saveId, sample: !fromLink && s.sample });
      restored = !fromLink && compute().n > 0;
      if (fromLink) track('hs_open_link', {});
    } catch (e) { }
  }
  var saveT = null;
  function flushDraft() { clearTimeout(saveT); saveT = null; var s = snapshot(); s.saveId = state.saveId; s.sample = state.sample; lsSet(K_DRAFT, JSON.stringify(s)); }
  function saveDraft() { clearTimeout(saveT); saveT = setTimeout(flushDraft, 300); }
  function getSaves() { try { var a = JSON.parse(lsGet(K_SAVES) || '[]'); return Array.isArray(a) ? a : []; } catch (e) { return []; } }
  function putSaves(a) { return lsSet(K_SAVES, JSON.stringify(a.slice(0, MAX_SAVES))); }
  function shareUrl() { return location.origin + location.pathname + '#hs=' + encodeURIComponent(btoa(unescape(encodeURIComponent(JSON.stringify(snapshot()))))); }

  /* ------------------------------------------------------------ icons */
  function svg(d, w) { return '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="' + (w || 2) + '" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + d + '</svg>'; }
  var IC = {
    x: svg('<path d="M18 6 6 18M6 6l12 12"/>'), plus: svg('<path d="M12 5v14M5 12h14"/>', 2.4), check: svg('<path d="M20 6 9 17l-5-5"/>', 3),
    arrow: svg('<path d="M5 12h14M13 6l6 6-6 6"/>', 2.4), back: svg('<path d="M19 12H5M11 18l-6-6 6-6"/>', 2.4),
    save: svg('<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><path d="M17 21v-8H7v8M7 3v5h8"/>'),
    folder: svg('<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>'),
    spark: svg('<path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8z"/>'),
    link: svg('<path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/>'),
    copy: svg('<rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v1"/>'),
    dl: svg('<path d="M12 3v12M7 10l5 5 5-5M5 21h14"/>'), print: svg('<path d="M6 9V3h12v6M6 18H4a1 1 0 0 1-1-1v-6a1 1 0 0 1 1-1h16a1 1 0 0 1 1 1v6a1 1 0 0 1-1 1h-2"/><path d="M6 14h12v7H6z"/>'),
    chev: svg('<path d="m6 9 6 6 6-6"/>', 2.4), cal: svg('<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 10h18M8 2v4M16 2v4"/>'),
    hist: svg('<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5M12 7v5l3 2"/>')
  };

  /* ------------------------------------------------------------ shell */
  var root;
  function stepBtn(n, label) { return '<button type="button" class="hs-step hs-go" data-go="' + n + '"><span class="hs-dotn">' + n + '</span><span>' + label + '</span></button>'; }
  function opts(list, blank) { return (blank ? '<option value="">' + blank + '</option>' : '') + list.map(function (x) { return '<option value="' + x[0] + '">' + (x[1] != null && typeof x[1] === 'string' ? x[1] : x[0]) + '</option>'; }).join(''); }

  function build() {
    root.classList.add('hs-root');
    root.innerHTML =
      '<div class="hsx">' +
        '<div class="hs-top">' +
          '<nav class="hs-steps" aria-label="Steps">' + stepBtn(1, 'Your GPA') + stepBtn(2, 'Target GPA') + '</nav>' +
          '<div class="hs-app-act">' +
            '<div class="hs-menuwrap"><button type="button" class="hs-ghost hs-mine" aria-haspopup="true" aria-expanded="false" aria-label="My saves">' + IC.folder + '<span>My saves</span><b class="hs-count"></b></button><div class="hs-menu" hidden></div></div>' +
            '<button type="button" class="hs-ghost hs-save" aria-label="Save">' + IC.save + '<span>Save</span></button>' +
          '</div>' +
        '</div>' +
        '<div class="hs-banner" hidden></div>' +
        '<div class="hs-welcome" hidden><div class="hs-wel-t">' + IC.hist + '<span><b>Welcome back!</b> <span class="hs-wel-s">Your classes are right where you left off.</span></span></div>' +
          '<div class="hs-wel-a"><button type="button" class="hs-soft hs-addterm2">' + IC.cal + 'Add this semester\'s grades</button><button type="button" class="hs-link hs-reset2">Start over</button></div></div>' +
        '<div class="hs-strip" hidden></div>' +

        '<section class="hs-panel" data-panel="1">' +
          '<div class="hs-empty"><div class="hs-empty-h"><b>New here?</b><span>Load a sample to see how it works, then add your own classes.</span></div>' +
            '<ol class="hs-how3"><li><i>1</i>Type a class<small>suggestions pop up</small></li><li><i>2</i>Pick your grade<small>from your report card</small></li><li><i>3</i>Pick the level<small>Honors, AP, IB…</small></li></ol>' +
            '<div class="hs-sbtns"><button type="button" class="hs-soft hs-sample" data-s="fresh">' + IC.spark + '9th-grade sample</button><button type="button" class="hs-soft hs-sample" data-s="junior">' + IC.spark + 'AP junior sample</button></div></div>' +
          '<button type="button" class="hs-link hs-sample hs-exlink" data-s="fresh" hidden>' + IC.spark + 'Show me an example</button>' +
          '<div class="hs-terms"></div>' +
          '<div class="hs-addbar">' +
            '<button type="button" class="hs-add hs-addterm">' + IC.cal + 'Add semester / year</button>' +
            '<label class="hs-switch"><input type="checkbox" class="hs-cr"><i></i><span>Credits</span></label>' +
            '<button type="button" class="hs-add hs-priorbtn">' + IC.hist + 'Already have a GPA?</button>' +
            '<button type="button" class="hs-link hs-reset">Start over</button>' +
          '</div>' +
          '<div class="hs-prior" hidden>' +
            '<div class="hs-prior-h"><b>Your GPA from earlier semesters</b><button type="button" class="hs-link hs-priorx">Remove</button></div>' +
            '<div class="hs-prior-g">' +
              '<label class="hs-field"><span class="hs-flbl">Unweighted GPA</span><input class="hs-in is-num" data-p="uw" type="text" inputmode="decimal" placeholder="e.g. 3.6" aria-label="Previous unweighted GPA"></label>' +
              '<label class="hs-field"><span class="hs-flbl">Weighted GPA <em>optional</em></span><input class="hs-in is-num" data-p="w" type="text" inputmode="decimal" placeholder="e.g. 3.9" aria-label="Previous weighted GPA (optional)"></label>' +
              '<label class="hs-field"><span class="hs-flbl hs-pn-l">Classes it covers</span><input class="hs-in is-num" data-p="n" type="text" inputmode="decimal" placeholder="e.g. 12" aria-label="Number of classes your previous GPA covers"></label>' +
            '</div><p class="hs-note hs-prior-note">Find these on your report card or transcript. A full year of 6 classes is usually 12 semester grades.</p>' +
          '</div>' +
          '<ul class="hs-howto">' +
            '<li><b>Grade:</b> pick the letter from your report card. <b>Level:</b> Honors adds 0.5, AP, IB and dual enrollment add 1.0 to your weighted GPA.</li>' +
            '<li>Typing a class like <code>AP Biology</code> sets the level for you. Your GPA appears below as you go.</li>' +
          '</ul>' +
          '<div class="hs-live" hidden><button type="button" class="hs-toresult"><span class="hs-live-l">GPA</span><b class="hs-live-v"></b><span class="hs-live-u"></span><span class="hs-live-go">Details ' + IC.arrow + '</span></button></div>' +
          '<div class="hs-await" aria-hidden="true"><div class="hs-await-n">–.––</div><div class="hs-await-t"><b>Your GPA shows up here</b><span>Pick a grade for any class. Weighted and unweighted update as you go.</span></div></div>' +
          '<div class="hs-result" hidden aria-live="polite">' +
            '<div class="hs-hero"><div class="hs-hero-top"><div class="hs-hero-l"><span class="hs-kicker"></span>' +
              '<div class="hs-scores"><div class="hs-sc hs-sc-w"><div class="hs-score"></div><span class="hs-sl">Weighted</span></div><div class="hs-sc hs-sc-u"><div class="hs-score2"></div><span class="hs-sl">Unweighted</span></div></div>' +
              '<span class="hs-delta" hidden></span><p class="hs-verdict"></p></div><div class="hs-ring"></div></div><div class="hs-good"></div><div class="hs-track"></div>' +
              '<details class="hs-calc"><summary><span>How it\'s calculated</span>' + IC.chev + '</summary><div class="hs-calc-in"></div></details></div>' +
          '</div>' +
          '<div class="hs-foot">' +
            '<div class="hs-share" hidden>' +
              '<button type="button" class="hs-chipbtn hs-copylink" aria-label="Copy my link" title="Copy a link to these grades">' + IC.link + '<span>Copy link</span></button>' +
              '<button type="button" class="hs-chipbtn hs-copytext" aria-label="Copy summary" title="Copy a text summary">' + IC.copy + '<span>Copy text</span></button>' +
              '<button type="button" class="hs-chipbtn hs-csv" aria-label="Download CSV" title="Download as a spreadsheet (CSV)">' + IC.dl + '<span>CSV</span></button>' +
              '<button type="button" class="hs-chipbtn hs-print" aria-label="Print or save as PDF" title="Print or save as PDF">' + IC.print + '<span>Print</span></button>' +
            '</div>' +
            '<button type="button" class="hs-primary hs-go hs-next1" data-go="2" hidden>Plan my target GPA ' + IC.arrow + '</button></div>' +
          '<div class="hs-after" hidden>' +
            '<div class="hs-savecard"></div>' +
          '</div>' +
        '</section>' +

        '<section class="hs-panel" data-panel="2" hidden>' +
          '<div class="hs-plan-in">' +
            '<div class="hs-goal"><div class="hs-goal-h"><span class="hs-flbl">I want to graduate with a</span>' +
              '<div class="hs-seg" role="group" aria-label="GPA type"><button type="button" data-b="uw">Unweighted</button><button type="button" data-b="w">Weighted</button></div></div>' +
              '<div class="hs-goalrow"><div class="hs-chips hs-tchips"></div>' +
              '<label class="hs-field hs-tg"><input class="hs-in is-num hs-target" type="text" inputmode="decimal" placeholder="Other" aria-label="Target GPA (or type your own)"></label></div></div>' +
            '<div class="hs-goal"><div class="hs-goal-h"><span class="hs-flbl hs-fut-l">Classes I still have left</span><span class="hs-hint">6 classes a semester = 6</span></div><div class="hs-goalrow"><div class="hs-chips hs-fchips"></div>' +
              '<label class="hs-field hs-tg"><input class="hs-in is-num hs-future" type="text" inputmode="decimal" placeholder="Other" aria-label="Classes left (or type your own)"></label></div></div>' +
            '<div class="hs-mix" hidden><div class="hs-mix-h"><span class="hs-flbl">Upcoming class levels <em>optional</em></span><span class="hs-mix-c"></span></div><div class="hs-mix-g">' +
              [['hon', 'Honors', '+0.5'], ['ap', 'AP', '+1.0'], ['ib', 'IB', '+1.0'], ['de', 'Dual Enroll.', '+1.0']].map(function (m) {
                return '<div class="hs-mixi hs-lvl-' + m[0] + '"><span class="hs-mixl">' + m[1] + '<small>' + m[2] + '</small></span><div class="hs-stepper">' +
                  '<button type="button" class="hs-stp" data-m="' + m[0] + '" data-d="-" aria-label="Fewer ' + m[1] + ' classes">−</button><b class="hs-step-n" data-m="' + m[0] + '">0</b>' +
                  '<button type="button" class="hs-stp" data-m="' + m[0] + '" data-d="+" aria-label="More ' + m[1] + ' classes">+</button></div></div>';
              }).join('') + '</div></div>' +
          '</div>' +
          '<p class="hs-note hs-plannote"></p>' +
          '<div class="hs-plan-out"></div>' +
          '<div class="hs-foot"><button type="button" class="hs-link hs-go" data-go="1">' + IC.back + 'Back to my classes</button>' +
            '<button type="button" class="hs-primary hs-save2">' + IC.save + 'Save my plan</button></div>' +
        '</section>' +
        '<div class="hs-sug" id="hs-sug" role="listbox" aria-label="Class suggestions" hidden></div>' +
        '<div class="hs-toast" role="status" aria-live="polite"></div>' +
      '</div>';
    render();
  }

  /* ------------------------------------------------------------ render */
  function render() {
    var sc = $('.hs-score'); if (sc) delete sc.dataset.shown;
    $('.hs-cr').checked = state.showCr;
    root.querySelector('.hsx').classList.toggle('show-cr', state.showCr);
    $$('[data-p]').forEach(function (i) { i.value = state.prior[i.dataset.p]; });
    var TCH = [3.0, 3.5, 3.7, 3.9, 4.0, 4.3, 4.5];
    $('.hs-target').value = TCH.indexOf(state.target) >= 0 ? '' : fmtT(state.target);
    $('.hs-future').value = ['6', '12', '24'].indexOf(String(state.future)) >= 0 ? '' : state.future;
    renderTerms(); renderCount(); update();
  }
  var resetBtn = null;
  function renderTerms() {
    var rb = resetBtn || (resetBtn = $('.hs-reset'));
    var box = $('.hs-terms'); box.innerHTML = '';
    var multi = state.terms.length > 1;
    box.classList.toggle('is-multi', multi);
    state.terms.forEach(function (t, ti) { box.appendChild(termView(t, ti, multi)); });
    // Start over: right end of the Add class line; with several semesters it moves to the add bar so it doesn't look like it resets one semester
    var last = box.lastElementChild;
    var spot = !multi && last ? last.querySelector('.hs-addline') : $('.hs-addbar');
    if (rb && spot) spot.appendChild(rb);
  }
  function termView(t, ti, multi) {
    var el = document.createElement('div');
    el.className = 'hs-term' + (t.open || !multi ? ' is-open' : ''); el.setAttribute('data-t', t.id);
    el.innerHTML =
      (multi ? '<div class="hs-th"><button type="button" class="hs-tog" aria-expanded="' + t.open + '" aria-label="Show or hide classes">' + IC.chev + '</button>' +
        '<input class="hs-tname" type="text" maxlength="40" placeholder="' + termPh(ti) + '" aria-label="Semester or year name">' +
        '<span class="hs-tsum"></span><button type="button" class="hs-tdel" aria-label="Remove this semester">' + IC.x + '</button></div>' : '') +
      '<div class="hs-tbody">' +
        '<div class="hs-head" aria-hidden="true"><div>Class <em>optional</em></div><div>Grade</div><div>Level</div><div class="hs-crc">Credits</div><div></div></div>' +
        '<div class="hs-rows"></div>' +
        '<div class="hs-addline"><button type="button" class="hs-add hs-addrow">' + IC.plus + 'Add class</button></div>' +
      '</div>';
    if (multi) el.querySelector('.hs-tname').value = t.name;
    var rows = el.querySelector('.hs-rows');
    t.rows.forEach(function (r, i) { rows.appendChild(rowView(r, i)); });
    refreshDel(el, t);
    return el;
  }
  function termPh(i) { return ['e.g. 9th grade', 'e.g. 10th grade', 'e.g. 11th grade', 'e.g. 12th grade'][i] || 'e.g. Semester ' + (i + 1); }
  function rowView(r, i) {
    var row = document.createElement('div');
    row.className = 'hs-row'; row.setAttribute('data-id', r.id);
    row.innerHTML =
      '<div class="hs-field f-name"><input class="hs-in" type="text" autocomplete="off" autocapitalize="words" spellcheck="false" maxlength="60" data-k="name" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="hs-sug" aria-label="Class name (optional)" placeholder="' + PH[i % PH.length] + '"></div>' +
      '<div class="hs-field f-grade"><select class="hs-sel" data-k="grade" aria-label="Grade">' + opts(GRADES, 'Grade') + '</select></div>' +
      '<div class="hs-field f-level"><select class="hs-sel" data-k="level" aria-label="Course level">' + LEVELS.map(function (l) { return '<option value="' + l[0] + '">' + l[1] + '</option>'; }).join('') + '</select></div>' +
      '<div class="hs-field f-cr hs-crc"><input class="hs-in is-num" type="text" inputmode="decimal" data-k="credits" aria-label="Credits" placeholder="1"></div>' +
      '<button type="button" class="hs-del" aria-label="Remove class">' + IC.x + '</button>';
    row.querySelector('[data-k=name]').value = r.name;
    row.querySelector('[data-k=grade]').value = r.grade;
    row.querySelector('[data-k=level]').value = r.level;
    row.querySelector('[data-k=credits]').value = r.credits;
    paintSel(row);
    return row;
  }
  function paintSel(row) { var g = row.querySelector('[data-k=grade]').value; $$('select', row).forEach(function (s) { var lv = s.dataset.k === 'level'; s.classList.toggle('is-empty', lv ? !g && s.value === 'reg' : !s.value); s.classList.toggle('is-adv', lv && s.value !== 'reg'); if (lv) s.setAttribute('data-lv', s.value); }); }
  function refreshDel(el, t) { $$('.hs-del', el).forEach(function (d) { d.disabled = t.rows.length <= 1; }); }
  function renderCount() { var n = getSaves().length; $('.hs-count').textContent = n ? String(n) : ''; }

  function findRow(id) { for (var i = 0; i < state.terms.length; i++) { var t = state.terms[i]; for (var j = 0; j < t.rows.length; j++) if (t.rows[j].id === id) return { t: t, r: t.rows[j], i: j }; } return null; }
  function findTerm(id) { for (var i = 0; i < state.terms.length; i++) if (state.terms[i].id === id) return state.terms[i]; return null; }

  function update() {
    var c = compute(), has = c.C > 0 && c.n + (c.prior ? 1 : 0) > 0;
    if (!has && state.step > 1) state.step = 1;
    var fut = num(state.future), planned = has && fut > 0;
    $$('.hs-step').forEach(function (b) {
      var n = +b.dataset.go, done = n === 1 ? has : planned, on = n === state.step;
      b.classList.toggle('is-on', on); b.classList.toggle('is-done', done && !on);
      b.disabled = n > 1 && !has;
      b.querySelector('.hs-dotn').innerHTML = done && !on ? IC.check : String(n);
      if (on) b.setAttribute('aria-current', 'step'); else b.removeAttribute('aria-current');
    });
    $$('.hs-panel').forEach(function (p) { p.hidden = +p.dataset.panel !== state.step; });

    var anyInput = state.terms.some(function (t) { return t.rows.some(function (r) { return r.name || r.grade; }); }) || state.prior.on;
    $('.hs-empty').hidden = anyInput || returning;
    $('.hs-exlink').hidden = anyInput || !returning;
    var ban = $('.hs-banner');
    if (state.sample) { ban.hidden = false; ban.innerHTML = '<span class="hs-ban-t">' + IC.spark + '<span><b>This is a sample.</b> Change a grade and watch the GPA move, try the Target GPA tab, then clear it and add your own classes.</span></span><button type="button" class="hs-primary hs-sm hs-clear-sample">Clear &amp; add mine</button>'; }
    else ban.hidden = true;
    $('.hs-welcome').hidden = !restored || state.sample || state.step !== 1;
    if (!$('.hs-welcome').hidden) { var sv = curSave(); $('.hs-wel-s').textContent = sv ? '"' + sv.name + '" is open, last saved ' + niceDate(sv.ts) + '.' : 'Your classes are right where you left off.'; }
    $('.hs-prior').hidden = !state.prior.on; $('.hs-priorbtn').hidden = state.prior.on;
    $('.hs-pn-l').textContent = state.showCr ? 'Credits it covers' : 'Classes it covers';
    if (state.prior.on) priorNote(c);

    // semester summaries (shown in each header; the only thing visible when a semester is collapsed)
    c.terms.forEach(function (x) {
      var el = root.querySelector('.hs-term[data-t="' + x.t.id + '"] .hs-tsum'); if (!el) return;
      el.innerHTML = x.n ? '<span>' + x.n + ' class' + (x.n > 1 ? 'es' : '') + '</span><b>' + f2(x.w) + '</b><small>W</small><b class="u">' + f2(x.uw) + '</b><small>UW</small>' : '<span class="hs-tsum-e">No grades yet</span>';
    });

    var strip = $('.hs-strip');
    if (has && state.step === 2) { strip.hidden = false; strip.innerHTML = '<span class="hs-strip-l">' + (c.cum ? 'Cumulative GPA' : 'Your GPA') + '</span><b class="hs-strip-v">' + f2(c.w) + '</b><span class="hs-strip-s">weighted</span><b class="hs-strip-v u">' + f2(c.uw) + '</b><span class="hs-strip-s">unweighted</span>'; }
    else strip.hidden = true;

    $('.hs-await').hidden = has || state.step !== 1;
    $('.hs-next1').hidden = !has; $('.hs-result').hidden = !has; $('.hs-after').hidden = !has; $('.hs-share').hidden = !has; $('.hs-howto').hidden = has || returning || !$('.hs-empty').hidden;
    $('.hs-reset').hidden = !anyInput;
    $('.hs-panel[data-panel="1"] .hs-foot').hidden = !anyInput;
    if (has) track('hs_result', { classes: c.n, adv: c.adv });
    var live = $('.hs-live'); live.hidden = !has;
    if (has) { $('.hs-live-v').textContent = f2(c.w); $('.hs-live-u').textContent = c.adv ? 'W · ' + f2(c.uw) + ' UW' : ''; $('.hs-live-l').textContent = c.adv ? 'Weighted' : 'GPA'; }
    paintLive();
    if (has) { renderResult(c); renderPlan(c); }
  }

  // Say why an earlier GPA isn't counted instead of silently ignoring it.
  function priorNote(c) {
    var P = state.prior, pu = num(P.uw), pw = num(P.w), pn = num(P.n), el = $('.hs-prior-note'), m = '';
    var unit = state.showCr ? 'credits' : 'classes';
    if (pu > 4.0001) m = 'Unweighted GPA tops out at 4.0. If yours is higher, it\'s weighted: put it in the Weighted box.';
    else if (pw > 6) m = 'That weighted GPA looks too high. Most schools top out around 5.0.';
    else if (pu >= 0 && !(pn > 0)) m = 'Add how many ' + unit + ' your earlier GPA covers so it can be blended in.';
    else if (!(pu >= 0) && (pw >= 0 || pn > 0)) m = 'Add your unweighted GPA so your earlier grades can be counted.';
    else if (pw >= 0 && pu >= 0 && pw < pu - 1e-9) m = 'Weighted is usually the same as or higher than unweighted. Double-check the two boxes.';
    el.textContent = m || (state.showCr ? 'Find these on your report card or transcript.' : 'Find these on your report card or transcript. A full year of 6 classes is usually 12 semester grades.');
    el.classList.toggle('is-warn', !!m);
  }

  /* ------------------------------------------------------------ result */
  var RING_C = { a: '#16a34a', b: '#2563eb', c: '#d97706', d: '#ea580c', f: '#dc2626' };
  function renderRing(uw, L) {
    var k = fam(L), r = 42, C = 2 * Math.PI * r, v = clamp(uw, 0, 4) / 4;
    var ring = $('.hs-ring'); ring.className = 'hs-ring hs-g-' + k;
    ring.innerHTML = '<svg viewBox="0 0 100 100" aria-hidden="true"><circle cx="50" cy="50" r="' + r + '" class="hs-ring-bg"/><circle cx="50" cy="50" r="' + r + '" class="hs-ring-fg" stroke="' + RING_C[k] + '" stroke-dasharray="' + (C * v).toFixed(1) + ' ' + C.toFixed(1) + '"/></svg>' +
      '<div class="hs-ring-in"><b>' + dL(L) + '</b><span>average</span></div>';
  }
  function renderTrack(uw) {
    // one clean rail: filled to the unweighted GPA in the letter color, a dot at the end, four tick labels
    var MIN = 1, p = (clamp(uw, MIN, 4) - MIN) / (4 - MIN) * 100, col = RING_C[fam(letterOf(uw))];
    $('.hs-track').innerHTML = '<div class="hs-rail" role="img" aria-label="Unweighted GPA ' + f2(uw) + ' on a 4.0 scale"><i class="hs-rail-fill" style="width:' + p + '%;background:' + col + '"></i>' +
      '<span class="hs-rail-dot" style="left:' + p + '%;border-color:' + col + '"></span></div>' +
      '<div class="hs-track-n"><span>1.0</span><span>2.0</span><span>3.0</span><span>4.0</span></div>';
  }
  var countT, heroInView = true;
  function paintLive() { var l = root && $('.hs-live'); if (l) l.classList.toggle('is-off', heroInView || state.step !== 1); }
  function countUp(elm, to) {
    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var show = function (v) { elm.textContent = f2(v); };
    cancelAnimationFrame(countT);
    if (reduce || elm.dataset.shown) { show(to); elm.dataset.shown = '1'; return; }
    elm.dataset.shown = '1';
    var t0 = performance.now(), d = 550;
    (function tick(now) { var k = Math.min(1, (now - t0) / d), e = 1 - Math.pow(1 - k, 3); show(to * e); if (k < 1) countT = requestAnimationFrame(tick); else show(to); })(t0);
  }
  function rowName(x) { return esc(x.r.name || 'Class ' + (x.t.rows.indexOf(x.r) + 1)); }
  function renderResult(c) {
    var L = letterOf(c.uw), adv = c.adv > 0;
    $('.hs-kicker').textContent = c.cum ? 'Cumulative GPA' : 'Your GPA';
    root.querySelector('.hsx').classList.toggle('no-adv', !adv);
    $('.hs-sc-w .hs-sl').textContent = adv ? 'Weighted' : 'Weighted & unweighted';
    countUp($('.hs-score'), c.w);
    $('.hs-score2').textContent = f2(c.uw);
    renderRing(c.uw, L); renderTrack(c.uw);
    var v = 'You\'re averaging ' + article(L) + ' <b>' + dL(L) + '</b>';
    if (adv) v += ', and your ' + (c.adv === 1 ? 'advanced class adds' : c.adv + ' advanced classes add') + ' <b>+' + f2(c.w - c.uw) + '</b> to your weighted GPA.';
    else v += '. Add Honors or AP levels if you have them to see your weighted GPA.';
    if (c.prior) v += ' Includes your earlier GPA.';
    $('.hs-verdict').innerHTML = v;
    renderGood(c);
    renderCalc(c);
    renderSaveCard(c);
  }
  // "Is my GPA good?": answered inside the result, judged on the unweighted GPA (what most colleges recalculate to),
  // using the exact number shown above so nothing on screen disagrees. The gpa-scale page stays as a quiet read-more.
  var BANDS = [
    [3.9, 'top', 'Excellent GPA', 'Competitive at the most selective colleges'],
    [3.7, 'strong', 'Very good GPA', 'Competitive at many selective colleges'],
    [3.3, 'solid', 'Good GPA', 'Competitive at many state universities'],
    [3.0, 'ok', 'Decent GPA', 'Meets the minimum at many four-year colleges'],
    [2.5, 'grow', 'Room to grow', 'Some four-year colleges accept this range'],
    [0, 'build', 'Needs a boost', 'Your next grades matter most']
  ];
  function bandOf(uw) { return BANDS.filter(function (x) { return uw >= x[0] - 1e-9; })[0]; }
  // One short line: a label that changes as they type, no paragraph and no exit link (the read-more lives in "Keep going").
  function renderGood(c) {
    var band = bandOf(c.uw), box = $('.hs-good');
    box.className = 'hs-good hs-band-' + band[1];
    box.innerHTML = '<span class="hs-good-tag">' + band[2] + '</span><span class="hs-good-q">for college</span><span class="hs-good-t">' + band[3] + '</span>';
  }
  function gpaScaleUrl(uw) { return SITE + '/gpa-scale/' + clamp(Math.floor(uw * 10 + 1e-9) / 10, 1, 4).toFixed(1).replace('.', '-') + '-gpa/'; }
  // Grade-point key: one row of letter → points (the grades they used are highlighted), plus the level boosts in words.
  function scaleKey(c) {
    var used = {}; c.rows.forEach(function (x) { used[x.r.grade === 'A+' ? 'A' : x.r.grade] = 1; });
    var chips = GRADES.filter(function (g) { return g[0] !== 'A+'; }).map(function (g) {
      return '<span class="hs-pt' + (used[g[0]] ? ' is-used' : '') + '"><b>' + (g[0] === 'A' ? 'A+ / A' : dL(g[0])) + '</b>' + g[1].toFixed(1) + '</span>';
    }).join('');
    return '<div class="hs-key"><p class="hs-key-h">Grade points (4.0 scale)</p><div class="hs-pts">' + chips + '</div>' +
      '<p class="hs-key-b"><span class="hs-boost">Honors <b>+0.5</b></span><span class="hs-boost">AP, IB, dual enrollment <b>+1.0</b></span><span class="hs-key-n">Boosts count toward weighted GPA only, and never on an F.</span></p></div>';
  }
  function renderCalc(c) {
    var rows = c.rows.map(function (x) {
      return '<tr><td>' + rowName(x) + (state.terms.length > 1 ? ' <small>' + esc(x.t.name || '') + '</small>' : '') + '</td><td>' + dL(x.r.grade) + '</td><td>' + LNAME[x.r.level] + '</td>' +
        (state.showCr ? '<td>' + fmt(x.c) + '</td>' : '') + '<td>' + x.p.uw.toFixed(1) + '</td><td>' + x.p.w.toFixed(1) + '</td></tr>';
    }).join('');
    if (c.prior) rows += '<tr><td>Earlier GPA <small>' + fmt(c.prior.n) + (state.showCr ? ' credits' : ' classes') + '</small></td><td>–</td><td>–</td>' + (state.showCr ? '<td>' + fmt(c.prior.n) + '</td>' : '') + '<td>' + f2(c.prior.uw) + '</td><td>' + f2(c.prior.w) + '</td></tr>';
    var unit = state.showCr ? 'credits' : 'classes';
    $('.hs-calc-in').innerHTML = '<div class="hs-tblwrap"><table class="hs-tbl"><thead><tr><th>Class</th><th>Grade</th><th>Level</th>' + (state.showCr ? '<th>Credits</th>' : '') + '<th>Points</th><th>Weighted</th></tr></thead><tbody>' + rows + '</tbody></table></div>' +
      '<p class="hs-note">Unweighted = ' + fmt(c.uwP) + ' points ÷ ' + fmt(c.C) + ' ' + unit + ' = <b>' + f2(c.uw) + '</b>. Weighted = ' + fmt(c.wP) + ' ÷ ' + fmt(c.C) + ' = <b>' + f2(c.w) + '</b>. ' +
      'Schools vary, so check your transcript legend.</p>' + scaleKey(c);
  }

  /* ------------------------------------------------------------ planner */
  function renderPlan(c) {
    var b = state.basis, T = state.target, F = num(state.future), unit = state.showCr ? 'credits' : 'classes';
    var mi = mixInfo(F), wmax = mi.set && !mi.over ? 4 + mi.bonus : 5, max = b === 'w' ? wmax : 4;
    $$('.hs-seg button').forEach(function (x) { x.setAttribute('aria-pressed', String(x.dataset.b === b)); });
    var tg = b === 'w' ? [3.5, 4.0, 4.3, 4.5] : [3.0, 3.5, 3.7, 3.9];
    $('.hs-tchips').innerHTML = tg.map(function (v) { return '<button type="button" class="hs-chip" data-t="' + v + '" aria-pressed="' + (Math.abs(T - v) < 1e-9) + '">' + v.toFixed(1) + '</button>'; }).join('');
    var fc = [['1 semester', 6], ['1 year', 12], ['2 years', 24]];
    $('.hs-fchips').innerHTML = fc.map(function (f) { return '<button type="button" class="hs-chip" data-f="' + f[1] + '" aria-pressed="' + (F === f[1]) + '">' + f[0] + '<small>' + f[1] + ' ' + unit + '</small></button>'; }).join('');
    $('.hs-fut-l').textContent = state.showCr ? 'Credits I still have left' : 'Classes I still have left';
    // upcoming course mix (weighted only)
    var mixEl = $('.hs-mix'); mixEl.hidden = b !== 'w' || !(F > 0);
    if (!mixEl.hidden) {
      var reg = Math.max(0, F - mi.n);
      $('.hs-mix-c').innerHTML = (mi.over ? '<b class="is-bad">' + fmt(mi.n) + ' of ' + fmt(F) + '</b> — more than you have left' : fmt(mi.n) + ' of ' + fmt(F) + ' advanced · <b>' + fmt(reg) + '</b> regular');
      $$('.hs-step-n', mixEl).forEach(function (el) { el.textContent = num(state.mix[el.dataset.m]) > 0 ? fmt(num(state.mix[el.dataset.m])) : '0'; });
      $$('.hs-stp', mixEl).forEach(function (bt) { var k = bt.dataset.m, v = num(state.mix[k]) || 0; bt.disabled = bt.dataset.d === '-' ? v <= 0 : mi.n >= F; });
    }
    var cur = b === 'w' ? c.w : c.uw;
    $('.hs-plannote').innerHTML = '';
    var out = $('.hs-plan-out');
    if (!(F > 0)) { out.innerHTML = '<div class="hs-placeholder">Pick how many ' + unit + ' you have left to see the grades you need.</div>'; return; }
    if (!(T > 0 && T <= 5 && (b === 'w' || T <= 4))) { out.innerHTML = '<div class="hs-placeholder">Enter a target between 0 and ' + (b === 'w' ? '5.0' : '4.0') + '.</div>'; return; }
    track('hs_plan', { basis: b });
    var n = need(c, T, F, b), lv, msg;
    // letter-grade average those classes need (weighted: remove the bonus from the upcoming mix)
    var gNeed = b === 'w' ? n - mi.bonus : n, L = gNeed > 0 && gNeed <= 4.0001 ? letterOf(Math.min(gNeed, 4)) : null;
    var mixTxt = b === 'w' && mi.set && !mi.over ? ' With your planned Honors/AP/IB classes, that\'s about ' + (L ? article(L) + ' <b>' + dL(L) + '</b> average (' + f2(Math.max(gNeed, 0)) + ')' : 'a perfect record') + ' in those classes.' : '';
    if (n <= 0) { lv = 'ok'; msg = 'You\'re already there. Even with low grades you\'d finish at ' + f2(finishWith(c, 0, F, b)) + ' or higher.'; }
    else if (n <= cur + 1e-9 && n <= max) { lv = 'ok'; msg = 'You\'re on track. Averaging <b>' + f2(n) + '</b>' + (b === 'w' ? ' weighted' : ' (about ' + article(L) + ' <b>' + dL(L) + '</b>)') + ' keeps you at ' + T.toFixed(1) + ' or higher.' + mixTxt; }
    else if (n > max + 1e-9) {
      lv = 'bad';
      msg = 'Not reachable with ' + fmt(F) + ' ' + unit + ' left' + (b === 'w' && mi.set ? ' and this class mix' : '') + '. Straight A\'s would get you to <b>' + f2(finishWith(c, max, F, b)) + '</b>.' +
        (b === 'w' && mi.set ? ' More AP/IB classes would raise the ceiling.' : '');
    } else {
      var gl = b === 'w' && mi.set ? gNeed : n;
      lv = gl <= 3.3 ? 'ok' : gl <= 3.7 ? 'mid' : 'hard';
      if (b === 'w' && !mi.set) msg = 'Average <b>' + f2(n) + '</b> weighted in your next ' + fmt(F) + ' ' + unit + '.' + (n > 4 ? ' That needs Honors/AP/IB classes. <b>Add your upcoming class levels</b> below to see the grades it takes.' : ' Add your upcoming class levels below to see the letter grades it takes.');
      else if (b === 'w') msg = 'Average <b>' + f2(n) + '</b> weighted in your next ' + fmt(F) + ' ' + unit + '.' + mixTxt;
      else msg = 'Average <b>' + f2(n) + '</b> in your next ' + fmt(F) + ' ' + unit + ' (about ' + article(L) + ' <b>' + dL(L) + '</b> average) to reach ' + T.toFixed(1) + '.';
    }
    var dl = { ok: 'Doable', mid: 'Stretch', hard: 'Tough', bad: 'Out of reach' }[lv];
    var ladder = tg.concat(b === 'w' ? [5.0] : [4.0]).filter(function (v, i, a) { return a.indexOf(v) === i; }).map(function (t) {
      var x = need(c, t, F, b), bad = x > max + 1e-9, cls = bad ? 'is-bad' : x <= cur ? 'is-ok' : '';
      return '<li class="' + cls + (Math.abs(t - T) < 1e-9 ? ' is-cur' : '') + '"><span>' + t.toFixed(1) + '</span><b>' + (bad ? '—' : x <= 0 ? '✓' : f2(x)) + '</b></li>';
    }).join('');
    var pre = [['A', 4.0], ['A−', 3.7], ['B+', 3.3], ['B', 3.0]];
    if (!state.wiTouched) { state.whatIf = pre.reduce(function (a, p) { return Math.abs(p[1] - c.uw) < Math.abs(a - c.uw) ? p[1] : a; }, 4.0); }
    var wi = clamp(state.whatIf, 0, 4);
    out.innerHTML =
      '<div class="hs-need hs-lv-' + lv + '"><div class="hs-need-n">' + (lv === 'bad' ? '—' : n <= 0 ? '✓' : f2(n)) + '</div><div class="hs-need-r"><p>' + msg + '</p>' +
        '<div class="hs-diff"><div class="hs-diff-bar"><i style="width:' + clamp(n / max * 100, 4, 100) + '%"></i></div><span>' + dl + '</span></div></div></div>' +
      '<div class="hs-sub2"><span class="hs-flbl">Average needed for each goal' + (b === 'w' ? ' <em>(weighted)</em>' : '') + '</span><ul class="hs-ladder">' + ladder + '</ul></div>' +
      '<div class="hs-whatif"><div class="hs-wi-h"><span class="hs-flbl">Try different grades</span><div class="hs-chips hs-wchips">' +
        pre.map(function (p) { return '<button type="button" class="hs-chip hs-wp" data-w="' + p[1] + '" aria-pressed="' + (Math.abs(wi - p[1]) < 1e-9) + '">All ' + p[0] + '</button>'; }).join('') + '</div></div>' +
        '<input class="hs-range" type="range" min="1" max="4" step="0.1" value="' + wi + '" aria-label="What-if grade average">' +
        '<p class="hs-wi-out"></p></div>' +
      '<div class="hs-next"><span>Keep going</span><a href="' + gpaScaleUrl(c.uw) + '">Is a ' + (Math.floor(c.uw * 10 + 1e-9) / 10).toFixed(1) + ' GPA good?</a><a href="' + SITE + '/how-to-raise-gpa/">How to raise your GPA</a><a href="' + SITE + '/weighted-gpa-calculator/">Weighted GPA calculator</a><a href="' + SITE + '/college-gpa-calculator/">College GPA calculator</a></div>';
    paintWhatIf(c);
  }
  function paintWhatIf(c) {
    var o = $('.hs-wi-out'); if (!o) return;
    c = c || compute();
    var F = num(state.future), wi = clamp(state.whatIf, 0, 4), mi = mixInfo(F), L = letterOf(wi);
    $$('.hs-wp').forEach(function (bt) { bt.setAttribute('aria-pressed', String(Math.abs(wi - +bt.dataset.w) < 1e-9)); });
    var u = finishWith(c, wi, F, 'uw'), w = (c.wP + (wi + (wi > 0 ? mi.bonus : 0)) * F) / (c.C + F);
    o.innerHTML = '<span class="hs-wi-l">Averaging ' + article(L) + ' <b>' + dL(L) + '</b> (' + wi.toFixed(1) + ') from here, you\'d graduate with</span>' +
      '<span class="hs-wi-stats"><span class="hs-wi-n"><b>' + f2(u) + '</b><small>Unweighted</small></span><span class="hs-wi-n"><b>' + f2(w) + '</b><small>Weighted' + (mi.set && !mi.over ? '' : ' · if the rest are regular') + '</small></span></span>';
  }

  /* ------------------------------------------------------------ actions */
  function toast(msg, undo) {
    var t = $('.hs-toast'); t.textContent = msg;
    if (undo) t.insertAdjacentHTML('beforeend', '<button type="button" class="hs-undo">Undo</button>');
    t.classList.toggle('has-act', !!undo); t.classList.add('is-on'); clearTimeout(toast.t);
    toast.t = setTimeout(function () { t.classList.remove('is-on'); undoSnap = null; }, undo ? 6000 : 2200);
  }
  var undoSnap = null;
  function changed() { restored = false; saveDraft(); update(); }
  function go(n) {
    if (n > 1 && !(compute().C > 0)) return;
    state.step = n; update(); saveDraft();
    if (n === 2) { track('hs_step_2', {}); if (!state.future) setTimeout(function () { var f = $('.hs-future'); if (f && window.innerWidth > 640) f.focus(); }, 50); }
    var top = root.getBoundingClientRect().top; if (top < 0) window.scrollTo({ top: window.pageYOffset + top - 90, behavior: 'smooth' });
  }
  function addRow(t, focus) {
    if (t.rows.length >= 20) return toast('Max 20 classes per semester');
    t.rows.push(newRow()); t.open = true; renderTerms(); saveDraft(); update();
    if (focus) { var el = root.querySelector('.hs-term[data-t="' + t.id + '"] .hs-row:last-child [data-k=' + (window.innerWidth > 640 ? 'name' : 'grade') + ']'); if (el) el.focus(); }
  }
  function addTerm() {
    if (state.terms.length >= 12) return toast('Max 12 semesters');
    state.terms.forEach(function (t) { t.open = !t.rows.some(function (r) { return r.grade; }); });
    var nt = newTerm(); state.terms.push(nt);
    renderTerms(); saveDraft(); update(); track('hs_add_term', {}, true);
    var el = root.querySelector('.hs-term[data-t="' + nt.id + '"] .hs-tname'); if (el) el.focus();
  }
  function startOver(withUndo) {
    var had = withUndo && !state.sample && compute().n > 0;
    undoSnap = had ? { data: snapshot(), saveId: state.saveId, step: state.step } : null;
    var id = state.saveId; state = fresh(); restored = false;
    if (id) state.saveId = null;
    try { history.replaceState(null, '', location.pathname + location.search); } catch (e) { }
    render(); flushDraft();
    if (had) toast('Cleared your classes.', true);
  }
  function undoStartOver() {
    if (!undoSnap) return;
    var u = undoSnap; undoSnap = null;
    apply(u.data, { saveId: u.saveId }); restored = false; render(); flushDraft();
    $('.hs-toast').classList.remove('is-on');
  }
  function loadSample(key) {
    var S = SAMPLES[key] || SAMPLES.fresh;
    apply({ name: S.name, target: S.target, basis: S.basis, future: S.future, mix: S.mix, whatIf: 3.7, terms: S.terms.map(function (t, i) { return { name: t.name, open: i === S.terms.length - 1, rows: t.rows.map(function (r) { return { name: r[0], grade: r[1], level: r[2], auto: r[2] !== 'reg' }; }) }; }) }, { sample: true });
    restored = false; render(); flushDraft(); track('hs_sample', { which: key });
    var top = root.getBoundingClientRect().top; if (top < 0) window.scrollTo({ top: window.pageYOffset + top - 90, behavior: 'smooth' });
  }
  function doSave(fromPlan) {
    if (state.sample) return toast('Samples can\'t be saved. Clear it and add your classes.');
    if (!(compute().n > 0)) return toast('Add at least one grade first');
    var list = getSaves(), name = state.name.trim(), i = -1;
    if (!name) name = 'My classes · ' + niceDate(Date.now());
    state.name = name;
    list.forEach(function (s, k) { if (s.id === state.saveId) i = k; });
    var cc = compute(), rec = { id: state.saveId || String(Date.now()), name: name, ts: Date.now(), data: snapshot(), gpa: { w: cc.w, uw: cc.uw } };
    if (i >= 0) list.splice(i, 1);
    list.unshift(rec); state.saveId = rec.id;
    if (putSaves(list)) { toast('Saved as "' + name + '". Rename it in My saves.'); track('hs_save', { from: fromPlan }, true); } else toast('Couldn\'t save (browser storage is off)');
    renderCount(); flushDraft(); update();
  }
  function curSave() { if (!state.saveId) return null; var a = getSaves(); for (var i = 0; i < a.length; i++) if (a[i].id === state.saveId) return a[i]; return null; }
  // Compare what matters (classes, grades, plan) — not UI state like collapsed semesters or the what-if slider.
  function key(snap) { var x = JSON.parse(JSON.stringify(snap)); delete x.whatIf; delete x.name; (x.terms || []).forEach(function (t) { delete t.open; (t.rows || []).forEach(function (r) { delete r.auto; }); }); return JSON.stringify(x); }
  var normCache = {};
  function savedKey(sv) { var k = sv.id + ':' + sv.ts; if (!normCache[k]) { var keep = state; apply(sv.data); normCache[k] = key(snapshot()); state = keep; } return normCache[k]; }
  function isDirty(sv) { return !sv || savedKey(sv) !== key(snapshot()); }
  function renderSaveCard(c) {
    var box = $('.hs-savecard'), sv = curSave(), dl = $('.hs-delta');
    if (state.sample) { box.hidden = true; dl.hidden = true; return; }
    box.hidden = false;
    if (!sv) {
      box.className = 'hs-savecard';
      box.innerHTML = '<div class="hs-sc-ic">' + IC.save + '</div><div class="hs-sc-t"><b>Save your classes for next time</b><span>Come back after report cards, add your new grades, and watch your GPA change. No account needed.</span></div>' +
        '<button type="button" class="hs-soft hs-save3">' + IC.save + 'Save my GPA</button>';
      dl.hidden = true; return;
    }
    var dirty = isDirty(sv);
    box.className = 'hs-savecard is-saved' + (dirty ? ' is-dirty' : '');
    box.innerHTML = '<div class="hs-sc-ic">' + (dirty ? IC.save : IC.check) + '</div><div class="hs-sc-t"><b>' + (dirty ? 'You have unsaved changes' : 'Saved as "' + esc(sv.name) + '"') + '</b><span>' +
      (dirty ? 'Last saved ' + when(sv.ts) + '. Save to keep your newest grades.' : 'On this device, updated ' + when(sv.ts) + '. Open it anytime from <b>My saves</b>.') + '</span></div>' +
      (dirty ? '<button type="button" class="hs-primary hs-save3">' + IC.save + 'Save changes</button>' : '<button type="button" class="hs-ghost hs-copylink2">' + IC.link + 'Link for another device</button>');
    // progress since the last save
    var d = sv.gpa && isFinite(sv.gpa.w) ? c.w - sv.gpa.w : 0;
    if (Math.abs(d) >= 0.005) { dl.hidden = false; dl.className = 'hs-delta ' + (d > 0 ? 'is-up' : 'is-down'); dl.innerHTML = (d > 0 ? '▲ +' : '▼ ') + f2(d).replace('-', '−') + ' since you saved ' + when(sv.ts); }
    else dl.hidden = true;
  }
  function menu(open) {
    var m = $('.hs-menu'), b = $('.hs-mine');
    if (open === undefined) open = m.hidden;
    m.hidden = !open; b.setAttribute('aria-expanded', String(open));
    if (!open) return;
    var list = getSaves();
    m.innerHTML = (state.saveId ? '<label class="hs-rename"><span>This save</span><input class="hs-in hs-rn" type="text" maxlength="60" aria-label="Rename this save"></label>' : '') +
      '<div class="hs-menu-h">My saves <small>on this device</small></div>' +
      (list.length ? list.map(function (s) { return '<div class="hs-mi' + (s.id === state.saveId ? ' is-cur' : '') + '"><button type="button" class="hs-mi-open" data-id="' + esc(s.id) + '"><b>' + esc(s.name) + '</b><small>' + niceDate(s.ts) + '</small></button><button type="button" class="hs-mi-del" data-id="' + esc(s.id) + '" aria-label="Delete ' + esc(s.name) + '">' + IC.x + '</button></div>'; }).join('')
        : '<p class="hs-menu-empty">Nothing saved yet. Tap Save to keep your classes for next semester.</p>') +
      '<button type="button" class="hs-mi-new">' + IC.plus + 'New calculation</button>';
    var rn = m.querySelector('.hs-rn'); if (rn) rn.value = state.name;
  }
  function rename(v) {
    state.name = v; var list = getSaves();
    list.forEach(function (s) { if (s.id === state.saveId) s.name = v.trim() || s.name; });
    putSaves(list); saveDraft();
    var cur = root.querySelector('.hs-mi.is-cur b'); if (cur) cur.textContent = v.trim() || cur.textContent;
  }
  function copy(text, ok) {
    var done = function () { toast(ok); };
    if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(text).then(done, function () { fallback(); });
    else fallback();
    function fallback() { var ta = document.createElement('textarea'); ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0'; document.body.appendChild(ta); ta.select(); try { document.execCommand('copy'); done(); } catch (e) { toast('Copy failed'); } document.body.removeChild(ta); }
  }
  function summary() {
    var c = compute(), lines = ['My ' + (c.cum ? 'cumulative ' : '') + 'GPA: ' + f2(c.w) + ' weighted, ' + f2(c.uw) + ' unweighted (' + letterOf(c.uw) + ' average)'];
    c.rows.forEach(function (x) { lines.push('• ' + (x.r.name || 'Class') + ': ' + x.r.grade + (x.r.level !== 'reg' ? ' (' + LNAME[x.r.level] + ')' : '')); });
    if (c.prior) lines.push('• Earlier GPA: ' + f2(c.prior.uw) + ' unweighted over ' + fmt(c.prior.n) + (state.showCr ? ' credits' : ' classes'));
    lines.push('Calculated at ' + location.origin + location.pathname);
    return lines.join('\n');
  }
  function csv() {
    var c = compute(), q = function (s) { s = String(s); if (/^[=+\-@\t\r]/.test(s) && isNaN(+s)) s = "'" + s; return /[",\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s; };
    var out = [['Semester', 'Class', 'Grade', 'Level', 'Credits', 'Unweighted points', 'Weighted points']];
    c.rows.forEach(function (x) { out.push([x.t.name, x.r.name, x.r.grade, LNAME[x.r.level], x.c, x.p.uw, x.p.w]); });
    if (c.prior) out.push(['Earlier GPA', '', '', '', c.prior.n, f2(c.prior.uw), f2(c.prior.w)]);
    out.push([]); out.push(['Unweighted GPA', f2(c.uw)]); out.push(['Weighted GPA', f2(c.w)]);
    var blob = new Blob([out.map(function (r) { return r.map(q).join(','); }).join('\n')], { type: 'text/csv' });
    var a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = 'high-school-gpa.csv'; document.body.appendChild(a); a.click();
    setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 500);
    track('hs_share', { how: 'csv' }, true);
  }

  /* ------------------------------------------------------------ class-name suggestions (combobox) */
  var sug = { inp: null, items: [], act: -1 }, SHORT = { hon: 'Honors', ap: 'AP', ib: 'IB', de: 'DE' };
  function hl(name, q) {
    var out = esc(name);
    words(q).forEach(function (w) { out = out.replace(new RegExp('(^|[^A-Za-z0-9>])(' + w + ')', 'i'), '$1<b>$2</b>'); });
    return out;
  }
  function openSug(inp) {
    var box = $('.hs-sug'), list = suggest(inp.value);
    sug.inp = inp; sug.items = list; sug.act = -1;
    if (!list.length) return closeSug();
    box.innerHTML = list.map(function (c, i) {
      return '<div class="hs-opt" role="option" id="hs-opt-' + i + '" data-i="' + i + '" aria-selected="false"><span class="hs-opt-n">' + hl(c[0], inp.value) + '</span>' +
        '<span class="hs-opt-s">' + esc(c[1]) + '</span>' + (c[2] !== 'reg' ? '<span class="hs-badge" data-lv="' + c[2] + '">' + SHORT[c[2]] + '</span>' : '<span class="hs-badge is-none"></span>') + '</div>';
    }).join('');
    var hr = root.querySelector('.hsx').getBoundingClientRect(), r = inp.getBoundingClientRect();
    box.style.top = (r.bottom - hr.top + 6) + 'px'; box.style.left = (r.left - hr.left) + 'px'; box.style.width = Math.max(r.width, Math.min(340, hr.width - (r.left - hr.left) - 12)) + 'px';
    box.hidden = false; inp.setAttribute('aria-expanded', 'true'); inp.removeAttribute('aria-activedescendant');
  }
  function closeSug() { var box = root && $('.hs-sug'); if (box) box.hidden = true; if (sug.inp) { sug.inp.setAttribute('aria-expanded', 'false'); sug.inp.removeAttribute('aria-activedescendant'); } sug.items = []; sug.act = -1; }
  function moveSug(d) {
    if (!sug.items.length) return;
    sug.act = (sug.act + d + sug.items.length) % sug.items.length;
    $$('.hs-opt').forEach(function (o, i) { o.setAttribute('aria-selected', String(i === sug.act)); });
    sug.inp.setAttribute('aria-activedescendant', 'hs-opt-' + sug.act);
    var a = $('#hs-opt-' + sug.act); if (a && a.scrollIntoView) a.scrollIntoView({ block: 'nearest' });
  }
  function pickSug(i) {
    var c = sug.items[i], inp = sug.inp; if (!c || !inp) return;
    var row = inp.closest('.hs-row'), f = findRow(+row.dataset.id); if (!f) return;
    inp.value = c[0]; f.r.name = c[0];
    if (c[2] !== 'reg') { f.r.level = c[2]; f.r.auto = true; } else if (f.r.auto) { f.r.level = 'reg'; f.r.auto = false; }
    row.querySelector('[data-k=level]').value = f.r.level; paintSel(row);
    closeSug(); track('hs_suggest', { level: c[2] }); changed();
    var g = row.querySelector('[data-k=grade]');
    if (window.innerWidth > 640 && g && !g.value) g.focus(); else inp.blur();
  }

  /* ------------------------------------------------------------ events */
  function bind() {
    root.addEventListener('input', function (e) {
      var el = e.target;
      if (el.classList.contains('hs-rn')) return rename(el.value);
      if (el.classList.contains('hs-tname')) { var t0 = findTerm(+el.closest('.hs-term').dataset.t); if (t0) { t0.name = el.value; saveDraft(); } return; }
      if (el.dataset.p) { var v = cleanNum(el.value, 5); if (v !== el.value) el.value = v; state.prior[el.dataset.p] = v; return changed(); }
      if (el.classList.contains('hs-target')) { var tv = cleanNum(el.value, 4); if (tv !== el.value) el.value = tv; var n = num(tv); if (n > 0) state.target = n; return changed(); }
      if (el.classList.contains('hs-future')) { var fv = cleanNum(el.value, 4); if (fv !== el.value) el.value = fv; state.future = fv; return changed(); }
      if (el.classList.contains('hs-range')) { state.wiTouched = true; state.whatIf = +el.value; paintWhatIf(); saveDraft(); track('hs_whatif', {}); return; }
      var k = el.dataset.k; if (!k || el.tagName === 'SELECT') return;
      var f = findRow(+el.closest('.hs-row').dataset.id); if (!f) return;
      if (k === 'credits') { var cv = cleanNum(el.value, 4); if (cv !== el.value) el.value = cv; f.r.credits = cv; }
      if (k === 'name') {
        f.r.name = el.value;
        var gl = guessLevel(el.value);
        if (gl && (f.r.level === 'reg' || f.r.auto)) { f.r.level = gl; f.r.auto = true; var ls = el.closest('.hs-row').querySelector('[data-k=level]'); ls.value = gl; paintSel(el.closest('.hs-row')); }
        else if (!gl && f.r.auto) { f.r.level = 'reg'; f.r.auto = false; var ls2 = el.closest('.hs-row').querySelector('[data-k=level]'); ls2.value = 'reg'; paintSel(el.closest('.hs-row')); }
      }
      if (state.sample && (k === 'name')) state.sample = false;
      changed();
      if (k === 'name') openSug(el);
    });
    root.addEventListener('focusin', function (e) { if (e.target.matches('input,select')) root.querySelector('.hsx').classList.add('is-typing'); });
    root.addEventListener('focusout', function () { setTimeout(function () { var a = document.activeElement; if (!a || !root.contains(a) || !a.matches('input,select')) root.querySelector('.hsx').classList.remove('is-typing'); }, 50); });
    root.addEventListener('focusin', function (e) { var el = e.target; if (el.dataset && el.dataset.k === 'name' && el.value) openSug(el); });
    root.addEventListener('focusout', function (e) { if (e.target === sug.inp) setTimeout(function () { if (document.activeElement !== sug.inp) closeSug(); }, 120); });
    root.addEventListener('mousedown', function (e) { if (e.target.closest('.hs-sug')) e.preventDefault(); });
    root.addEventListener('click', function (e) { var o = e.target.closest('.hs-opt'); if (o) pickSug(+o.dataset.i); });
    window.addEventListener('resize', closeSug);
    root.addEventListener('change', function (e) {
      var el = e.target;
      if (el.classList.contains('hs-cr')) { state.showCr = el.checked; root.querySelector('.hsx').classList.toggle('show-cr', el.checked); track('hs_credits', {}); return changed(); }
      if (el.tagName !== 'SELECT' || !el.dataset.k) return;
      var row = el.closest('.hs-row'), f = findRow(+row.dataset.id); if (!f) return;
      f.r[el.dataset.k] = el.value; if (el.dataset.k === 'level') f.r.auto = false;
      paintSel(row);
      // grade picked on the last row → add a fresh one so there's always an empty row ready
      if (el.dataset.k === 'grade' && el.value && f.i === f.t.rows.length - 1 && f.t.rows.length < 20) { f.t.rows.push(newRow()); var rowsEl = row.parentNode; rowsEl.appendChild(rowView(f.t.rows[f.t.rows.length - 1], f.t.rows.length - 1)); refreshDel(row.closest('.hs-term'), f.t); }
      changed();
    });
    root.addEventListener('click', function (e) {
      var b = e.target.closest('button'); if (!b || !root.contains(b)) { if (!e.target.closest('.hs-menuwrap')) menu(false); return; }
      if (!b.closest('.hs-menuwrap')) menu(false);
      if (b.classList.contains('hs-go')) return go(+b.dataset.go);
      if (b.classList.contains('hs-addrow')) { var t = findTerm(+b.closest('.hs-term').dataset.t); return addRow(t, true); }
      if (b.classList.contains('hs-addterm')) return addTerm();
      if (b.classList.contains('hs-del')) {
        var f = findRow(+b.closest('.hs-row').dataset.id); if (!f || f.t.rows.length <= 1) return;
        f.t.rows.splice(f.i, 1); b.closest('.hs-row').remove(); refreshDel(root.querySelector('.hs-term[data-t="' + f.t.id + '"]'), f.t); return changed();
      }
      if (b.classList.contains('hs-tog')) { var t1 = findTerm(+b.closest('.hs-term').dataset.t); t1.open = !t1.open; b.closest('.hs-term').classList.toggle('is-open', t1.open); b.setAttribute('aria-expanded', String(t1.open)); saveDraft(); return; }
      if (b.classList.contains('hs-tdel')) {
        var t2 = findTerm(+b.closest('.hs-term').dataset.t), hasG = t2.rows.some(function (r) { return r.grade; });
        if (hasG && !window.confirm('Remove this semester and its grades?')) return;
        state.terms = state.terms.filter(function (x) { return x !== t2; }); if (state.terms.length === 1) state.terms[0].open = true;
        renderTerms(); return changed();
      }
      if (b.classList.contains('hs-priorbtn')) { state.prior.on = true; update(); var pi = $('[data-p=uw]'); if (pi) pi.focus(); track('hs_prior', {}); return saveDraft(); }
      if (b.classList.contains('hs-priorx')) { state.prior = { on: false, uw: '', w: '', n: '' }; $$('[data-p]').forEach(function (i) { i.value = ''; }); return changed(); }
      if (b.classList.contains('hs-sample')) return loadSample(b.dataset.s);
      if (b.classList.contains('hs-addterm2')) { restored = false; return addTerm(); }
      if (b.classList.contains('hs-copylink2')) { track('hs_share', { how: 'link' }, true); return copy(shareUrl(), 'Link copied. Open it on your phone or laptop to keep going.'); }
      if (b.classList.contains('hs-undo')) return undoStartOver();
      if (b.classList.contains('hs-clear-sample')) return startOver(false);
      if (b.classList.contains('hs-reset') || b.classList.contains('hs-reset2')) return startOver(true);
      if (b.classList.contains('hs-toresult')) { var r = $('.hs-result'); r.scrollIntoView({ behavior: 'smooth', block: 'start' }); return; }
      if (b.classList.contains('hs-stp')) { var mk = b.dataset.m, mv = num(state.mix[mk]) || 0; mv = b.dataset.d === '-' ? Math.max(0, mv - 1) : mv + 1; state.mix[mk] = mv ? String(mv) : ''; track('hs_mix', {}); return changed(); }
      if (b.classList.contains('hs-wp')) { state.wiTouched = true; state.whatIf = +b.dataset.w; var rg = $('.hs-range'); if (rg) rg.value = state.whatIf; paintWhatIf(); saveDraft(); track('hs_whatif', {}); return; }
      if (b.classList.contains('hs-chip') && b.dataset.t) { state.target = +b.dataset.t; $('.hs-target').value = ''; return changed(); }
      if (b.classList.contains('hs-chip') && b.dataset.f) { state.future = b.dataset.f; $('.hs-future').value = ''; return changed(); }
      if (b.dataset.b) { if (state.basis !== b.dataset.b) { state.basis = b.dataset.b; var tg = b.dataset.b === 'w' ? 4.0 : 3.5; if (b.dataset.b === 'uw' && state.target > 4) state.target = tg; if (b.dataset.b === 'w' && state.target <= 4 && state.target === 3.5) state.target = tg; $('.hs-target').value = [3.0, 3.5, 3.7, 3.9, 4.0, 4.3, 4.5].indexOf(state.target) >= 0 ? '' : fmtT(state.target); } return changed(); }
      if (b.classList.contains('hs-save') || b.classList.contains('hs-save2') || b.classList.contains('hs-save3')) return doSave(b.classList.contains('hs-save2') ? 'plan' : b.classList.contains('hs-save3') ? 'card' : 'top');
      if (b.classList.contains('hs-mine')) return menu();
      if (b.classList.contains('hs-mi-open')) { var s = getSaves().filter(function (x) { return x.id === b.dataset.id; })[0]; if (s) { apply(s.data, { saveId: s.id }); restored = false; render(); flushDraft(); toast('Opened ' + s.name); } return menu(false); }
      if (b.classList.contains('hs-mi-del')) { putSaves(getSaves().filter(function (x) { return x.id !== b.dataset.id; })); if (state.saveId === b.dataset.id) state.saveId = null; renderCount(); return menu(true); }
      if (b.classList.contains('hs-mi-new')) { menu(false); return startOver(true); }
      if (b.classList.contains('hs-copylink')) { track('hs_share', { how: 'link' }, true); return copy(shareUrl(), 'Link copied. It opens with your classes filled in.'); }
      if (b.classList.contains('hs-copytext')) { track('hs_share', { how: 'text' }, true); return copy(summary(), 'Summary copied'); }
      if (b.classList.contains('hs-csv')) return csv();
      if (b.classList.contains('hs-print')) { track('hs_share', { how: 'print' }, true); var d = $('.hs-calc'); if (d) d.open = true; return window.print(); }
    });
    root.addEventListener('keydown', function (e) {
      var open = !$('.hs-sug').hidden && e.target === sug.inp;
      if (open && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) { e.preventDefault(); return moveSug(e.key === 'ArrowDown' ? 1 : -1); }
      if (open && e.key === 'Enter' && sug.act >= 0) { e.preventDefault(); return pickSug(sug.act); }
      if (open && e.key === 'Escape') { e.preventDefault(); return closeSug(); }
      if (open && e.key === 'Enter') closeSug();
      if (e.key === 'Escape') return menu(false);
      if (e.key !== 'Enter' || e.target.tagName !== 'INPUT' || !e.target.closest('.hs-row')) return;
      e.preventDefault();
      var row = e.target.closest('.hs-row'), next = row.nextElementSibling, k = e.target.dataset.k;
      if (next) { var n = next.querySelector('[data-k=' + k + ']'); if (n) n.focus(); }
      else { var t = findTerm(+row.closest('.hs-term').dataset.t); addRow(t, true); }
    });
    window.addEventListener('pagehide', flushDraft);
    document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'hidden' && saveT) flushDraft(); });
    if ('IntersectionObserver' in window) {
      new IntersectionObserver(function (en) { heroInView = en[0].isIntersecting; paintLive(); }, { rootMargin: '0px 0px -80px 0px' }).observe($('.hs-hero'));
    }
  }

  function init() {
    root = document.getElementById('root'); if (!root || root.dataset.hsReady) return;
    root.dataset.hsReady = '1';
    loadInitial(); build(); bind();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
