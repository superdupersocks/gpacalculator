/* gpacalculator.net calculator core v2.0.0 (lives in the gpacalculator-manager plugin)
 * Shared helpers for every calculator: DOM builder, input parsing, grade scale,
 * storage (drafts + named saves), share (URL hash, summary, CSV), GA4 events,
 * count-up, live pill and the standard layout template.
 *
 * ES module: calculators import what they need, e.g.
 *   import { h, parseScore, createStore, mountLayout } from './core/calc-core.js';
 * The theme enqueues calculator scripts as type="module", so nothing here touches window.
 */

export const CORE_VERSION = '2.0.0';

/* ---------- DOM ---------- */

/** h('div', { class: 'x', onclick: fn, 'aria-label': 'y' }, child, 'text', [more]) */
export function h(tag, attrs, ...children) {
  const el = document.createElement(tag);
  if (attrs) {
    for (const [k, v] of Object.entries(attrs)) {
      if (v == null || v === false) continue;
      if (k === 'class') el.className = v;
      else if (k === 'dataset') Object.assign(el.dataset, v);
      else if (k === 'style' && typeof v === 'object') for (const [p, x] of Object.entries(v)) el.style.setProperty(p.replace(/[A-Z]/g, (c) => `-${c.toLowerCase()}`), x);
      else if (k.startsWith('on') && typeof v === 'function') el.addEventListener(k.slice(2), v);
      else if (k === 'text') el.textContent = v;
      else if (k in el && k !== 'list' && typeof v !== 'string') el[k] = v;
      else el.setAttribute(k, v === true ? '' : String(v));
    }
  }
  append(el, children);
  return el;
}

function append(el, children) {
  for (const c of children) {
    if (c == null || c === false) continue;
    if (Array.isArray(c)) append(el, c);
    else el.append(c instanceof Node ? c : document.createTextNode(String(c)));
  }
}

/** Set text only when it changed, so screen readers and layout aren't disturbed while typing. */
export function setText(el, text) {
  const t = String(text);
  if (el.textContent !== t) el.textContent = t;
}

/* ---------- Numbers and parsing ---------- */

const LETTER_PCT = {
  'A+': 98, A: 95, 'A-': 91, 'B+': 88, B: 85, 'B-': 81, 'C+': 78, C: 75, 'C-': 71,
  'D+': 68, D: 65, 'D-': 61, F: 50,
};

/**
 * Parse a score typed by a student. Accepts "84", "84%", "84.5", "42/50", "42 / 50",
 * "42 out of 50" and letter grades ("B+", midpoint of the letter's band).
 * Returns { pct, kind, earned?, possible? } or null when the text can't be read.
 * Blank returns null too; callers decide whether blank is an error.
 */
export function parseScore(raw) {
  if (raw == null) return null;
  const s = String(raw).trim().replace(/,/g, '.');
  if (!s) return null;
  let m = s.match(/^(-?\d+(?:\.\d+)?)\s*(?:\/|out of|of)\s*(\d+(?:\.\d+)?)$/i);
  if (m) {
    const earned = Number(m[1]);
    const possible = Number(m[2]);
    if (!(possible > 0) || earned < 0) return null;
    return { pct: (earned / possible) * 100, kind: 'fraction', earned, possible };
  }
  m = s.match(/^(-?\d+(?:\.\d+)?)\s*%?$/);
  if (m) {
    const pct = Number(m[1]);
    if (pct < 0) return null;
    return { pct, kind: 'percent' };
  }
  const L = s.toUpperCase().replace(/\s+/g, '');
  if (Object.prototype.hasOwnProperty.call(LETTER_PCT, L)) return { pct: LETTER_PCT[L], kind: 'letter' };
  return null;
}

/** Parse a plain non-negative number (weights, credits, points). "20%" reads as 20. */
export function parseNumber(raw) {
  if (raw == null) return null;
  const s = String(raw).trim().replace(/,/g, '.').replace(/%$/, '').trim();
  if (!/^\d+(?:\.\d+)?$|^\.\d+$/.test(s)) return null;
  return Number(s);
}

/** Round half away from zero to `d` decimals, avoiding binary float surprises (1.005 -> 1.01). */
export function round(n, d = 2) {
  const f = 10 ** d;
  return Math.sign(n) * Math.round((Math.abs(n) * f) + 1e-9 * f) / f;
}

