(function (root) {
  'use strict';

  function prepareSample(profile) {
    const sample = profile.sampleData;
    if (!sample || !Array.isArray(sample.completed) || !Array.isArray(sample.planned)) {
      throw new Error('Approved calculator profile has no sample.');
    }
    profile.sample = function (nextId) {
      const copy = rows => rows.map(row => ({ ...row, id: nextId() }));
      return {
        previousGpa: String(sample.previousGpa ?? ''),
        previousUnits: String(sample.previousUnits ?? ''),
        completed: copy(sample.completed),
        planned: copy(sample.planned),
      };
    };
  }

  function mount(host) {
    if (host.dataset.gpcmReady === '1') return;
    const id = host.getAttribute('data-gpcm-profile-id');
    const script = host.querySelector('script[type="application/json"][data-gpcm-profile]');
    const engine = root.TopUniGPACalculator;
    const manager = root.GPACalcManager;
    if (!id || !script || !engine || !manager) return;
    try {
      const profile = JSON.parse(script.textContent);
      if (profile.slug !== id || profile.schemaVersion !== 1) throw new Error('Profile ID mismatch.');
      prepareSample(profile);
      const isolated = manager.getMount(host);
      if (!isolated) throw new Error('Shadow DOM is unavailable.');
      isolated.root.setAttribute('data-top-uni-gpa-calculator', '');
      isolated.root.setAttribute('data-config', id);
      engine.configs[id] = profile;
      engine.mount(isolated.root);
      host.dataset.gpcmReady = '1';
    } catch (error) {
      root.console?.error('Top Uni GPA Calculator could not mount:', error);
      host.textContent = 'This GPA calculator is temporarily unavailable.';
    }
  }

  function start() {
    root.document.querySelectorAll('[data-gpcm-profile-id]').forEach(mount);
  }
  if (root.document.readyState === 'loading') {
    root.document.addEventListener('DOMContentLoaded', start, { once: true });
  } else {
    start();
  }
})(typeof window !== 'undefined' ? window : globalThis);