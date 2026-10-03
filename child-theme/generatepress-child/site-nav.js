/* Site header + footer navigation behaviour (site-nav.php).
   Footer: columns are <details open> in the HTML; on phones (≤768px) they start closed, on wider
   screens they stay open and their titles don't toggle. Header: dropdown parents report
   aria-expanded and open with Enter/Space/ArrowDown, close with Escape. */
(function () {
	'use strict';
	var phone = window.matchMedia('(max-width: 768px)');

	/* ---------- Footer ---------- */
	var cols = Array.prototype.slice.call(document.querySelectorAll('.site-footer details.gpa-foot-col'));
	function syncFooter() {
		cols.forEach(function (d) {
			var s = d.querySelector('summary');
			d.open = !phone.matches;
			if (!s) return;
			if (phone.matches) s.removeAttribute('tabindex');
			else s.setAttribute('tabindex', '-1');
		});
	}
	cols.forEach(function (d) {
		var s = d.querySelector('summary');
		if (s) s.addEventListener('click', function (e) { if (!phone.matches) e.preventDefault(); });
	});
	syncFooter();
	if (phone.addEventListener) phone.addEventListener('change', syncFooter);
	else if (phone.addListener) phone.addListener(syncFooter);

	/* ---------- Header dropdowns ---------- */
	var nav = document.getElementById('site-navigation');
	if (!nav) return;
	var parents = Array.prototype.slice.call(nav.querySelectorAll('.main-nav > ul > li.menu-item-has-children'));
	var mobileNav = function () { return phone.matches || nav.classList.contains('toggled'); };
	var clickMode = nav.classList.contains('dropdown-click');

	parents.forEach(function (li, i) {
		var a = li.querySelector(':scope > a');
		var sub = li.querySelector(':scope > .sub-menu');
		if (!a || !sub) return;
		if (!sub.id) sub.id = 'gpa-sub-' + (i + 1);
		a.setAttribute('aria-controls', sub.id);
		a.setAttribute('aria-haspopup', 'true');

		var isOpen = function () {
			return li.classList.contains('sfHover') || sub.classList.contains('toggled-on') || li.matches(':hover');
		};
		var sync = function () { a.setAttribute('aria-expanded', isOpen() ? 'true' : 'false'); };
		var setOpen = function (open) {
			li.classList.toggle('sfHover', open);
			sync();
		};
		sync();
		new MutationObserver(sync).observe(li, { attributes: true, attributeFilter: ['class'] });
		new MutationObserver(sync).observe(sub, { attributes: true, attributeFilter: ['class'] });
		li.addEventListener('mouseenter', sync);
		li.addEventListener('mouseleave', function () { setTimeout(sync, 0); });

		var isHash = a.getAttribute('href') === '#';
		a.addEventListener('click', function (e) {
			if (mobileNav() || clickMode || !isHash) return;
			e.preventDefault();
			setOpen(!li.classList.contains('sfHover'));
		});
		a.addEventListener('keydown', function (e) {
			if (mobileNav() || clickMode) return;
			var k = e.key;
			if ((k === 'Enter' || k === ' ') && isHash) {
				e.preventDefault();
				setOpen(!li.classList.contains('sfHover'));
			} else if (k === 'ArrowDown') {
				e.preventDefault();
				setOpen(true);
				var first = sub.querySelector('a');
				if (first) first.focus();
			}
		});
		li.addEventListener('keydown', function (e) {
			if (e.key !== 'Escape' || mobileNav()) return;
			if (li.classList.contains('sfHover') || sub.contains(document.activeElement)) {
				e.preventDefault();
				setOpen(false);
				a.focus();
			}
		});
		sub.addEventListener('keydown', function (e) {
			if (mobileNav() || (e.key !== 'ArrowDown' && e.key !== 'ArrowUp')) return;
			var links = Array.prototype.slice.call(sub.querySelectorAll('a'));
			var at = links.indexOf(document.activeElement);
			if (at < 0) return;
			e.preventDefault();
			var next = links[(at + (e.key === 'ArrowDown' ? 1 : links.length - 1)) % links.length];
			next.focus();
		});
		li.addEventListener('focusout', function (e) {
			if (mobileNav()) return;
			if (!e.relatedTarget || !li.contains(e.relatedTarget)) setOpen(false);
		});
	});
})();