/** 84.5 -> "84.5%", 84 -> "84%", 84.567 -> "84.57%" */
export function fmtPct(n, d = 2) {
  return `${trimZeros(round(n, d).toFixed(d))}%`;
}

export function fmtNum(n, d = 2) {
  return trimZeros(round(n, d).toFixed(d));
}

function trimZeros(s) {
  return s.includes('.') ? s.replace(/\.?0+$/, '') : s;
}

/* ---------- Grade scale ---------- */

/** Standard US scale: [min %, letter, GPA points on 4.0] */
export const STANDARD_SCALE = [
  [97, 'A+', 4.0], [93, 'A', 4.0], [90, 'A-', 3.7],
  [87, 'B+', 3.3], [83, 'B', 3.0], [80, 'B-', 2.7],
  [77, 'C+', 2.3], [73, 'C', 2.0], [70, 'C-', 1.7],
  [67, 'D+', 1.3], [63, 'D', 1.0], [60, 'D-', 0.7],
  [0, 'F', 0.0],
];

/** Simple 10-point scale with no plus/minus. */
export const PLAIN_SCALE = [
  [90, 'A', 4.0], [80, 'B', 3.0], [70, 'C', 2.0], [60, 'D', 1.0], [0, 'F', 0.0],
];

/** Look up a percentage. Compares on the value rounded to 2 decimals, so 89.999 counts as 90. */
export function gradeFor(pct, scale = STANDARD_SCALE) {
  const p = round(pct, 2);
  for (const [min, letter, gpa] of scale) {
    if (p >= min) return { letter, gpa, band: bandOf(letter), min };
  }
  const last = scale[scale.length - 1];
  return { letter: last[1], gpa: last[2], band: bandOf(last[1]), min: last[0] };
}

/** Points for a letter on a scale, or null if the letter isn't on it. */
export function gpaForLetter(letter, scale = STANDARD_SCALE) {
  const L = String(letter).trim().toUpperCase();
  const row = scale.find((r) => r[1] === L);
  return row ? row[2] : null;
}

/** 'a' | 'b' | 'c' | 'd' | 'f' for colors */
export function bandOf(letter) {
  const c = String(letter).trim().charAt(0).toLowerCase();
  return 'abcd'.includes(c) && c ? c : 'f';
}

/** Next letter up from pct: { letter, min, gap } or null at the top. */
export function nextGrade(pct, scale = STANDARD_SCALE) {
  const p = round(pct, 2);
  let best = null;
  for (const [min, letter] of scale) if (min > p && (!best || min < best.min)) best = { letter, min };
  return best && { ...best, gap: round(best.min - p, 2) };
}

/* ---------- Storage ---------- */

function safeLS() {
  try {
    const ls = window.localStorage;
    const k = '__calc_probe__';
    ls.setItem(k, '1');
    ls.removeItem(k);
    return ls;
  } catch (e) {
    return null;
  }
}

/**
 * Per-calculator storage. `key` must be unique to the calculator (e.g. 'gpacalc.gc').
 * Keeps: draft (autosaved, debounced, flushed on pagehide), named saves, and a seen flag.
 * Every call is safe when storage is blocked: reads return defaults, writes are no-ops.
 */
