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
  const vh = window.innerHeight;
  const vw = window.innerWidth;
  const boxes = new Set();
  for (const el of document.querySelectorAll(AD_FOOTER)) boxes.add(el);
  // Other fixed boxes near the bottom edge (Freestar's floating video player, which sits on top of the
  // sticky ad; consent bars): probe a few points and climb to the fixed ancestor. The calculator's own
  // pill, toasts and sheet don't count.
  if (document.elementsFromPoint) {
    const seen = new Set();
    for (const y of [vh - 6, vh - 60, vh - 120, vh - 180, vh - 240]) {
      for (const x of [8, vw / 2, vw - 8]) {
        for (let el of document.elementsFromPoint(x, y)) {
          for (; el && el !== document.body && el !== document.documentElement; el = el.parentElement) {
            if (seen.has(el)) break;
            seen.add(el);
            if (el.closest('#root, .gpacalc-mount')) break;
            const pos = getComputedStyle(el).position;
            if (pos === 'fixed' || pos === 'sticky') { boxes.add(el); break; }
          }
        }
      }
    }
  }
  // Stack them up from the bottom: a box counts when it touches the bottom edge or the box below it.
  const rects = [...boxes].map((el) => el.getBoundingClientRect()).filter((r) => r.height > 0 && r.width > 0 && r.top < vh)
    .sort((a, b) => b.bottom - a.bottom);
  let max = 0;
  for (const r of rects) if (r.bottom >= vh - max - 16) max = Math.max(max, vh - r.top);
  return Math.max(0, Math.min(Math.round(max), Math.round(vh / 2)));
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
    const on = enabled && away && !typing && !document.documentElement.classList.contains('calc-sheet-open');
    if (on) place();
    pill.classList.toggle('is-on', on);
    pill.setAttribute('aria-hidden', on ? 'false' : 'true');
    pill.tabIndex = on ? 0 : -1;
  };
  pill.addEventListener('click', () => {
    target.scrollIntoView({ behavior: reducedMotion() ? 'auto' : 'smooth', block: 'start' });
    if (onOpen) onOpen();
  });
  // Shows only while the result is still below the screen (the student is entering courses above it):
  // hidden once any part of the result is on screen, and once they scroll past it, so it never sits
  // over the result, Keep going or the article below.
  if ('IntersectionObserver' in window) {
    new IntersectionObserver(([e]) => {
      away = !e.isIntersecting && e.boundingClientRect.top > 0;
      sync();
    }).observe(target);
  }
  new MutationObserver(sync).observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
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

/**
 * Bottom sheet of choice buttons (phones): the grade picker's letter grid, credit quick buttons.
 * open({ title, options: [{ value, label }], value, cols }) resolves to the chosen value, or null when
 * closed (Escape, backdrop, Cancel). Focus returns to the element that opened it.
 */
