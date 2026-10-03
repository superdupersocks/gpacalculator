/* College GPA calculator v2 entry ([college-gpa-calculator] page). Mounts the GPA screen with the
 * College profile into #root, or into a shortcode mount. Loaded only where the per-page switch
 * (includes/calc-switch.php) turns the new version on; the old bundle is the fallback.
 *
 * Saves from the old College/homepage calculator (gpa_calc_draft_v1, gpa_calc_saved_v1) are copied
 * once into College's own storage. The old keys are only read, never changed, so the homepage
 * calculator keeps its saved data exactly as it is. */
import { mountsFor } from '../core/calc-core.js?v=da1cb432a2';
import { mountGpa, fromBolt } from './gpa-app.js?v=da1cb432a2';
import college from '../profiles/college.js?v=da1cb432a2';

const profile = {
  ...college,
  legacy: {
    keys: ['gpa_calc_draft_v1', 'gpa_calc_saved_v1'],
    convert(draft, saved) {
      const d = fromBolt(draft, 'college');
      const list = saved && Array.isArray(saved.calculations) ? saved.calculations : [];
      return {
        draft: d ? { state: d, save: null, dirty: false } : null,
        saves: list.map((c) => ({ name: c.name || 'My calculation', state: fromBolt(c, 'college') })).filter((s) => s.state),
      };
    },
  },
};

for (const el of mountsFor('college-gpa-calculator')) mountGpa(el, profile);
