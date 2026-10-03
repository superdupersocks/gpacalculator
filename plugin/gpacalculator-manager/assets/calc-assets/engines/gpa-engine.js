/* gpacalculator.net GPA engine v1.0.0 (gpacalculator-manager plugin)
 * Pure math, no DOM: every GPA calculator (homepage, college, high school, middle school,
 * university pages) runs on this with its own profile. Tested in tests/js/gpa-engine.test.mjs.
 *
 * GPA = Σ(credits × grade points) ÷ Σ credits, over the courses that count.
 * A profile decides what counts and how much: its grading scales, course types (P/NP, transfer),
 * levels (Honors/AP bonus), repeat policy and optional rule hooks:
 *   rules.countRow(row, ctx)          -> false to leave a counted row out (ctx.why = reason)
 *   rules.rowPoints(row, points, ctx) -> points to use instead
 *   rules.afterCompute(result, ctx)   -> extra fields on the result (e.g. a UC GPA)
 */

export const ENGINE_VERSION = '1.0.0';

/** "3", "3.5", ".5" -> number; anything else -> null. "" -> undefined (blank). */
export function parseNum(raw) {
  const s = String(raw == null ? '' : raw).trim().replace(/,/g, '.');
  if (!s) return undefined;
  if (!/^\d+(?:\.\d+)?$|^\.\d+$/.test(s)) return null;
  return Number(s);
}

/** Round half away from zero (2.345 -> 2.35), safe against float noise. */
export function roundHalf(n, d = 2) {
  if (n == null || !Number.isFinite(n)) return n;
  const f = 10 ** d;
  return (Math.sign(n) * Math.round(Math.abs(n) * f + 1e-9 * f)) / f;
}

export function fmtGpa(n, d = 2) {
  return n == null || !Number.isFinite(n) ? '—' : roundHalf(n, d).toFixed(d);
}

/** Trim trailing zeros: 3.50 -> "3.5", 4 -> "4". */
export function fmtNum(n, d = 2) {
  if (n == null || !Number.isFinite(n)) return '—';
  const s = roundHalf(n, d).toFixed(d);
  return s.includes('.') ? s.replace(/\.?0+$/, '') : s;
}

export function scaleOf(profile, state) {
  const list = profile.scales || [];
  return list.find((s) => s.id === (state && state.scale)) || list.find((s) => s.id === profile.defaultScale) || list[0];
}

/** Highest points on a scale (4.0, 4.33, 7 …). */
export function scaleMax(scale) {
  if (scale.max != null) return scale.max;
  return Math.max(...Object.values(scale.grades).filter((v) => v != null));
}

/** Grades in display order (highest points first, non-GPA grades last). */
export function gradeList(scale) {
  return Object.keys(scale.grades);
}

function typeOf(profile, name) {
  const types = profile.courseTypes || [];
  return types.find((t) => t.name === name) || types[0] || null;
}

const norm = (s) => String(s || '').trim().toLowerCase().replace(/\s+/g, ' ');

/**
 * Compute everything a GPA calculator shows.
 * state = { scale, prior: { gpa, credits }, terms: [{ id, name, planned, rows: [{ id, name, grade, credits, major, type, level }] }] }
 */
