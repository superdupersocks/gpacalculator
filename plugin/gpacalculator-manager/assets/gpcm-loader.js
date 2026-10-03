(function (root) {
  'use strict';
  const cached = new WeakMap();

  function getMount(host) {
    if (!host || !host.getAttribute) return null;
    if (cached.has(host)) return cached.get(host);
    const css = host.getAttribute('data-gpacalc-css');
    if (!css || !host.attachShadow) return null;
    const shadow = host.shadowRoot || host.attachShadow({ mode: 'open' });
    const link = root.document.createElement('link');
    link.rel = 'stylesheet';
    link.href = css;
    shadow.appendChild(link);
    const content = root.document.createElement('div');
    content.className = 'gpcm-root';
    shadow.appendChild(content);
    const mount = { host, shadow, root: content };
    cached.set(host, mount);
    return mount;
  }

  function mounts(id) {
    return Array.from(root.document.querySelectorAll('[data-gpacalc-id]'))
      .filter(host => host.getAttribute('data-gpacalc-id') === id)
      .map(getMount).filter(Boolean);
  }

  root.GPACalcManager = Object.freeze({ getMount, mounts });
})(typeof window !== 'undefined' ? window : globalThis);