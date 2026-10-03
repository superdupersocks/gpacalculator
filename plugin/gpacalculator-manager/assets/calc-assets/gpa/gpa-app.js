/* gpacalculator.net GPA calculator screen v1.3.0 (gpacalculator-manager plugin)
 * One screen for every GPA-engine calculator; each page passes its profile (profiles/*.js).
 * Layout, spacing, result pill, saves, sample, options, phone entry and the goal line follow the Calculator Design
 * Standard (docs/calculator-design-standard.md). Look: core/calc-core.css + gpa/gpa-app.css (tokens only).
 * Math: engines/gpa-engine.js. Services from core/calc-core.js: storage, share, GA4, menus, toast, pill. */
import {
  h, setText, createStore, readHash, shareUrl, clearHash, copyText, downloadCSV, createTracker,
  createLivePill, createMenu, createActionToast, createSheet, trackCalculatorUsed, sendEvent, importLegacyOnce, printPage,
  reducedMotion, watchErrors, createHomeScreenHint,
} from '../core/calc-core.js';
import * as E from '../engines/gpa-engine.js';

let uid = 0;
const newId = (p = 'r') => `${p}${Date.now().toString(36)}${(uid++).toString(36)}`;

/* ---------- State ---------- */

function blankRow(profile, i = 0) {
  const types = profile.courseTypes;
  return { id: newId(), name: '', grade: '', credits: profile.defaultCredits || '', major: false, type: types ? types[0].name : undefined, _ph: i };
}

function blankState(profile) {
  const terms = profile.fixedTerms
    ? profile.fixedTerms.map((t) => ({ id: t.id, name: t.name, planned: !!t.planned, rows: t.planned ? [] : rowsOf(profile, 1) }))
    : [{ id: newId('t'), name: termName(profile, 0), rows: rowsOf(profile, 1) }];
  return { v: 1, scale: profile.defaultScale, prior: { gpa: '', credits: '' }, priorOpen: false, showMajor: false, terms, target: { gpa: '', credits: '' } };
}

function rowsOf(profile, n) {
  return Array.from({ length: n }, (_, i) => blankRow(profile, i));
}

function termName(profile, i) {
  const names = profile.termNames || [];
  return names[i] || `${profile.termWord || 'Semester'} ${i + 1}`;
}

/** Bring any saved / shared / sample state into the current shape, with fresh ids. */
function normalize(profile, s) {
  const base = blankState(profile);
  if (!s || typeof s !== 'object' || !Array.isArray(s.terms)) return base;
  const scale = (profile.scales.find((x) => x.id === s.scale) || profile.scales[0]).id;
  const str = (v) => (v == null ? '' : String(v)).slice(0, 80);
  const typeNames = (profile.courseTypes || []).map((t) => t.name);
  const row = (r, i) => ({
    id: newId(), name: str(r.name), grade: str(r.grade), credits: str(r.credits), major: !!r.major,
    type: typeNames.length ? (typeNames.includes(r.type) ? r.type : typeNames[0]) : undefined, _ph: i,
  });
  let terms = s.terms.slice(0, 24).map((t, i) => ({
    id: profile.fixedTerms ? (t.id || newId('t')) : newId('t'),
    name: str(t.name) || termName(profile, i), planned: !!t.planned,
    rows: (Array.isArray(t.rows) ? t.rows : []).slice(0, 40).map(row),
  }));
  if (profile.fixedTerms) {
    terms = profile.fixedTerms.map((ft) => {
      const found = terms.find((t) => t.id === ft.id) || terms.find((t) => !!t.planned === !!ft.planned);
      return { id: ft.id, name: ft.name, planned: !!ft.planned, rows: found ? found.rows : [] };
    });
  }
  if (!terms.length) return base;
  return {
    v: 1, scale,
    prior: { gpa: str(s.prior && s.prior.gpa), credits: str(s.prior && s.prior.credits) },
    priorOpen: !!(s.priorOpen || (s.prior && (s.prior.gpa || s.prior.credits))),
    showMajor: !!(profile.major && (s.showMajor || terms.some((t) => t.rows.some((r) => r.major)))),
    terms,
    target: { gpa: str(s.target && s.target.gpa), credits: str(s.target && s.target.credits) },
  };
}

/** Compact copy for storage / share links (no ids, no empty rows). */
function pack(state) {
  return {
    v: 1, scale: state.scale, prior: state.prior, priorOpen: state.priorOpen || undefined, showMajor: state.showMajor || undefined,
    terms: state.terms.map((t) => ({
      id: t.id, name: t.name, planned: t.planned || undefined,
      rows: t.rows.filter((r) => r.name || r.grade).map((r) => ({
        name: r.name || undefined, grade: r.grade || undefined, credits: r.credits || undefined, major: r.major || undefined, type: r.type,
      })),
    })),
    target: state.target.gpa || state.target.credits ? state.target : undefined,
  };
}

function hasData(state) {
  return state.terms.some((t) => t.rows.some((r) => r.grade || r.name)) || !!(state.prior.gpa || state.prior.credits);
}

/* ---------- Legacy saves (Bolt homepage/college bundles) ---------- */

/** Bolt calculators kept { calculatorMode, showMajor, semesters: [{ name, courses: [{ name, grade, credits, isMajor }] }] }. */
export function fromBolt(o, mode = 'college') {
  if (!o || !Array.isArray(o.semesters) || !o.semesters.length) return null;
  if (o.calculatorMode && o.calculatorMode !== mode) return null;
  if (!o.calculatorMode && o.semesters.some((s) => (s.courses || []).some((c) => c.courseType))) return null;
  const terms = o.semesters.map((s, i) => ({
    name: s.name || `Semester ${i + 1}`,
    rows: (s.courses || []).filter((c) => c && (c.grade || c.name)).map((c) => ({
      name: c.name || '', grade: c.grade || '', credits: c.credits == null ? '' : String(c.credits), major: !!c.isMajor,
    })),
  }));
  if (!terms.some((t) => t.rows.length)) return null;
  return { v: 1, scale: 'standard', prior: { gpa: '', credits: '' }, showMajor: !!o.showMajor, terms };
}

/* ---------- Icons ---------- */

