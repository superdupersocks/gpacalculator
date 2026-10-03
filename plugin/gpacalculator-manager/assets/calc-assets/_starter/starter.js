/* Starter calculator v1.0.0: the layout template every new calculator copies.
 * Not deployed (package.sh skips calc-assets/_starter). It doubles as the fixture for tests/core_qa.py.
 *
 * Shape to copy: parse -> compute (pure) -> update (touch output nodes only).
 * Step 1: rows of score + weight with a live weighted grade.
 * Step 2: planner, "what do I need on the final?".
 */
import {
  h, setText, parseScore, parseNumber, fmtPct, round, gradeFor, nextGrade,
  createStore, createTracker, readHash, clearHash, mountLayout, createResultHero,
  createLivePill, createToast, enterToNext, wireSavesAndShare, mountsFor,
} from '../core/calc-core.js?v=2625dc4f8b';

const PREFIX = 'stx';
const STORE_KEY = 'gpacalc.starter';
const PASS = 60;

const SAMPLE = {
  rows: [
    { name: 'Homework', score: '92%', weight: '20' },
    { name: 'Quizzes', score: '42/50', weight: '30' },
    { name: 'Midterm', score: 'B+', weight: '25' },
  ],
  final: { weight: '25', target: '90' },
};

const blankState = () => ({ rows: [{ name: '', score: '', weight: '' }, { name: '', score: '', weight: '' }, { name: '', score: '', weight: '' }], final: { weight: '', target: '' } });

/* ---------- compute (pure) ---------- */

/** Weighted average of rows that have a valid score. Missing weights share the rest equally. */
export function compute(rows) {
  const parsed = rows.map((r) => ({ score: parseScore(r.score), weight: parseNumber(r.weight), blankW: !String(r.weight || '').trim() }));
  const used = parsed.filter((p) => p.score);
  if (!used.length) return null;
  const given = used.filter((p) => p.weight != null);
  const missing = used.filter((p) => p.weight == null);
  const givenSum = given.reduce((a, p) => a + p.weight, 0);
  let sumW = 0;
  let sumWS = 0;
  if (!given.length) {
    used.forEach((p) => { sumW += 1; sumWS += p.score.pct; });
  } else {
    const share = missing.length ? Math.max(0, 100 - givenSum) / missing.length : 0;
    for (const p of used) {
      const w = p.weight != null ? p.weight : share;
      sumW += w;
      sumWS += w * p.score.pct;
    }
  }
  if (!(sumW > 0)) return null;
  return { pct: sumWS / sumW, weightUsed: given.length ? round(sumW, 4) : null };
}

/** Score needed on the final (weight %) to finish at target %. */
export function needed(current, finalWeight, target) {
  const w = finalWeight / 100;
  if (!(w > 0) || w > 1) return null;
  return (target - current * (1 - w)) / w;
}

/* ---------- UI ---------- */