export function createSheet(host) {
  const title = h('p', { class: 'calc-sheet-title', id: `calc-sheet-${Math.random().toString(36).slice(2, 8)}` });
  const grid = h('div', { class: 'calc-sheet-grid' });
  const cancel = h('button', { type: 'button', class: 'calc-btn calc-btn-ghost calc-sheet-cancel' }, 'Cancel');
  const panel = h('div', { class: 'calc-sheet', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': title.id }, title, grid, cancel);
  const wrap = h('div', { class: 'calc-sheet-wrap', hidden: true }, h('div', { class: 'calc-sheet-backdrop' }), panel);
  // On <body>, outside the page's stacking contexts, so it covers the site header; the wrappers keep the
  // calculator's scoped styles and tokens.
  const portal = h('div', { class: 'gpacalc-mount gpacalc-portal' }, h('div', { class: `calc ${host.className.replace(/\bcalc\b/, '')}`.trim() }, wrap));
  document.body.append(portal);
  let done = null;
  let opener = null;
  const close = (v) => {
    if (wrap.hidden) return;
    wrap.hidden = true;
    document.documentElement.classList.remove('calc-sheet-open');
    if (opener && opener.isConnected) opener.focus({ preventScroll: true });
    const d = done;
    done = null;
    if (d) d(v);
  };
  wrap.addEventListener('click', (e) => { if (e.target.classList.contains('calc-sheet-backdrop')) close(null); });
  cancel.addEventListener('click', () => close(null));
  wrap.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') { e.preventDefault(); close(null); }
    if (e.key === 'Tab') {
      const f = [...panel.querySelectorAll('button')];
      const i = f.indexOf(document.activeElement);
      if (e.shiftKey && i <= 0) { e.preventDefault(); f[f.length - 1].focus(); }
      else if (!e.shiftKey && i === f.length - 1) { e.preventDefault(); f[0].focus(); }
    }
  });
  return {
    open({ title: t, options, value, cols = 4 }) {
      if (done) close(null);
      opener = document.activeElement;
      setText(title, t);
      grid.textContent = '';
      grid.style.setProperty('--calc-sheet-cols', String(cols));
      let first = null;
      let current = null;
      for (const o of options) {
        // { heading } starts a labelled group (e.g. "Not counted in GPA") on its own full-width line.
        if (o.heading) { grid.append(h('p', { class: 'calc-sheet-group', role: 'presentation' }, o.heading)); continue; }
        const b = h('button', { type: 'button', class: `calc-sheet-opt${o.value === value ? ' is-on' : ''}${o.wide ? ' is-wide' : ''}`, 'aria-pressed': String(o.value === value), onclick: () => close(o.value) }, o.label);
        grid.append(b);
        if (!first) first = b;
        if (o.value === value) current = b;
      }
      // Lift the panel above anything fixed to the bottom of the screen, so the ad never covers a choice.
      panel.style.setProperty('--calc-sheet-lift', `${bottomObstruction()}px`);
      wrap.hidden = false;
      document.documentElement.classList.add('calc-sheet-open');
      (current || first || cancel).focus({ preventScroll: true });
      return new Promise((r) => { done = r; });
    },
    close: () => close(null),
    get isOpen() { return !wrap.hidden; },
    el: wrap,
  };
}

/**
 * Name suggestions under a text input (combobox + listbox). source(text) returns up to ~6 items
 * { label, hint }; onPick(item) runs when one is chosen (tap, or Arrow keys + Enter). The list closes on
 * Escape, blur and after a pick. Enter with no highlighted item is left to the page (next row).
 * openOnFocus: the list also opens on focus / click with source('') (e.g. every grade on the scale).
 * autoFirst: what the student typed highlights the first item, so Enter takes it (e.g. "93" -> A).
 */
