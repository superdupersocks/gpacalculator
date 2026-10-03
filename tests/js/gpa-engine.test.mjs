// Unit tests for the GPA engine: node --test tests/js/
// Expected values are worked out by hand in the comments, never by calling engine helpers.
import test from 'node:test';
import assert from 'node:assert/strict';
import { compute, plan, whatIf, biggestLever, trend, priorOf, parseNum, roundHalf, fmtGpa, letterAtLeast } from '../../plugin/gpacalculator-manager/assets/calc-assets/engines/gpa-engine.js';
import college from '../../plugin/gpacalculator-manager/assets/calc-assets/profiles/college.js';
import { fromGpcm } from '../../plugin/gpacalculator-manager/assets/calc-assets/profiles/from-gpcm.js';
import { readFileSync } from 'node:fs';

const near = (a, b, msg) => assert.ok(Math.abs(a - b) < 1e-9, `${msg || ''} got ${a}, want ${b}`);
const row = (id, grade, credits, extra = {}) => ({ id, name: extra.name || '', grade, credits: String(credits), ...extra });
const st = (rows, extra = {}) => ({ scale: 'standard', prior: { gpa: '', credits: '' }, terms: [{ id: 't1', name: 'Fall', rows }], ...extra });

test('parsing and rounding', () => {
  assert.equal(parseNum(''), undefined);
  assert.equal(parseNum(' 3 '), 3);
  assert.equal(parseNum('3,5'), 3.5);
  assert.equal(parseNum('.5'), 0.5);
  assert.equal(parseNum('3a'), null);
  assert.equal(parseNum('-1'), null);
  assert.equal(roundHalf(2.345, 2), 2.35);
  assert.equal(roundHalf(1.005, 2), 1.01);
  assert.equal(fmtGpa(3.4166666, 2), '3.42');
  assert.equal(fmtGpa(3.4166666, 3), '3.417');
  assert.equal(fmtGpa(null), '—');
});

test('basic college GPA: A 3cr, B+ 4cr, A- 3cr, C+ 2cr', () => {
  // 3*4 + 4*3.3 + 3*3.7 + 2*2.3 = 12 + 13.2 + 11.1 + 4.6 = 40.9 over 12 credits = 3.408333…
  const r = compute(college, st([row('a', 'A', 3), row('b', 'B+', 4), row('c', 'A-', 3), row('d', 'C+', 2)]));
  near(r.current.points, 40.9);
  assert.equal(r.current.credits, 12);
  near(r.current.gpa, 40.9 / 12);
  assert.equal(fmtGpa(r.current.gpa), '3.41');
  assert.equal(r.courses.counted, 4);
  assert.ok(r.ready);
});

test('P, NP and W are not counted in GPA (and need no credits)', () => {
  const r = compute(college, st([row('a', 'B', 4), row('p', 'P', 3), row('n', 'NP', 3), row('w', 'W', '')]));
  assert.equal(fmtGpa(r.current.gpa), '3.00');
  assert.equal(r.current.credits, 4);
  for (const id of ['p', 'n', 'w']) assert.equal(r.rows[id].status, 'excluded');
  assert.equal(r.rows.p.why, 'P (pass) isn’t counted in GPA.');
  assert.equal(r.rows.w.why, 'W (withdrawn) isn’t counted in GPA.');
  assert.equal(r.errors, 0);
  const lever = biggestLever(r);
  assert.equal(lever.id, 'a');
  for (const scale of ['a-plus-433', 'no-plus-minus']) {
    const r2 = compute(college, st([row('a', 'B', 4), row('p', 'P', 3)], { scale }));
    assert.equal(r2.rows.p.status, 'excluded');
    assert.equal(fmtGpa(r2.current.gpa), '3.00');
  }
  assert.equal(letterAtLeast(3.9, college.scales[0]), 'A');
});

test('scales: A+ = 4.33 and no plus/minus', () => {
  const rows = [row('a', 'A+', 3), row('b', 'B', 3)];
  near(compute(college, st(rows)).current.gpa, (12 + 9) / 6);
  near(compute(college, st(rows, { scale: 'a-plus-433' })).current.gpa, (3 * 4.33 + 9) / 6);
  // A+ isn't on the no plus/minus scale: that row is an error, B counts alone
  const r = compute(college, st(rows, { scale: 'no-plus-minus' }));
  assert.equal(r.rows.a.status, 'error');
  near(r.current.gpa, 3);
});

test('blank, pending and invalid rows', () => {
  const r = compute(college, st([
    row('a', 'A', 3), row('blank', '', ''), row('pending', '', 3), row('nocr', 'B', ''), row('zero', 'B', 0), row('big', 'B', 99), row('word', 'B', 'abc'),
  ]));
  assert.equal(r.rows.blank.status, 'empty');
  assert.equal(r.rows.pending.status, 'pending');
  for (const id of ['nocr', 'zero', 'big', 'word']) assert.equal(r.rows[id].status, 'error', id);
  assert.equal(r.errors, 4);
  near(r.current.gpa, 4);
});

