/* GPA scale tools (gpa-shortcodes.php): the hub's quick converter and, on /gpa-scale/<x-y>-gpa/ pages, the
   Unweighted | Weighted view ([gpa_scale_view]) with its mini converter and the chart mark.
   Everything is rendered by the server first; this only reacts to input, so nothing shifts on load. */
(() => {
  // Site-wide rule, same as gpa_scale_figures() in PHP: nearest chart letter (both on a tie), percentage
  // interpolated between the chart letters' range midpoints, chart values show their range.
  const POINTS = [[4.0, 'A', 94.5, '93–100%'], [3.7, 'A−', 91, '90–92%'], [3.3, 'B+', 88, '87–89%'],
    [3.0, 'B', 84.5, '83–86%'], [2.7, 'B−', 81, '80–82%'], [2.3, 'C+', 78, '77–79%'], [2.0, 'C', 74.5, '73–76%'],
    [1.7, 'C−', 71, '70–72%'], [1.3, 'D+', 68, '67–69%'], [1.0, 'D', 65.5, '65–66%'], [0.7, 'D−', 62, '60–64%'],
    [0.0, 'F', 50, 'Below 60%']];
  const figures = (gpa) => {
    const g = Math.round(gpa * 100) / 100;
    const exact = POINTS.find((p) => Math.abs(p[0] - g) < 0.001);
    if (exact) return { letter: exact[1], pct: exact[3] };
    for (let i = 0; i < POINTS.length - 1; i++) {
      const [hi, hl, hp] = POINTS[i];
      const [lo, ll, lp] = POINTS[i + 1];
      if (g < hi && g > lo) {
        const dh = hi - g; const dl = g - lo;
        const letter = Math.abs(dh - dl) < 0.001 ? `${ll}/${hl}` : (dh < dl ? hl : ll);
        return { letter, pct: `≈${Math.floor(lp + ((hp - lp) * (g - lo)) / (hi - lo) + 0.5)}%` };
      }
    }
    return { letter: 'F', pct: 'Below 60%' };
  };

  // Hub quick converter.
  document.querySelectorAll('[data-gpa-quickconv]').forEach((box) => {
    const select = box.querySelector('select');
    const out = (name) => box.querySelector(`[data-out="${name}"]`);
    if (!select) return;
    select.addEventListener('change', () => {
      const o = select.selectedOptions[0];
      out('points').textContent = o.dataset.points;
      out('range').textContent = o.dataset.range;
      out('gpa').textContent = o.dataset.points;
      const link = out('link');
      if (o.dataset.url) { link.href = o.dataset.url; link.hidden = false; } else { link.hidden = true; }
    });
  });

  // GPA page view toggle + weighted mini converter.
  document.querySelectorAll('[data-gpa-view]').forEach((view) => {
    const gpa = parseFloat(view.dataset.gpa);
    const title = document.querySelector('.entry-header h1, h1.entry-title');
    const baseTitle = title ? title.textContent : '';
    const table = document.querySelector('.gpa-scale-table');
    const rows = table ? [...table.querySelectorAll('tbody tr[data-letter]')] : [];
    const inputs = Object.fromEntries([...view.querySelectorAll('[data-in]')].map((i) => [i.dataset.in, i]));
    const out = (name) => view.querySelector(`[data-out="${name}"]`);

    const mark = (value, label) => {
      const { letter, pct } = figures(value);
      const want = letter.split('/');
      if (Math.abs(value - 4) < 0.001) want.push('A+');
      rows.forEach((tr) => {
        const on = want.includes(tr.dataset.letter);
        tr.classList.toggle('is-current-gpa', on);
        if (on) tr.setAttribute('aria-current', 'true'); else tr.removeAttribute('aria-current');
        const td = tr.querySelector('td');
        if (on) td.dataset.mark = `${label} · ${pct}`; else delete td.dataset.mark;
      });
    };
    const estimate = () => {
      const n = (k) => Math.max(0, parseInt(inputs[k] && inputs[k].value, 10) || 0);
      const total = n('total');
      const boost = total ? (Math.min(n('ap'), total) * 1 + Math.min(n('honors'), total) * 0.5) / total : 0;
      const uw = Math.min(4, Math.max(0, gpa - boost));
      const f = figures(uw);
      out('uw').textContent = uw.toFixed(2);
      out('letter').textContent = f.letter;
      out('pct').textContent = f.pct;
      return uw;
    };
    const show = (which) => {
      view.querySelectorAll('[data-view]').forEach((p) => { p.hidden = p.dataset.view !== which; });
      view.querySelectorAll('[data-view-btn]').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.viewBtn === which)));
      view.dataset.active = which;
      if (title) title.textContent = which === 'weighted' ? baseTitle.replace(/(\d\.\d)\s*GPA/, '$1 Weighted GPA') : baseTitle;
      if (which === 'weighted') { const uw = estimate(); mark(uw, `Est. ${uw.toFixed(2)}`); } else { mark(gpa, `Your ${gpa.toFixed(1)}`); }
    };
    view.querySelectorAll('[data-view-btn]').forEach((b) => b.addEventListener('click', () => show(b.dataset.viewBtn)));
    Object.values(inputs).forEach((i) => i.addEventListener('input', () => {
      if (view.dataset.active === 'weighted') { const uw = estimate(); mark(uw, `Est. ${uw.toFixed(2)}`); }
    }));
    if (gpa > 4) {
      // Weighted-only page (4.1+): no toggle; the chart marks the estimated unweighted GPA.
      const uw = estimate(); mark(uw, `Est. ${uw.toFixed(2)}`);
    } else if (location.hash === '#weighted') {
      show('weighted');
    }
  });
})();