export function compute(profile, state) {
  const scale = scaleOf(profile, state);
  const max = scaleMax(scale);
  const rules = profile.rules || {};
  const creditMax = profile.creditMax || 999;
  const levels = profile.levels || null;
  const rows = {};
  const ctx = { profile, state, scale, max };

  // 1. Each row on its own
  const counted = [];
  (state.terms || []).forEach((term, ti) => {
    (term.rows || []).forEach((row, ri) => {
      const r = { id: row.id, src: row, term: term.id, ti, ri, planned: !!term.planned, status: 'empty', credits: 0, points: 0, weighted: 0 };
      rows[row.id] = r;
      const grade = String(row.grade || '').trim();
      const cr = parseNum(row.credits);
      if (!grade && cr === undefined) return;
      if (cr === null || (cr !== undefined && (cr <= 0 || cr > creditMax))) {
        r.status = 'error';
        r.error = cr === null ? `Enter ${profile.creditWord || 'credits'} as a number.` : cr <= 0 ? `${cap(profile.creditWord || 'credits')} must be more than 0.` : `${cap(profile.creditWord || 'credits')} can be at most ${creditMax} per course.`;
        return;
      }
      if (!grade) { r.status = 'pending'; return; }
      // P, NP, W …: on the transcript but not in the GPA, so they need no credits.
      if (scale.grades[grade] === null && !(profile.courseTypes || []).length) { r.status = 'excluded'; r.why = (scale.notes && scale.notes[grade]) || `${grade} doesn't count in your GPA.`; return; }
      if (cr === undefined) {
        r.status = 'error';
        r.error = `Enter ${profile.creditWord || 'credits'} for this course.`;
        return;
      }
      const type = typeOf(profile, row.type);
      if (type && type.manualReview) { r.status = 'excluded'; r.why = type.note || 'Check this course with your registrar.'; r.review = true; return; }
      if (type && type.excluded) { r.status = 'excluded'; r.why = type.note || `${type.name} doesn't count in your GPA.`; return; }
      if (type && type.allowed && !type.allowed.includes(grade)) { r.status = 'error'; r.error = `${grade} isn't a grade for ${type.name}.`; return; }
      const table = type && type.grades ? type.grades : scale.grades;
      if (!Object.prototype.hasOwnProperty.call(table, grade)) { r.status = 'error'; r.error = `“${grade}” isn't a grade on this scale.`; return; }
      let pts = table[grade];
      if (pts == null) { r.status = 'excluded'; r.why = (scale.notes && scale.notes[grade]) || `${grade} doesn't count in your GPA.`; return; }
      if (rules.rowPoints) pts = rules.rowPoints(row, pts, ctx);
      if (rules.countRow && rules.countRow(row, ctx) === false) { r.status = 'excluded'; r.why = ctx.why || 'Not counted by this calculator’s rules.'; ctx.why = ''; return; }
      const bonus = levels && row.level && levels[row.level] ? levels[row.level] : 0;
      Object.assign(r, { status: 'counted', credits: cr, points: pts, weighted: pts > 0 ? pts + bonus : pts, grade, major: !!row.major, name: row.name || '', key: norm(row.name) });
      counted.push(r);
    });
  });

  // 2. Repeated courses: with "replace", only the latest attempt of a course counts
  if (profile.repeat === 'replace') {
    const last = {};
    counted.forEach((r) => { if (r.key) last[r.key] = r; });
    counted.forEach((r) => {
      if (r.key && last[r.key] !== r) { r.status = 'replaced'; r.why = `Replaced by your later ${last[r.key].name || 'attempt'} grade.`; }
    });
  }

  // 3. Totals
  const sum = (list) => list.reduce((a, r) => ({ credits: a.credits + r.credits, points: a.points + r.credits * r.points, weighted: a.weighted + r.credits * r.weighted }), { credits: 0, points: 0, weighted: 0 });
  const live = counted.filter((r) => r.status === 'counted');
  const terms = (state.terms || []).map((t) => {
    const s = sum(live.filter((r) => r.term === t.id));
    return { id: t.id, name: t.name, planned: !!t.planned, ...s, gpa: s.credits ? s.points / s.credits : null, wgpa: s.credits ? s.weighted / s.credits : null };
  });

  const prior = priorOf(profile, state, max);
  const done = sum(live.filter((r) => !r.planned));
  const plan = sum(live.filter((r) => r.planned));
  const current = { credits: prior.credits + done.credits, points: prior.points + done.points, weighted: prior.points + done.weighted };
  current.gpa = current.credits ? current.points / current.credits : null;
  current.wgpa = current.credits ? current.weighted / current.credits : null;
  const projected = { credits: current.credits + plan.credits, points: current.points + plan.points };
  projected.gpa = plan.credits && projected.credits ? projected.points / projected.credits : null;
  const majorS = sum(live.filter((r) => r.major && !r.planned));
  const major = majorS.credits ? { ...majorS, gpa: majorS.points / majorS.credits } : null;

  const result = {
    scale, max, rows, terms, prior, current, projected, major,
    courses: { counted: live.length, newCredits: done.credits, newPoints: done.points },
    errors: Object.values(rows).filter((r) => r.status === 'error').length,
    excluded: Object.values(rows).filter((r) => r.status === 'excluded' || r.status === 'replaced'),
    weighted: !!levels,
    ready: current.credits > 0,
  };
  if (rules.afterCompute) Object.assign(result, rules.afterCompute(result, ctx) || {});
  return result;
}