test('F counts with its credits; all F = 0.00', () => {
  near(compute(college, st([row('a', 'A', 3), row('f', 'F', 3)])).current.gpa, 2);
  const r = compute(college, st([row('f', 'F', 3)]));
  assert.equal(r.current.gpa, 0);
  assert.equal(fmtGpa(r.current.gpa), '0.00');
});

test('decimal credits', () => {
  // 1.5*4 + 0.5*3 = 7.5 over 2 = 3.75
  near(compute(college, st([row('a', 'A', 1.5), row('b', 'B', '0.5')])).current.gpa, 3.75);
});

test('semesters + previous GPA', () => {
  // prior 3.2 over 30 = 96 points; Fall A 3 (12) B 3 (9); Spring C 4 (8)
  // total 96 + 29 = 125 over 40 = 3.125; Fall term 21/6 = 3.5; Spring 2.0
  const s = { scale: 'standard', prior: { gpa: '3.2', credits: '30' }, terms: [
    { id: 'f', name: 'Fall', rows: [row('a', 'A', 3), row('b', 'B', 3)] },
    { id: 's', name: 'Spring', rows: [row('c', 'C', 4)] },
  ] };
  const r = compute(college, s);
  near(r.current.gpa, 3.125);
  near(r.terms[0].gpa, 3.5);
  near(r.terms[1].gpa, 2);
  assert.equal(r.current.credits, 40);
  const tr = trend(r);
  near(tr[0].cumulative, (96 + 21) / 36);
  near(tr[1].cumulative, 3.125);
});

test('previous GPA needs both fields and a valid range', () => {
  assert.equal(priorOf(college, { prior: { gpa: '3.5', credits: '' } }, 4).creditsError !== '', true);
  assert.equal(priorOf(college, { prior: { gpa: '', credits: '30' } }, 4).gpaError !== '', true);
  assert.equal(priorOf(college, { prior: { gpa: '4.5', credits: '30' } }, 4).gpaError !== '', true);
  assert.equal(priorOf(college, { prior: { gpa: '3.5', credits: '30' } }, 4).used, true);
  // prior only: GPA equals the prior
  near(compute(college, st([], { prior: { gpa: '3.5', credits: '30' } })).current.gpa, 3.5);
});

test('major GPA counts only major courses', () => {
  const r = compute(college, st([row('a', 'A', 3, { major: true }), row('b', 'C', 3), row('c', 'B', 4, { major: true })]));
  near(r.major.gpa, (12 + 12) / 7);
  near(r.current.gpa, (12 + 6 + 12) / 10);
});

test('planner: reachable, met, out of reach, impossible target', () => {
  const tot = { credits: 30, points: 90 }; // 3.0
  const scale = college.scales[0];
  // target 3.3 with 15 credits: (3.3*45 - 90) / 15 = (148.5 - 90)/15 = 3.9
  let p = plan(tot, '3.3', '15', scale);
  assert.equal(p.status, 'reachable');
  near(p.needed, 3.9);
  assert.equal(p.letter, 'A');
  near(p.highest, (90 + 60) / 45);
  // target 2.5: (112.5 - 90)/15 = 1.5 -> reachable, C- is 1.7 (lowest letter at least 1.5)
  p = plan(tot, '2.5', '15', scale);
  near(p.needed, 1.5);
  assert.equal(p.letter, 'C-');
  // target 1.8: (81 - 90) / 15 < 0 -> met even with all F
  assert.equal(plan(tot, '1.8', '15', scale).status, 'met');
  // target 3.8 with 15: (171 - 90)/15 = 5.4 -> out of reach; credits of straight A: (3.8*30 - 90)/(4-3.8) = 24/0.2 = 120
  p = plan(tot, '3.8', '15', scale);
  assert.equal(p.status, 'out-of-reach');
  assert.equal(p.creditsForTarget, 120);
  near(p.highest, 150 / 45);
  // target 4.0 when below: never reachable, no credit count
  p = plan(tot, '4', '15', scale);
  assert.equal(p.status, 'out-of-reach');
  assert.equal(p.creditsForTarget, null);
  assert.equal(plan(tot, '4.5', '15', scale).status, 'invalid');
  assert.equal(plan(tot, '3.5', '0', scale).status, 'invalid');
  assert.equal(plan(tot, '', '15', scale).status, 'incomplete');
});

test('what-if and biggest lever', () => {
  near(whatIf({ credits: 30, points: 90 }, 3.5, 15), (90 + 52.5) / 45);
  // B 4 credits -> B+ gains 4*0.3/10 = 0.12; C 3cr -> C+ gains 3*0.3/10 = 0.09; A can't rise on standard (A+ = 4.0 same points)
  const r = compute(college, st([row('a', 'A', 3), row('b', 'B', 4), row('c', 'C', 3)]));
  const l = biggestLever(r);
  assert.equal(l.id, 'b');
  assert.equal(l.to, 'B+');
  near(l.gain, 0.12);
});

test('letterAtLeast', () => {
  const sc = college.scales[0];
  assert.equal(letterAtLeast(3.5, sc), 'A-');
  assert.equal(letterAtLeast(3.0, sc), 'B');
  assert.equal(letterAtLeast(0.1, sc), 'D-');
});

