/* College GPA profile: the generic college calculator on the GPA engine ([college-gpa-calculator]).
 * Everything here is data or copy; the math lives in engines/gpa-engine.js and the screen in gpa/gpa-app.js. */

const STANDARD = { 'A+': 4, A: 4, 'A-': 3.7, 'B+': 3.3, B: 3, 'B-': 2.7, 'C+': 2.3, C: 2, 'C-': 1.7, 'D+': 1.3, D: 1, 'D-': 0.7, F: 0 };

export default {
  id: 'college',
  engine: 'gpa',
  schema: 1,
  prefix: 'col', // GA4 events col_result, col_sample, col_step_2, col_plan, col_save, col_restore, col_share, col_reset
  hashKey: 'gpa', // share links: #gpa=…
  storeKey: 'gpac:college:v1',
  url: '/college-gpa-calculator/',
  title: 'College GPA',
  decimals: 2,
  creditWord: 'credits',
  creditMax: 20,
  defaultCredits: '3',
  rowsPerTerm: 4,
  termWord: 'Semester',
  termNames: ['Fall', 'Spring', 'Summer'],
  major: true,
  repeat: 'none',
  prior: true,
  planner: true,
  charts: true,
  scales: [
    { id: 'standard', label: '4.0 scale, A+ = 4.0', grades: STANDARD, max: 4 },
    { id: 'a-plus-433', label: '4.0 scale, A+ = 4.33', grades: { ...STANDARD, 'A+': 4.33 }, max: 4.33 },
    { id: 'no-plus-minus', label: 'No plus or minus (A, B, C, D, F)', grades: { A: 4, B: 3, C: 2, D: 1, F: 0 }, max: 4 },
  ],
  defaultScale: 'standard',
  courseHint: 'e.g. BIO 110',
  coursePlaceholders: ['e.g. ENG 101', 'e.g. MATH 121', 'e.g. PSY 201', 'e.g. BIO 110'],
  standingLine: 2,

  // Result blocks, in order. A profile can drop, reorder or add its own (gpa-app.js BLOCKS).
  blocks: ['verdict', 'stats', 'next', 'how', 'trend'],

  // Step 2 and the links under the result (the user's GPA is passed forward as ?gpa=).
  next: {
    planLabel: 'Open planner: grades I need next semester',
    rescueLabel: 'Open planner: get back above 2.0',
    links: [
      { label: 'Convert my GPA to a percentage', href: '/gpa-scale/', withGpa: true },
      { label: 'How to raise your GPA', href: '/how-to-raise-gpa/' },
    ],
  },
  keepGoing: [
    { title: 'Raise your GPA', sub: 'See what it takes to move your GPA up a step.', href: '/how-to-raise-gpa/', icon: 'trend' },
    { title: 'Cumulative GPA', sub: 'Combine every semester into one GPA.', href: '/cumulative-cgpa-calculator/', icon: 'sum' },
    { title: 'Semester grade', sub: 'Work out a course grade from your scores.', href: '/semester-grade-calculator/', icon: 'calc' },
  ],

  sample: {
    scale: 'standard',
    prior: { gpa: '', credits: '' },
    terms: [
      { name: 'Fall', rows: [
        { name: 'ENG 101: English Composition', grade: 'A', credits: '3' },
        { name: 'MATH 121: Calculus I', grade: 'B+', credits: '4', major: true },
        { name: 'PSY 201: Intro to Psychology', grade: 'A-', credits: '3' },
        { name: 'BIO 110: General Biology', grade: 'C+', credits: '2' },
      ] },
      { name: 'Spring', rows: [
        { name: 'MATH 122: Calculus II', grade: 'B', credits: '4', major: true },
        { name: 'CHEM 101: General Chemistry', grade: 'B-', credits: '4' },
        { name: 'HIST 110: World History', grade: 'A', credits: '3' },
        { name: 'COMM 120: Public Speaking', grade: 'A-', credits: '3' },
      ] },
    ],
  },

  copy: {
    onboard: 'Pick a grade and credits for each class. Your GPA updates as you go.',
    resultKicker: 'Cumulative GPA',
    termKicker: 'This semester',
    pill: 'GPA',
    summaryUrl: 'gpacalculator.net/college-gpa-calculator/',
  },
};