function cap(s) { return s.charAt(0).toUpperCase() + s.slice(1); }

/** Previous cumulative GPA + credits: both or neither. */
export function priorOf(profile, state, max) {
  const p = (state && state.prior) || {};
  const g = parseNum(p.gpa);
  const c = parseNum(p.credits);
  const out = { credits: 0, points: 0, gpa: null, used: false, gpaError: '', creditsError: '' };
  if (g === undefined && c === undefined) return out;
  if (g === null || (g !== undefined && (g < 0 || g > max))) out.gpaError = `Enter a GPA from 0 to ${fmtNum(max)}.`;
  if (c === null || (c !== undefined && c <= 0)) out.creditsError = `Enter the ${profile.creditWord || 'credits'} you've completed.`;
  if (!out.gpaError && g === undefined) out.gpaError = 'Enter your GPA too.';
  if (!out.creditsError && c === undefined) out.creditsError = `Enter your ${profile.creditWord || 'credits'} too.`;
  if (out.gpaError || out.creditsError) return out;
  return { ...out, credits: c, points: g * c, gpa: g, used: true };
}

/**
 * Target planner. totals = { credits, points } so far; target GPA; upcoming credits.
 * Returns { needed, highest, lowest, status: 'met' | 'reachable' | 'out-of-reach', letter, creditsForTarget }.
 */
export function plan(totals, target, upcoming, scale) {
  const max = scaleMax(scale);
  const T = parseNum(target);
  const U = parseNum(upcoming);
  if (T == null || U == null) return { status: 'incomplete' };
  if (T > max) return { status: 'invalid', error: `Pick a target up to ${fmtNum(max)}.` };
  if (!(U > 0)) return { status: 'invalid', error: 'Enter the credits you have coming up.' };
  const C = totals.credits;
  const P = totals.points;
  const needed = (T * (C + U) - P) / U;
  const highest = (P + max * U) / (C + U);
  const lowest = P / (C + U);
  const out = { target: T, upcoming: U, needed, highest, lowest, max };
  if (needed <= 0) out.status = 'met';
  else if (needed <= max + 1e-9) out.status = 'reachable';
  else {
    out.status = 'out-of-reach';
    // Credits of straight top grades it would take: (P + max n) / (C + n) >= T
    out.creditsForTarget = T < max ? Math.ceil(((T * C - P) / (max - T)) - 1e-9) : null;
  }
  out.letter = needed > 0 && needed <= max + 1e-9 ? letterAtLeast(needed, scale) : null;
  return out;
}

/** Lowest grade whose points reach `pts` (the average you need, as a letter). */
export function letterAtLeast(pts, scale) {
  let best = null;
  for (const [g, v] of Object.entries(scale.grades)) {
    if (v == null || v + 1e-9 < pts) continue;
    if (!best || v < best.v || (v === best.v && best.g.endsWith('+'))) best = { g, v };
  }
  return best && best.g;
}

/** GPA after `upcoming` credits at an `avg` grade-point average. */
export function whatIf(totals, avg, upcoming) {
  const t = totals.credits + upcoming;
  return t ? (totals.points + avg * upcoming) / t : null;
}

/** The counted course whose one-step grade raise moves the GPA most. */
export function biggestLever(result) {
  const { scale } = result;
  const steps = Object.entries(scale.grades).filter(([, v]) => v != null).sort((a, b) => a[1] - b[1]);
  const C = result.current.credits;
  let best = null;
  for (const r of Object.values(result.rows)) {
    if (r.status !== 'counted' || r.planned) continue;
    const up = steps.find(([, v]) => v > r.points + 1e-9);
    if (!up) continue;
    const gain = (r.credits * (up[1] - r.points)) / C;
    if (!best || gain > best.gain + 1e-12) best = { id: r.id, name: r.name, from: r.grade, to: up[0], gain };
  }
  return best;
}

/** Running cumulative GPA after each term (for the trend chart). */
export function trend(result) {
  let c = result.prior.credits;
  let p = result.prior.points;
  return result.terms.filter((t) => !t.planned && t.credits > 0).map((t) => {
    c += t.credits;
    p += t.points;
    return { id: t.id, name: t.name, term: t.gpa, cumulative: p / c };
  });
}
