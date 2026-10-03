(function (root) {
  'use strict';

  function parseJsonScript(host, selector) {
    var script = host.querySelector(selector);
    if (!script) return null;
    try { return JSON.parse(script.textContent || ''); }
    catch (error) { return null; }
  }

  function mount(host) {
    if (!host || host.dataset.gpcmInternationalReady === '1') return;
    var engine = root.InternationalGradeConverter;
    var manager = root.GPACalcManager;
    if (!engine || !manager) return;

    try {
      var config = parseJsonScript(host, 'script[type="application/json"][data-gpcm-international-config]');
      var countries = parseJsonScript(host, 'script[type="application/json"][data-gpcm-international-countries]');
      var isolated = manager.getMount(host);
      if (!isolated) throw new Error('Shadow DOM is unavailable.');
      isolated.root.setAttribute('data-international-grade-converter', '');

      var options = {};
      if (config) options.config = config;
      if (Array.isArray(countries)) options.countries = countries;
      var base = host.getAttribute('data-config-base');
      if (base) options.configBaseUrl = base;

      engine.mount(isolated.root, options);
      host.dataset.gpcmInternationalReady = '1';
    } catch (error) {
      if (root.console && root.console.error) root.console.error('International Grade Converter could not mount:', error);
      host.textContent = 'This grade converter is temporarily unavailable.';
    }
  }

  function start() {
    root.document.querySelectorAll('[data-gpcm-international]').forEach(mount);
  }

  if (root.document.readyState === 'loading') {
    root.document.addEventListener('DOMContentLoaded', start, { once: true });
  } else {
    start();
  }
})(typeof window !== 'undefined' ? window : globalThis);