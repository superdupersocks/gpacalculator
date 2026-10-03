/* gpacalculator.net GPA calculator screen v1.0.0 (gpacalculator-manager plugin)
 * One screen for every GPA-engine calculator; each page passes its profile (profiles/*.js).
 * Look: core/calc-core.css + gpa/gpa-app.css (tokens only). Math: engines/gpa-engine.js.
 * Services from core/calc-core.js: storage, share, GA4, menus, toast, live pill. */
import {
  h, setText, createStore, encodeState, readHash, shareUrl, clearHash, copyText, downloadCSV, createTracker,
  createLivePill, createMenu, createActionToast, trackCalculatorUsed, sendEvent, importLegacyOnce, printPage,
  enterToNext, reducedMotion,
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
    ? profile.fixedTerms.map((t) => ({ id: t.id, name: t.name, planned: !!t.planned, rows: t.planned ? [] : rowsOf(profile, profile.rowsPerTerm) }))
    : [{ id: newId('t'), name: termName(profile, 0), rows: rowsOf(profile, profile.rowsPerTerm) }];
  return { v: 1, scale: profile.defaultScale, prior: { gpa: '', credits: '' }, priorOpen: false, terms, target: { gpa: '', credits: '' } };
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
    name: str(t.name) || termName(profile, i), planned: !!t.planned, collapsed: !!t.collapsed,
    rows: (Array.isArray(t.rows) ? t.rows : []).slice(0, 40).map(row),
  }));
  if (profile.fixedTerms) {
    terms = profile.fixedTerms.map((ft) => {
      const found = terms.find((t) => t.id === ft.id) || terms.find((t) => !!t.planned === !!ft.planned);
      return { id: ft.id, name: ft.name, planned: !!ft.planned, collapsed: false, rows: found ? found.rows : [] };
    });
  }
  if (!terms.length) return base;
  return {
    v: 1, scale,
    prior: { gpa: str(s.prior && s.prior.gpa), credits: str(s.prior && s.prior.credits) },
    priorOpen: !!(s.priorOpen || (s.prior && (s.prior.gpa || s.prior.credits))),
    terms,
    target: { gpa: str(s.target && s.target.gpa), credits: str(s.target && s.target.credits) },
  };
}

/** Compact copy for storage / share links (no ids, no empty rows). */
function pack(state) {
  return {
    v: 1, scale: state.scale, prior: state.prior, priorOpen: state.priorOpen || undefined,
    terms: state.terms.map((t) => ({
      id: t.id, name: t.name, planned: t.planned || undefined, collapsed: t.collapsed || undefined,
      rows: t.rows.filter((r) => r.name || r.grade || (r.credits && r.credits !== '')).map((r) => ({
        name: r.name || undefined, grade: r.grade || undefined, credits: r.credits || undefined, major: r.major || undefined, type: r.type,
      })),
    })),
    target: state.target.gpa || state.target.credits ? state.target : undefined,
  };
}

function hasData(state) {
  return state.terms.some((t) => t.rows.some((r) => r.grade || r.name)) || !!(state.prior.gpa || state.prior.credits);
}

/* ---------- Legacy saves (Bolt homepage/college bundles; university engine) ---------- */

/** Bolt calculators kept { calculatorMode, semesters: [{ name, courses: [{ name, grade, credits, isMajor }] }] }. */
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
  return { v: 1, scale: 'standard', prior: { gpa: '', credits: '' }, terms };
}

/* ---------- Screen ---------- */

const ICONS = {
  trend: 'M3 17l6-6 4 4 8-8M15 7h6v6',
  sum: 'M18 4H6l6 8-6 8h12',
  calc: 'M7 3h10a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2zM8 7h8M8 12h2M14 12h2M8 16h2M14 16h2',
  target: 'M12 3a9 9 0 1 0 9 9M12 7a5 5 0 1 0 5 5M12 11a1 1 0 1 0 1 1',
};
const icon = (name) => `url("data:image/svg+xml,${encodeURIComponent(`<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='${ICONS[name] || ICONS.calc}'/></svg>`)}")`;