export function createStore(key, { debounce = 400 } = {}) {
  const ls = safeLS();
  const read = (k, dflt) => {
    if (!ls) return dflt;
    try {
      const v = ls.getItem(k);
      return v == null ? dflt : JSON.parse(v);
    } catch (e) {
      return dflt;
    }
  };
  const write = (k, v) => {
    if (!ls) return false;
    try {
      if (v === undefined) ls.removeItem(k);
      else ls.setItem(k, JSON.stringify(v));
      return true;
    } catch (e) {
      return false;
    }
  };

  let pending;
  let timer = 0;
  const flush = () => {
    if (timer) clearTimeout(timer);
    timer = 0;
    if (pending !== undefined) {
      write(`${key}.draft`, pending);
      pending = undefined;
    }
  };
  window.addEventListener('pagehide', flush);
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'hidden') flush();
  });

  const savesKey = `${key}.saves`;
  const saves = () => {
    const s = read(savesKey, {});
    return s && typeof s === 'object' && !Array.isArray(s) ? s : {};
  };

  return {
    available: !!ls,
    /** Autosave the working state (debounced). Pass null to clear. */
    saveDraft(state) {
      if (state == null) {
        pending = undefined;
        if (timer) clearTimeout(timer);
        timer = 0;
        write(`${key}.draft`, undefined);
        return;
      }
      pending = state;
      if (timer) clearTimeout(timer);
      timer = setTimeout(flush, debounce);
    },
    loadDraft() {
      return read(`${key}.draft`, null);
    },
    flush,
    /** Named saves, newest first: [{ name, savedAt }] */
    list() {
      return Object.entries(saves())
        .map(([name, v]) => ({ name, savedAt: v.savedAt || 0 }))
        .sort((a, b) => b.savedAt - a.savedAt);
    },
    save(name, state) {
      const n = String(name || '').trim().slice(0, 60);
      if (!n) return false;
      const all = saves();
      all[n] = { state, savedAt: Date.now() };
      return write(savesKey, all);
    },
    open(name) {
      const v = saves()[name];
      return v ? v.state : null;
    },
    remove(name) {
      const all = saves();
      delete all[name];
      return write(savesKey, all);
    },
    rename(from, to) {
      const n = String(to || '').trim().slice(0, 60);
      const all = saves();
      if (!n || !all[from] || (n !== from && all[n])) return false;
      all[n] = all[from];
      if (n !== from) delete all[from];
      return write(savesKey, all);
    },
    has(name) {
      return Object.prototype.hasOwnProperty.call(saves(), name);
    },
    /** Small flags (e.g. "legacy saves imported"), stored as <key>.<flag>. */
    flag(name, value) {
      if (value === undefined) return read(`${key}.${name}`, null);
      return write(`${key}.${name}`, value);
    },
    /** Read another calculator's raw key (legacy migration); never writes to it. */
    readRaw(k) {
      return read(k, null);
    },
    /** First-visit flag for onboarding vs "Show an example" link. */
    seen() {
      return !!read(`${key}.seen`, false);
    },
    markSeen() {
      write(`${key}.seen`, true);
    },
  };
}

/* ---------- Share ---------- */

