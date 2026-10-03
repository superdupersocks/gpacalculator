/* Homepage GPA calculator v2 entry ([gpa-calculator]). A "School level" switch (College | High School) on
 * top of two GPA screens: College runs the College profile, High School the high school profile (levels,
 * weighted GPA, course suggestions). Each keeps its own draft and saves; the last level used is remembered.
 * Loaded only where the per-page switch (includes/calc-switch.php) turns the new version on.
 *
 * The old homepage (Bolt) kept one draft and one save list for both levels (gpa_calc_draft_v1,
 * gpa_calc_saved_v1, with calculatorMode). They are copied once into the matching level's storage and only
 * read, never changed, so the old calculator (?calc=old) still has them. */
import { h, mountsFor, sendEvent } from '../core/calc-core.js?v=396d419a7d';
import { mountGpa, fromBolt, fromBoltHS } from './gpa-app.js?v=396d419a7d';
import college from '../profiles/college.js?v=396d419a7d';
import highSchool from '../profiles/high-school.js?v=396d419a7d';

const MODE_KEY = 'gpac:home:mode';
const OLD = ['gpa_calc_draft_v1', 'gpa_calc_saved_v1'];

const legacy = (conv) => ({
  keys: OLD,
  convert(draft, saved) {
    const d = conv(draft);
    const list = saved && Array.isArray(saved.calculations) ? saved.calculations : [];
    return {
      draft: d ? { state: d, save: null, dirty: false } : null,
      saves: list.map((c) => ({ name: c.name || 'My calculation', state: conv(c) })).filter((s) => s.state),
    };
  },
});

const PROFILES = {
  college: {
    ...college,
    id: 'home-college',
    prefix: 'home', // GA4: home_result, home_plan, home_save …
    storeKey: 'gpac:home:college:v1',
    url: '/',
    title: 'GPA',
    homeScreenHint: undefined, // ships with "go home screen"
    copy: { ...college.copy, summaryUrl: 'gpacalculator.net' },
    legacy: legacy((o) => fromBolt(o, 'college')),
  },
  hs: {
    ...highSchool,
    id: 'home-hs',
    prefix: 'home_hs',
    storeKey: 'gpac:home:hs:v1',
    url: '/',
    legacy: legacy(fromBoltHS),
  },
};

function ls() {
  try { return window.localStorage; } catch (e) { return null; }
}

/** The level to open on: the last one used here, else the old homepage draft's level, else College. */
function startMode() {
  const s = ls();
  if (!s) return 'college';
  try {
    const m = s.getItem(MODE_KEY);
    if (m === 'college' || m === 'hs') return m;
    const old = JSON.parse(s.getItem(OLD[0]) || 'null');
    if (old && old.calculatorMode === 'highschool') return 'hs';
  } catch (e) { /* fall through */ }
  return 'college';
}

function mountHome(root) {
  if (root.id === 'root') root.classList.add('calc-mounted');
  root.textContent = '';
  const btn = (mode, label) => h('button', { type: 'button', class: 'calc-seg-btn', role: 'radio', 'data-mode': mode, 'aria-checked': 'false' }, label);
  const bCollege = btn('college', 'College');
  const bHs = btn('hs', 'High School');
  const seg = h('div', { class: 'calc-seg gpa-home-level', role: 'radiogroup', 'aria-label': 'School level' }, bCollege, bHs);
  const hosts = { college: h('div', { class: 'gpa-home-pane' }), hs: h('div', { class: 'gpa-home-pane' }) };
  root.append(h('div', { class: 'calc gpa-home-switch' }, h('span', { class: 'calc-seg-label', 'aria-hidden': 'true' }, 'School level'), seg), hosts.college, hosts.hs);
  const mounted = {};

  const show = (mode, user) => {
    for (const [m, el] of Object.entries(hosts)) el.hidden = m !== mode;
    bCollege.setAttribute('aria-checked', String(mode === 'college'));
    bHs.setAttribute('aria-checked', String(mode === 'hs'));
    bCollege.tabIndex = mode === 'college' ? 0 : -1;
    bHs.tabIndex = mode === 'hs' ? 0 : -1;
    // Each level's screen is built the first time it is shown (its own storage, legacy import and events).
    if (!mounted[mode]) mounted[mode] = mountGpa(hosts[mode], PROFILES[mode]);
    if (user) {
      const s = ls();
      try { if (s) s.setItem(MODE_KEY, mode); } catch (e) { /* private mode */ }
      sendEvent('home_level', { level: mode === 'hs' ? 'high_school' : 'college' });
    }
  };
  seg.addEventListener('click', (e) => {
    const b = e.target.closest('[data-mode]');
    if (b && b.getAttribute('aria-checked') !== 'true') show(b.dataset.mode, true);
  });
  seg.addEventListener('keydown', (e) => {
    if (!['ArrowLeft', 'ArrowRight'].includes(e.key)) return;
    e.preventDefault();
    const next = bCollege.getAttribute('aria-checked') === 'true' ? 'hs' : 'college';
    show(next, true);
    (next === 'hs' ? bHs : bCollege).focus();
  });
  show(startMode(), false);
}

for (const el of mountsFor('gpa-calculator')) mountHome(el);
