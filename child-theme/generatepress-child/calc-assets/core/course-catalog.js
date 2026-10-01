/* gpacalculator.net course catalog, core v1.0.0
 * Course levels with weighting bonuses and a list of common US high school courses
 * for autocomplete (<datalist>) and level guessing. Shared by the GPA calculators.
 *
 * Weighting varies by school; these are the most common defaults and every
 * calculator should let the student change them.
 */

/** Course levels. bonus = points added on a 4.0 scale for weighted GPA. */
export const LEVELS = [
  { id: 'regular', label: 'Regular', short: 'Reg', bonus: 0 },
  { id: 'honors', label: 'Honors', short: 'Hon', bonus: 0.5 },
  { id: 'ap', label: 'AP', short: 'AP', bonus: 1.0 },
  { id: 'ib', label: 'IB', short: 'IB', bonus: 1.0 },
  { id: 'de', label: 'Dual Enrollment', short: 'DE', bonus: 1.0 },
];

export const LEVEL_BY_ID = Object.fromEntries(LEVELS.map((l) => [l.id, l]));

/** Common credit values: a full-year course and a one-semester course. */
export const CREDITS = { year: 1.0, semester: 0.5 };

const AP = [
  '2-D Art and Design', '3-D Art and Design', 'African American Studies', 'Art History', 'Biology',
  'Calculus AB', 'Calculus BC', 'Chemistry', 'Chinese Language and Culture',
  'Comparative Government and Politics', 'Computer Science A', 'Computer Science Principles', 'Drawing',
  'English Language and Composition', 'English Literature and Composition', 'Environmental Science',
  'European History', 'French Language and Culture', 'German Language and Culture', 'Human Geography',
  'Italian Language and Culture', 'Japanese Language and Culture', 'Latin', 'Macroeconomics',
  'Microeconomics', 'Music Theory', 'Physics 1', 'Physics 2', 'Physics C: Electricity and Magnetism',
  'Physics C: Mechanics', 'Precalculus', 'Psychology', 'Research', 'Seminar',
  'Spanish Language and Culture', 'Spanish Literature and Culture', 'Statistics',
  'United States Government and Politics', 'United States History', 'World History: Modern',
];

const IB = [
  'English A: Language and Literature', 'English A: Literature', 'Spanish B', 'French B', 'History',
  'Economics', 'Psychology', 'Biology', 'Chemistry', 'Physics', 'Environmental Systems and Societies',
  'Mathematics: Analysis and Approaches', 'Mathematics: Applications and Interpretation',
  'Computer Science', 'Visual Arts', 'Music', 'Theatre', 'Theory of Knowledge',
];

/** Core subjects with typical course names. Honors versions are offered for most. */
const SUBJECTS = {
  English: ['English 9', 'English 10', 'English 11', 'English 12', 'Creative Writing', 'Journalism', 'Speech and Debate'],
  Math: ['Pre-Algebra', 'Algebra 1', 'Geometry', 'Algebra 2', 'Trigonometry', 'Precalculus', 'Calculus', 'Statistics', 'Integrated Math 1', 'Integrated Math 2', 'Integrated Math 3'],
  Science: ['Physical Science', 'Earth Science', 'Biology', 'Chemistry', 'Physics', 'Environmental Science', 'Anatomy and Physiology', 'Forensic Science'],
  'Social Studies': ['World History', 'US History', 'Government', 'Economics', 'World Geography', 'Psychology', 'Sociology', 'Civics'],
  'World Languages': ['Spanish 1', 'Spanish 2', 'Spanish 3', 'Spanish 4', 'French 1', 'French 2', 'French 3', 'French 4', 'German 1', 'German 2', 'Chinese 1', 'Chinese 2', 'Latin 1', 'Latin 2', 'American Sign Language 1'],
  Arts: ['Art 1', 'Drawing and Painting', 'Ceramics', 'Photography', 'Band', 'Choir', 'Orchestra', 'Theater', 'Digital Media'],
  Electives: ['Physical Education', 'Health', 'Computer Science', 'Personal Finance', 'Business', 'Engineering', 'Culinary Arts', 'Yearbook'],
};

const NO_HONORS = new Set(['Physical Education', 'Health', 'Yearbook', 'Culinary Arts', 'Band', 'Choir', 'Orchestra']);

/** Flat catalog: [{ name, subject, level }] including Honors, AP and IB variants. */
export const COURSES = (() => {
  const out = [];
  for (const [subject, names] of Object.entries(SUBJECTS)) {
    for (const name of names) {
      out.push({ name, subject, level: 'regular' });
      if (!NO_HONORS.has(name)) out.push({ name: `Honors ${name}`, subject, level: 'honors' });
    }
  }
  for (const n of AP) out.push({ name: `AP ${n}`, subject: 'AP', level: 'ap' });
  for (const n of IB) out.push({ name: `IB ${n}`, subject: 'IB', level: 'ib' });
  return out;
})();

/** Guess a level from a typed course name ("AP Bio" -> ap, "Hon. Chem" -> honors). */
export function guessLevel(name) {
  const s = String(name || '').trim().toLowerCase();
  if (!s) return null;
  if (/^ap\b|\bap$|\(ap\)/.test(s)) return 'ap';
  if (/^ib\b|\bib\b|\b[hs]l$/.test(s)) return 'ib';
  if (/^(dual|de\b)|dual enrollment|\bdual credit\b/.test(s)) return 'de';
  if (/^hon(ors|\.)?\b|\bhonors\b|\(h\)$|\bh$/.test(s)) return 'honors';
  return null;
}

/** Case-insensitive search for autocomplete; prefix matches rank first. */
export function searchCourses(q, limit = 8) {
  const s = String(q || '').trim().toLowerCase();
  if (!s) return [];
  const starts = [];
  const contains = [];
  for (const c of COURSES) {
    const n = c.name.toLowerCase();
    if (n.startsWith(s)) starts.push(c);
    else if (n.includes(s)) contains.push(c);
  }
  return starts.concat(contains).slice(0, limit);
}

/**
 * Weighted points for one course: base GPA points plus the level bonus.
 * opts.bonuses overrides LEVELS bonuses ({ ap: 1, honors: 0.5, ... }).
 * opts.bonusOnF (default false): most schools don't add a bonus to an F.
 */
export function weightedPoints(basePoints, levelId, opts = {}) {
  const { bonuses = {}, bonusOnF = false } = opts;
  const lvl = LEVEL_BY_ID[levelId] || LEVEL_BY_ID.regular;
  const bonus = Object.prototype.hasOwnProperty.call(bonuses, lvl.id) ? Number(bonuses[lvl.id]) : lvl.bonus;
  if (basePoints <= 0 && !bonusOnF) return 0;
  return basePoints + bonus;
}

/** Fill a <datalist> with the catalog once; inputs use list="<id>". */
export function courseDatalist(id) {
  const existing = document.getElementById(id);
  if (existing) return existing;
  const dl = document.createElement('datalist');
  dl.id = id;
  for (const c of COURSES) {
    const o = document.createElement('option');
    o.value = c.name;
    dl.append(o);
  }
  return dl;
}
