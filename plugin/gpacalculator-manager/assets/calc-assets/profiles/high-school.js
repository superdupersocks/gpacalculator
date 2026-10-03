/* High school GPA profile: weighted and unweighted GPA with course levels (Honors +0.5, AP / IB / Dual
 * Enrollment +1.0). Used by the Homepage's high school mode; the High School page moves onto it next.
 * Everything here is data or copy; the math lives in engines/gpa-engine.js and the screen in gpa/gpa-app.js. */
import { guessLevel, suggestCourses } from './hs-courses.js';

const GRADES = { 'A+': 4, A: 4, 'A-': 3.7, 'B+': 3.3, B: 3, 'B-': 2.7, 'C+': 2.3, C: 2, 'C-': 1.7, 'D+': 1.3, D: 1, 'D-': 0.7, F: 0 };

export default {
  id: 'high-school',
  engine: 'gpa',
  schema: 1,
  prefix: 'hs',
  hashKey: 'hsgpa',
  storeKey: 'gpac:high-school:v1',
  url: '/high-school-gpa-calculator/',
  title: 'High School GPA',
  decimals: 2,
  creditWord: 'credits',
  creditMax: 10,
  defaultCredits: '1',
  creditChoices: ['0.5', '1'], // phone quick buttons (+ Other)
  termWord: 'Year',
  termNames: ['9th grade', '10th grade', '11th grade', '12th grade'],
  major: false,
  repeat: 'none',
  prior: true,
  planner: true,
  whatIf: true,
  scales: [{ id: 'standard', label: '4.0 scale', grades: GRADES, max: 4 }],
  defaultScale: 'standard',

  // Course levels: the weighted GPA adds the boost to every passing grade (an F stays 0).
  levels: { reg: 0, hon: 0.5, ap: 1, ib: 1, de: 1 },
  levelList: [
    { id: 'reg', label: 'Regular', short: 'Reg' },
    { id: 'hon', label: 'Honors', short: 'Hon' },
    { id: 'ap', label: 'AP', short: 'AP' },
    { id: 'ib', label: 'IB', short: 'IB' },
    { id: 'de', label: 'Dual Enrollment', short: 'DE' },
  ],
  // Course features (Calculator Design Standard, opt-in): name suggestions with nicknames, and the level set
  // from the course name unless the student picked one by hand.
  courses: { suggest: suggestCourses, guessLevel },

  courseHint: 'e.g. English 10',
  coursePlaceholders: ['e.g. English 10', 'e.g. AP Biology', 'e.g. Algebra 2', 'e.g. Honors Chemistry', 'e.g. US History', 'e.g. Spanish 2'],

  blocks: ['verdict', 'stats', 'next', 'how'],
  next: {
    planLabel: 'Plan next year’s grades',
    links: [
      { label: 'Convert my GPA to a percentage', href: '/gpa-scale/', withGpa: true },
      { label: 'How to raise your GPA', href: '/how-to-raise-gpa/' },
    ],
  },
  keepGoing: [
    { title: 'Weighted GPA', sub: 'How Honors and AP classes raise your GPA.', href: '/weighted-gpa-calculator/', icon: 'trend' },
    { title: 'Raise your GPA', sub: 'See what it takes to move your GPA up a step.', href: '/how-to-raise-gpa/', icon: 'target' },
    { title: 'College GPA', sub: 'Semester and cumulative GPA for college.', href: '/college-gpa-calculator/', icon: 'calc' },
  ],
  goalFromTarget: true,
  upcomingDefault: 6,

  // "Is my GPA good?" for high school students (unweighted, 4.0 scale)
  goodText(x, g) {
    if (g >= 3.7) return `${x} is in the A range: competitive for selective colleges, especially with Honors and AP classes.`;
    if (g >= 3.3) return `${x} is a B+ average or better: above the 3.0 most colleges and many scholarships look for.`;
    if (g >= 3.0) return `${x} is a B average: it meets the 3.0 many colleges and scholarships ask for.`;
    if (g >= 2.5) return `${x} is a C+ to B− average: open admission and many state colleges accept it.`;
    if (g >= 2.0) return `${x} meets the 2.0 most high schools need for graduation and athletics.`;
    return `${x} is below 2.0, the usual minimum for athletics and many programs. The planner shows the way up.`;
  },

  sample: {
    scale: 'standard',
    prior: { gpa: '', credits: '' },
    terms: [
      { name: '9th grade', rows: [
        { name: 'English 9', grade: 'A-', credits: '1' },
        { name: 'Algebra 1', grade: 'B+', credits: '1' },
        { name: 'Honors Biology', grade: 'A', credits: '1', level: 'hon' },
        { name: 'World History', grade: 'B', credits: '1' },
        { name: 'Spanish 1', grade: 'A', credits: '1' },
        { name: 'PE', grade: 'A', credits: '0.5' },
      ] },
      { name: '10th grade', rows: [
        { name: 'Honors English 10', grade: 'B+', credits: '1', level: 'hon' },
        { name: 'Geometry', grade: 'B', credits: '1' },
        { name: 'AP World History: Modern', grade: 'A-', credits: '1', level: 'ap' },
        { name: 'Chemistry', grade: 'B+', credits: '1' },
        { name: 'Spanish 2', grade: 'A-', credits: '1' },
        { name: 'Health', grade: 'A', credits: '0.5' },
      ] },
    ],
  },

  copy: {
    onboard: 'Pick a grade for each class and its level. Your weighted and unweighted GPA update as you go.',
    resultKicker: 'Cumulative GPA',
    termResultKicker: 'GPA',
    pill: 'GPA',
    summaryUrl: 'gpacalculator.net',
  },
};