/** JSON -> URL-safe base64 (UTF-8 safe). */
export function encodeState(obj) {
  const bytes = new TextEncoder().encode(JSON.stringify(obj));
  let bin = '';
  for (const b of bytes) bin += String.fromCharCode(b);
  return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

/** Inverse of encodeState; returns null on anything malformed. */
export function decodeState(str) {
  try {
    let s = String(str).replace(/-/g, '+').replace(/_/g, '/');
    while (s.length % 4) s += '=';
    const bin = atob(s);
    const bytes = Uint8Array.from(bin, (c) => c.charCodeAt(0));
    return JSON.parse(new TextDecoder().decode(bytes));
  } catch (e) {
    return null;
  }
}

/** Read state from location.hash like "#gc=<encoded>". */
export function readHash(prefix) {
  const m = location.hash.match(new RegExp(`[#&]${prefix}=([A-Za-z0-9_-]+)`));
  return m ? decodeState(m[1]) : null;
}

/** Full share URL for a state, without changing the current page. */
export function shareUrl(prefix, state) {
  return `${location.origin}${location.pathname}${location.search}#${prefix}=${encodeState(state)}`;
}

/** Remove our hash after loading it, so a refresh uses the draft instead. */
export function clearHash() {
  if (location.hash) history.replaceState(null, '', location.pathname + location.search);
}

export async function copyText(text) {
  try {
    if (navigator.clipboard && window.isSecureContext) {
      await navigator.clipboard.writeText(text);
      return true;
    }
  } catch (e) {
    /* fall through to the textarea path */
  }
  const ta = h('textarea', { 'aria-hidden': 'true', style: { position: 'fixed', top: '-1000px', opacity: '0' } });
  ta.value = text;
  document.body.append(ta);
  ta.select();
  let ok = false;
  try {
    ok = document.execCommand('copy');
  } catch (e) {
    ok = false;
  }
  ta.remove();
  return ok;
}

/** RFC 4180 CSV. Cells starting with = + - @ are prefixed with ' so spreadsheets don't run them. */
export function toCSV(rows) {
  return rows
    .map((r) => r.map((c) => {
      let s = c == null ? '' : String(c);
      if (/^[=+\-@]/.test(s) && !/^-?\d+(\.\d+)?$/.test(s)) s = `'${s}`;
      return /[",\n\r]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
    }).join(','))
    .join('\r\n');
}

export function downloadCSV(filename, rows) {
  const blob = new Blob([`﻿${toCSV(rows)}`], { type: 'text/csv;charset=utf-8' });
  const url = URL.createObjectURL(blob);
  const a = h('a', { href: url, download: filename, style: { display: 'none' } });
  document.body.append(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}

/* ---------- Analytics (GA4) ---------- */

/**
 * track = createTracker('gc'); track('result') fires gtag event 'gc_result' once per page view.
 * track('save', { n: 2 }, true) fires every time.
 */
export function createTracker(prefix) {
  const fired = new Set();
  return function track(name, params, repeatable = false) {
    const ev = `${prefix}_${name}`;
    if (!repeatable && fired.has(ev)) return;
    fired.add(ev);
    try {
      if (typeof window.gtag === 'function') window.gtag('event', ev, params || {});
    } catch (e) {
      /* analytics must never break the calculator */
    }
  };
}

/* ---------- Motion ---------- */

export function reducedMotion() {
  try {
    return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  } catch (e) {
    return true;
  }
}

/**
 * Count el's text up to `to`. format(n) renders each frame. Instant with reduced motion.
 * Returns cancel(); call it before writing a newer value so the animation can't overwrite it.
 */
export function countUp(el, to, format, duration = 700) {
  if (reducedMotion() || !(to > 0)) {
    setText(el, format(to));
    return () => {};
  }
  let raf = 0;
  const start = performance.now();
  const step = (t) => {
    const k = Math.min(1, (t - start) / duration);
    const eased = 1 - (1 - k) ** 3;
    setText(el, format(k < 1 ? to * eased : to));
    raf = k < 1 ? requestAnimationFrame(step) : 0;
  };
  raf = requestAnimationFrame(step);
  return () => cancelAnimationFrame(raf);
}

/* ---------- Behaviors ---------- */

/** Enter in an input moves focus to the next input/select in `container` (rows feel like a sheet). */
export function enterToNext(container, onLast) {
  container.addEventListener('keydown', (e) => {
    if (e.key !== 'Enter' || e.isComposing) return;
    const t = e.target;
    if (!(t instanceof HTMLInputElement) || t.type === 'range') return;
    e.preventDefault();
    const fields = [...container.querySelectorAll('input:not([type=range]):not([disabled]), select:not([disabled])')];
    const i = fields.indexOf(t);
    if (i > -1 && i < fields.length - 1) fields[i + 1].focus();
    else if (onLast) onLast(t);
  });
}

/** Toast message inside #root (role=status). */
export function createToast(host) {
  const el = h('div', { class: 'calc-toast', role: 'status', hidden: true });
  host.append(el);
  let t = 0;
  return (msg) => {
    el.textContent = msg;
    el.hidden = false;
    clearTimeout(t);
    t = setTimeout(() => { el.hidden = true; }, 2200);
  };
}

/* Sticky ad footer on phones (Freestar); the pill and toasts sit above it, never over it. */
const AD_FOOTER = '#fs-sticky-footer, .fs-sticky-footer, [id*="sticky_footer"], [id*="sticky-footer"], [data-freestar-ad*="sticky"]';

/** Height of whatever is fixed to the bottom of the screen (the sticky ad), in px; 0 when none shows. */
export function bottomObstruction() {
  let max = 0;
  for (const el of document.querySelectorAll(AD_FOOTER)) {
    const r = el.getBoundingClientRect();
    if (r.height > 0 && r.bottom >= window.innerHeight - 2) max = Math.max(max, window.innerHeight - r.top);
  }
  return Math.max(0, Math.round(max));
}

/**
 * Sticky result pill (Calculator Design Standard): fixed at the bottom while the result panel is
 * off-screen (above or below), zero layout height, one button that scrolls to the result, hidden while
 * a text input has focus (iOS keyboards move fixed elements), 12px above the sticky ad.
 * set({ label, value, label2, value2, aria }) fills it; with two values the "Details" chip is dropped.
 * update(text) is the v1 one-value form. enable(bool) turns it on once there is a result.
 */
export function createLivePill(host, target, { label = 'Live grade', onOpen } = {}) {
  const l1 = h('span', { class: 'calc-pill-l' }, label);
  const v1 = h('b', { class: 'calc-pill-v' });
  const l2 = h('span', { class: 'calc-pill-l calc-pill-sep', hidden: true });
  const v2 = h('b', { class: 'calc-pill-v', hidden: true });
  const chip = h('span', { class: 'calc-pill-chip' }, 'Details →');
  const pill = h('button', { type: 'button', class: 'calc-pill', 'aria-hidden': 'true', tabindex: '-1' }, l1, v1, l2, v2, chip);
  host.append(pill);
  document.documentElement.classList.add('calc-has-pill');
  let enabled = false;
  let away = false;
  let typing = false;
  const place = () => pill.style.setProperty('--calc-pill-offset', `${bottomObstruction() + 12}px`);
  const sync = () => {
    const on = enabled && away && !typing;
    if (on) place();
    pill.classList.toggle('is-on', on);
    pill.setAttribute('aria-hidden', on ? 'false' : 'true');
    pill.tabIndex = on ? 0 : -1;
  };
  pill.addEventListener('click', () => {
    target.scrollIntoView({ behavior: reducedMotion() ? 'auto' : 'smooth', block: 'start' });
    if (onOpen) onOpen();
  });
  if ('IntersectionObserver' in window) {
    new IntersectionObserver(([e]) => {
      away = !e.isIntersecting;
      sync();
    }).observe(target);
  }
  const isText = (t) => t instanceof HTMLInputElement && !['checkbox', 'radio', 'range', 'button'].includes(t.type) && t.inputMode !== 'decimal';
  host.addEventListener('focusin', (e) => { typing = isText(e.target); sync(); });
  host.addEventListener('focusout', () => { typing = false; sync(); });
  window.addEventListener('resize', () => enabled && place(), { passive: true });
  return {
    update(text) {
      setText(v1, text);
    },
    set({ label: a, value, label2, value2, aria }) {
      setText(l1, a);
      setText(v1, value);
      const two = label2 != null;
      l2.hidden = !two;
      v2.hidden = !two;
      chip.hidden = two;
      if (two) { setText(l2, label2); setText(v2, value2); }
      pill.setAttribute('aria-label', aria || `${a} ${value}${two ? `, ${label2} ${value2}` : ''}, go to result`);
    },
    enable(on) {
      enabled = !!on;
      sync();
    },
    el: pill,
  };
}

/** Menu with a trigger button: closes on Escape and outside click. */
export function createMenu(trigger, menu, onOpen) {
  const close = () => {
    menu.hidden = true;
    trigger.setAttribute('aria-expanded', 'false');
  };
  const open = () => {
    if (onOpen) onOpen();
    menu.hidden = false;
    trigger.setAttribute('aria-expanded', 'true');
    const first = menu.querySelector('button');
    if (first) first.focus();
  };
  trigger.setAttribute('aria-haspopup', 'true');
  trigger.setAttribute('aria-expanded', 'false');
  menu.hidden = true;
  trigger.addEventListener('click', () => (menu.hidden ? open() : close()));
  menu.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      close();
      trigger.focus();
    }
  });
  document.addEventListener('click', (e) => {
    if (!menu.hidden && e.target.isConnected && !menu.contains(e.target) && !trigger.contains(e.target)) close();
  });
  return { open, close };
}

