/* High school course catalog (from High School v3.2): course-name suggestions, nicknames and the level a
 * course name implies. Opt-in per profile (profile.courses); used by High School and the Homepage's high
 * school mode. Level ids: reg, hon, ap, ib, de. */

// Per subject: regular | honors | AP | IB ('*' = offered at SL and HL) | dual enrollment. Prefixes are added here.
const CAT = [
  ['English', 'English 9|English 10|English 11|English 12|English Language Arts|Creative Writing|Journalism|Speech and Debate|American Literature|British Literature|World Literature|Composition|Public Speaking',
    'English 9|English 10|English 11|English 12|American Literature', 'English Language and Composition|English Literature and Composition|Seminar|Research', 'English A: Language and Literature*|English A: Literature*'],
  ['Math', 'Pre-Algebra|Algebra 1|Geometry|Algebra 2|Trigonometry|Pre-Calculus|Calculus|Statistics|Integrated Math 1|Integrated Math 2|Integrated Math 3|Math Analysis|Financial Algebra|Consumer Math|Discrete Math',
    'Algebra 1|Geometry|Algebra 2|Pre-Calculus|Calculus|Integrated Math 2|Integrated Math 3', 'Precalculus|Calculus AB|Calculus BC|Statistics', 'Math: Analysis and Approaches*|Math: Applications and Interpretation*'],
  ['Science', 'Biology|Chemistry|Physics|Earth Science|Physical Science|Environmental Science|Anatomy and Physiology|Astronomy|Forensic Science|Marine Biology|Integrated Science|Biotechnology|Geology|Zoology',
    'Biology|Chemistry|Physics|Anatomy and Physiology|Earth Science|Environmental Science', 'Biology|Chemistry|Environmental Science|Physics 1|Physics 2|Physics C: Mechanics|Physics C: Electricity and Magnetism', 'Biology*|Chemistry*|Physics*|Environmental Systems and Societies*|Sports, Exercise and Health Science*'],
  ['Social Studies', 'World History|US History|World Geography|Government|Civics|Economics|Psychology|Sociology|Personal Finance|Ethnic Studies|Law|Current Events|State History',
    'World History|US History|Government|Economics|World Geography', 'United States History|World History: Modern|European History|Human Geography|United States Government and Politics|Comparative Government and Politics|Macroeconomics|Microeconomics|Psychology|African American Studies',
    'History*|Geography*|Economics*|Psychology*|Global Politics*|Business Management*|Philosophy*|Theory of Knowledge'],
  ['World Language', 'Spanish 1|Spanish 2|Spanish 3|Spanish 4|French 1|French 2|French 3|French 4|German 1|German 2|German 3|Chinese 1|Chinese 2|Chinese 3|Japanese 1|Japanese 2|Japanese 3|Latin 1|Latin 2|Latin 3|Italian 1|Italian 2|Korean 1|Korean 2|ASL 1|ASL 2|ASL 3|Spanish for Native Speakers',
    'Spanish 3|Spanish 4|French 3|French 4|German 3|Chinese 3|Japanese 3|Latin 3', 'Spanish Language and Culture|Spanish Literature and Culture|French Language and Culture|German Language and Culture|Chinese Language and Culture|Japanese Language and Culture|Italian Language and Culture|Latin',
    'Spanish B*|French B*|German B*|Chinese B*|Spanish ab initio|French ab initio|Mandarin ab initio'],
  ['Arts', 'Art 1|Art 2|Drawing|Painting|Ceramics|Sculpture|Photography|Digital Art|Graphic Design|Band|Concert Band|Jazz Band|Orchestra|Choir|Music Theory|Guitar|Piano|Theater|Drama|Dance|Film Studies',
    'Art 3|Choir|Orchestra|Band', 'Art History|Music Theory|Drawing|2-D Art and Design|3-D Art and Design', 'Visual Arts*|Music*|Theatre*|Film*|Dance*'],
  ['Tech & Careers', 'Computer Science|Intro to Programming|Web Design|Robotics|Engineering Design|Business|Accounting|Marketing|Entrepreneurship|Culinary Arts|Automotive Technology|Health Science|Medical Terminology|Video Production|Yearbook|Woodworking|Child Development',
    'Computer Science|Engineering Design', 'Computer Science A|Computer Science Principles', 'Computer Science*|Design Technology*'],
  ['PE & Health', 'PE|Physical Education|Health|Weight Training|Team Sports|Yoga|Driver Education'],
  ['Electives', 'AVID|Leadership|Study Skills|Peer Tutoring|Teacher Assistant|Library Aide'],
  ['Dual Enrollment', '', '', '', '', 'English Composition|College Algebra|Statistics|Psychology|US History|Biology|Public Speaking|Sociology|Chemistry|Calculus']
];

