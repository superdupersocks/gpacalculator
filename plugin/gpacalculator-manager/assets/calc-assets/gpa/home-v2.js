/* Homepage GPA calculator v2 entry (proposal, [gpa-calculator]): one calculator for high school and college
 * (profiles/home.js), no College | High School switch. Not wired to the per-page switch yet.
 *
 * The old homepage (Bolt) kept one draft and one save list for both levels (gpa_calc_draft_v1,
 * gpa_calc_saved_v1, with calculatorMode). They are copied once and only read, never changed, so the old
 * calculator (?calc=old) still has them. */
import { mountsFor } from '../core/calc-core.js?v=da1cb432a2';
import { mountGpa, fromBolt, fromBoltHS } from './gpa-app.js?v=da1cb432a2';
import home from '../profiles/home.js?v=da1cb432a2';

const conv = (o) => fromBoltHS(o) || fromBolt(o, 'college');

const profile = {
  ...home,
  legacy: {
    keys: ['gpa_calc_draft_v1', 'gpa_calc_saved_v1'],
    convert(draft, saved) {
      const d = conv(draft);
      const list = saved && Array.isArray(saved.calculations) ? saved.calculations : [];
      return {
        draft: d ? { state: d, save: null, dirty: false } : null,
        saves: list.map((c) => ({ name: c.name || 'My calculation', state: conv(c) })).filter((s) => s.state),
      };
    },
  },
};

for (const el of mountsFor('gpa-calculator')) mountGpa(el, profile);