/* ---------- Mount points ---------- */

/**
 * Elements a calculator should mount into: every shortcode mount
 * (<div class="gpacalc-mount" data-calc="slug">, printed by the plugin), or #root on
 * page-template calculators. Each element is returned once, so a script can't mount twice.
 */
export function mountsFor(slug) {
  const els = [...document.querySelectorAll(`.gpacalc-mount[data-calc="${CSS.escape(slug)}"]`)];
  if (!els.length) {
    const root = document.getElementById('root');
    if (root) els.push(root);
  }
  return els.filter((el) => {
    if (el.dataset.calcMounted) return false;
    el.dataset.calcMounted = slug;
    return true;
  });
}

/** Shortcode attributes the plugin passed in data-atts ({} on #root or bad JSON). */
export function readAtts(el) {
  try {
    const a = JSON.parse(el.dataset.atts || '{}');
    return a && typeof a === 'object' ? a : {};
  } catch (e) {
    return {};
  }
}

/* ---------- Layout template ---------- */

const SCALE_BANDS = [
  // [from %, to %, band, label] across the 50-100 track
  [50, 60, 'f', 'F'], [60, 70, 'd', 'D'], [70, 80, 'c', 'C'], [80, 90, 'b', 'B'], [90, 100, 'a', 'A'],
];