// Nicknames students type (APUSH, precalc, psych …), matched as extra words.
const ALIAS = { 'AP United States History': 'apush us', 'AP United States Government and Politics': 'us gov apgov', 'AP World History: Modern': 'apwh', 'AP European History': 'apeuro',
  'AP English Language and Composition': 'lang apl', 'AP English Literature and Composition': 'lit', 'AP Computer Science A': 'csa', 'AP Computer Science Principles': 'csp', 'AP Environmental Science': 'apes',
  'AP Human Geography': 'aphg', 'AP Psychology': 'psych', 'Psychology': 'psych', 'US History': 'american united states', 'Government': 'gov', 'Physical Education': 'pe', 'Anatomy and Physiology': 'a&p', 'Pre-Calculus': 'precalc', 'Precalculus': 'precalc' };

/** The level a course name implies (AP …, IB …, Honors …, Dual Enrollment …), or null. */
export function guessLevel(name) {
  const s = String(name || '').trim();
  if (/^AP\b/i.test(s)) return 'ap';
  if (/^IB\b/i.test(s)) return 'ib';
  if (/^hon(ors|\.)?\b|\bhonors\b/i.test(s)) return 'hon';
  if (/^(DE|DC)\b|^dual\b|dual (enrollment|credit)/i.test(s)) return 'de';
  return null;
}

const words = (s) => String(s).toLowerCase().split(/[^a-z0-9&]+/).filter(Boolean);

export const COURSES = (() => {
  const out = [];
  const seen = new Set();
  const add = (n, subject) => { if (n && !seen.has(n)) { seen.add(n); out.push({ name: n, subject, level: guessLevel(n) || 'reg' }); } };
  for (const c of CAT) {
    const sp = (i) => (c[i] ? c[i].split('|') : []);
    sp(1).forEach((n) => add(n, c[0]));
    sp(2).forEach((n) => add(`Honors ${n}`, c[0]));
    sp(3).forEach((n) => add(`AP ${n}`, c[0]));
    sp(4).forEach((n) => { if (n.endsWith('*')) { add(`IB ${n.slice(0, -1)} HL`, c[0]); add(`IB ${n.slice(0, -1)} SL`, c[0]); } else add(`IB ${n}`, c[0]); });
    sp(5).forEach((n) => add(`Dual Enrollment ${n}`, c[0]));
  }
  for (const c of out) { c.words = words(`${c.name} ${ALIAS[c.name] || ''}`); c.lower = c.name.toLowerCase(); }
  return out;
})();

/** Up to `max` courses matching what the student typed (every word is a prefix of a name word or nickname). */
export function suggestCourses(q, max = 6) {
  const qw = words(q);
  const ql = String(q).trim().toLowerCase();
  // A full course name typed out needs no list (and the list would sit over the row's grade and level).
  if (!qw.length || COURSES.some((c) => c.lower === ql)) return [];
  const res = [];
  for (const c of COURSES) {
    const ok = qw.every((w, i) => c.words.some((x) => (i === qw.length - 1 ? x.startsWith(w) : x === w || x.startsWith(w))));
    if (ok) res.push([c.lower.startsWith(ql) ? 0 : 1, c.name.length, c]);
  }
  res.sort((a, b) => a[0] - b[0] || a[1] - b[1]);
  return res.slice(0, max).map((r) => r[2]);
}
