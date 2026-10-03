/* gpacalculator.net chart kit v1.0.0: small token-styled SVG charts (line, bar, ring) for calculators.
 * Loaded with import() only by profiles that use a chart, so other pages never download it.
 * Colors come from calc-core.css classes (.ck-s1 brand, .ck-s2 accent) that read the theme tokens. */

const NS = 'http://www.w3.org/2000/svg';
const el = (tag, attrs, text) => {
  const n = document.createElementNS(NS, tag);
  for (const [k, v] of Object.entries(attrs || {})) n.setAttribute(k, String(v));
  if (text != null) n.textContent = text;
  return n;
};

/**
 * Line chart. series = [{ name, cls: 'ck-s1', points: [{ label, y }] }]. All series share the x labels.
 * opts = { yMin, yMax, ticks: [..], format(y), title (for screen readers) }
 */
export function lineChart(host, series, opts = {}) {
  const W = 640;
  const H = 220;
  const pad = { l: 40, r: 56, t: 14, b: 30 };
  const labels = (series[0] && series[0].points.map((p) => p.label)) || [];
  const n = labels.length;
  const yMin = opts.yMin ?? 0;
  const yMax = opts.yMax ?? 4;
  const fmt = opts.format || ((y) => y.toFixed(2));
  const X = (i) => pad.l + (n <= 1 ? (W - pad.l - pad.r) / 2 : (i * (W - pad.l - pad.r)) / (n - 1));
  const Y = (y) => pad.t + (1 - (Math.max(yMin, Math.min(yMax, y)) - yMin) / (yMax - yMin)) * (H - pad.t - pad.b);
  const svg = el('svg', { viewBox: `0 0 ${W} ${H}`, role: 'img', 'aria-label': opts.title || 'Chart' });
  const ticks = opts.ticks || [yMin, (yMin + yMax) / 2, yMax];
  for (const t of ticks) {
    svg.append(el('line', { class: 'ck-grid', x1: pad.l, x2: W - pad.r, y1: Y(t), y2: Y(t) }));
    svg.append(el('text', { class: 'ck-axis', x: pad.l - 8, y: Y(t) + 4, 'text-anchor': 'end' }, fmt(t)));
  }
  labels.forEach((lb, i) => {
    const short = String(lb).length > 12 ? `${String(lb).slice(0, 11)}…` : lb;
    svg.append(el('text', { class: 'ck-axis', x: X(i), y: H - 8, 'text-anchor': 'middle' }, short));
  });
  const ends = [];
  for (const s of series) {
    const pts = s.points.map((p, i) => [X(i), Y(p.y)]);
    if (pts.length > 1) svg.append(el('polyline', { class: `ck-line ${s.cls}`, points: pts.map((p) => p.join(',')).join(' ') }));
    pts.forEach(([x, y]) => svg.append(el('circle', { class: `ck-dot ${s.cls}`, cx: x, cy: y, r: 5 })));
    const last = pts[pts.length - 1];
    if (last) ends.push({ x: last[0] + 10, y: last[1] + 4, cls: s.cls, text: fmt(s.points[s.points.length - 1].y) });
  }
  // End labels never overlap: keep them at least 14px apart.
  ends.sort((a, b) => a.y - b.y);
  for (let i = 1; i < ends.length; i++) if (ends[i].y - ends[i - 1].y < 14) ends[i].y = ends[i - 1].y + 14;
  for (const e of ends) svg.append(el('text', { class: `ck-label ${e.cls}`, x: e.x, y: e.y }, e.text));
  host.replaceChildren(svg);
  return svg;
}

/** Horizontal bar per item: items = [{ label, value, cls }] on 0..max. */
export function barChart(host, items, opts = {}) {
  const W = 640;
  const row = 32;
  const H = items.length * row + 8;
  const max = opts.max ?? Math.max(...items.map((i) => i.value), 1);
  const fmt = opts.format || ((v) => String(v));
  const L = 140;
  const svg = el('svg', { viewBox: `0 0 ${W} ${H}`, role: 'img', 'aria-label': opts.title || 'Chart' });
  items.forEach((it, i) => {
    const y = i * row + 4;
    svg.append(el('text', { class: 'ck-axis', x: L - 10, y: y + 18, 'text-anchor': 'end' }, it.label));
    svg.append(el('rect', { class: `ck-grid`, x: L, y: y + 6, width: W - L - 60, height: 16, rx: 8, fill: 'none' }));
    svg.append(el('rect', { class: it.cls || 'ck-s1', x: L, y: y + 6, width: Math.max(0, ((W - L - 60) * it.value) / max), height: 16, rx: 8 }));
    svg.append(el('text', { class: 'ck-label', x: W - 52, y: y + 18 }, fmt(it.value)));
  });
  host.replaceChildren(svg);
  return svg;
}

/** Progress ring: value of max, with a center label. */
export function ringChart(host, value, max, label, opts = {}) {
  const R = 52;
  const C = 2 * Math.PI * R;
  const svg = el('svg', { viewBox: '0 0 128 128', role: 'img', 'aria-label': opts.title || label });
  svg.append(el('circle', { class: 'ck-grid', cx: 64, cy: 64, r: R, fill: 'none', 'stroke-width': 12 }));
  const bar = el('circle', { class: `ck-line ${opts.cls || 'ck-s1'}`, cx: 64, cy: 64, r: R, 'stroke-width': 12, 'stroke-dasharray': C, 'stroke-dashoffset': C * (1 - Math.max(0, Math.min(1, value / max))), transform: 'rotate(-90 64 64)' });
  svg.append(bar);
  svg.append(el('text', { class: 'ck-label', x: 64, y: 70, 'text-anchor': 'middle' }, label));
  host.replaceChildren(svg);
  return svg;
}

/**
 * What-if slider: a native range input (keyboard and screen-reader friendly) with a live readout.
 * onInput(value) returns the text to show.
 */
export function whatIfSlider(host, { min, max, step, value, label, onInput }) {
  const id = `ck-${Math.random().toString(36).slice(2, 8)}`;
  const out = document.createElement('output');
  out.setAttribute('for', id);
  const input = document.createElement('input');
  Object.assign(input, { type: 'range', id, min, max, step, value });
  input.className = 'calc-range';
  input.setAttribute('aria-label', label);
  const update = () => {
    const t = onInput(Number(input.value));
    out.textContent = t;
    input.setAttribute('aria-valuetext', t);
  };
  input.addEventListener('input', update);
  host.replaceChildren(input, out);
  update();
  return { input, out, set(v) { input.value = v; update(); } };
}