/**
 * Result hero: kicker, big count-up score, progress ring (letter + GPA) and F-A scale track.
 * update({ pct, letter, gpa, verdict, scoreText }) refreshes nodes in place.
 */
export function createResultHero({ kicker = 'Current grade', gpaLabel = 'GPA' } = {}) {
  const score = h('div', { class: 'calc-score', 'aria-hidden': 'true' }, '0%');
  const verdict = h('p', { class: 'calc-verdict' });
  const live = h('p', { class: 'calc-sr', 'aria-live': 'polite' });
  const R = 56;
  const C = 2 * Math.PI * R;
  const NS = 'http://www.w3.org/2000/svg';
  const svg = document.createElementNS(NS, 'svg');
  svg.setAttribute('viewBox', '0 0 132 132');
  svg.setAttribute('aria-hidden', 'true');
  const mk = (cls) => {
    const c = document.createElementNS(NS, 'circle');
    c.setAttribute('cx', '66');
    c.setAttribute('cy', '66');
    c.setAttribute('r', String(R));
    c.setAttribute('fill', 'none');
    c.setAttribute('stroke-width', '12');
    c.setAttribute('class', cls);
    return c;
  };
  const bar = mk('calc-ring-bar');
  bar.setAttribute('stroke-linecap', 'round');
  bar.setAttribute('stroke-dasharray', String(C));
  bar.setAttribute('stroke-dashoffset', String(C));
  svg.append(mk('calc-ring-track'), bar);
  const letter = h('div', { class: 'calc-ring-letter' });
  const gpa = h('div', { class: 'calc-ring-gpa' });
  const ring = h('div', { class: 'calc-ring' }, svg, h('div', { class: 'calc-ring-center' }, letter, gpa));

  const you = h('div', { class: 'calc-scale-you' }, 'You');
  const scale = h('div', { class: 'calc-scale', 'aria-hidden': 'true' },
    you,
    h('div', { class: 'calc-scale-bar' }, SCALE_BANDS.map(([a, b, band]) => h('span', {
      style: { width: `${(b - a) * 2}%`, background: `var(--calc-${band})` },
    }))),
    h('div', { class: 'calc-scale-labels' }, SCALE_BANDS.map(([a, b, , l]) => h('span', { style: { width: `${(b - a) * 2}%` } }, l))));

  const el = h('div', { class: 'calc-hero-wrap' },
    h('div', { class: 'calc-hero' },
      h('div', null, h('div', { class: 'calc-kicker' }, kicker), score, verdict),
      ring),
    scale, live);

  let shown = false;
  let cancel = () => {};
  let lastBand = '';
  return {
    el,
    update({ pct, letter: L, gpa: G, verdict: V, scoreText }) {
      const fmt = scoreText || ((n) => fmtPct(n, 2));
      if (!shown) {
        shown = true;
        cancel = countUp(score, pct, fmt);
      } else {
        cancel();
        setText(score, fmt(pct));
      }
      const band = bandOf(L);
      if (band !== lastBand) {
        if (lastBand) el.classList.remove(`is-band-${lastBand}`);
        el.classList.add(`is-band-${band}`);
        lastBand = band;
      }
      setText(letter, L);
      setText(gpa, G == null ? '' : `${fmtNum(G, 2)} ${gpaLabel}`);
      setText(verdict, V || '');
      const k = Math.max(0, Math.min(100, pct)) / 100;
      bar.setAttribute('stroke-dashoffset', String(C * (1 - k)));
      const pos = (Math.max(50, Math.min(100, pct)) - 50) * 2;
      you.style.left = `${pos}%`;
      setText(live, `${kicker}: ${fmt(pct)}, ${L}${V ? `. ${V}` : ''}`);
    },
    reset() {
      cancel();
      shown = false;
    },
  };
}

/**
 * Standard calculator shell, mounted into #root or a .gpacalc-mount:
 *   toolbar (My classes menu + share) / step bar / sample banner / onboarding /
 *   inputs / result (hidden until data) / next step / insights / keep going / live pill.
 * Returns the nodes so the calculator fills them; static structure is built once.
 */
