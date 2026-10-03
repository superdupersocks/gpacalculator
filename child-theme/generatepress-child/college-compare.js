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
    var youEl = box.querySelector('.gpa-compare__you-label');
    var noteEl = box.querySelector('.gpa-compare__note');
    var fitsEl = box.querySelector('.gpa-compare__fits');
    var fitsBtn = box.querySelector('.gpa-compare__cta--fits');
    var planBtn = box.querySelector('.gpa-compare__cta--plan');
    var bands = fitsBtn ? (fitsBtn.getAttribute('data-bands') || '').split(',') : [];
    var hub = fitsBtn ? fitsBtn.getAttribute('href') : '';
    var staticText = textEl.textContent;
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

    // The result text's key figures in bold: hi() marks them, setRich() writes the text as text nodes and <strong>s
    function hi(text) {
        return '\u0001' + text + '\u0002';
    }

    function setRich(el, text) {
        el.textContent = '';
        text.split(/(\u0001[^\u0002]*\u0002)/).forEach(function (bit) {
            if (bit.charAt(0) === '\u0001') {
                var b = document.createElement('strong');
                b.textContent = bit.slice(1, -1);
                el.appendChild(b);
            } else if (bit !== '') {
                el.appendChild(document.createTextNode(bit));
            }
        });
    }

    // "a weighted", "an unweighted"
    function an(word) {
        return (/^[aeiou]/i.test(word) ? 'an ' : 'a ') + word;
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

        // "Colleges where a 3.5 fits": the hub's list for the GPA typed, rounded to a tenth (/admissions/?gpa=3.5), shown
        // only when that GPA has a list; an unweighted GPA can't be above 4.0
        if (fitsBtn) {
            var key = gOk ? (Math.round(g * 10) / 10).toFixed(1) : '';
            var show = key !== '' && bands.indexOf(key) !== -1 && !(state.scale === 'unweighted' && g > 4);
            fitsBtn.hidden = !show;
            if (show) {
                fitsEl.textContent = 'a ' + key;
                fitsBtn.setAttribute('href', hub + (hub.indexOf('?') === -1 ? '?' : '&') + 'gpa=' + key);
            }
            planBtn.classList.toggle('gpa-compare__cta--primary', !show);
        }
        noteEl.hidden = !(gOk && state.scale === 'weighted' && g > 4 && c.basis !== 'weighted');

        if (!gOk && !sOk) {
            verdictEl.textContent = 'Enter your GPA';
            textEl.textContent = staticText;
            rangeEl.hidden = true;
            return;
        }

        // GPA, only against a stated basis and on that basis
        if (gOk) {
            if (c.gpa !== null && state.scale === c.basis) {
                var diff = g - c.gpa;
                var pos = diff >= 0.15 ? 1 : (diff > -0.15 ? 0.6 : 0.2);
                signals.push(pos);
                parts.push('Your ' + state.scale + ' ' + fmt(g, 2) + ' is ' + hi(pos === 1 ? 'above' : (pos === 0.6 ? 'close to' : 'below')) +
                    ' the ' + c.basis + ' average of ' + hi(fmt(c.gpa, 2)) + ' that ' + c.name + ' reported for ' + c.gpaYear + '.');
            } else if (c.gpa !== null) {
                parts.push(c.name + ' reported ' + an(c.basis) + ' average (' + fmt(c.gpa, 2) + '), so ' + an(state.scale) +
                    ' GPA can’t be compared with it directly. Switch to ' + c.basis + ' if you know yours.');
            } else if (c.gpaUnstated) {
                parts.push(c.name + ' doesn’t say whether its average GPA is weighted or unweighted, so we can’t place your ' + fmt(g, 2) + ' against it.');
            } else {
                parts.push(c.name + ' hasn’t published an average GPA we could verify, so we can’t place your ' + fmt(g, 2) + ' against admitted students.');
            }
        }

        // Test score against the middle 50% of enrolled students who sent one
        rangeEl.hidden = !sOk;
        if (sOk) {
            var tp = testPosition(s, p);
            signals.push([0.1, 0.5, 0.75, 1][tp]);
            var label = state.test === 'ACT' ? p[0] + '–' + p[2] : 'about ' + p[0] + '–' + p[2];
            parts.push('Your ' + state.test + ' of ' + s + ' is ' +
                [hi('below') + ' the middle 50% (' + hi(label) + ')', hi('in the lower half') + ' of the middle 50% (' + hi(label) + ')',
                    hi('in the upper half') + ' of the middle 50% (' + hi(label) + ')', hi('above') + ' the middle 50% (' + hi(label) + ')'][tp] +
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
            ends[1].textContent = 'Middle 50% of enrolled students: ' + label;
            ends[2].textContent = lim[1];
            youEl.textContent = 'You: ' + s;
        }

        if (c.rate !== null) {
            parts.push(c.name + ' admitted ' + hi(c.rateTxt) + ' of applicants for ' + c.fall + '.');
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
        setRich(textEl, parts.join(' '));
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