export function createSuggest(input, { source, onPick, openOnFocus = false, autoFirst = false }) {
  const id = `${input.id || `calc-in-${Math.random().toString(36).slice(2, 8)}`}-sug`;
  const list = h('ul', { class: 'calc-suggest', id, role: 'listbox', hidden: true });
  input.after(list);
  input.setAttribute('role', 'combobox');
  input.setAttribute('aria-autocomplete', 'list');
  input.setAttribute('aria-expanded', 'false');
  input.setAttribute('aria-controls', id);
  let items = [];
  let active = -1;
  const close = () => {
    list.hidden = true;
    items = [];
    active = -1;
    input.setAttribute('aria-expanded', 'false');
    input.removeAttribute('aria-activedescendant');
  };
  const mark = () => {
    [...list.children].forEach((li, i) => li.setAttribute('aria-selected', String(i === active)));
    if (active >= 0) {
      input.setAttribute('aria-activedescendant', `${id}-${active}`);
      const li = list.children[active];
      if (li && list.scrollHeight > list.clientHeight) li.scrollIntoView({ block: 'nearest' });
    } else input.removeAttribute('aria-activedescendant');
  };
  const pick = (i) => {
    const it = items[i];
    close();
    if (it) onPick(it);
  };
  const open = () => {
    const typed = !!input.value.trim();
    items = typed || openOnFocus ? source(input.value.trim()) || [] : [];
    list.textContent = '';
    active = -1;
    if (!items.length) { close(); return; }
    items.forEach((it, i) => {
      const li = h('li', { id: `${id}-${i}`, role: 'option', 'aria-selected': 'false' }, h('span', null, it.label), it.hint ? h('span', { class: 'calc-suggest-hint' }, it.hint) : null);
      // Keep focus in the input: a tap on an option must not blur it first.
      li.addEventListener('pointerdown', (e) => e.preventDefault());
      li.addEventListener('mousedown', (e) => e.preventDefault());
      li.addEventListener('click', () => pick(i));
      list.append(li);
    });
    list.hidden = false;
    input.setAttribute('aria-expanded', 'true');
    if (autoFirst && typed) { active = 0; mark(); }
  };
  input.addEventListener('input', open);
  if (openOnFocus) {
    input.addEventListener('focus', open);
    input.addEventListener('click', () => { if (list.hidden) open(); });
  }
  input.addEventListener('blur', () => setTimeout(close, 0));
  input.addEventListener('keydown', (e) => {
    if (list.hidden) return;
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      const n = items.length;
      active = e.key === 'ArrowDown' ? (active + 1) % n : (active - 1 + n) % n;
      mark();
    } else if (e.key === 'Enter' && active >= 0) {
      e.preventDefault();
      e.stopPropagation();
      pick(active);
    } else if (e.key === 'Escape') {
      e.preventDefault();
      close();
    }
  });
  return { close, el: list };
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
 * Report script errors from the calculator files as GA4 'calc_error' events (at most 3 a page), so a
 * broken calculator shows up in GA4. Errors from ads, the theme or other plugins are ignored.
 */
let errorsWatched = false;
export function watchErrors(calc) {
  if (errorsWatched || typeof window === 'undefined') return;
  errorsWatched = true;
  let sent = 0;
  const ours = (src) => /\/calc-assets\//.test(String(src || ''));
  const report = (msg, src) => {
    if (sent >= 3 || !ours(src)) return;
    sent += 1;
    sendEvent('calc_error', { calc, error_message: String(msg || 'error').slice(0, 100) });
  };
  window.addEventListener('error', (e) => report(e.message, e.filename || (e.error && e.error.stack)));
  window.addEventListener('unhandledrejection', (e) => report(e.reason && e.reason.message, e.reason && e.reason.stack));
}

/* ---------- Add to home screen (Calculator Design Standard, "Add to home screen") ---------- */

// Chrome/Android fires this once the page qualifies (manifest + icons). Keep it for the hint's Add button.
let installEvent = null;
const installWaiters = new Set();
if (typeof window !== 'undefined') {
  window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault(); // no browser mini-infobar; the hint offers it at the right moment
    installEvent = e;
    installWaiters.forEach((f) => f());
  });
}

const A2HS_KEY = 'gpac:a2hs:dismissed';
const A2HS_COUNT = 'gpac:a2hs:calcs';
const A2HS_DAYS = 30;
const SHARE_ICON = 'M12 3v12M8 7l4-4 4 4M6 11H5a1 1 0 0 0-1 1v8a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-8a1 1 0 0 0-1-1h-1';

function lsGet(k) { try { return window.localStorage.getItem(k); } catch (e) { return null; } }
function lsSet(k, v) { try { window.localStorage.setItem(k, v); } catch (e) { /* storage blocked: the hint just shows again */ } }

/** Opened from the home screen (or an installed app window)? */
export function launchedFromHomeScreen() {
  try {
    if (new URLSearchParams(window.location.search).get('source') === 'homescreen') return true;
    if (window.navigator.standalone === true) return true;
    return !!(window.matchMedia && window.matchMedia('(display-mode: standalone)').matches);
  } catch (e) {
    return false;
  }
}

const isIOS = () => /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);

/**
 * "Use this calculator again? Add it to your home screen." A dismissible bar placed in the page flow right
 * after `after` (the calculator card), at its width. Phones only, after the second calculation or a Save,
 * never on first load. iPhone: Share-sheet instructions; Android/Chrome: an Add button that opens the
 * browser's install prompt (no bar if the browser never offers it). Returns { calculated(key), saved(), seen(key) }.
 */