export function mountLayout(root, { prefix, steps = ['Your grade'], savesLabel = 'My classes' }) {
  if (root.id === 'root') root.classList.add('calc-mounted');
  root.textContent = '';
  const stepItems = steps.map((label, i) => h('li', { class: 'calc-step' },
    h('span', { class: 'calc-step-dot' }, String(i + 1)), h('span', { class: 'calc-step-label' }, label)));
  const stepBar = steps.length > 1 ? h('ol', { class: 'calc-steps', 'aria-label': 'Steps' }, stepItems) : null;

  const savesBtn = h('button', { type: 'button', class: 'calc-btn calc-btn-text' }, `${savesLabel} ▾`);
  const savesMenu = h('ul', { class: 'calc-menu', role: 'menu' });
  const shareBtn = h('button', { type: 'button', class: 'calc-btn calc-btn-text' }, 'Share ▾');
  const shareMenu = h('ul', { class: 'calc-menu', role: 'menu' });
  const toolbar = h('div', { class: 'calc-toolbar' },
    h('div', { class: 'calc-menu-wrap' }, savesBtn, savesMenu),
    h('div', { class: 'calc-menu-wrap' }, shareBtn, shareMenu));

  const bannerText = h('span');
  const bannerClear = h('button', { type: 'button', class: 'calc-btn calc-btn-text' }, 'Clear');
  const banner = h('div', { class: 'calc-banner', role: 'status', hidden: true }, bannerText, bannerClear);
  const onboard = h('div', { class: 'calc-onboard', hidden: true });
  const inputs = h('div', { class: 'calc-inputs' });
  const result = h('section', { class: 'calc-result', hidden: true, tabindex: '-1', 'aria-label': 'Result' });
  const next = h('div', { class: 'calc-next' });
  const insights = h('div', { class: 'calc-insights' });
  const stage = h('div', { class: 'calc-stage' }, inputs, result);
  const card = h('div', { class: 'calc-card' }, toolbar, stepBar, banner, onboard, stage);
  const keepGoing = h('div', { class: 'calc-card calc-keep', hidden: true });
  const app = h('div', { class: `calc ${prefix}` }, card, keepGoing);
  root.append(app);

  return {
    app, card, toolbar, stepBar, banner, bannerText, bannerClear, onboard, stage, inputs,
    result, next, insights, keepGoing, savesBtn, savesMenu, shareBtn, shareMenu,
    /** Mark step i (0-based) current; earlier steps get checkmarks. */
    setStep(i) {
      stepItems.forEach((li, j) => {
        li.classList.toggle('is-done', j < i);
        if (j === i) li.setAttribute('aria-current', 'step');
        else li.removeAttribute('aria-current');
        setText(li.firstChild, j < i ? '✓' : String(j + 1));
      });
    },
  };
}

/**
 * Wire the standard "My classes" saves menu and Share menu.
 * opts: { store, track, toast, getState(), setState(state), isSample(), prefix, summary(), csv() -> rows, csvName }
 */
export function wireSavesAndShare(L, opts) {
  const { store, track, toast, getState, setState, isSample, prefix, summary, csv, csvName = 'grades.csv', newState } = opts;
  const renderSaves = () => {
    L.savesMenu.textContent = '';
    const item = (label, fn, cls) => h('li', { role: 'none' }, h('button', { type: 'button', role: 'menuitem', class: cls, onclick: fn }, label));
    L.savesMenu.append(item('Save as…', () => {
      if (isSample && isSample()) {
        toast('Sample data can’t be saved. Enter your own to save.');
        return;
      }
      const name = window.prompt('Name this save (e.g. Biology, Fall)');
      if (name && store.save(name, getState())) {
        track('save', null, true);
        toast(`Saved “${name.trim().slice(0, 60)}”`);
      } else if (name) toast('Couldn’t save. Your browser may be blocking storage.');
      L.savesMenu.hidden = true;
    }));
    if (newState) {
      L.savesMenu.append(item('New (start over)', () => {
        setState(newState());
        L.savesMenu.hidden = true;
      }));
    }
    const list = store.list();
    if (!list.length) L.savesMenu.append(h('li', { class: 'calc-menu-empty', role: 'none' }, 'No saves yet'));
    for (const s of list) {
      L.savesMenu.append(h('li', { role: 'none' },
        h('button', { type: 'button', role: 'menuitem', onclick: () => {
          const st = store.open(s.name);
          if (st) setState(st);
          L.savesMenu.hidden = true;
          toast(`Opened “${s.name}”`);
        } }, s.name),
        h('button', { type: 'button', role: 'menuitem', class: 'calc-menu-del', 'aria-label': `Delete ${s.name}`, onclick: () => {
          if (window.confirm(`Delete “${s.name}”?`)) {
            store.remove(s.name);
            renderSaves();
          }
        } }, '✕')));
    }
  };
  createMenu(L.savesBtn, L.savesMenu, renderSaves);

  const shareItem = (label, fn) => h('li', { role: 'none' }, h('button', { type: 'button', role: 'menuitem', onclick: async () => {
    L.shareMenu.hidden = true;
    await fn();
    track('share', { method: label }, true);
  } }, label));
  L.shareMenu.append(
    shareItem('Copy link', async () => toast(await copyText(shareUrl(prefix, getState())) ? 'Link copied' : 'Couldn’t copy. Try again.')),
    shareItem('Copy summary', async () => toast(await copyText(summary()) ? 'Summary copied' : 'Couldn’t copy. Try again.')),
    shareItem('Download CSV', async () => downloadCSV(csvName, csv())),
  );
  createMenu(L.shareBtn, L.shareMenu);
}