test('repeat policy replace: the later attempt replaces the earlier', () => {
  const p = { ...college, repeat: 'replace' };
  const s = { scale: 'standard', terms: [
    { id: 'f', rows: [row('a', 'F', 3, { name: 'Calc I' }), row('b', 'B', 3, { name: 'Bio' })] },
    { id: 's', rows: [row('c', 'A', 3, { name: 'calc  i' })] },
  ] };
  const r = compute(p, s);
  assert.equal(r.rows.a.status, 'replaced');
  near(r.current.gpa, (9 + 12) / 6);
  // without the policy, both attempts count
  near(compute(college, s).current.gpa, (0 + 9 + 12) / 9);
});

test('levels: weighted GPA beside unweighted (Honors +0.5, AP +1, no bonus on F)', () => {
  const p = { ...college, levels: { regular: 0, honors: 0.5, ap: 1 } };
  const r = compute(p, st([row('a', 'A', 1, { level: 'ap' }), row('b', 'B', 1, { level: 'honors' }), row('c', 'F', 1, { level: 'ap' })]));
  near(r.current.gpa, 7 / 3);
  near(r.current.wgpa, (5 + 3.5 + 0) / 3);
});

test('rule hooks: a UC-style capped weighted GPA (10th-11th grade only, honors points capped at 8, 4 from 10th)', () => {
  // Hook example from the design note. Rows carry grade year and honors flag; afterCompute applies the cap.
  const uc = {
    ...college,
    rules: {
      countRow: (r, ctx) => { if (r.year === '10' || r.year === '11') return true; ctx.why = 'UC GPA counts only 10th and 11th grade.'; return false; },
      afterCompute: (res) => {
        const rows = Object.values(res.rows).filter((x) => x.status === 'counted');
        const honors = (y) => rows.filter((x) => x.src.honors && x.src.year === y && x.points > 0).length;
        const bonus = Math.min(8, Math.min(4, honors('10')) + honors('11'));
        return { ucCapped: (res.current.points + bonus) / res.current.credits, ucBonus: bonus };
      },
    },
  };
  const rowsIn = [];
  for (let i = 0; i < 6; i++) rowsIn.push(row(`t${i}`, 'A', 1, { year: '10', honors: true }));
  for (let i = 0; i < 6; i++) rowsIn.push(row(`e${i}`, 'B', 1, { year: '11', honors: true }));
  rowsIn.push(row('n9', 'C', 1, { year: '9' }));
  const r = compute(uc, st(rowsIn));
  assert.equal(r.rows.n9.status, 'excluded');
  // 6 A (24) + 6 B (18) = 42 over 12 = 3.5 unweighted; bonus: 4 from 10th + 6 from 11th capped at 8 -> (42+8)/12
  near(r.current.gpa, 3.5);
  assert.equal(r.ucBonus, 8);
  near(r.ucCapped, 50 / 12);
});

test('UCLA profile from the university profile JSON', () => {
  const cfg = JSON.parse(readFileSync(new URL('../../data/gpcm-university-profiles/ucla.json', import.meta.url)));
  const p = fromGpcm(cfg);
  assert.equal(p.id, 'ucla');
  assert.equal(p.creditWord, 'units');
  // A 4 units, B+ 4 units -> 29.2 / 8 = 3.65 (the profile's own help example)
  const s = { scale: p.defaultScale, terms: [{ id: 'c', rows: [row('a', 'A', 4, { type: 'UCLA course' }), row('b', 'B+', 4, { type: 'UCLA course' })] }] };
  near(compute(p, s).current.gpa, 3.65);
  // P/NP and transfer courses are left out; F stays in the denominator
  const s2 = { scale: p.defaultScale, terms: [{ id: 'c', rows: [
    row('a', 'A', 4, { type: 'UCLA course' }), row('p', 'P', 4, { type: 'UCLA course' }),
    row('t', 'A', 4, { type: 'Non-UC transfer / other institution' }), row('f', 'F', 4, { type: 'UCLA course' }),
    row('r', 'A', 4, { type: 'Repeated UCLA/UC course' }),
  ] }] };
  const r = compute(p, s2);
  assert.equal(r.rows.p.status, 'excluded');
  assert.equal(r.rows.t.status, 'excluded');
  assert.equal(r.rows.r.status, 'excluded');
  assert.equal(r.rows.r.review, true);
  near(r.current.gpa, 2);
  // planned courses: projected GPA includes them, current doesn't
  const s3 = { scale: p.defaultScale, prior: { gpa: '3.2', credits: '60' }, terms: [
    { id: 'c', rows: [row('a', 'A', 4, { type: 'UCLA course' })] },
    { id: 'p', planned: true, rows: [row('b', 'B', 4, { type: 'UCLA course' })] },
  ] };
  const r3 = compute(p, s3);
  near(r3.current.gpa, (192 + 16) / 64);
  near(r3.projected.gpa, (192 + 16 + 12) / 68);
});