export function createHomeScreenHint(after, { calc = '', icon = '' } = {}) {
  const noop = { calculated() {}, saved() {}, seen() {}, el: null };
  if (typeof window === 'undefined' || !after || !after.parentNode) return noop;
  if (launchedFromHomeScreen()) {
    sendEvent('a2hs_open', { calc });
    return noop;
  }
  const phone = () => window.matchMedia('(max-width: 640px)').matches;
  const dismissed = () => {
    const t = Number(lsGet(A2HS_KEY));
    return t > 0 && Date.now() - t < A2HS_DAYS * 864e5;
  };
  const ios = isIOS();
  const close = h('button', { type: 'button', class: 'calc-btn calc-btn-icon calc-a2hs-x', 'aria-label': 'Dismiss' }, '×');
  const text = h('div', { class: 'calc-a2hs-text' },
    h('p', { class: 'calc-a2hs-title' }, 'Use this calculator again?'),
    h('p', { class: 'calc-a2hs-sub' }, 'Add it to your home screen.'));
  let add = null;
  if (ios) {
    const NS = 'http://www.w3.org/2000/svg';
    const svg = document.createElementNS(NS, 'svg');
    for (const [k, v] of Object.entries({ viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', 'stroke-width': '2', 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'aria-hidden': 'true', class: 'calc-a2hs-share' })) svg.setAttribute(k, v);
    const path = document.createElementNS(NS, 'path');
    path.setAttribute('d', SHARE_ICON);
    svg.append(path);
    text.append(h('p', { class: 'calc-a2hs-how' }, 'Tap ', svg, ' ', h('strong', null, 'Share'), ', then ', h('strong', null, 'Add to Home Screen')));
  } else {
    add = h('button', { type: 'button', class: 'calc-a2hs-add' }, 'Add');
  }
  const bar = h('aside', { class: 'calc-a2hs', hidden: true, 'aria-label': 'Add to home screen' },
    icon ? h('img', { class: 'calc-a2hs-icon', src: icon, alt: '', width: '40', height: '40' }) : null, text, add, close);
  after.insertAdjacentElement('afterend', bar);

  let wanted = false;
  let shown = false;
  const canShow = () => phone() && !dismissed() && !launchedFromHomeScreen() && (ios || !!installEvent);
  const sync = () => {
    const on = wanted && canShow();
    bar.hidden = !on;
    if (on && !shown) { shown = true; sendEvent('a2hs_shown', { calc, platform: ios ? 'ios' : 'android' }); }
  };
  installWaiters.add(sync);
  window.addEventListener('appinstalled', () => { sendEvent('a2hs_installed', { calc }); wanted = false; lsSet(A2HS_KEY, String(Date.now())); sync(); });
  close.addEventListener('click', () => { lsSet(A2HS_KEY, String(Date.now())); sendEvent('a2hs_dismiss', { calc }); wanted = false; sync(); });
  if (add) {
    add.addEventListener('click', async () => {
      sendEvent('a2hs_add_click', { calc });
      const e = installEvent;
      if (!e) return;
      installEvent = null;
      try {
        await e.prompt();
        const choice = await e.userChoice;
        if (choice && choice.outcome === 'accepted') { wanted = false; sync(); }
      } catch (err) { /* the browser refused; leave the bar */ }
    });
  }

  // A calculation counts once its inputs settle (1.5s), and only when the result is new.
  let timer = null;
  let lastKey = null;
  let count = Number(lsGet(A2HS_COUNT)) || 0;
  return {
    el: bar,
    calculated(key) {
      clearTimeout(timer);
      timer = setTimeout(() => {
        if (key === lastKey) return;
        lastKey = key;
        count += 1;
        lsSet(A2HS_COUNT, String(Math.min(count, 99)));
        if (count >= 2) { wanted = true; sync(); }
      }, 1500);
    },
    saved() { wanted = true; sync(); },
    /** A restored or shared result: remember it without counting it as a calculation. */
    seen(key) { clearTimeout(timer); lastKey = key; },
  };
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