/* ---------- v2: shared services for every calculator ---------- */

/**
 * Toast with an optional action button (Undo). show(msg, { action: 'Undo', onAction, ms })
 * One toast at a time; a new message replaces the old one.
 */
export function createActionToast(host) {
  const text = h('span');
  const btn = h('button', { type: 'button', hidden: true });
  const el = h('div', { class: 'calc-toast', role: 'status', 'aria-live': 'polite', hidden: true }, text, btn);
  host.append(el);
  let t = 0;
  let fn = null;
  const hide = () => { el.hidden = true; fn = null; };
  btn.addEventListener('click', () => {
    const f = fn;
    hide();
    if (f) f();
  });
  return {
    show(msg, { action, onAction, ms = 2600 } = {}) {
      text.textContent = msg;
      btn.hidden = !action;
      btn.textContent = action || '';
      fn = onAction || null;
      el.style.setProperty('--calc-toast-offset', `${bottomObstruction() + 72}px`);
      el.hidden = false;
      clearTimeout(t);
      t = setTimeout(hide, ms);
    },
    hide,
    el,
  };
}

/**
 * GA4 `calculator_used`: once per page view, on the first edit. The theme already sends it for
 * calculators inside #root, .gpa-calc-portal or #middle-school-gpa (functions.php), so only fire it
 * here for calculators mounted anywhere else, and the event is never counted twice.
 */
export function trackCalculatorUsed(root) {
  if (root.closest('#root, .gpa-calc-portal, #middle-school-gpa')) return;
  let used = false;
  const on = () => {
    if (used) return;
    used = true;
    try {
      if (typeof window.gtag === 'function') window.gtag('event', 'calculator_used');
    } catch (e) {
      /* analytics must never break the calculator */
    }
  };
  root.addEventListener('change', on, true);
  root.addEventListener('input', on, true);
}

/** Fire a legacy GA4 event name as is (e.g. 'gpa_export'), never throwing. */
export function sendEvent(name, params) {
  try {
    if (typeof window.gtag === 'function') window.gtag('event', name, params || {});
  } catch (e) {
    /* ignore */
  }
}

/**
 * Copy saves from a legacy calculator once (flag kept in the store). The legacy keys are only read,
 * never changed or deleted, so the old calculator and any other calculator sharing them keep working.
 * convert(rawDraft, rawSaves) -> { draft?, saves?: [{ name, state }] }
 */
export function importLegacyOnce(store, flagName, keys, convert) {
  if (!store.available || store.flag(flagName)) return { imported: 0 };
  store.flag(flagName, Date.now());
  let out;
  try {
    out = convert(...keys.map((k) => store.readRaw(k))) || {};
  } catch (e) {
    return { imported: 0 };
  }
  let n = 0;
  for (const s of out.saves || []) {
    let name = String(s.name || 'My calculation').trim().slice(0, 52) || 'My calculation';
    let i = 2;
    while (store.has(name)) name = `${s.name} (${i++})`.slice(0, 60);
    if (store.save(name, s.state)) n += 1;
  }
  if (out.draft && !store.loadDraft()) {
    store.saveDraft(out.draft);
    store.flush();
  }
  return { imported: n, draft: !!out.draft };
}

/** Print / save as PDF. Print CSS in calc-core.css hides buttons and menus. */
export function printPage() {
  try {
    window.print();
  } catch (e) {
    /* some in-app browsers block printing */
  }
}
