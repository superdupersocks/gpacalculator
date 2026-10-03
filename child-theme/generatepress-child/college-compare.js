/**
 * "How does your GPA compare?" on college pages (template v2, college-v2.php). Reads the college's figures from the
 * box's data-college attribute and places the student's GPA and optional test score against them.
 *
 * Rules (the admissions data rules): a GPA is compared only with a cited average whose basis the college stated, and
 * only on the same basis; otherwise the box says why it can't. The SAT range is the two section ranges added together
 * and is labeled as approximate. The verdict is a guide from reported figures, never a prediction.
 */
(function () {
    'use strict';

    var box = document.querySelector('.gpa-compare[data-college]');
    if (!box) {
        return;
    }
    var c;
    try {
        c = JSON.parse(box.getAttribute('data-college'));
    } catch (e) {
        return;
    }

    var gpaIn = box.querySelector('#gpa-compare-gpa');
    var scoreIn = box.querySelector('#gpa-compare-score');
    var verdictEl = box.querySelector('.gpa-compare__verdict strong');
    var textEl = box.querySelector('.gpa-compare__text');
    var rangeEl = box.querySelector('.gpa-compare__range');
    var state = { scale: 'unweighted', test: c.act ? 'ACT' : 'SAT' };

    function num(el) {
        if (!el) {
            return NaN;
        }
        var v = parseFloat(String(el.value).replace(',', '.'));
        return isNaN(v) ? NaN : v;
    }

    function fmt(n, d) {
        return Number(n).toFixed(d);
    }

    // Position of a score in the middle 50%: 0 below the 25th, 1 in the lower half, 2 in the upper half, 3 above the 75th
    function testPosition(score, p) {
        if (score > p[2]) {
            return 3;
        }
        if (score < p[0]) {
            return 0;
        }
        var mid = p[1] !== null ? p[1] : (p[0] + p[2]) / 2;
        return score >= mid ? 2 : 1;
    }

    function verdictFor(signal) {
        var r = c.rate;
        if (r === null) {
            return signal >= 0.75 ? 'Strong match' : (signal >= 0.45 ? 'Match' : 'Possible reach');
        }
        if (r < 15) {
            return 'Reach';
        }
        if (r < 25) {
            return signal >= 0.75 ? 'Possible match' : 'Reach';
        }
        if (r < 50) {
            return signal >= 0.75 ? 'Match' : (signal >= 0.45 ? 'Possible match' : 'Reach');
        }
        return signal >= 0.75 ? 'Strong match' : (signal >= 0.45 ? 'Match' : 'Possible reach');
    }

    function update() {
        var g = num(gpaIn);
        var s = num(scoreIn);
        var gOk = !isNaN(g) && g > 0 && g <= 5.5;
        var p = state.test === 'ACT' ? c.act : c.sat;
        var lim = state.test === 'ACT' ? [1, 36] : [400, 1600];
        var sOk = !!p && !isNaN(s) && s >= lim[0] && s <= lim[1];
        var parts = [];
        var signals = [];

        if (!gOk && !sOk) {
            verdictEl.textContent = 'Enter your GPA';
            rangeEl.hidden = true;
            return;
        }

        // GPA, only against a stated basis and on that basis
        if (gOk) {
            if (c.gpa !== null && state.scale === c.basis) {
                var diff = g - c.gpa;
                var pos = diff >= 0.15 ? 1 : (diff > -0.15 ? 0.6 : 0.2);
                signals.push(pos);
                parts.push('Your ' + state.scale + ' ' + fmt(g, 2) + ' is ' + (pos === 1 ? 'above' : (pos === 0.6 ? 'close to' : 'below')) +
                    ' the ' + c.basis + ' average of ' + fmt(c.gpa, 2) + ' that ' + c.name + ' reported for ' + c.gpaYear + '.');
            } else if (c.gpa !== null) {
                parts.push(c.name + ' reported a ' + c.basis + ' average (' + fmt(c.gpa, 2) + '), so a ' + state.scale +
                    ' GPA can’t be compared with it directly. Switch to ' + c.basis + ' if you know yours.');
            } else if (c.gpaUnstated) {
                parts.push(c.name + ' doesn’t say whether its average GPA is weighted or unweighted, so we can’t place your ' + fmt(g, 2) + ' against it.');
            } else {
                parts.push(c.name + ' hasn’t published an average GPA we could verify, so we can’t place your ' + fmt(g, 2) + ' against admitted students.');
            }
            if (state.scale === 'weighted' && g > 4 && c.basis !== 'weighted') {
                parts.push('Weighted GPAs above 4.0 can’t be compared with unweighted figures; use your unweighted GPA here.');
            }
        }

        // Test score against the middle 50% of enrolled students who sent one
        rangeEl.hidden = !sOk;
        if (sOk) {
            var tp = testPosition(s, p);
            signals.push([0.1, 0.5, 0.75, 1][tp]);
            var label = state.test === 'ACT' ? p[0] + '–' + p[2] : 'about ' + p[0] + '–' + p[2];
            parts.push('Your ' + state.test + ' of ' + s + ' is ' +
                ['below the middle 50% (' + label + ')', 'in the lower half of the middle 50% (' + label + ')',
                    'in the upper half of the middle 50% (' + label + ')', 'above the middle 50% (' + label + ')'][tp] +
                ' of first-year students who sent scores.' +
                (state.test === 'SAT' ? ' The SAT range is the two section ranges added together, so it’s approximate.' : '') +
                (tp === 0 && c.tests === 'optional' ? ' Scores are optional here, so you could apply without them.' : ''));
            var span = lim[1] - lim[0];
            var pct = function (v) { return Math.max(0, Math.min(100, (v - lim[0]) / span * 100)); };
            var band = rangeEl.querySelector('.gpa-compare__band');
            band.style.left = pct(p[0]) + '%';
            band.style.width = (pct(p[2]) - pct(p[0])) + '%';
            rangeEl.querySelector('.gpa-compare__you').style.left = pct(s) + '%';
            var ends = rangeEl.querySelectorAll('.gpa-compare__scale span');
            ends[0].textContent = lim[0];
            ends[1].textContent = 'Middle 50%: ' + label;
            ends[2].textContent = lim[1];
        }

        if (c.rate !== null) {
            parts.push(c.name + ' admitted ' + c.rateTxt + ' of applicants for ' + c.fall + '.');
        }
        if (!signals.length) {
            verdictEl.textContent = c.rate !== null && c.rate >= 75 ? 'Likely match' : (c.rate !== null && c.rate < 15 ? 'Reach' : 'Add a test score');
        } else {
            var avg = signals.reduce(function (a, b) { return a + b; }, 0) / signals.length;
            verdictEl.textContent = verdictFor(avg);
        }
        if (c.rate !== null && c.rate < 15) {
            parts.push('At a college this selective, strong grades and scores don’t guarantee admission.');
        }
        textEl.textContent = parts.join(' ');
    }

    function press(group, attr, value) {
        box.querySelectorAll('[' + attr + ']').forEach(function (b) {
            b.setAttribute('aria-pressed', b.getAttribute(attr) === value ? 'true' : 'false');
        });
    }

    box.addEventListener('click', function (e) {
        var b = e.target.closest('button');
        if (!b) {
            return;
        }
        if (b.hasAttribute('data-scale')) {
            state.scale = b.getAttribute('data-scale');
            press(box, 'data-scale', state.scale);
        } else if (b.hasAttribute('data-test')) {
            state.test = b.getAttribute('data-test');
            press(box, 'data-test', state.test);
            if (scoreIn) {
                scoreIn.value = '';
                scoreIn.placeholder = state.test === 'ACT' ? 'e.g. 26' : 'e.g. 1200';
            }
        }
        update();
    });
    box.addEventListener('input', update);
})();