const ICONS = {
  trend: 'M3 17l6-6 4 4 8-8M15 7h6v6',
  sum: 'M18 4H6l6 8-6 8h12',
  calc: 'M7 3h10a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2zM8 7h8M8 12h2M14 12h2M8 16h2M14 16h2',
  target: 'M12 3a9 9 0 1 0 9 9M12 7a5 5 0 1 0 5 5M12 11a1 1 0 1 0 1 1',
  folder: 'M3 6a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z',
  save: 'M5 3h11l3 3v13a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2zM8 3v5h7V3M8 21v-7h8v7',
};
const iconUrl = (name) => `url("data:image/svg+xml,${encodeURIComponent(`<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='${ICONS[name] || ICONS.calc}'/></svg>`)}")`;
function svgIcon(name) {
  const NS = 'http://www.w3.org/2000/svg';
  const svg = document.createElementNS(NS, 'svg');
  for (const [k, v] of Object.entries({ viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', 'stroke-width': '2', 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'aria-hidden': 'true' })) svg.setAttribute(k, v);
  const p = document.createElementNS(NS, 'path');
  p.setAttribute('d', ICONS[name]);
  svg.append(p);
  return svg;
}

/* ---------- Screen ---------- */

export function mountGpa(root, profile, opts = {}) {
  if (root.id === 'root') root.classList.add('calc-mounted');
  root.textContent = '';
  const P = profile;
  const D = P.decimals || 2;
  const fmt = (n) => E.fmtGpa(n, D);
  const track = createTracker(P.prefix);
  const store = createStore(P.storeKey, { debounce: 500 });
  const app = h('div', { class: `calc gpa gpa-${P.id}`, 'data-profile': P.id });
  root.append(app);
  const toast = createActionToast(app);
  trackCalculatorUsed(root);
  watchErrors(P.prefix);

  let state = blankState(P);
  let mode = 'own'; // own | sample | shared
  let ownBefore = null; // the student's own state while a sample or shared calculation is shown
  let currentSave = null;
  let dirty = false;
  let res = null;
  let lastTerm = null; // the semester the student last edited ("This term" in the pill)

  /* ----- card header: Options on the left, My saves + Save on the right (no step bar) ----- */
  const optionsBtn = h('button', { type: 'button', class: 'calc-btn calc-btn-text gpa-options-btn', 'aria-expanded': 'false', 'aria-controls': `${P.id}-options` }, 'Options');
  const saveName = h('span', { class: 'calc-save-name' });
  const folderBtn = h('button', { type: 'button', class: 'calc-btn calc-btn-tool', 'aria-label': 'My saves', title: 'My saves' }, svgIcon('folder'));
  const savesMenu = h('ul', { class: 'calc-menu', role: 'menu', 'aria-label': 'My saves' });
  const saveBtn = h('button', { type: 'button', class: 'calc-btn calc-btn-tool', 'aria-label': 'Save', title: 'Save', onclick: () => saveNow() }, svgIcon('save'));
  const hasOptions = P.scales.length > 1 || !!P.prior || !!P.major;
  const toolbar = h('div', { class: 'calc-toolbar gpa-top' }, hasOptions ? optionsBtn : h('span'),
    h('div', { class: 'calc-toolbar-group' }, saveName, h('div', { class: 'calc-menu-wrap' }, folderBtn, savesMenu), saveBtn));

  const bannerText = h('span');
  const bannerActions = h('span', { class: 'calc-banner-actions' });
  const banner = h('div', { class: 'calc-banner', role: 'status', hidden: true }, bannerText, bannerActions);

  /* ----- Options (closed by default): grading scale, previous GPA, major GPA ----- */
  const scaleSel = h('select', { class: 'calc-select', id: `${P.id}-scale`, 'aria-label': 'Grading scale' });
  for (const s of P.scales) scaleSel.append(h('option', { value: s.id }, s.label));
  const priorToggle = h('button', { type: 'button', class: 'calc-btn calc-btn-text gpa-prior-toggle', 'aria-expanded': 'false' }, 'Add previous GPA');
  const majorToggle = P.major ? h('input', { type: 'checkbox', id: `${P.id}-major-on` }) : null;
  const settings = h('div', { class: 'gpa-settings' },
    P.scales.length > 1 ? h('div', { class: 'gpa-scale-field' }, scaleSel) : null,
    P.prior ? priorToggle : null,
    majorToggle ? h('label', { class: 'calc-check gpa-major-on', for: majorToggle.id }, majorToggle, h('span', null, 'Major GPA')) : null);

  const priorGpa = h('input', { class: 'calc-input is-num', id: `${P.id}-prior-gpa`, inputmode: 'decimal', autocomplete: 'off', placeholder: 'e.g. 3.20' });
  const priorCr = h('input', { class: 'calc-input is-num', id: `${P.id}-prior-cr`, inputmode: 'decimal', autocomplete: 'off', placeholder: 'e.g. 60' });
  const priorGpaMsg = h('p', { class: 'calc-hint is-error', id: `${P.id}-prior-gpa-msg` });
  const priorCrMsg = h('p', { class: 'calc-hint is-error', id: `${P.id}-prior-cr-msg` });
  priorGpa.setAttribute('aria-describedby', priorGpaMsg.id);
  priorCr.setAttribute('aria-describedby', priorCrMsg.id);
  const priorBox = h('div', { class: 'gpa-prior', hidden: true },
    h('div', { class: 'calc-field' }, h('label', { class: 'calc-label', for: priorGpa.id }, 'Current cumulative GPA'), priorGpa, priorGpaMsg),
    h('div', { class: 'calc-field' }, h('label', { class: 'calc-label', for: priorCr.id }, `${cap(P.creditWord)} completed`), priorCr, priorCrMsg));
  const optionsPanel = h('div', { class: 'gpa-options', id: `${P.id}-options`, hidden: true }, settings, P.prior ? priorBox : null);
  let optionsOpen = false;

  /* ----- rows + action row ----- */
  const termsEl = h('div', { class: 'gpa-terms' });
  const addTermBtn = P.fixedTerms ? null : h('button', { type: 'button', class: 'calc-btn calc-btn-ghost gpa-add-term' }, `Add ${(P.termWord || 'semester').toLowerCase()}`);
  const resetBtn = h('button', { type: 'button', class: 'calc-btn calc-btn-ghost gpa-reset', hidden: true }, 'Start over'); // shown once something is entered
  const sampleBtn = h('button', { type: 'button', class: 'calc-btn calc-btn-text gpa-sample', onclick: () => showSample() }, 'Try a sample');
  const savedNote = h('span', { class: 'calc-saved-note', hidden: true }, 'Saved on this device');
  const actions = h('div', { class: 'calc-actions gpa-actions' }, addTermBtn, resetBtn, sampleBtn, savedNote);

  /* ----- result panel ----- */
  const kicker = h('div', { class: 'calc-kicker' }, P.copy.resultKicker);
  const score = h('div', { class: 'calc-score', 'aria-hidden': 'true' });
  const verdict = h('p', { class: 'calc-verdict' });
  const live = h('p', { class: 'calc-sr', 'aria-live': 'polite' });
  const badge = h('span', { class: 'calc-badge gpa-letter', 'aria-hidden': 'true' });
  const stat = (k) => {
    const v = h('div', { class: 'calc-stat-v' });
    const kEl = h('div', { class: 'calc-stat-k' }, k);
    return { el: h('div', { class: 'calc-stat' }, kEl, v), v, k: kEl };
  };
  const sCredits = stat(`GPA ${P.creditWord}`);
  const sPoints = stat('Quality points');
  const sMajor = stat('Major GPA');
  const sProj = stat('With planned courses');
  const note = h('p', { class: 'calc-result-note' });
  const ctaBtn = h('button', { type: 'button', class: 'calc-btn calc-btn-primary', onclick: () => openPlanner() });
  const nextLinks = h('span', { class: 'gpa-next-links' });
  const nextEl = h('div', { class: 'calc-next' }, P.planner ? ctaBtn : null, nextLinks);
  const howBody = h('div', { class: 'gpa-how-body' });
  const how = h('details', { class: 'calc-how' }, h('summary', null, 'Show how it’s calculated'), howBody);
  how.addEventListener('toggle', () => { if (how.open) renderHow(); });
  const BLOCKS = {
    verdict: h('div', { class: 'calc-result-top' }, h('div', null, kicker, score, verdict), badge),
    stats: h('div', { class: 'calc-stats' }, sCredits.el, sPoints.el, sMajor.el, sProj.el),
    note,
    next: nextEl,
    how,
    ...(opts.blocks || {}),
  };
  const result = h('section', { class: 'calc-result', hidden: true, tabindex: '-1', 'aria-label': 'Your result' }, live,
    (P.blocks || ['verdict', 'stats', 'next', 'how']).flatMap((b) => (b === 'stats' ? [BLOCKS.stats, BLOCKS.note] : [BLOCKS[b]])).filter(Boolean));

  /* ----- goal: the planner's target, saved, shown as one status line under the result ----- */
  const goalList = h('ul', { class: 'gpa-goals' });
  const goalsEl = P.planner && P.goalFromTarget ? h('div', { class: 'gpa-goals-wrap', hidden: true }, goalList) : null;

  /* ----- planner: only after the result, opened by its button ----- */
  const tGpa = h('input', { class: 'calc-input is-num', id: `${P.id}-t-gpa`, inputmode: 'decimal', autocomplete: 'off', placeholder: 'e.g. 3.50' });
  const tCr = h('input', { class: 'calc-input is-num', id: `${P.id}-t-cr`, inputmode: 'decimal', autocomplete: 'off', placeholder: 'e.g. 15' });
  const planBig = h('div', { class: 'calc-plan-big' });
  const planLine = h('p', { class: 'calc-plan-line' });
  const planLine2 = h('p', { class: 'calc-plan-line' });
  const whatIfHost = h('div', { class: 'gpa-whatif-host' });
  const whatIf = h('div', { class: 'calc-whatif', hidden: true },
    h('div', { class: 'calc-whatif-row' }, h('strong', null, 'What if?'), h('span', { class: 'calc-hint' }, 'Drag to try a different average next term')), whatIfHost);
  const planOut = h('div', { class: 'calc-plan-out', 'aria-live': 'polite' }, planBig, planLine, planLine2);
  const planner = P.planner ? h('section', { class: 'calc-plan', hidden: true, tabindex: '-1', 'aria-label': 'Target GPA planner' },
    h('h3', null, 'Plan your target GPA'),
    h('p', { class: 'calc-sub' }, `See the average you need in your next ${P.creditWord} to reach your goal.`),
    h('div', { class: 'calc-plan-grid' },
      h('div', { class: 'calc-field' }, h('label', { class: 'calc-label', for: tGpa.id }, 'Target cumulative GPA'), tGpa),
      h('div', { class: 'calc-field' }, h('label', { class: 'calc-label', for: tCr.id }, `Upcoming ${P.creditWord}`), tCr)),
    planOut, whatIf) : null;

  const insights = h('div', { class: 'calc-insights', 'aria-label': 'Insights' });
  const card = h('div', { class: 'calc-card' }, toolbar, banner, hasOptions ? optionsPanel : null, termsEl, actions, result, goalsEl, planner, insights);
  const keep = h('div', { class: 'calc-keep', hidden: !(P.keepGoing && P.keepGoing.length) },
    h('h3', null, 'Keep going'), h('div', { class: 'calc-keep-grid' }));
  app.append(card, keep);
  const pill = createLivePill(app, result, { label: P.copy.pill || 'GPA', onOpen: () => track('pill', null, true) });
  const sheet = createSheet(app);
  // Phones: "Add it to your home screen" under the card after the 2nd calculation or a Save (opt-in per profile).
  const homeHint = P.homeScreenHint ? createHomeScreenHint(card, { calc: P.prefix, icon: P.homeScreenHint.icon || badgeUrl() }) : null;
  let loading = false;
  const resultKey = () => JSON.stringify(pack(state).terms);
  const phone = window.matchMedia('(max-width: 640px)');

  /* ----- rows and terms ----- */
  const types = P.courseTypes && P.courseTypes.length > 1 ? P.courseTypes : null;
  const colsClass = () => ['gpa-cols', types ? 'has-type' : '', P.major && state.showMajor ? 'has-major' : ''].join(' ');
  const crAbbr = P.creditAbbr || (P.creditWord === 'credits' ? 'cr' : P.creditWord);
  const gradeLabel = (g) => g.replace('-', '−');

  function gradesFor(row) {
    const t = types && types.find((x) => x.name === row.type);
    return t && t.allowed ? t.allowed : E.gradeList(E.scaleOf(P, state));
  }

  /** Grades with no points (P, NP, W) go last, under "Not counted in GPA". */
  const notCounted = (g) => E.scaleOf(P, state).grades[g] === null;
  const NOT_COUNTED_LABEL = 'Not counted in GPA';

  function gradeOptions(sel, row) {
    const list = gradesFor(row);
    const want = [''].concat(list);
    const have = [...sel.options].map((o) => o.value);
    if (want.join('|') !== have.join('|')) {
      sel.textContent = '';
      sel.append(h('option', { value: '' }, 'Grade'));
      const off = list.filter(notCounted);
      for (const g of list.filter((x) => !notCounted(x))) sel.append(h('option', { value: g }, gradeLabel(g)));
      if (off.length) sel.append(h('optgroup', { label: NOT_COUNTED_LABEL }, ...off.map((g) => h('option', { value: g }, gradeLabel(g)))));
    }
    sel.value = list.includes(row.grade) ? row.grade : '';
    if (row.grade && !list.includes(row.grade)) {
      sel.append(h('option', { value: row.grade }, row.grade));
      sel.value = row.grade;
    }
  }

  const rowEls = new Map();
  let activeRow = null; // the row being edited; on phones every other finished row collapses to one line
  const openTerms = new Set(); // earlier semesters a phone user expanded

  const filled = (r) => !!r.grade;
  const rowName = (r, i) => r.name.trim() || `Course ${i + 1}`;
  const rowMeta = (r) => (notCounted(r.grade) ? `${gradeLabel(r.grade)} · not counted` : `${gradeLabel(r.grade)} · ${r.credits || '?'} ${crAbbr}`);

  function rowView(term, row, i) {
    const id = row.id;
    const ph = (P.coursePlaceholders && P.coursePlaceholders[i % P.coursePlaceholders.length]) || P.courseHint || 'Course name';
    const name = h('input', { class: 'calc-input', id: `${id}-n`, value: row.name, autocomplete: 'off', enterkeyhint: 'next', placeholder: ph, 'aria-label': `Course ${i + 1} name (optional)` });
    const grade = h('select', { class: 'calc-select', id: `${id}-g`, 'aria-label': `Course ${i + 1} grade` });
    // Phones: the grade opens a letter grid in a bottom sheet; credits are quick buttons 1-5 + Other.
    const gradeBtn = h('button', { type: 'button', class: 'calc-select gpa-grade-btn', 'aria-haspopup': 'dialog', 'aria-label': `Course ${i + 1} grade` });
    const cr = h('input', { class: 'calc-input is-num', id: `${id}-c`, value: row.credits, inputmode: 'decimal', autocomplete: 'off', enterkeyhint: 'next', placeholder: cap(P.creditWord), 'aria-label': `Course ${i + 1} ${P.creditWord}` });
    const crBtn = h('button', { type: 'button', class: 'calc-input gpa-cr-btn', 'aria-haspopup': 'dialog', 'aria-label': `Course ${i + 1} ${P.creditWord}` });
    const sum = h('button', { type: 'button', class: 'gpa-row-sum', 'aria-label': `Edit course ${i + 1}` });
    const msg = h('p', { class: 'calc-hint calc-row-msg', id: `${id}-m` });
    cr.setAttribute('aria-describedby', msg.id);
    const rm = h('button', { type: 'button', class: 'calc-btn calc-btn-icon gpa-rm', 'aria-label': `Remove course ${i + 1}` }, '×');
    let typeSel = null;
    if (types) {
      typeSel = h('select', { class: 'calc-select gpa-type', id: `${id}-t`, 'aria-label': `Course ${i + 1} type` });
      for (const t of types) typeSel.append(h('option', { value: t.name }, t.name));
      typeSel.value = row.type;
    }
    let major = null;
    if (P.major && state.showMajor) {
      major = h('input', { type: 'checkbox', id: `${id}-mj`, checked: !!row.major, 'aria-label': `Course ${i + 1} counts toward your major` });
      major = h('label', { class: 'calc-check gpa-major', title: 'Counts toward your major GPA' }, major, h('span', null, 'Major'));
    }
    gradeOptions(grade, row);
    const el = h('div', { class: `calc-row gpa-row ${colsClass()}`, 'data-id': id },
      sum,
      h('div', { class: 'calc-field gpa-f-name' }, name),
      h('div', { class: 'calc-field gpa-f-grade' }, grade, gradeBtn),
      h('div', { class: 'calc-field gpa-f-cr' }, cr, crBtn),
      typeSel ? h('div', { class: 'calc-field gpa-f-type' }, typeSel) : null,
      major, rm, msg);
    const v = { el, msg, cr, crBtn, grade, gradeBtn, name, typeSel, sum, row, term, i };
    const syncBtns = () => {
      setText(gradeBtn, row.grade ? gradeLabel(row.grade) : 'Grade');
      gradeBtn.classList.toggle('is-empty', !row.grade);
      setText(crBtn, row.credits ? `${row.credits} ${crAbbr}` : cap(P.creditWord));
      // The course name truncates; grade and credits never do (they sit at the right, then Edit).
      sum.replaceChildren(h('span', { class: 'gpa-row-sum-t' }, row.grade ? rowName(row, i) : ''), h('span', { class: 'gpa-row-sum-m' }, row.grade ? rowMeta(row) : ''),
        h('span', { class: 'gpa-row-sum-e', 'aria-hidden': 'true' }, 'Edit'));
      sum.setAttribute('aria-label', row.grade ? `Edit ${rowName(row, i)}, ${gradeLabel(row.grade)}${notCounted(row.grade) ? ', not counted in GPA' : `, ${row.credits || 'no'} ${P.creditWord}`}` : `Edit course ${i + 1}`);
    };
    v.syncBtns = syncBtns;
    syncBtns();
    const edit = () => { lastTerm = term.id; changed(); };
    const setGrade = (g) => {
      row.grade = g;
      grade.value = g;
      syncBtns();
      edit();
      autoAdd(term, row);
    };
    name.addEventListener('input', () => { row.name = name.value; lastTerm = term.id; syncBtns(); changed(false); });
    grade.addEventListener('change', () => setGrade(grade.value));
    cr.addEventListener('input', () => { row.credits = cr.value; syncBtns(); edit(); });
    gradeBtn.addEventListener('click', async () => {
      activate(row.id);
      const list = gradesFor(row);
      const off = list.filter(notCounted);
      const options = list.filter((x) => !notCounted(x)).map((x) => ({ value: x, label: gradeLabel(x) }))
        .concat(off.length ? [{ heading: NOT_COUNTED_LABEL }, ...off.map((x) => ({ value: x, label: gradeLabel(x) }))] : []);
      const g = await sheet.open({ title: `Grade${row.name.trim() ? ` for ${row.name.trim()}` : ''}`, options, value: row.grade, cols: 4 });
      if (g != null) setGrade(g);
    });
    crBtn.addEventListener('click', async () => {
      activate(row.id);
      const quick = ['1', '2', '3', '4', '5'];
      const c = await sheet.open({ title: cap(P.creditWord), options: quick.map((x) => ({ value: x, label: x })).concat({ value: 'other', label: 'Other', wide: false }), value: quick.includes(row.credits) ? row.credits : (row.credits ? 'other' : ''), cols: 3 });
      if (c == null) return;
      if (c === 'other') {
        el.classList.add('is-cr-other');
        cr.focus();
        cr.select();
        return;
      }
      row.credits = c;
      cr.value = c;
      syncBtns();
      edit();
    });
    sum.addEventListener('click', () => { activate(row.id); name.focus({ preventScroll: true }); });
    if (typeSel) typeSel.addEventListener('change', () => { row.type = typeSel.value; gradeOptions(grade, row); edit(); });
    if (major) major.querySelector('input').addEventListener('change', (e) => { row.major = e.target.checked; edit(); });
    rm.addEventListener('click', () => {
      const idx = term.rows.indexOf(row);
      term.rows.splice(idx, 1);
      ensureTrailing(term);
      renderTerms();
      changed();
      const rows = termsEl.querySelectorAll(`[data-term="${term.id}"] .gpa-row`);
      const next = rows[Math.min(idx, rows.length - 1)];
      if (next) { activate(next.dataset.id); next.querySelector('input').focus(); }
    });
    if (row.credits && !['1', '2', '3', '4', '5'].includes(row.credits)) el.classList.add('is-cr-other');
    rowEls.set(id, v);
    return el;
  }

  /** Every semester ends with one blank row; filling the last row adds the next (no "Add class" button). */
  function ensureTrailing(term) {
    if (term.planned) return false;
    const last = term.rows[term.rows.length - 1];
    if (last && !filled(last) && !last.name) return false;
    term.rows.push(blankRow(P, term.rows.length));
    return true;
  }

  function autoAdd(term, row) {
    if (term.rows[term.rows.length - 1] !== row || !filled(row) || term.planned) return;
    const r = blankRow(P, term.rows.length);
    term.rows.push(r);
    const wrap = termsEl.querySelector(`[data-term="${term.id}"] .gpa-rows`);
    if (wrap) wrap.append(rowView(term, r, term.rows.length - 1));
    syncCollapse();
  }

  function activate(id) {
    if (activeRow === id) return;
    activeRow = id;
    syncCollapse();
  }

  function syncCollapse() {
    for (const [, v] of rowEls) v.el.classList.toggle('is-collapsed', filled(v.row) && v.row.id !== activeRow && !v.el.querySelector('[aria-invalid="true"]'));
    const last = state.terms.length - 1;
    state.terms.forEach((t, ti) => {
      const sec = termsEl.querySelector(`[data-term="${t.id}"]`);
      if (!sec) return;
      const holdsActive = t.rows.some((r) => r.id === activeRow);
      sec.classList.toggle('is-collapsed', ti < last && !openTerms.has(t.id) && !holdsActive && t.rows.some(filled));
    });
  }

  function addRow(term, focus = true) {
    term.rows.push(blankRow(P, term.rows.length));
    renderTerms();
    changed(false);
    if (focus) {
      const rows = termsEl.querySelectorAll(`[data-term="${term.id}"] .gpa-row`);
      const last = rows[rows.length - 1];
      activate(last.dataset.id);
      last.querySelector('input').focus();
    }
  }

  function termSummary(term) {
    const n = term.rows.filter(filled).length;
    const t = res && res.terms.find((x) => x.id === term.id);
    return `${term.name || P.termWord || 'Semester'} · ${n} class${n === 1 ? '' : 'es'}${t && t.gpa != null ? ` · GPA ${fmt(t.gpa)}` : ''}`;
  }

  function termView(term, ti) {
    const fixed = !!P.fixedTerms;
    const many = fixed || state.terms.length > 1;
    const gpaChip = h('span', { class: 'gpa-term-gpa', 'data-term-gpa': term.id });
    const head = ti === 0 || (fixed && term.rows.length && !state.terms[0].rows.length)
      ? h('div', { class: `calc-row-head ${colsClass()}`, 'aria-hidden': 'true' },
        h('span', null, 'Course (optional)'), h('span', null, 'Grade'), h('span', null, 'Credits'),
        types ? h('span', null, 'Type') : null, P.major && state.showMajor ? h('span', null, 'Major') : null, h('span'))
      : null;
    const rowsWrap = h('div', { class: 'gpa-rows' });
    term.rows.forEach((r, i) => rowsWrap.append(rowView(term, r, i)));
    const add = term.planned ? h('button', { type: 'button', class: 'calc-btn calc-btn-add gpa-add-row', onclick: () => addRow(term) }, 'Add class') : null;
    let title;
    if (fixed) {
      title = h('h3', { class: 'gpa-term-title' }, term.name, term.planned ? h('em', null, ' (optional)') : null);
    } else {
      title = h('input', { class: 'calc-input gpa-term-name', value: term.name, enterkeyhint: 'done', 'aria-label': `${P.termWord || 'Semester'} ${ti + 1} name` });
      title.addEventListener('input', () => { term.name = title.value; changed(false); });
    }
    // Removing a semester sits in a ⋯ menu (never a bare button beside the name) and can be undone.
    let del = null;
    if (!fixed && state.terms.length > 1) {
      const label = term.name || (P.termWord || 'semester').toLowerCase();
      const more = h('button', { type: 'button', class: 'calc-btn calc-btn-icon gpa-term-more', 'aria-haspopup': 'menu', 'aria-expanded': 'false', 'aria-label': `More for ${label}` }, '⋯');
      const menu = h('ul', { class: 'calc-menu', role: 'menu', hidden: true, 'aria-label': `${label} options` },
        h('li', { role: 'none' }, h('button', { type: 'button', role: 'menuitem', class: 'gpa-del-term', onclick: () => {
          menu.hidden = true;
          const before = JSON.stringify(pack(state));
          state.terms.splice(state.terms.indexOf(term), 1);
          renderTerms();
          changed();
          toast.show(`Removed ${label}`, { action: 'Undo', ms: 8000, onAction: () => { load(JSON.parse(before), { keepMode: true }); changed(); } });
        } }, `Remove ${label}`)));
      createMenu(more, menu);
      del = h('div', { class: 'calc-menu-wrap gpa-term-menu' }, more, menu);
    }
    const sum = h('button', { type: 'button', class: 'gpa-term-sum', 'data-term-sum': term.id, 'aria-label': `Show ${term.name || 'semester'}` }, termSummary(term));
    sum.addEventListener('click', () => { openTerms.add(term.id); syncCollapse(); });
    // One semester: no semester header, so the first course field sits right under the card header.
    return h('section', { class: `gpa-term${term.planned ? ' is-planned' : ''}`, 'data-term': term.id },
      many ? sum : null, many ? h('div', { class: 'gpa-term-head' }, title, gpaChip, del) : null, head, rowsWrap, add);
  }

  function renderTerms() {
    rowEls.clear();
    termsEl.textContent = '';
    state.terms.forEach((t, i) => termsEl.append(termView(t, i)));
    syncCollapse();
  }

  // The row being edited stays open; on phones the others collapse to their one-line summary.
  // On a tap the browser focuses the button (mousedown) before it fires the click. Collapsing the other
  // rows at that moment moves the button out from under the finger and the tap is lost, so a focus that
  // comes from a tap or click waits until the click has landed (or 600ms, if no click follows).
  let pressedAt = -1e9;
  let pending = null;
  termsEl.addEventListener('pointerdown', () => { pressedAt = performance.now(); }, true);
  const flush = () => {
    const id = pending;
    pending = null;
    if (id) activate(id);
  };
  window.addEventListener('click', () => { pressedAt = -1e9; if (pending) setTimeout(flush, 0); }, true);
  termsEl.addEventListener('focusin', (e) => {
    const rowEl = e.target.closest('.gpa-row');
    if (!rowEl) return;
    if (performance.now() - pressedAt < 1000) {
      pending = rowEl.dataset.id;
      setTimeout(flush, 600);
    } else activate(rowEl.dataset.id);
  });

  // Enter moves to the next row; on the last row it adds one.
  termsEl.addEventListener('keydown', (e) => {
    if (e.key !== 'Enter' || e.isComposing || !(e.target instanceof HTMLInputElement)) return;
    const rowEl = e.target.closest('.gpa-row');
    if (!rowEl) { if (e.target.classList.contains('gpa-term-name')) { e.preventDefault(); e.target.blur(); } return; }
    e.preventDefault();
    const next = rowEl.nextElementSibling;
    if (next) { activate(next.dataset.id); next.querySelector('input').focus(); return; }
    const term = state.terms.find((t) => t.id === rowEl.closest('.gpa-term').dataset.term);
    const v = rowEls.get(rowEl.dataset.id);
    if (term && v && (filled(v.row) || v.row.name)) addRow(term);
  });

  /* ----- updates ----- */

  function changed(recompute = true) {
    syncOptions();
    resetBtn.hidden = !hasData(state);
    if (mode === 'own') {
      dirty = !!currentSave;
      store.saveDraft({ state: pack(state), save: currentSave, dirty });
      savedNote.hidden = !store.available;
    }
    if (recompute) update();
    else saveLabel();
  }

  function verdictFor(g) {
    const sc = E.scaleOf(P, state);
    const steps = Object.entries(sc.grades).filter(([, v]) => v != null).sort((a, b) => b[1] - a[1]);
    const near = steps.find(([, v]) => g + 1e-9 >= v) || steps[steps.length - 1];
    const letter = steps.filter(([, v]) => v === near[1]).map(([k]) => k).find((k) => !k.endsWith('+')) || near[0];
    return { letter, band: letter.charAt(0).toLowerCase() };
  }

  function verdictText(g, letter) {
    const L = letter.replace('-', '−');
    if (P.standingLine && g < P.standingLine) return [`You have a ${fmt(g)} — `, h('span', { class: 'is-danger' }, `below the ${E.fmtGpa(P.standingLine, 1)} most colleges require for good standing`), '.'];
    const adj = g >= 3.7 ? 'an excellent' : g >= 3.0 ? 'a solid' : articleFor(letter);
    return [`You have a ${fmt(g)} — ${adj} ${L} average.`];
  }

  function update() {
    res = E.compute(P, state);
    for (const [id, v] of rowEls) {
      const r = res.rows[id];
      if (!r) continue;
      const err = r.status === 'error' ? r.error : '';
      const info = r.status === 'excluded' || r.status === 'replaced' ? `Not counted: ${r.why}` : '';
      setText(v.msg, err || info);
      v.msg.className = `calc-hint calc-row-msg${err ? ' is-error' : ''}`;
      v.cr.setAttribute('aria-invalid', err && /credit|unit/i.test(err) ? 'true' : 'false');
      v.grade.setAttribute('aria-invalid', err && !/credit|unit/i.test(err) ? 'true' : 'false');
    }
    for (const t of res.terms) {
      const chip = termsEl.querySelector(`[data-term-gpa="${t.id}"]`);
      if (chip) setText(chip, t.gpa == null ? '' : `${t.planned ? 'Planned' : 'GPA'} ${fmt(t.gpa)}`);
    }
    for (const t of state.terms) {
      const sum = termsEl.querySelector(`[data-term-sum="${t.id}"]`);
      if (sum) setText(sum, termSummary(t));
    }
    syncCollapse();
    setText(priorGpaMsg, res.prior.gpaError && (state.prior.gpa || state.prior.credits) ? res.prior.gpaError : '');
    setText(priorCrMsg, res.prior.creditsError && (state.prior.gpa || state.prior.credits) ? res.prior.creditsError : '');
    priorGpa.setAttribute('aria-invalid', priorGpaMsg.textContent ? 'true' : 'false');
    priorCr.setAttribute('aria-invalid', priorCrMsg.textContent ? 'true' : 'false');

    const ready = res.ready && (P.hasOfficialGpa !== false);
    result.hidden = !ready;
    pill.enable(ready);
    if (ready) {
      const g = res.current.gpa;
      const v = verdictFor(g);
      setText(score, fmt(g));
      setText(badge, v.letter.replace('-', '−'));
      badge.className = `calc-badge gpa-letter is-band-${v.band}`;
      verdict.replaceChildren(...verdictText(g, v.letter));
      // "Semester GPA" until there are 2+ semesters or a previous GPA; then "Cumulative GPA".
      const doneTerms = res.terms.filter((t) => !t.planned && t.credits > 0).length;
      const kick = P.copy.termResultKicker && doneTerms <= 1 && !res.prior.used ? P.copy.termResultKicker : P.copy.resultKicker;
      setText(kicker, kick);
      setText(live, `${kick}: ${fmt(g)}`);
      updatePill(g);
      setText(sCredits.v, E.fmtNum(res.current.credits));
      setText(sPoints.v, E.fmtNum(res.current.points, 2));
      sMajor.el.hidden = !res.major || !state.showMajor;
      if (res.major) setText(sMajor.v, fmt(res.major.gpa));
      sProj.el.hidden = res.projected.gpa == null;
      if (res.projected.gpa != null) setText(sProj.v, fmt(res.projected.gpa));
      const out = res.excluded.length;
      const review = res.excluded.filter((r) => r.review).length;
      setText(note, [
        out ? `${out} course${out > 1 ? 's' : ''} not counted (see the note under ${out > 1 ? 'each' : 'it'}).` : '',
        review ? 'Courses marked for review need your registrar’s rules before they count.' : '',
        res.errors ? `${res.errors} course${res.errors > 1 ? 's need' : ' needs'} a fix before ${res.errors > 1 ? 'they count' : 'it counts'}.` : '',
      ].filter(Boolean).join(' '));
      const below = P.standingLine && g < P.standingLine;
      setText(ctaBtn, below ? P.next.rescueLabel : P.next.planLabel);
      renderLinks(g);
      if (how.open) renderHow();
      renderInsights();
      track('result', { gpa: E.roundHalf(g, 2) });
      if (homeHint && mode === 'own' && !loading) homeHint.calculated(resultKey());
    } else {
      insights.textContent = '';
    }
    if (planner && !planner.hidden) updatePlan();
    else renderGoals();
    renderKeep();
    saveLabel();
  }

  function updatePill(g) {
    const done = res.terms.filter((t) => !t.planned && t.credits > 0);
    const several = done.length > 1 || (done.length && res.prior.used);
    if (several && !P.fixedTerms) {
      const t = done.find((x) => x.id === lastTerm) || done[done.length - 1];
      pill.set({ label: 'Cumulative', value: fmt(g), label2: 'This term', value2: fmt(t.gpa), aria: `Your cumulative GPA ${fmt(g)}, this term ${fmt(t.gpa)}, go to result` });
    } else {
      pill.set({ label: P.copy.pill || 'GPA', value: fmt(g), aria: `Your GPA ${fmt(g)}, go to result` });
    }
  }

  function renderLinks(g) {
    nextLinks.textContent = '';
    for (const l of (P.next && P.next.links) || []) {
      const href = l.withGpa ? `${l.href}?gpa=${E.roundHalf(g, 2).toFixed(2)}` : l.href;
      nextLinks.append(h('a', { href, onclick: () => track('next', { to: l.href }, true) }, l.label));
    }
  }

  function renderKeep() {
    const grid = keep.querySelector('.calc-keep-grid');
    if (!P.keepGoing || !P.keepGoing.length || grid.childElementCount) return;
    for (const k of P.keepGoing.slice(0, 4)) {
      const a = h('a', { href: k.href, onclick: () => track('keep_going', { to: k.href }, true) }, k.title);
      grid.append(h('div', { class: 'rx-relcard', style: { '--rx-ico': iconUrl(k.icon) } }, h('h3', null, a), h('p', { class: 'rx-relsub' }, k.sub)));
    }
  }

  function renderInsights() {
    insights.textContent = '';
    const g = res.current.gpa;
    const card2 = (k, text) => insights.append(h('div', { class: 'calc-insight' }, h('div', { class: 'calc-insight-k' }, k), h('div', { class: 'calc-insight-v' }, text)));
    card2('Is my GPA good?', goodText(g));
    const lever = E.biggestLever(res);
    if (lever && lever.gain >= 0.005) {
      card2('Biggest lever', `Raising ${lever.name || 'one course'} from ${lever.from.replace('-', '−')} to ${lever.to.replace('-', '−')} adds ${E.fmtNum(lever.gain, D)} to your GPA.`);
    }
  }

  /** "Is my GPA good?": what the number means for the things students use it for. */
  function goodText(g) {
    const x = fmt(g);
    const max = res ? res.max : 4;
    if (max > 4.5) return `${x} out of ${E.fmtNum(max)}. Compare it with the cutoffs your school publishes.`;
    if (g >= 3.7) return `${x} is in the A range: high enough for Latin honors at many colleges and for competitive grad programs.`;
    if (g >= 3.5) return `${x} is where Dean’s List and cum laude usually start, and above the 3.0 most grad schools ask for.`;
    if (g >= 3.0) return `${x} is above 3.0, the usual minimum for grad school and many scholarships.`;
    if (g >= 2.5) return `${x} is above good standing but under the 3.0 many grad schools and scholarships ask for.`;
    if (g >= 2.0) return `${x} keeps you in good standing (2.0), but many scholarships and grad programs want 3.0.`;
    return `${x} is below 2.0, the usual line for good academic standing. The planner shows the way back.`;
  }

  let kitP = null;
  const loadKit = () => (kitP ||= import('../core/chart-kit.js'));

  /** The saved goal is the planner's target GPA: one line under the result, tap to reopen the planner. */
  function goalStatus(value) {
    const U = E.parseNum(state.target.credits) || P.upcomingDefault || 15;
    const cw = P.creditWord;
    const T = E.fmtGpa(value, 2);
    const sc = E.scaleOf(P, state);
    const term = (P.termWord || 'term').toLowerCase();
    const cur = res.current.gpa;
    if (cur + 1e-9 >= value) return { cls: 'is-met', text: `Your goal: ${T}. You’re there, ${E.fmtGpa(cur - value, 2)} above it.` };
    const p = E.plan({ credits: res.current.credits, points: res.current.points }, value, U, sc);
    const rescue = P.standingLine && value <= P.standingLine;
    if (p.status === 'reachable') {
      return { cls: rescue ? 'is-danger' : 'is-need', text: `Your goal: ${T}. You need a ${E.fmtGpa(p.needed, 2)} over your next ${E.fmtNum(U)} ${cw}.` };
    }
    const terms = p.creditsForTarget ? Math.ceil(p.creditsForTarget / U) : null;
    return { cls: rescue ? 'is-danger' : 'is-warn', text: `Your goal: ${T}. The highest possible next ${term} is ${E.fmtGpa(p.highest, 2)}${terms ? `; ${terms} ${term}s of straight ${topLetter(sc)}s would get you there` : ''}.` };
  }

  function renderGoals() {
    if (!goalsEl) return;
    const value = E.parseNum(state.target.gpa);
    const show = !!(res && res.ready && value != null && value > 0 && value <= res.max && planner.hidden);
    goalsEl.hidden = !show;
    goalList.textContent = '';
    if (!show) return;
    const st = goalStatus(value);
    const open = h('button', { type: 'button', class: `gpa-goal ${st.cls}`, onclick: () => openPlanner() }, st.text);
    const rm = h('button', { type: 'button', class: 'calc-btn calc-btn-icon gpa-goal-rm', 'aria-label': 'Clear your goal', onclick: () => { state.target.gpa = ''; tGpa.value = ''; changed(); } }, '×');
    goalList.append(h('li', null, open, rm));
  }

  /* ----- "Show how it's calculated": the component library's worked-example table ----- */
  function renderHow() {
    howBody.textContent = '';
    if (!res || !res.ready) return;
    const rows = Object.values(res.rows).filter((r) => r.status === 'counted' && !r.planned);
    const cw = cap(P.creditWord);
    const th = (t, cls) => h('th', { scope: 'col', class: cls }, t);
    const op = (t) => h('td', { class: 'gpa-ex__op', 'aria-hidden': 'true' }, t);
    const body = h('tbody');
    if (res.prior.used) {
      body.append(h('tr', null, h('th', { scope: 'row', class: 'gpa-ex__course' }, 'Current GPA (before these courses)'),
        h('td', { class: 'gpa-ex__badge-cell' }, '—'), op('→'), h('td', null, E.fmtNum(res.prior.gpa, 3)), op('×'),
        h('td', null, E.fmtNum(res.prior.credits)), op('='), h('td', { class: 'gpa-ex__qp' }, E.fmtNum(res.prior.points, 2))));
    }
    for (const r of rows) {
      const band = r.grade.charAt(0).toLowerCase();
      body.append(h('tr', null,
        h('th', { scope: 'row', class: 'gpa-ex__course' }, r.name || `Course ${r.ri + 1}`),
        h('td', null, h('span', { class: `gpa-ex__badge gpa-ex__badge--${'abcdf'.includes(band) ? band : 'c'}` }, r.grade.replace('-', '–'))),
        op('→'), h('td', null, E.fmtNum(r.points, 2)), op('×'), h('td', null, E.fmtNum(r.credits)), op('='),
        h('td', { class: 'gpa-ex__qp' }, E.fmtNum(r.points * r.credits, 2))));
    }
    const tile = (label, value, cls) => h('div', { class: `gpa-ex__tile${cls ? ` ${cls}` : ''}` }, h('span', { class: 'gpa-ex__label' }, label), ' ', h('span', { class: 'gpa-ex__value' }, value));
    const strip = h('div', { class: 'gpa-ex__result' },
      tile('Total quality points', E.fmtNum(res.current.points, 2)), h('span', { class: 'gpa-ex__rop', 'aria-hidden': 'true' }, '÷'),
      tile(`GPA ${P.creditWord}`, E.fmtNum(res.current.credits), 'gpa-ex__tile--div'), h('span', { class: 'gpa-ex__rop', 'aria-hidden': 'true' }, '='),
      tile('GPA', fmt(res.current.gpa), 'gpa-ex__tile--gpa'));
    const table = h('table', { class: 'gpa-ex__table' },
      h('caption', { class: 'screen-reader-text calc-sr' }, `How your ${fmt(res.current.gpa)} GPA is calculated`),
      h('thead', null, h('tr', null, th('Course', 'gpa-ex__course'), th('Grade'), h('th', { class: 'gpa-ex__op', 'aria-hidden': 'true' }), th('Grade points'),
        h('th', { class: 'gpa-ex__op', 'aria-hidden': 'true' }), th(cw), h('th', { class: 'gpa-ex__op', 'aria-hidden': 'true' }), th('Quality points'))),
      body, h('tfoot', null, h('tr', null, h('td', { colspan: '8' }, strip))));
    howBody.append(h('figure', { class: 'gpa-ex' },
      h('div', { class: 'gpa-ex__mhead', 'aria-hidden': 'true' }, h('span', null, `Grade points × ${P.creditWord}`), h('span', null, 'Quality points')), table),
      h('p', { class: 'calc-how-note' }, `Each grade's points × its ${P.creditWord} gives quality points. Add them up and divide by the total ${P.creditWord}.${res.excluded.length ? ' Courses that don’t count are left out.' : ''}`));
    if (P.help && P.help.note) howBody.append(h('p', { class: 'calc-how-note' }, P.help.note));
    if (P.sources && P.sources.length) {
      howBody.append(h('p', { class: 'calc-how-note' }, 'Rules from ', ...P.sources.slice(0, 3).flatMap((s, i) => [i ? ', ' : '', h('a', { href: s.url, target: '_blank', rel: 'noopener' }, s.label)]), '.'));
    }
    track('how');
  }

  /* ----- planner ----- */
  let slider = null;
  function openPlanner() {
    if (!planner) return;
    if (!res || !res.ready) {
      toast.show('Add a grade first, then plan your target.');
      return;
    }
    planner.hidden = false;
    const g = res.current.gpa;
    if (!state.target.credits) state.target.credits = String(P.upcomingDefault || 15);
    if (!state.target.gpa) {
      // A goal one step up that one more term can reach (or 2.0 for good standing).
      const U = E.parseNum(state.target.credits) || 15;
      const best = (res.current.points + res.max * U) / (res.current.credits + U);
      let want = g < (P.standingLine || 0) ? P.standingLine : Math.ceil((g + 0.1) * 10 - 1e-9) / 10;
      if (want > best) want = Math.floor(best * 10) / 10;
      state.target.gpa = Math.max(0, Math.min(res.max, want)).toFixed(2);
    }
    tGpa.value = state.target.gpa;
    tCr.value = state.target.credits;
    updatePlan();
    planner.scrollIntoView({ behavior: reducedMotion() ? 'auto' : 'smooth', block: 'start' });
    tGpa.focus({ preventScroll: true });
    track('step_2');
    changed(false);
  }

  function updatePlan() {
    if (!res || !res.ready) return;
    const sc = E.scaleOf(P, state);
    const p = E.plan({ credits: res.current.credits, points: res.current.points }, state.target.gpa, state.target.credits, sc);
    whatIf.hidden = true;
    const cw = P.creditWord;
    if (p.status === 'incomplete' || p.status === 'invalid') {
      setText(planBig, '');
      setText(planLine, p.error || `Enter a target GPA and your upcoming ${cw}.`);
      setText(planLine2, '');
      renderGoals();
      return;
    }
    const T = fmt(p.target);
    const top = topLetter(sc);
    if (p.status === 'met') {
      setText(planBig, 'You’re there');
      setText(planLine, `Your GPA stays at or above ${T} even with the lowest grades in your next ${E.fmtNum(p.upcoming)} ${cw}.`);
      setText(planLine2, '');
    } else if (p.status === 'reachable') {
      setText(planBig, fmt(p.needed));
      setText(planLine, `You need about ${articleFor(p.letter)} ${p.letter.replace('-', '−')} average (${fmt(p.needed)}) in your next ${E.fmtNum(p.upcoming)} ${cw} to reach ${T}.`);
      setText(planLine2, `Straight ${top}s would take you to ${fmt(p.highest)}.`);
    } else {
      setText(planBig, 'Out of reach this term');
      setText(planLine, `Even straight ${top}s in ${E.fmtNum(p.upcoming)} ${cw} would bring you to ${fmt(p.highest)}, short of ${T}.`);
      setText(planLine2, p.creditsForTarget ? `Reaching ${T} would take about ${E.fmtNum(p.creditsForTarget)} ${cw} of straight ${top}s.` : '');
    }
    track('plan', { target: p.target });
    renderGoals();
    if (P.whatIf !== false) {
      whatIf.hidden = false;
      loadKit().then((kit) => {
        const max = E.scaleMax(sc);
        const start = Math.max(0, Math.min(max, p.status === 'reachable' ? E.roundHalf(p.needed, 1) : E.roundHalf(res.current.gpa, 1)));
        const after = (avg) => E.whatIf({ credits: res.current.credits, points: res.current.points }, avg, E.parseNum(state.target.credits) || p.upcoming);
        const fn = (avg) => `A ${avg.toFixed(1)} average next term → ${fmt(after(avg))} cumulative`;
        if (!slider || slider.max !== max) {
          slider = kit.whatIfSlider(whatIfHost, { min: 0, max, step: 0.1, value: start, label: 'Average GPA next term', onInput: fn });
          slider.max = max;
        }
      });
    }
  }

  tGpa.addEventListener('input', () => { state.target.gpa = tGpa.value; changed(false); updatePlan(); });
  tCr.addEventListener('input', () => { state.target.credits = tCr.value; changed(false); updatePlan(); });

  /* ----- settings events ----- */
  scaleSel.addEventListener('change', () => {
    state.scale = scaleSel.value;
    for (const t of state.terms) for (const r of t.rows) { const v = rowEls.get(r.id); if (v) gradeOptions(v.grade, r); }
    slider = null;
    changed();
  });
  priorToggle.addEventListener('click', () => {
    state.priorOpen = !state.priorOpen;
    syncPrior();
    if (state.priorOpen) priorGpa.focus();
    changed(false);
  });
  if (majorToggle) {
    majorToggle.addEventListener('change', () => {
      state.showMajor = majorToggle.checked;
      renderTerms();
      changed();
    });
  }
  optionsBtn.addEventListener('click', () => {
    optionsOpen = !optionsOpen;
    syncOptions();
    if (optionsOpen) (P.scales.length > 1 ? scaleSel : priorToggle).focus({ preventScroll: true });
    track('options', { open: optionsOpen ? 1 : 0 }, true);
  });
  function syncOptions() {
    optionsPanel.hidden = !optionsOpen;
    optionsBtn.setAttribute('aria-expanded', String(optionsOpen));
    const on = [state.scale !== P.defaultScale, !!(state.prior.gpa || state.prior.credits), !!state.showMajor].filter(Boolean).length;
    optionsBtn.replaceChildren('Options', on ? h('span', { class: 'gpa-options-n', 'aria-label': `${on} on` }, ` · ${on} on`) : '', h('span', { class: 'gpa-options-caret', 'aria-hidden': 'true' }, optionsOpen ? ' ▴' : ' ▾'));
  }
  function syncPrior() {
    priorBox.hidden = !state.priorOpen;
    priorToggle.setAttribute('aria-expanded', String(!!state.priorOpen));
    setText(priorToggle, state.priorOpen ? 'Hide previous GPA' : 'Add previous GPA');
  }
  priorGpa.addEventListener('input', () => { state.prior.gpa = priorGpa.value; changed(); });
  priorCr.addEventListener('input', () => { state.prior.credits = priorCr.value; changed(); });
  if (addTermBtn) {
    addTermBtn.addEventListener('click', () => {
      state.terms.push({ id: newId('t'), name: termName(P, state.terms.length), rows: rowsOf(P, 1) });
      renderTerms();
      changed();
      const first = termsEl.querySelector('.gpa-term:last-of-type .gpa-row input');
      if (first) first.focus();
    });
  }
  resetBtn.addEventListener('click', () => reset());

  /* ----- load / modes ----- */
  function load(s, { keepMode = false } = {}) {
    state = normalize(P, s);
    for (const t of state.terms) ensureTrailing(t);
    if (!keepMode) slider = null;
    lastTerm = null;
    activeRow = null;
    openTerms.clear();
    scaleSel.value = state.scale;
    priorGpa.value = state.prior.gpa;
    priorCr.value = state.prior.credits;
    tGpa.value = state.target.gpa;
    tCr.value = state.target.credits;
    if (majorToggle) majorToggle.checked = !!state.showMajor;
    syncPrior();
    syncOptions();
    renderTerms();
    loading = true;
    update();
    loading = false;
    if (homeHint) homeHint.seen(resultKey()); // a restored calculation isn't a new one
  }

  function setBanner(kind) {
    bannerActions.textContent = '';
    banner.classList.toggle('is-sample', kind === 'sample');
    const btn = (label, fn, extra = {}) => bannerActions.append(h('button', { type: 'button', class: 'calc-btn calc-btn-text', onclick: fn, ...extra }, label));
    if (!kind) { banner.hidden = true; return; }
    if (kind === 'welcome') {
      setText(bannerText, 'Welcome back — we restored your last calculation');
      btn('Start fresh', () => reset());
      btn('×', () => { banner.hidden = true; }, { class: 'calc-btn calc-btn-text calc-banner-x', 'aria-label': 'Dismiss' });
    } else if (kind === 'sample') {
      setText(bannerText, 'Viewing a sample');
      btn('Clear', () => leaveSpecial());
    } else if (kind === 'shared') {
      setText(bannerText, 'Viewing a shared calculation');
      btn('Save a copy', () => saveAs());
      btn('Close', () => { clearHash(); leaveSpecial(); });
    }
    banner.hidden = false;
  }

  function showSample() {
    if (mode === 'own') ownBefore = { state: pack(state), save: currentSave, dirty };
    mode = 'sample';
    load(P.sample);
    setBanner('sample');
    store.markSeen();
    syncSampleLink();
    track('sample');
  }

  function leaveSpecial() {
    const b = ownBefore;
    mode = 'own';
    ownBefore = null;
    setBanner(null);
    currentSave = b ? b.save : null;
    dirty = b ? b.dirty : false;
    load(b ? b.state : null);
    syncSampleLink();
  }

  function syncSampleLink() {
    sampleBtn.hidden = mode !== 'own';
    setText(sampleBtn, store.seen() ? 'Show an example' : 'Try a sample');
    sampleBtn.classList.toggle('is-small', store.seen());
  }

  function reset() {
    const snapshot = { state: pack(state), save: currentSave, dirty, mode, ownBefore };
    if (mode !== 'own') { mode = 'own'; ownBefore = null; }
    setBanner(null);
    currentSave = null;
    dirty = false;
    load(null);
    if (planner) planner.hidden = true;
    store.saveDraft(null);
    savedNote.hidden = true;
    syncSampleLink();
    track('reset', null, true);
    toast.show('Started over.', { action: 'Undo', ms: 6000, onAction: () => {
      mode = snapshot.mode;
      ownBefore = snapshot.ownBefore;
      currentSave = snapshot.save;
      dirty = snapshot.dirty;
      load(snapshot.state);
      if (mode === 'own') store.saveDraft({ state: pack(state), save: currentSave, dirty });
      if (mode === 'sample') setBanner('sample');
      syncSampleLink();
      toast.show('Restored.');
    } });
  }

  /* ----- saves (the My saves menu also holds Share) ----- */
  function saveLabel() {
    setText(saveName, currentSave ? `${currentSave}${dirty ? ' · unsaved changes' : ''}` : '');
  }

  function askName(def) {
    const n = window.prompt('Name this calculation (e.g. Fall 2026)', def || '');
    return n == null ? null : n.trim().slice(0, 60);
  }

  function saveAs() {
    if (mode === 'sample') { toast.show('Samples can’t be saved. Enter your own grades to save them.'); return; }
    if (!store.available) { toast.show('Saving is off in this browser (private mode or blocked storage).'); return; }
    const name = askName(currentSave ? `${currentSave} (copy)` : '');
    if (!name) return;
    if (store.has(name) && !window.confirm(`Replace your save “${name}”?`)) return;
    if (!store.save(name, pack(state))) { toast.show('Couldn’t save. Your browser may be blocking storage.'); return; }
    if (mode === 'shared') { mode = 'own'; ownBefore = null; setBanner(null); clearHash(); syncSampleLink(); }
    currentSave = name;
    dirty = false;
    store.saveDraft({ state: pack(state), save: currentSave, dirty });
    saveLabel();
    track('save', null, true);
    toast.show(`Saved “${name}”`);
    if (homeHint) homeHint.saved();
  }

  function saveNow() {
    if (!currentSave || mode !== 'own') { saveAs(); return; }
    store.save(currentSave, pack(state));
    dirty = false;
    store.saveDraft({ state: pack(state), save: currentSave, dirty });
    saveLabel();
    track('save', null, true);
    toast.show(`Saved “${currentSave}”`);
    if (homeHint) homeHint.saved();
  }

  function okToLeave() {
    if (mode === 'own' && currentSave && dirty) return window.confirm(`You have unsaved changes to “${currentSave}”. Leave them?`);
    if (mode === 'own' && !currentSave && hasData(state)) return window.confirm('Your current calculation isn’t saved. Leave it?');
    return true;
  }

  function openSave(name) {
    if (!okToLeave()) return;
    const s = store.open(name);
    if (!s) return;
    if (mode !== 'own') { mode = 'own'; ownBefore = null; clearHash(); }
    setBanner(null);
    currentSave = name;
    dirty = false;
    load(s);
    store.saveDraft({ state: pack(state), save: currentSave, dirty });
    saveLabel();
    syncSampleLink();
    track('restore', { from: 'save' }, true);
    toast.show(`Opened “${name}”`);
  }

  /* ----- share ----- */
  function summary() {
    const lines = [`My ${P.title}: ${fmt(res.current.gpa)} (${E.fmtNum(res.current.credits)} ${P.creditWord})`];
    for (const t of res.terms) if (t.credits) lines.push(`${t.name || 'Semester'}: ${fmt(t.gpa)} over ${E.fmtNum(t.credits)} ${P.creditWord}`);
    if (res.major && state.showMajor) lines.push(`Major GPA: ${fmt(res.major.gpa)}`);
    lines.push(`Calculated with ${P.copy.summaryUrl}`);
    return lines.join('\n');
  }
  function csvRows() {
    const out = [[P.termWord || 'Term', 'Course', 'Grade', cap(P.creditWord), 'Grade points', 'Quality points', 'Counted']];
    for (const t of state.terms) {
      for (const r of t.rows) {
        const c = res.rows[r.id];
        if (!c || c.status === 'empty') continue;
        out.push([t.name, r.name, r.grade, r.credits, c.status === 'counted' ? c.points : '', c.status === 'counted' ? E.roundHalf(c.points * c.credits, 2) : '', c.status === 'counted' ? 'yes' : c.why || c.error || 'no']);
      }
    }
    if (res.prior.used) out.push(['Before', 'Current GPA', '', res.prior.credits, res.prior.gpa, E.roundHalf(res.prior.points, 2), 'yes']);
    out.push([], ['Cumulative GPA', fmt(res.current.gpa)], [`GPA ${P.creditWord}`, res.current.credits]);
    return out;
  }
  const SHARE = [
    ['Copy link', async () => toast.show(await copyText(shareUrl(P.hashKey, pack(state))) ? 'Link copied. Anyone with it sees these grades.' : 'Couldn’t copy. Try again.')],
    ['Copy summary', async () => toast.show(await copyText(summary()) ? 'Summary copied' : 'Couldn’t copy. Try again.')],
    ['Download CSV', async () => { downloadCSV(`${P.id}-gpa.csv`, csvRows()); sendEvent('gpa_export', { format: 'csv' }); }],
    ['Print / Save as PDF', async () => { how.open = true; renderHow(); sendEvent('gpa_export', { format: 'print' }); printPage(); }],
  ];

  function renderSavesMenu() {
    savesMenu.textContent = '';
    const item = (label, fn) => h('li', { role: 'none' }, h('button', { type: 'button', role: 'menuitem', onclick: () => { savesMenu.hidden = true; fn(); } }, label));
    if (!store.available) {
      savesMenu.append(h('li', { class: 'calc-menu-empty', role: 'none' }, 'Saving is off in this browser (private mode or blocked storage).'));
    } else {
      savesMenu.append(item('Save as…', saveAs));
      if (currentSave && mode === 'own') {
        savesMenu.append(item('Rename…', () => {
          const n = askName(currentSave);
          if (!n || n === currentSave) return;
          if (!store.rename(currentSave, n)) { toast.show('That name is taken.'); return; }
          currentSave = n;
          store.saveDraft({ state: pack(state), save: currentSave, dirty });
          saveLabel();
        }));
      }
      savesMenu.append(item('New calculation', () => {
        if (!okToLeave()) return;
        if (mode !== 'own') { mode = 'own'; ownBefore = null; clearHash(); }
        setBanner(null);
        currentSave = null;
        dirty = false;
        load(null);
        store.saveDraft(null);
        syncSampleLink();
      }));
      savesMenu.append(h('li', { role: 'separator', class: 'calc-menu-sep' }));
      const list = store.list();
      if (!list.length) savesMenu.append(h('li', { class: 'calc-menu-empty', role: 'none' }, 'No saves yet. Saves stay in this browser.'));
      for (const s of list) {
        savesMenu.append(h('li', { role: 'none', class: s.name === currentSave ? 'is-current' : '' },
          h('button', { type: 'button', role: 'menuitem', onclick: () => { savesMenu.hidden = true; openSave(s.name); } }, s.name),
          h('button', { type: 'button', role: 'menuitem', class: 'calc-menu-del', 'aria-label': `Delete ${s.name}`, onclick: () => {
            if (!window.confirm(`Delete “${s.name}”? This can’t be undone.`)) return;
            store.remove(s.name);
            if (currentSave === s.name) { currentSave = null; dirty = false; changed(false); }
            renderSavesMenu();
          } }, '✕')));
      }
    }
    savesMenu.append(h('li', { role: 'separator', class: 'calc-menu-sep' }), h('li', { class: 'calc-menu-k', role: 'none' }, 'Share'));
    for (const [label, fn] of SHARE) {
      savesMenu.append(h('li', { role: 'none' }, h('button', { type: 'button', role: 'menuitem', onclick: async () => {
        savesMenu.hidden = true;
        if (!(res && res.ready)) { toast.show('Add a grade first.'); return; }
        await fn();
        track('share', { method: label }, true);
      } }, label)));
    }
  }
  createMenu(folderBtn, savesMenu, renderSavesMenu);

  /* ----- start ----- */
  if (P.legacy) importLegacyOnce(store, 'imported', P.legacy.keys, P.legacy.convert);
  const shared = readHash(P.hashKey);
  const draft = store.loadDraft();
  if (shared && Array.isArray(shared.terms)) {
    mode = 'shared';
    ownBefore = draft && draft.state ? { state: draft.state, save: draft.save || null, dirty: !!draft.dirty } : null;
    load(shared);
    setBanner('shared');
    track('open_link');
  } else if (draft && draft.state && hasData(normalize(P, draft.state))) {
    currentSave = draft.save || null;
    dirty = !!draft.dirty;
    load(draft.state);
    setBanner('welcome');
    track('restore', { from: 'draft' });
  } else {
    load(opts.initial || null);
  }
  syncSampleLink();
  store.markSeen();
  return { get state() { return state; }, get result() { return res; }, openPlanner, reset, load };
}

/** The 4.0 badge shipped with the plugin (assets/calc-assets/a2hs/), next to this module (a page may override it). */
function badgeUrl() {
  if (typeof window !== 'undefined' && window.GPACALC_A2HS_ICON) return window.GPACALC_A2HS_ICON;
  try {
    return new URL('../a2hs/badge-192.png', import.meta.url).href;
  } catch (e) {
    return '/wp-content/plugins/gpacalculator-manager/assets/calc-assets/a2hs/badge-192.png';
  }
}

function cap(s) { return s.charAt(0).toUpperCase() + s.slice(1); }
function articleFor(letter) { return /^[AEF]/i.test(letter) ? 'an' : 'a'; }
function topLetter(sc) { return Object.keys(sc.grades).find((k) => !k.endsWith('+') && sc.grades[k] === E.scaleMax(sc)) || 'A'; }

export { normalize, pack, blankState };
