/* Homepage GPA profile (v2 proposal): one calculator for high school and college. Course, Grade, Credits
 * (default 1) and Level (default Regular); the level follows the course name ("AP Biology" -> AP) until
 * it is picked by hand. The headline is the unweighted GPA; the weighted one joins it once a course has a
 * level boost (the "5.0 weighted" scale leads with weighted). Grades can be typed as letters or percentages.
 * Everything here is data or copy; the math lives in engines/gpa-engine.js and the screen in gpa/gpa-app.js. */
import { guessLevel, suggestCourses } from './hs-courses.js?v=da1cb432a2';
import highSchool from './high-school.js?v=da1cb432a2';
import college from './college.js?v=da1cb432a2';

const BASE = { 'A+': 4, A: 4, 'A-': 3.7, 'B+': 3.3, B: 3, 'B-': 2.7, 'C+': 2.3, C: 2, 'C-': 1.7, 'D+': 1.3, D: 1, 'D-': 0.7, F: 0 };
const A433 = { 'A+': 4.33, A: 4, 'A-': 3.67, 'B+': 3.33, B: 3, 'B-': 2.67, 'C+': 2.33, C: 2, 'C-': 1.67, 'D+': 1.33, D: 1, 'D-': 0.67, F: 0 };
const NOT_COUNTED = { P: null, NP: null, W: null };
const NOTES = { P: 'P (pass) isn’t counted in GPA.', NP: 'NP (no pass) isn’t counted in GPA.', W: 'W (withdrawn) isn’t counted in GPA.' };

export default {
  id: 'home',
  engine: 'gpa',
  schema: 1,
  prefix: 'home', // GA4: home_result_shown, home_insight_tap, home_return_visit, home_result, home_sample …
  hashKey: 'hgpa',
  storeKey: 'gpac:home:v2',
  url: '/',
  title: 'GPA',
  decimals: 2,
  creditWord: 'credits',
  creditMax: 20,
  defaultCredits: '1',
  creditChoices: ['0.5', '1', '2', '3', '4'], // phone quick buttons (+ Other)
  termWord: 'Semester',
  major: false,
  repeat: 'none',
  prior: true,
  planner: true,
  whatIf: true,

  // The scale menu sits in the card header. 4.0 and 4.33 lead with unweighted; "5.0 weighted" leads with weighted.
  scaleMenu: true,
  scales: [
    { id: 'standard', short: '4.0', label: '4.0 scale', grades: { ...BASE, ...NOT_COUNTED }, notes: NOTES, max: 4 },
    { id: 'a-plus-433', short: '4.33', label: '4.33 scale (A+ = 4.33)', grades: { ...A433, ...NOT_COUNTED }, notes: NOTES, max: 4.33 },
    { id: 'weighted-5', short: '5.0 weighted', label: '5.0 weighted scale', grades: { ...BASE, ...NOT_COUNTED }, notes: NOTES, max: 4, weightedLead: true },
  ],
  defaultScale: 'standard',

  // Typed percentages, by the site's grade chart (A+ 97–100 and A 93–96 are both 4.0 on the 4.0 scale).
  percentGrades: [[97, 'A+'], [93, 'A'], [90, 'A-'], [87, 'B+'], [83, 'B'], [80, 'B-'], [77, 'C+'], [73, 'C'], [70, 'C-'], [67, 'D+'], [63, 'D'], [60, 'D-'], [0, 'F']],

  // Levels: the weighted GPA adds the boost to every passing grade (an F stays 0).
  levels: highSchool.levels,
  levelList: highSchool.levelList,
  courses: { suggest: suggestCourses, guessLevel },

  weightedBeside: true,
  insightLine: true,
  extraEvents: true,

  courseHint: 'e.g. English 10',
  coursePlaceholders: ['e.g. English 10', 'e.g. AP Biology', 'e.g. MATH 121', 'e.g. Honors Chemistry', 'e.g. PSY 201', 'e.g. Spanish 2'],

  blocks: ['verdict', 'stats', 'next', 'how'],
  next: {
    planLabel: 'Plan next semester’s grades',
    links: [
      { label: 'Convert my GPA to a percentage', href: '/gpa-scale/', withGpa: true },
      { label: 'How to raise your GPA', href: '/how-to-raise-gpa/' },
    ],
  },
  keepGoing: [
    { title: 'High school GPA', sub: 'Every year of high school, weighted and unweighted.', href: '/high-school-gpa-calculator/', icon: 'calc' },
    { title: 'College GPA', sub: 'Semester and cumulative GPA for college.', href: '/college-gpa-calculator/', icon: 'sum' },
    { title: 'Raise your GPA', sub: 'See what it takes to move your GPA up a step.', href: '/how-to-raise-gpa/', icon: 'trend' },
  ],
  goalFromTarget: true,
  upcomingDefault: 6,

  goodText(x, g) {
    if (g >= 3.7) return `${x} is in the A range: competitive for selective colleges, honors programs and most scholarships.`;
    if (g >= 3.3) return `${x} is a B+ average or better: above the 3.0 most colleges, scholarships and grad programs look for.`;
    if (g >= 3.0) return `${x} is a B average: it meets the 3.0 many colleges, scholarships and grad programs ask for.`;
    if (g >= 2.5) return `${x} is a C+ to B− average: above the 2.0 line, under the 3.0 many programs ask for.`;
    if (g >= 2.0) return `${x} meets the 2.0 most schools need for good standing, graduation and athletics.`;
    return `${x} is below 2.0, the usual minimum for good standing and athletics. The planner shows the way up.`;
  },

  // "Try a sample: High school · College". Some grades are typed as percentages to show that works.
  samples: [
    {
      id: 'high_school',
      label: 'High school',
      state: {
        scale: 'standard',
        prior: { gpa: '', credits: '' },
        terms: highSchool.sample.terms.map((t, ti) => ({
          name: t.name,
          rows: t.rows.map((r, ri) => (ti === 0 && ri === 1 ? { ...r, grade: 'B+', pct: '88' } : ti === 1 && ri === 3 ? { ...r, grade: 'B+', pct: '89' } : r)),
        })),
      },
    },
    {
      id: 'college',
      label: 'College',
      state: {
        scale: 'standard',
        prior: { gpa: '', credits: '' },
        terms: college.sample.terms.map((t, ti) => ({
          name: t.name,
          rows: t.rows.map(({ major, ...r }, ri) => (ti === 0 && ri === 0 ? { ...r, grade: 'A', pct: '94' } : r)),
        })),
      },
    },
  ],

  // The old homepage (Bolt) kept one draft and one save list for both levels; copied once, only read.
  legacy: null, // set by the entry (gpa/home-v2.js), which knows the old keys

  copy: {
    onboard: 'Add each class with its grade. Type a letter or a percentage; AP, Honors and IB raise your weighted GPA.',
    resultKicker: 'Cumulative GPA',
    termResultKicker: 'GPA',
    pill: 'GPA',
    summaryUrl: 'gpacalculator.net',
  },
};