export function mountGpa(root, profile, opts = {}) {
  if (root.id === 'root') root.classList.add('calc-mounted');
  root.textContent = '';
  const P = profile;
  const D = P.decimals || 2;
  const fmt = (n) => E.fmtGpa(n, D);
  const track = createTracker(P.prefix);
  const store = createStore(P.storeKey);
  const app = h('div', { class: `calc gpa gpa-${P.id}`, 'data-profile': P.id });
  root.append(app);
  const toast = createActionToast(app);
  trackCalculatorUsed(root);

  let state = blankState(P);
  let mode = 'own'; // own | sample | shared
  let ownBefore = null; // the user's state while a sample or shared calculation is shown
  let currentSave = null;
  let dirty = false;
  let step = 0;
  let res = null;
  let planShown = false;

  /* ----- shell ----- */
  const savesBtn = h('button', { type: 'button', class: 'calc-btn calc-btn-text' }, 'My saves ▾');
  const savesMenu = h('ul', { class: 'calc-menu', role: 'menu', 'aria-label': 'My saves' });
  const saveName = h('span', { class: 'calc-save-name' });
  const shareBtn = h('button', { type: 'button', class: 'calc-btn calc-btn-text' }, 'Share ▾');
  const shareMenu = h('ul', { class: 'calc-menu', role: 'menu', 'aria-label': 'Share' });
  const toolbar = h('div', { class: 'calc-toolbar' },
    h('div', { class: 'calc-toolbar-group' }, h('div', { class: 'calc-menu-wrap is-left' }, savesBtn, savesMenu), saveName),
    h('div', { class: 'calc-menu-wrap' }, shareBtn, shareMenu));

  const stepBtns = [];
  const stepsEl = P.planner ? h('ol', { class: 'calc-steps', 'aria-label': 'Steps' },
    ['Your GPA', 'Plan your target'].map((label, i) => {
      const b = h('button', { type: 'button', onclick: () => (i ? openPlanner() : goStep1()) },
        h('span', { class: 'calc-step-dot' }, String(i + 1)), h('span', { class: 'calc-step-label' }, label));
      stepBtns.push(b);
      return h('li', { class: 'calc-step' }, b);
    })) : null;

  const bannerText = h('span');
  const bannerActions = h('span', { class: 'calc-banner-actions' });
  const banner = h('div', { class: 'calc-banner', role: 'status', hidden: true }, bannerText, bannerActions);
  const onboardText = h('span');
  const sampleBtn = h('button', { type: 'button', class: 'calc-btn calc-btn-text', onclick: () => showSample() }, 'Try a sample');
  const onboard = h('div', { class: 'calc-onboard', hidden: true }, onboardText, sampleBtn);

  // settings: grading scale + previous GPA
  const scaleSel = h('select', { class: 'calc-select', id: `${P.id}-scale` });
  for (const s of P.scales) scaleSel.append(h('option', { value: s.id }, s.label));
  const scaleField = P.scales.length > 1 ? h('div', { class: 'calc-field gpa-scale-field' },
    h('label', { class: 'calc-label', for: scaleSel.id }, 'Grading scale'), scaleSel) : null;
  const priorGpa = h('input', { class: 'calc-input is-num', id: `${P.id}-prior-gpa`, inputmode: 'decimal', autocomplete: 'off', placeholder: 'e.g. 3.20' });
  const priorCr = h('input', { class: 'calc-input is-num', id: `${P.id}-prior-cr`, inputmode: 'decimal', autocomplete: 'off', placeholder: 'e.g. 60' });
  const priorGpaMsg = h('p', { class: 'calc-hint is-error', id: `${P.id}-prior-gpa-msg` });
  const priorCrMsg = h('p', { class: 'calc-hint is-error', id: `${P.id}-prior-cr-msg` });
  priorGpa.setAttribute('aria-describedby', priorGpaMsg.id);
  priorCr.setAttribute('aria-describedby', priorCrMsg.id);
  const priorBox = h('div', { class: 'gpa-prior', hidden: true },
    h('div', { class: 'calc-field' }, h('label', { class: 'calc-label', for: priorGpa.id }, 'Current cumulative GPA'), priorGpa, priorGpaMsg),
    h('div', { class: 'calc-field' }, h('label', { class: 'calc-label', for: priorCr.id }, `${cap(P.creditWord)} completed`), priorCr, priorCrMsg));
  const priorToggle = h('button', { type: 'button', class: 'calc-btn calc-btn-text gpa-prior-toggle', 'aria-expanded': 'false' }, 'Add your current GPA');
  const settings = h('div', { class: 'gpa-settings' }, scaleField, P.prior ? h('div', { class: 'gpa-prior-wrap' }, priorToggle) : null);

  const termsEl = h('div', { class: 'gpa-terms' });
  const addTermBtn = P.fixedTerms ? null : h('button', { type: 'button', class: 'calc-btn calc-btn-ghost gpa-add-term' }, 'Add semester / year');
  const resetBtn = h('button', { type: 'button', class: 'calc-btn calc-btn-text gpa-reset' }, 'Start over');
  const inputs = h('div', { class: 'calc-inputs' }, settings, P.prior ? priorBox : null, termsEl,
    h('div', { class: 'calc-actions gpa-actions' }, addTermBtn, resetBtn));

  // result
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
  const sTerm = stat(P.copy.termKicker);
  const sCredits = stat(`Total ${P.creditWord}`);
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
  const trendEl = h('div', { class: 'calc-chart gpa-trend', hidden: true });
  const BLOCKS = {
    verdict: h('div', { class: 'calc-result-top' }, h('div', null, kicker, score, verdict), badge),
    stats: h('div', { class: 'calc-stats' }, sTerm.el, sCredits.el, sPoints.el, sMajor.el, sProj.el),
    note,
    next: nextEl,
    how,
    trend: trendEl,
    ...(opts.blocks || {}),
  };
  const result = h('section', { class: 'calc-result', hidden: true, tabindex: '-1', 'aria-label': 'Your result' }, live,
    (P.blocks || ['verdict', 'stats', 'next', 'how']).flatMap((b) => (b === 'stats' ? [BLOCKS.stats, BLOCKS.note] : [BLOCKS[b]])).filter(Boolean));

  // planner (step 2)
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
  const card = h('div', { class: 'calc-card' }, toolbar, stepsEl, banner, onboard, inputs, result, planner, insights);
  const keep = h('div', { class: 'calc-keep', hidden: !(P.keepGoing && P.keepGoing.length) },
    h('h3', null, 'Keep going'), h('div', { class: 'calc-keep-grid' }));
  app.append(card, keep);
  const pill = createLivePill(app, result, { label: P.copy.pill || 'GPA', onOpen: () => track('pill', null, true) });

  /* ----- rows and terms ----- */
  const types = P.courseTypes && P.courseTypes.length > 1 ? P.courseTypes : null;
  const colsClass = ['gpa-cols', types ? 'has-type' : '', P.major ? 'has-major' : ''].join(' ');

  function gradeOptions(sel, row) {
    const sc = E.scaleOf(P, state);
    const t = types && types.find((x) => x.name === row.type);
    const list = t && t.allowed ? t.allowed : E.gradeList(sc);
    const want = [''].concat(list);
    const have = [...sel.options].map((o) => o.value);
    if (want.join('|') !== have.join('|')) {
      sel.textContent = '';
      sel.append(h('option', { value: '' }, 'Grade'));
      for (const g of list) sel.append(h('option', { value: g }, g.replace('-', '−')));
    }
    sel.value = list.includes(row.grade) ? row.grade : '';
    if (row.grade && !list.includes(row.grade)) {
      sel.append(h('option', { value: row.grade }, row.grade));
      sel.value = row.grade;
    }
  }

  const rowEls = new Map();

  function rowView(term, row, i) {
    const id = row.id;
    const name = h('input', { class: 'calc-input', id: `${id}-n`, value: row.name, autocomplete: 'off', placeholder: (P.coursePlaceholders && P.coursePlaceholders[i % P.coursePlaceholders.length]) || P.courseHint || '', 'aria-label': `Course ${i + 1} name (optional)` });
    const grade = h('select', { class: 'calc-select', id: `${id}-g`, 'aria-label': `Course ${i + 1} grade` });
    const cr = h('input', { class: 'calc-input is-num', id: `${id}-c`, value: row.credits, inputmode: 'decimal', autocomplete: 'off', placeholder: P.defaultCredits || '4', 'aria-label': `Course ${i + 1} ${P.creditWord}` });
    const msg = h('p', { class: 'calc-hint calc-row-msg', id: `${id}-m` });
    cr.setAttribute('aria-describedby', msg.id);
    const rm = h('button', { type: 'button', class: 'calc-btn calc-btn-icon', 'aria-label': `Remove course ${i + 1}` }, '×');
    let typeSel = null;
    if (types) {
      typeSel = h('select', { class: 'calc-select gpa-type', id: `${id}-t`, 'aria-label': `Course ${i + 1} type` });
      for (const t of types) typeSel.append(h('option', { value: t.name }, t.name));
      typeSel.value = row.type;
    }
    let major = null;
    if (P.major) {
      major = h('input', { type: 'checkbox', id: `${id}-mj`, checked: !!row.major });
      major = h('label', { class: 'calc-check gpa-major', title: 'Counts toward your major GPA' }, major, h('span', null, 'Major'));
    }
    gradeOptions(grade, row);
    const el = h('div', { class: `calc-row gpa-row ${colsClass}`, 'data-id': id },
      h('div', { class: 'calc-field gpa-f-name' }, h('label', { class: 'calc-inline-label', for: name.id }, 'Course'), name),
      h('div', { class: 'calc-field gpa-f-grade' }, h('label', { class: 'calc-inline-label', for: grade.id }, 'Grade'), grade),
      h('div', { class: 'calc-field gpa-f-cr' }, h('label', { class: 'calc-inline-label', for: cr.id }, cap(P.creditWord)), cr),
      typeSel ? h('div', { class: 'calc-field gpa-f-type' }, h('label', { class: 'calc-inline-label', for: typeSel.id }, 'Type'), typeSel) : null,
      major, rm, msg);
    name.addEventListener('input', () => { row.name = name.value; changed(); });
    grade.addEventListener('change', () => { row.grade = grade.value; changed(); });
    cr.addEventListener('input', () => { row.credits = cr.value; changed(); });
    if (typeSel) typeSel.addEventListener('change', () => { row.type = typeSel.value; gradeOptions(grade, row); changed(); });
    if (major) major.querySelector('input').addEventListener('change', (e) => { row.major = e.target.checked; changed(); });
    rm.addEventListener('click', () => {
      const idx = term.rows.indexOf(row);
      term.rows.splice(idx, 1);
      if (!term.rows.length && !term.planned) term.rows.push(blankRow(P));
      renderTerms();
      changed();
      const next = termsEl.querySelector(`[data-term="${term.id}"] .gpa-row:nth-of-type(${Math.max(1, idx)}) input`);
      if (next) next.focus();
    });
    rowEls.set(id, { el, msg, cr, grade, name, typeSel });
    return el;
  }

  function termView(term, ti) {
    const fixed = !!P.fixedTerms;
    const gpaChip = h('span', { class: 'gpa-term-gpa', 'data-term-gpa': term.id });
    const body = h('div', { class: 'gpa-term-body', id: `${term.id}-body`, hidden: !!term.collapsed });
    const head = h('div', { class: `calc-row-head ${colsClass}`, 'aria-hidden': 'true' },
      h('span', null, 'Course (optional)'), h('span', null, 'Grade'), h('span', null, cap(P.creditWord)),
      types ? h('span', null, 'Type') : null, P.major ? h('span', null, 'Major') : null, h('span'));
    const rowsWrap = h('div', { class: 'gpa-rows' });
    term.rows.forEach((r, i) => rowsWrap.append(rowView(term, r, i)));
    const add = h('button', { type: 'button', class: 'calc-btn calc-btn-add gpa-add-row' }, 'Add class');
    add.addEventListener('click', () => {
      term.rows.push(blankRow(P, term.rows.length));
      renderTerms();
      changed();
      const last = termsEl.querySelector(`[data-term="${term.id}"] .gpa-row:last-of-type input`);
      if (last) last.focus();
    });
    body.append(term.rows.length || !term.planned ? head : null, rowsWrap, add);
    let title;
    if (fixed) {
      title = h('h3', { class: 'gpa-term-title' }, term.name, term.planned ? h('em', null, ' (optional)') : null);
    } else {
      title = h('input', { class: 'calc-input gpa-term-name', value: term.name, 'aria-label': `${P.termWord || 'Semester'} ${ti + 1} name` });
      title.addEventListener('input', () => { term.name = title.value; changed(false); });
    }
    const toggle = h('button', { type: 'button', class: 'calc-btn calc-btn-text gpa-collapse', 'aria-expanded': String(!term.collapsed), 'aria-controls': body.id }, term.collapsed ? 'Show' : 'Hide');
    toggle.addEventListener('click', () => {
      term.collapsed = !term.collapsed;
      body.hidden = term.collapsed;
      toggle.setAttribute('aria-expanded', String(!term.collapsed));
      toggle.textContent = term.collapsed ? 'Show' : 'Hide';
      changed(false);
    });
    const del = !fixed && state.terms.length > 1 ? h('button', { type: 'button', class: 'calc-btn calc-btn-text gpa-del-term' }, 'Remove') : null;
    if (del) {
      del.addEventListener('click', () => {
        const before = JSON.stringify(pack(state));
        state.terms.splice(state.terms.indexOf(term), 1);
        renderTerms();
        changed();
        toast.show(`Removed ${term.name || 'semester'}`, { action: 'Undo', ms: 6000, onAction: () => { load(JSON.parse(before), { keepMode: true }); changed(); } });
      });
    }
    return h('section', { class: `gpa-term${term.planned ? ' is-planned' : ''}`, 'data-term': term.id },
      h('div', { class: 'gpa-term-head' }, title, gpaChip, h('span', { class: 'gpa-term-tools' }, toggle, del)), body);
  }

  function renderTerms() {
    rowEls.clear();
    termsEl.textContent = '';
    state.terms.forEach((t, i) => termsEl.append(termView(t, i)));
  }

  enterToNext(termsEl);

  /* ----- updates ----- */

  function changed(recompute = true) {
    if (mode === 'own') {
      dirty = !!currentSave;
      store.saveDraft({ state: pack(state), save: currentSave, dirty });
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

  function update() {
    res = E.compute(P, state);
    // rows: errors and exclusions
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
    // prior
    setText(priorGpaMsg, res.prior.gpaError && (state.prior.gpa || state.prior.credits) ? res.prior.gpaError : '');
    setText(priorCrMsg, res.prior.creditsError && (state.prior.gpa || state.prior.credits) ? res.prior.creditsError : '');
    priorGpa.setAttribute('aria-invalid', priorGpaMsg.textContent ? 'true' : 'false');
    priorCr.setAttribute('aria-invalid', priorCrMsg.textContent ? 'true' : 'false');

    const ready = res.ready && (P.hasOfficialGpa !== false);
    result.hidden = !ready;
    pill.enable(ready);
    onboard.hidden = mode !== 'own' || hasData(state) && store.seen();
    if (ready) {
      const g = res.current.gpa;
      const v = verdictFor(g);
      setText(score, fmt(g));
      setText(badge, v.letter.replace('-', '−'));
      badge.className = `calc-badge gpa-letter is-band-${v.band}`;
      const below = P.standingLine && g < P.standingLine;
      setText(verdict, `You have a ${fmt(g)}, ${articleFor(v.letter)} ${v.letter.replace('-', '−')} average${below ? ', below the 2.0 most colleges require for good standing' : ''}.`);
      setText(live, `${P.copy.resultKicker}: ${fmt(g)}`);
      pill.update(fmt(g));
      const lastTerm = [...res.terms].reverse().find((t) => !t.planned && t.credits > 0);
      sTerm.el.hidden = !(lastTerm && res.terms.filter((t) => !t.planned && t.credits > 0).length > 1 || (lastTerm && res.prior.used));
      if (lastTerm) { setText(sTerm.v, fmt(lastTerm.gpa)); setText(sTerm.k, `${lastTerm.name || P.copy.termKicker} GPA`); }
      setText(sCredits.v, E.fmtNum(res.current.credits));
      setText(sPoints.v, E.fmtNum(res.current.points, 2));
      sMajor.el.hidden = !res.major;
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
      setText(ctaBtn, below ? P.next.rescueLabel : P.next.planLabel);
      renderLinks(g);
      if (how.open) renderHow();
      renderTrend();
      renderInsights(v);
      track('result', { gpa: E.roundHalf(g, 2) });
    } else {
      insights.textContent = '';
    }
    if (planner && !planner.hidden) updatePlan();
    renderKeep(ready ? res.current.gpa : null);
    saveLabel();
  }

  function renderLinks(g) {
    nextLinks.textContent = '';
    for (const l of (P.next && P.next.links) || []) {
      const href = l.withGpa ? `${l.href}?gpa=${E.roundHalf(g, 2).toFixed(2)}` : l.href;
      nextLinks.append(h('a', { href, onclick: () => track('next', { to: l.href }, true) }, l.label));
    }
  }

  function renderKeep(g) {
    const grid = keep.querySelector('.calc-keep-grid');
    if (!P.keepGoing || !P.keepGoing.length) return;
    if (grid.childElementCount) return;
    for (const k of P.keepGoing) {
      const a = h('a', { href: k.href, onclick: () => track('keep_going', { to: k.href }, true) }, k.title);
      grid.append(h('div', { class: 'rx-relcard', style: { '--rx-ico': icon(k.icon) } }, h('h3', null, a), h('p', { class: 'rx-relsub' }, k.sub)));
    }
    void g;
  }

  function renderInsights(v) {
    insights.textContent = '';
    const g = res.current.gpa;
    const card = (k, text) => insights.append(h('div', { class: 'calc-insight' }, h('div', { class: 'calc-insight-k' }, k), h('div', { class: 'calc-insight-v' }, text)));
    let good;
    if (g >= 3.7) good = `${fmt(g)} is in the A range, the top grade band.`;
    else if (g >= 3.0) good = `${fmt(g)} is a B average or better.`;
    else if (g >= 2.0) good = `${fmt(g)} is above 2.0, the usual line for good academic standing.`;
    else good = `${fmt(g)} is below 2.0, the usual line for good academic standing. The planner shows the way back.`;
    card('Is my GPA good?', good);
    const lever = E.biggestLever(res);
    if (lever && lever.gain >= 0.005) {
      card('Biggest lever', `Raising ${lever.name || 'one course'} from ${lever.from.replace('-', '−')} to ${lever.to.replace('-', '−')} adds ${E.fmtNum(lever.gain, D)} to your GPA.`);
    }
    void v;
  }

  async function renderTrend() {
    if (!P.charts || !BLOCKS.trend) return;
    const pts = E.trend(res);
    trendEl.hidden = pts.length < 2;
    if (pts.length < 2) return;
    const kit = await loadKit();
    // Zoom to the student's range (at least one grade point tall) so changes are visible.
    const ys = pts.flatMap((p) => [p.term, p.cumulative]);
    let lo = Math.max(0, Math.floor((Math.min(...ys) - 0.25) * 2) / 2);
    let hi = Math.min(res.max, Math.ceil((Math.max(...ys) + 0.25) * 2) / 2);
    if (hi - lo < 1) { hi = Math.min(res.max, lo + 1); lo = Math.max(0, hi - 1); }
    trendEl.textContent = '';
    const host = h('div');
    trendEl.append(h('p', { class: 'calc-chart-title' }, 'Your GPA by semester'), host,
      h('div', { class: 'calc-legend' }, h('span', { class: 'ck-s1' }, h('i'), 'Semester GPA'), h('span', { class: 'ck-s2' }, h('i'), 'Cumulative GPA')));
    kit.lineChart(host, [
      { name: 'Semester GPA', cls: 'ck-s1', points: pts.map((p) => ({ label: p.name, y: p.term })) },
      { name: 'Cumulative GPA', cls: 'ck-s2', points: pts.map((p) => ({ label: p.name, y: p.cumulative })) },
    ], { yMin: lo, yMax: hi, ticks: [lo, (lo + hi) / 2, hi], format: (y) => E.fmtGpa(y, 2), title: `Semester and cumulative GPA: ${pts.map((p) => `${p.name} ${E.fmtGpa(p.term, 2)}`).join(', ')}` });
  }

  let kitP = null;
  const loadKit = () => (kitP ||= import('../core/chart-kit.js'));

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
      tile(`Total ${P.creditWord}`, E.fmtNum(res.current.credits), 'gpa-ex__tile--div'), h('span', { class: 'gpa-ex__rop', 'aria-hidden': 'true' }, '='),
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
    step = 1;
    setStep();
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

  function goStep1() {
    step = 0;
    setStep();
    card.scrollIntoView({ behavior: reducedMotion() ? 'auto' : 'smooth', block: 'start' });
  }

  function setStep() {
    stepBtns.forEach((b, i) => {
      const li = b.parentElement;
      li.classList.toggle('is-done', i < step || (i === 0 && res && res.ready));
      if (i === step) li.setAttribute('aria-current', 'step');
      else li.removeAttribute('aria-current');
      setText(b.firstChild, (i < step || (i === 0 && step === 1)) ? '✓' : String(i + 1));
    });
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
      return;
    }
    const T = fmt(p.target);
    if (p.status === 'met') {
      setText(planBig, 'You’re there');
      setText(planLine, `Your GPA stays at or above ${T} even with the lowest grades in your next ${E.fmtNum(p.upcoming)} ${cw}.`);
      setText(planLine2, '');
    } else if (p.status === 'reachable') {
      setText(planBig, fmt(p.needed));
      setText(planLine, `You need about ${articleFor(p.letter)} ${p.letter.replace('-', '−')} average (${fmt(p.needed)}) in your next ${E.fmtNum(p.upcoming)} ${cw} to reach ${T}.`);
      setText(planLine2, `Straight ${topLetter(sc)}s would take you to ${fmt(p.highest)}.`);
    } else {
      setText(planBig, 'Out of reach this term');
      setText(planLine, `Even straight ${topLetter(sc)}s in ${E.fmtNum(p.upcoming)} ${cw} would bring you to ${fmt(p.highest)}, short of ${T}.`);
      setText(planLine2, p.creditsForTarget ? `Reaching ${T} would take about ${E.fmtNum(p.creditsForTarget)} ${cw} of straight ${topLetter(sc)}s.` : '');
    }
    track('plan', { target: p.target });
    if (P.charts) {
      whatIf.hidden = false;
      loadKit().then((kit) => {
        const start = Math.max(0, Math.min(sc.max ?? E.scaleMax(sc), p.status === 'reachable' ? E.roundHalf(p.needed, 1) : E.roundHalf(res.current.gpa, 1)));
        const tot = { credits: res.current.credits, points: res.current.points };
        const fn = (avg) => {
          const after = E.whatIf(tot, avg, p.upcoming);
          return `A ${avg.toFixed(1)} average next term → ${fmt(after)} cumulative`;
        };
        if (!slider || slider.max !== E.scaleMax(sc)) {
          slider = kit.whatIfSlider(whatIfHost, { min: 0, max: E.scaleMax(sc), step: 0.1, value: start, label: 'Average GPA next term', onInput: fn });
          slider.max = E.scaleMax(sc);
          slider.fn = fn;
        } else {
          slider.input.dispatchEvent(new Event('input'));
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
  function syncPrior() {
    priorBox.hidden = !state.priorOpen;
    priorToggle.setAttribute('aria-expanded', String(!!state.priorOpen));
    setText(priorToggle, state.priorOpen ? 'Hide current GPA' : 'Add your current GPA');
  }
  priorGpa.addEventListener('input', () => { state.prior.gpa = priorGpa.value; changed(); });
  priorCr.addEventListener('input', () => { state.prior.credits = priorCr.value; changed(); });
  if (addTermBtn) {
    addTermBtn.addEventListener('click', () => {
      state.terms.push({ id: newId('t'), name: termName(P, state.terms.length), rows: rowsOf(P, P.rowsPerTerm) });
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
    if (!keepMode) slider = null;
    scaleSel.value = state.scale;
    priorGpa.value = state.prior.gpa;
    priorCr.value = state.prior.credits;
    tGpa.value = state.target.gpa;
    tCr.value = state.target.credits;
    syncPrior();
    renderTerms();
    update();
  }

  function setBanner(text, actions) {
    if (!text) {
      banner.hidden = true;
      return;
    }
    setText(bannerText, text);
    bannerActions.textContent = '';
    for (const [label, fn] of actions || []) bannerActions.append(h('button', { type: 'button', class: 'calc-btn calc-btn-text', onclick: fn }, label));
    banner.hidden = false;
  }

  function showSample() {
    if (mode === 'own') ownBefore = { state: pack(state), save: currentSave, dirty };
    mode = 'sample';
    load(P.sample);
    setBanner('You’re looking at a sample.', [['Clear sample', () => leaveSpecial()]]);
    store.markSeen();
    track('sample');
  }

  function leaveSpecial() {
    const b = ownBefore;
    mode = 'own';
    ownBefore = null;
    setBanner('');
    currentSave = b ? b.save : null;
    dirty = b ? b.dirty : false;
    load(b ? b.state : null);
  }

  function reset() {
    const snapshot = { state: pack(state), save: currentSave, dirty, mode, ownBefore };
    if (mode !== 'own') { mode = 'own'; ownBefore = null; setBanner(''); }
    currentSave = null;
    dirty = false;
    load(null);
    planner && (planner.hidden = true);
    step = 0;
    setStep();
    store.saveDraft(null);
    track('reset', null, true);
    toast.show('Started over.', { action: 'Undo', ms: 6000, onAction: () => {
      mode = snapshot.mode;
      ownBefore = snapshot.ownBefore;
      currentSave = snapshot.save;
      dirty = snapshot.dirty;
      load(snapshot.state);
      if (mode === 'own') store.saveDraft({ state: pack(state), save: currentSave, dirty });
      if (mode === 'sample') setBanner('You’re looking at a sample.', [['Clear sample', () => leaveSpecial()]]);
      toast.show('Restored.');
    } });
  }

  /* ----- saves ----- */
  function saveLabel() {
    setText(saveName, currentSave ? `${currentSave}${dirty ? ' · unsaved changes' : ''}` : '');
  }

  function askName(def) {
    const n = window.prompt('Name this calculation (e.g. Fall 2026)', def || '');
    return n == null ? null : n.trim().slice(0, 60);
  }

  function saveAs() {
    if (mode === 'sample') { toast.show('Samples can’t be saved. Enter your own grades to save them.'); return; }
    const name = askName(currentSave ? `${currentSave} (copy)` : '');
    if (!name) return;
    if (store.has(name) && !window.confirm(`Replace your save “${name}”?`)) return;
    if (!store.save(name, pack(state))) { toast.show('Couldn’t save. Your browser may be blocking storage.'); return; }
    if (mode === 'shared') { mode = 'own'; ownBefore = null; setBanner(''); clearHash(); }
    currentSave = name;
    dirty = false;
    store.saveDraft({ state: pack(state), save: currentSave, dirty });
    saveLabel();
    track('save', null, true);
    toast.show(`Saved “${name}”`);
  }

  function saveNow() {
    if (!currentSave || mode !== 'own') { saveAs(); return; }
    store.save(currentSave, pack(state));
    dirty = false;
    store.saveDraft({ state: pack(state), save: currentSave, dirty });
    saveLabel();
    track('save', null, true);
    toast.show(`Saved “${currentSave}”`);
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
    if (mode !== 'own') { mode = 'own'; ownBefore = null; setBanner(''); clearHash(); }
    currentSave = name;
    dirty = false;
    load(s);
    store.saveDraft({ state: pack(state), save: currentSave, dirty });
    saveLabel();
    track('restore', { from: 'save' }, true);
    toast.show(`Opened “${name}”`);
  }

  function renderSavesMenu() {
    savesMenu.textContent = '';
    const item = (label, fn, cls) => h('li', { role: 'none', class: cls }, h('button', { type: 'button', role: 'menuitem', onclick: () => { savesMenu.hidden = true; fn(); } }, label));
    if (!store.available) {
      savesMenu.append(h('li', { class: 'calc-menu-empty', role: 'none' }, 'Saving is off in this browser (private mode or blocked storage).'));
      return;
    }
    if (currentSave && mode === 'own') savesMenu.append(item(`Save “${currentSave}”`, saveNow));
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
      if (mode !== 'own') { mode = 'own'; ownBefore = null; setBanner(''); clearHash(); }
      currentSave = null;
      dirty = false;
      load(null);
      store.saveDraft(null);
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
  createMenu(savesBtn, savesMenu, renderSavesMenu);

  /* ----- share ----- */
  function summary() {
    const lines = [`My ${P.title}: ${fmt(res.current.gpa)} (${E.fmtNum(res.current.credits)} ${P.creditWord})`];
    for (const t of res.terms) if (t.credits) lines.push(`${t.name || 'Semester'}: ${fmt(t.gpa)} over ${E.fmtNum(t.credits)} ${P.creditWord}`);
    if (res.major) lines.push(`Major GPA: ${fmt(res.major.gpa)}`);
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
    out.push([], ['Cumulative GPA', fmt(res.current.gpa)], [`Total ${P.creditWord}`, res.current.credits]);
    return out;
  }
  const shareItem = (label, fn, needsResult = true) => h('li', { role: 'none' }, h('button', { type: 'button', role: 'menuitem', onclick: async () => {
    shareMenu.hidden = true;
    if (needsResult && !(res && res.ready)) { toast.show('Add a grade first.'); return; }
    await fn();
    track('share', { method: label }, true);
  } }, label));
  shareMenu.append(
    shareItem('Copy link', async () => toast.show(await copyText(shareUrl(P.hashKey, pack(state))) ? 'Link copied. Anyone with it sees these grades.' : 'Couldn’t copy. Try again.')),
    shareItem('Copy summary', async () => toast.show(await copyText(summary()) ? 'Summary copied' : 'Couldn’t copy. Try again.')),
    shareItem('Download CSV', async () => { downloadCSV(`${P.id}-gpa.csv`, csvRows()); sendEvent('gpa_export', { format: 'csv' }); }),
    shareItem('Print / Save as PDF', async () => { how.open = true; renderHow(); sendEvent('gpa_export', { format: 'print' }); printPage(); }),
  );
  createMenu(shareBtn, shareMenu);

  /* ----- start ----- */
  if (P.legacy) importLegacyOnce(store, 'imported', P.legacy.keys, P.legacy.convert);
  const shared = readHash(P.hashKey);
  const draft = store.loadDraft();
  if (shared && Array.isArray(shared.terms)) {
    mode = 'shared';
    ownBefore = draft && draft.state ? { state: draft.state, save: draft.save || null, dirty: !!draft.dirty } : null;
    load(shared);
    setBanner('Viewing a shared calculation.', [['Save a copy', () => saveAs()], ['Close', () => { clearHash(); leaveSpecial(); }]]);
    track('open_link');
  } else if (draft && draft.state && hasData(normalize(P, draft.state))) {
    currentSave = draft.save || null;
    dirty = !!draft.dirty;
    load(draft.state);
    setBanner('Welcome back, we restored your last calculation.', [['Start fresh', () => reset()]]);
    track('restore', { from: 'draft' });
  } else {
    load(opts.initial || null);
  }
  setText(onboardText, P.copy.onboard);
  if (store.seen()) setText(sampleBtn, 'Show an example');
  onboard.hidden = mode !== 'own' || (hasData(state) && store.seen());
  store.markSeen();
  setStep();
  return { get state() { return state; }, get result() { return res; }, openPlanner, reset, load };
}

function cap(s) { return s.charAt(0).toUpperCase() + s.slice(1); }
function articleFor(letter) { return /^[AEF]/i.test(letter) ? 'an' : 'a'; }
function topLetter(sc) { return Object.keys(sc.grades).find((k) => !k.endsWith('+') && sc.grades[k] === E.scaleMax(sc)) || 'A'; }

export { normalize, pack, blankState };