function init(root) {
  const store = createStore(STORE_KEY);
  const track = createTracker(PREFIX);
  const L = mountLayout(root, { prefix: PREFIX, steps: ['Your grade', 'Plan your final'] });
  const toast = createToast(L.app);
  const hero = createResultHero({ kicker: 'Current grade' });
  let state = blankState();
  let sample = false;

  // Step 1: rows
  const rowsHost = h('div', { class: 'calc-rows' });
  const head = h('div', { class: 'calc-row-head', 'aria-hidden': 'true' }, h('span', null, 'Category'), h('span', null, 'Score'), h('span', null, 'Weight %'), h('span'));
  const addBtn = h('button', { type: 'button', class: 'calc-btn calc-btn-text' }, '+ Add row');
  L.inputs.append(h('h2', null, 'Your grade'), h('p', { class: 'calc-sub' }, 'Scores can be 84, 84%, 42/50 or a letter like B+.'), head, rowsHost, addBtn);
  enterToNext(L.inputs, () => addRow(true));

  const rowEls = [];
  function addRow(focus) {
    state.rows.push({ name: '', score: '', weight: '' });
    buildRow(state.rows.length - 1);
    if (focus) rowEls[rowEls.length - 1].name.focus();
    changed();
  }
  function buildRow(i) {
    const n = i + 1;
    const name = h('input', { class: 'calc-input', type: 'text', placeholder: 'e.g. Homework', 'aria-label': `Category ${n} name`, autocomplete: 'off' });
    const score = h('input', { class: 'calc-input is-num', type: 'text', inputmode: 'decimal', placeholder: 'e.g. 88%', 'aria-label': `Category ${n} score` });
    const weight = h('input', { class: 'calc-input is-num', type: 'text', inputmode: 'decimal', placeholder: 'e.g. 20', 'aria-label': `Category ${n} weight percent` });
    const hint = h('div', { class: 'calc-hint', 'aria-live': 'polite' });
    const del = h('button', { type: 'button', class: 'calc-btn calc-btn-icon', 'aria-label': `Remove category ${n}` }, '×');
    const row = h('div', { class: 'calc-row' },
      h('div', { class: 'calc-field' }, name),
      h('div', { class: 'calc-field' }, h('span', { class: 'calc-inline-label' }, 'Score'), score, hint),
      h('div', { class: 'calc-field' }, h('span', { class: 'calc-inline-label' }, 'Weight %'), weight),
      del);
    const els = { row, name, score, weight, hint };
    rowEls.push(els);
    rowsHost.append(row);
    const bind = (input, field) => input.addEventListener('input', () => {
      state.rows[rowEls.indexOf(els)][field] = input.value;
      changed();
    });
    bind(name, 'name');
    bind(score, 'score');
    bind(weight, 'weight');
    del.addEventListener('click', () => {
      const idx = rowEls.indexOf(els);
      rowEls.splice(idx, 1);
      state.rows.splice(idx, 1);
      row.remove();
      changed();
    });
  }
  addBtn.addEventListener('click', () => addRow(true));

  // Result + step 2
  const planBtn = h('button', { type: 'button', class: 'calc-btn calc-btn-primary' }, 'What do I need on the final?');
  L.result.append(hero.el, h('div', { class: 'calc-actions' }, planBtn));
  const planner = h('div', { class: 'calc-card calc-planner', hidden: true });
  const fw = h('input', { class: 'calc-input is-num', type: 'text', inputmode: 'decimal', placeholder: 'e.g. 25', 'aria-label': 'Final exam weight percent' });
  const tg = h('input', { class: 'calc-input is-num', type: 'text', inputmode: 'decimal', placeholder: 'e.g. 90', 'aria-label': 'Target grade percent' });
  const planOut = h('p', { class: 'calc-verdict calc-plan-out', 'aria-live': 'polite' });
  planner.append(h('h2', null, 'Plan your final'),
    h('div', { class: 'calc-row', style: { '--calc-cols': '1fr 1fr', '--calc-cols-mobile': '1fr 1fr' } },
      h('label', { class: 'calc-field' }, h('span', { class: 'calc-label' }, 'Final weight %'), fw),
      h('label', { class: 'calc-field' }, h('span', { class: 'calc-label' }, 'Grade you want %'), tg)),
    planOut);
  L.card.after(planner);
  fw.addEventListener('input', () => { state.final.weight = fw.value; changed(); });
  tg.addEventListener('input', () => { state.final.target = tg.value; changed(); });
  planBtn.addEventListener('click', () => {
    planner.hidden = false;
    L.setStep(1);
    track('step_2');
    if (!state.final.target && planBtn.dataset.pass) {
      state.final.target = String(PASS);
      tg.value = state.final.target;
    }
    fw.focus();
    update();
  });

  const pill = createLivePill(L.app, L.result, { label: 'Live grade' });

  // Sample + onboarding
  const sampleBtn = h('button', { type: 'button', class: 'calc-btn calc-btn-ghost' }, 'Try a sample');
  L.onboard.append(h('p', null, 'New here? Load example scores to see how it works.'), sampleBtn);
  L.onboard.hidden = store.seen();
  const exampleLink = h('button', { type: 'button', class: 'calc-btn calc-btn-text' }, 'Show an example');
  if (store.seen()) L.inputs.append(exampleLink);
  const loadSample = () => {
    setState(JSON.parse(JSON.stringify(SAMPLE)), true);
    track('sample');
  };
  sampleBtn.addEventListener('click', loadSample);
  exampleLink.addEventListener('click', loadSample);
  L.bannerText.textContent = 'You’re looking at sample data.';
  L.bannerClear.addEventListener('click', () => setState(blankState(), false));

  function setState(next, isSample = false) {
    state = next;
    sample = isSample;
    rowEls.length = 0;
    rowsHost.textContent = '';
    state.rows.forEach((_, i) => buildRow(i));
    rowEls.forEach((els, i) => {
      els.name.value = state.rows[i].name || '';
      els.score.value = state.rows[i].score || '';
      els.weight.value = state.rows[i].weight || '';
    });
    fw.value = state.final.weight || '';
    tg.value = state.final.target || '';
    hero.reset();
    changed(true);
  }

  function changed(fromSet) {
    if (!fromSet && sample) sample = false;
    L.banner.hidden = !sample;
    if (!sample) store.saveDraft(state);
    update();
  }

  function update() {
    rowEls.forEach((els, i) => {
      const raw = String(state.rows[i].score || '');
      const p = parseScore(raw);
      els.score.setAttribute('aria-invalid', raw.trim() && !p ? 'true' : 'false');
      els.hint.className = `calc-hint${raw.trim() && !p ? ' is-error' : p && p.kind !== 'percent' ? ' is-ok' : ''}`;
      setText(els.hint, !raw.trim() ? '' : !p ? 'Try 84, 84% or 42/50' : p.kind !== 'percent' ? `= ${fmtPct(p.pct)}` : '');
    });
    const r = compute(state.rows);
    L.result.hidden = !r;
    pill.enable(!!r);
    if (!r) {
      L.setStep(0);
      return;
    }
    if (!store.seen()) store.markSeen();
    track('result');
    const g = gradeFor(r.pct);
    const atRisk = r.pct < PASS + 5;
    const verdict = r.pct >= PASS ? `Right now you’re passing with ${/^[AF]/.test(g.letter) ? 'an' : 'a'} ${g.letter}.` : 'Right now you’re below passing.';
    hero.update({ pct: r.pct, letter: g.letter, gpa: g.gpa, verdict });
    pill.update(fmtPct(r.pct));
    planBtn.dataset.pass = atRisk ? '1' : '';
    setText(planBtn, atRisk ? 'What do I need to pass?' : 'What do I need on the final?');
    if (planner.hidden) L.setStep(0);

    const w = parseNumber(state.final.weight);
    const t = parseNumber(state.final.target);
    if (w == null || t == null) setText(planOut, 'Enter the final’s weight and the grade you want.');
    else {
      const n = needed(r.pct, w, t);
      if (n == null) setText(planOut, 'Final weight must be between 0 and 100%.');
      else {
        track('plan');
        const nxt = nextGrade(r.pct);
        setText(planOut, n <= 0 ? `You’ll finish at ${fmtPct(t)} or higher even with a 0% on the final.`
          : n > 100 ? `You’d need ${fmtPct(n)} on the final, which isn’t possible without extra credit.`
            : `You need ${fmtPct(n)} on the final to finish at ${fmtPct(t)}.${nxt ? ` (${nxt.letter} starts at ${nxt.min}%.)` : ''}`);
      }
    }
  }

  const summary = () => {
    const r = compute(state.rows);
    const lines = state.rows.filter((x) => parseScore(x.score)).map((x) => `${x.name || 'Category'}: ${fmtPct(parseScore(x.score).pct)}${x.weight ? ` (${x.weight}%)` : ''}`);
    return `${lines.join('\n')}\nCurrent grade: ${r ? `${fmtPct(r.pct)} ${gradeFor(r.pct).letter}` : 'n/a'}`;
  };
  wireSavesAndShare(L, {
    store, track, toast, prefix: PREFIX,
    getState: () => state,
    setState: (s) => setState(s, false),
    isSample: () => sample,
    newState: blankState,
    summary,
    csv: () => [['Category', 'Score', 'Score %', 'Weight %'], ...state.rows.map((x) => {
      const p = parseScore(x.score);
      return [x.name, x.score, p ? fmtPct(p.pct) : '', x.weight];
    })],
    csvName: 'grade.csv',
  });

  const fromHash = readHash(PREFIX);
  const draft = store.loadDraft();
  if (fromHash && Array.isArray(fromHash.rows)) {
    setState({ rows: fromHash.rows, final: fromHash.final || { weight: '', target: '' } });
    clearHash();
  } else if (draft && Array.isArray(draft.rows)) setState(draft);
  else setState(blankState());
}

// Shortcode mounts ([starter_calculator]) or #root on a page template.
mountsFor('starter').forEach(init);
