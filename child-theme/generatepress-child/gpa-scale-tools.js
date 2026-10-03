/* /gpa-scale/ hub quick converter ([gpa_scale_converter], gpa-shortcodes.php).
   The server renders a working default; this only swaps the numbers and the link when the letter changes. */
(() => {
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
})();
