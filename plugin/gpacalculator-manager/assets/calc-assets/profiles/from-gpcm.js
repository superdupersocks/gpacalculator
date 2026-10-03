/* University profiles: turns a Grade + GPA university profile (the JSON in the gpcm_university_profiles
 * option, one per [gpcm_calculator id="…"] page) into a GPA-engine profile. The JSON stays the source
 * of truth and is not changed; this only reads it.
 *
 * Mapping: baseContext.grades -> the one grading scale (null points = not counted, e.g. P/NP);
 * specialTypes -> course types (excluded, manualReview, own grade points); repeatPolicy "replace" ->
 * engine repeat "replace"; completed/planned courses -> a completed term and a planned term;
 * previous GPA/units -> prior; targetEnabled -> planner. Fields the engine doesn't model yet are
 * listed in profile.unsupported so a profile is never moved over silently. */

const KNOWN = ['scaleMax', 'scaleLabel', 'courseUnitMax', 'hasOfficialGpa', 'grades', 'gradeExplanations', 'specialTypes', 'targetEnabled', 'repeatPolicy'];

export function fromGpcm(cfg, recordName) {
  const rec = (cfg.records || []).find((r) => r.name === recordName) || (cfg.records || [])[0] || {};
  const ctx = { ...cfg.baseContext, ...rec };
  const types = (ctx.specialTypes && ctx.specialTypes.length ? ctx.specialTypes : [{ name: 'Standard course', grades: null, excluded: false, note: '' }])
    .map((t) => ({
      name: t.name,
      excluded: !!t.excluded,
      manualReview: !!t.manualReview,
      note: t.note || '',
      allowed: Array.isArray(t.grades) ? t.grades : null,
      grades: t.gradePoints || null,
    }));
  const rp = ctx.repeatPolicy || {};
  const unsupported = Object.keys(ctx).filter((k) => !KNOWN.includes(k) && k !== 'name');
  if (types.some((t) => t.grades)) unsupported.push('specialTypes.gradePoints');
  if (rp.supported && rp.mode !== 'replace') unsupported.push(`repeatPolicy.${rp.mode}`);
  if (cfg.records && cfg.records.length > 1) unsupported.push('records');

  const sample = cfg.sampleData || {};
  const toRows = (list) => (list || []).map((c) => ({ name: c.name || '', grade: c.grade || '', credits: String(c.units || ''), type: c.special || types[0].name }));
  const short = (cfg.universityName || cfg.slug).replace(/^University of California, /, 'UC ');

  return {
    id: cfg.slug,
    engine: 'gpa',
    schema: 1,
    source: { kind: 'gpcm', slug: cfg.slug, configVersion: cfg.configVersion, qaStatus: cfg.qaStatus, record: rec.name || '' },
    prefix: 'uni',
    hashKey: 'gpa',
    storeKey: `gpac:uni-${cfg.slug}:v1`,
    legacyKey: `top-uni-gpa-calculator-v3-${cfg.slug}`,
    title: `${short} GPA`,
    university: cfg.universityName,
    decimals: 3,
    creditWord: 'units',
    creditMax: ctx.courseUnitMax || 100,
    defaultCredits: '',
    termWord: 'Courses',
    fixedTerms: [{ id: 'done', name: 'Completed courses' }, { id: 'plan', name: 'Planned courses', planned: true }],
    major: false,
    repeat: rp.supported && rp.mode === 'replace' ? 'replace' : 'none',
    prior: true,
    planner: ctx.targetEnabled !== false,
    whatIf: false,
    hasOfficialGpa: ctx.hasOfficialGpa !== false,
    scales: [{ id: 'official', label: ctx.scaleLabel || `${ctx.scaleMax} scale`, grades: ctx.grades, max: ctx.scaleMax, notes: ctx.gradeExplanations || {} }],
    defaultScale: 'official',
    courseTypes: types,
    blocks: ['verdict', 'stats', 'next', 'how'],
    courseHint: 'e.g. CHEM 14A',
    next: {
      planLabel: 'Open planner: grades I need next term',
      rescueLabel: 'Open planner: get back above 2.0',
      links: [],
    },
    keepGoing: [],
    help: { intro: cfg.helpIntro || '', note: cfg.helpNote || '', example: cfg.helpExample || '' },
    sources: cfg.officialLinks || [],
    sample: {
      scale: 'official',
      prior: { gpa: String(sample.previousGpa || ''), credits: String(sample.previousUnits || '') },
      terms: [
        { id: 'done', name: 'Completed courses', rows: toRows(sample.completed) },
        { id: 'plan', name: 'Planned courses', planned: true, rows: toRows(sample.planned) },
      ],
    },
    copy: {
      onboard: `Add your ${short} courses, units and grades. Your GPA updates as you go.`,
      resultKicker: `${short} GPA`,
      termKicker: 'With planned courses',
      pill: 'GPA',
      summaryUrl: 'gpacalculator.net',
    },
    standingLine: 2,
    unsupported,
  };
}

/** Old university calculator draft (localStorage `top-uni-gpa-calculator-v3-<slug>`) -> engine state. */
export function fromGpcmDraft(raw, profile) {
  if (!raw || typeof raw !== 'object' || !raw.records) return null;
  const rec = raw.records[raw.activeRecord] || Object.values(raw.records)[0];
  if (!rec) return null;
  const rows = (list) => (list || []).map((c) => ({ name: String(c.name || ''), grade: String(c.grade || ''), credits: String(c.units == null ? '' : c.units), type: c.special || profile.courseTypes[0].name }));
  const has = (list) => (list || []).some((c) => c.grade || c.units || c.name);
  if (!has(rec.completed) && !has(rec.planned) && !rec.previousGpa) return null;
  return {
    scale: 'official',
    prior: { gpa: String(rec.previousGpa || ''), credits: String(rec.previousUnits || '') },
    terms: [
      { id: 'done', name: 'Completed courses', rows: rows(rec.completed) },
      { id: 'plan', name: 'Planned courses', planned: true, rows: rows(rec.planned) },
    ],
    target: { gpa: String(raw.targetGpa || ''), credits: String(raw.targetUnits || '') },
  };
}
