/* University GPA calculator v2 entry ([gpcm_calculator id="…"] pages). The shortcode prints the
 * university's profile JSON; when the per-page switch puts a university on the new version, the
 * host is marked data-gpac-engine="v2" and this module mounts the GPA screen there (no shadow DOM,
 * so the site tokens and component library apply). The profile JSON itself is unchanged.
 *
 * The old university calculator's draft (top-uni-gpa-calculator-v3-<slug>) is copied once; the old
 * key is only read. */
import { mountGpa } from './gpa-app.js?v=2625dc4f8b';
import { fromGpcm, fromGpcmDraft } from '../profiles/from-gpcm.js?v=2625dc4f8b';

for (const host of document.querySelectorAll('[data-gpcm-profile-id][data-gpac-engine="v2"]')) {
  if (host.dataset.calcMounted) continue;
  host.dataset.calcMounted = 'uni';
  try {
    const json = host.querySelector('script[type="application/json"][data-gpcm-profile]');
    const cfg = JSON.parse(json.textContent);
    if (cfg.slug !== host.getAttribute('data-gpcm-profile-id')) throw new Error('Profile ID mismatch');
    const profile = fromGpcm(cfg);
    if (profile.unsupported.length) console.warn(`GPA calculator ${cfg.slug}: not modeled yet:`, profile.unsupported.join(', '));
    profile.legacy = {
      keys: [profile.legacyKey],
      convert(old) {
        const s = fromGpcmDraft(old, profile);
        return { draft: s ? { state: s, save: null, dirty: false } : null };
      },
    };
    host.classList.add('gpacalc-mount');
    mountGpa(host, profile);
  } catch (e) {
    console.error('GPA calculator could not mount:', e);
    host.textContent = 'This GPA calculator is temporarily unavailable.';
  }
}
