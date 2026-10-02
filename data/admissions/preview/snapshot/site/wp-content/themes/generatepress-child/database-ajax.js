/**
 * College Database AJAX - Filtering, Search, Pagination
 * Vanilla JS (no jQuery dependency)
 *
 * Expects: gpa_db_ajax.ajax_url, gpa_db_ajax.nonce, gpa_db_ajax.per_page,
 *          gpa_db_ajax.total_colleges, gpa_db_ajax.total_pages
 */
(function () {
    'use strict';

    // ============================================
    // State
    // ============================================
    var state = {
        search: '',
        quick_filter: 'all',
        ownership: '',
        acceptance_rate: '',
        gpa: '',
        sat: '',
        sort: 'name_asc',
        page: 1,
        maxPages: parseInt(gpa_db_ajax.total_pages, 10) || 1,
        totalColleges: parseInt(gpa_db_ajax.total_colleges, 10) || 0,
        showingCount: 0,
        loading: false,
        loadingMore: false
    };

    // ============================================
    // DOM References
    // ============================================
    var searchInput       = document.getElementById('db-search-input');
    var searchSpinner     = document.getElementById('db-search-spinner');
    var quickPills        = document.getElementById('db-quick-pills');
    var filtersToggle     = document.getElementById('db-filters-toggle');
    var filtersPanel      = document.getElementById('db-filters-panel');
    var filtersReset      = document.getElementById('db-filters-reset');
    var ownershipSelect   = document.getElementById('db-filter-ownership');
    var acceptanceSelect  = document.getElementById('db-filter-acceptance');
    var gpaSelect         = document.getElementById('db-filter-gpa');
    var satSelect         = document.getElementById('db-filter-sat');
    var sortSelect        = document.getElementById('db-filter-sort');
    var collegeGrid       = document.getElementById('db-college-grid');
    var showingCountEl    = document.getElementById('db-showing-count');
    var totalCountEl      = document.getElementById('db-total-count');
    var noResults         = document.getElementById('db-no-results');
    var noResultsReset    = document.getElementById('db-no-results-reset');
    var loadMoreWrap      = document.getElementById('db-load-more-wrap');
    var loadMoreBtn       = document.getElementById('db-load-more-btn');
    var skeletonTemplate  = document.getElementById('db-skeleton-card-template');

    // ============================================
    // Utility: Debounce
    // ============================================
    function debounce(fn, delay) {
        var timer = null;
        return function () {
            var context = this;
            var args = arguments;
            if (timer) clearTimeout(timer);
            timer = setTimeout(function () {
                fn.apply(context, args);
            }, delay);
        };
    }

    // ============================================
    // Utility: Build query string from state
    // ============================================
    function buildParams() {
        var params = {
            action: 'filter_colleges',
            nonce: gpa_db_ajax.nonce,
            page: state.page,
            per_page: gpa_db_ajax.per_page
        };

        if (state.search) params.search = state.search;
        if (state.quick_filter && state.quick_filter !== 'all') params.quick_filter = state.quick_filter;
        if (state.ownership) params.ownership = state.ownership;
        if (state.acceptance_rate) params.acceptance_rate = state.acceptance_rate;
        if (state.gpa) params.gpa = state.gpa;
        if (state.sat) params.sat = state.sat;
        if (state.sort) params.sort = state.sort;

        return params;
    }

    // ============================================
    // Utility: Serialize params to form data string
    // ============================================
    function serializeParams(params) {
        var parts = [];
        for (var key in params) {
            if (params.hasOwnProperty(key)) {
                parts.push(encodeURIComponent(key) + '=' + encodeURIComponent(params[key]));
            }
        }
        return parts.join('&');
    }

    // ============================================
    // URL State Management
    // ============================================
    function updateURLParams() {
        var params = new URLSearchParams();

        if (state.search) params.set('search', state.search);
        if (state.quick_filter && state.quick_filter !== 'all') params.set('filter', state.quick_filter);
        if (state.ownership) params.set('ownership', state.ownership);
        if (state.acceptance_rate) params.set('acceptance', state.acceptance_rate);
        if (state.gpa) params.set('gpa', state.gpa);
        if (state.sat) params.set('sat', state.sat);
        if (state.sort && state.sort !== 'name_asc') params.set('sort', state.sort);

        var queryString = params.toString();
        var newUrl = window.location.pathname + (queryString ? '?' + queryString : '');

        window.history.replaceState(null, '', newUrl);
    }

    function readURLParams() {
        var params = new URLSearchParams(window.location.search);

        if (params.has('search')) {
            state.search = params.get('search');
            if (searchInput) searchInput.value = state.search;
        }
        if (params.has('filter')) {
            state.quick_filter = params.get('filter');
            setActivePill(state.quick_filter);
        }
        if (params.has('ownership')) {
            state.ownership = params.get('ownership');
            if (ownershipSelect) ownershipSelect.value = state.ownership;
        }
        if (params.has('acceptance')) {
            state.acceptance_rate = params.get('acceptance');
            if (acceptanceSelect) acceptanceSelect.value = state.acceptance_rate;
        }
        if (params.has('gpa')) {
            state.gpa = params.get('gpa');
            if (gpaSelect) gpaSelect.value = state.gpa;
        }
        if (params.has('sat')) {
            state.sat = params.get('sat');
            if (satSelect) satSelect.value = state.sat;
        }
        if (params.has('sort')) {
            state.sort = params.get('sort');
            if (sortSelect) sortSelect.value = state.sort;
        }
    }

    // ============================================
    // Set active quick filter pill
    // ============================================
    function setActivePill(filter) {
        if (!quickPills) return;
        var pills = quickPills.querySelectorAll('.db-archive-pill');
        for (var i = 0; i < pills.length; i++) {
            if (pills[i].getAttribute('data-filter') === filter) {
                pills[i].classList.add('db-archive-pill--active');
            } else {
                pills[i].classList.remove('db-archive-pill--active');
            }
        }
    }

    // ============================================
    // Show/hide loading states
    // ============================================
    function showSearchSpinner() {
        if (searchSpinner) searchSpinner.style.display = 'flex';
    }

    function hideSearchSpinner() {
        if (searchSpinner) searchSpinner.style.display = 'none';
    }

    function showSkeletonCards() {
        if (!skeletonTemplate || !collegeGrid) return;
        var count = 6;
        for (var i = 0; i < count; i++) {
            var clone = skeletonTemplate.content.cloneNode(true);
            collegeGrid.appendChild(clone);
        }
    }

    function removeSkeletonCards() {
        if (!collegeGrid) return;
        var skeletons = collegeGrid.querySelectorAll('.db-college-card--skeleton');
        for (var i = 0; i < skeletons.length; i++) {
            skeletons[i].remove();
        }
    }

    // ============================================
    // Update results count display
    // ============================================
    function updateResultsCount(showing, total) {
        if (showingCountEl) showingCountEl.textContent = showing;
        if (totalCountEl) totalCountEl.textContent = total.toLocaleString();
    }

    // ============================================
    // Toggle load more button visibility
    // ============================================
    function updateLoadMoreVisibility() {
        if (!loadMoreWrap || !loadMoreBtn) return;
        if (state.page >= state.maxPages) {
            loadMoreWrap.style.display = 'none';
        } else {
            loadMoreWrap.style.display = 'flex';
        }
    }

    // ============================================
    // Toggle no results visibility
    // ============================================
    function updateNoResultsVisibility(hasResults) {
        if (!noResults) return;
        noResults.style.display = hasResults ? 'none' : 'block';
        if (collegeGrid) collegeGrid.style.display = hasResults ? 'grid' : 'none';
        if (loadMoreWrap) loadMoreWrap.style.display = hasResults ? '' : 'none';
        if (hasResults) {
            updateLoadMoreVisibility();
        }
    }

    // ============================================
    // AJAX Request: Filter/Search Colleges
    // ============================================
    function fetchColleges(append) {
        if (state.loading) return;
        state.loading = true;

        var params = buildParams();
        var body = serializeParams(params);

        if (append) {
            state.loadingMore = true;
            showSkeletonCards();
            setLoadMoreLoading(true);
        } else {
            showSearchSpinner();
        }

        var xhr = new XMLHttpRequest();
        xhr.open('POST', gpa_db_ajax.ajax_url, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');

        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) return;

            state.loading = false;
            hideSearchSpinner();

            if (append) {
                removeSkeletonCards();
                setLoadMoreLoading(false);
                state.loadingMore = false;
            }

            if (xhr.status === 200) {
                try {
                    var response = JSON.parse(xhr.responseText);

                    if (response.success && response.data) {
                        var data = response.data;

                        if (append) {
                            // Append new cards to the grid
                            var tempDiv = document.createElement('div');
                            tempDiv.innerHTML = data.html;
                            var newCards = tempDiv.children;
                            while (newCards.length > 0) {
                                collegeGrid.appendChild(newCards[0]);
                            }
                        } else {
                            // Replace grid content
                            if (collegeGrid) collegeGrid.innerHTML = data.html;
                        }

                        // Update state
                        state.maxPages = parseInt(data.max_pages, 10) || 1;
                        state.totalColleges = parseInt(data.total, 10) || 0;

                        // Count visible cards
                        var visibleCards = collegeGrid ? collegeGrid.querySelectorAll('.db-college-card:not(.db-college-card--skeleton)') : [];
                        state.showingCount = visibleCards.length;

                        // Update UI
                        updateResultsCount(state.showingCount, state.totalColleges);
                        updateNoResultsVisibility(state.showingCount > 0);
                        updateLoadMoreVisibility();

                        // Update load more button data attributes
                        if (loadMoreBtn) {
                            loadMoreBtn.setAttribute('data-page', state.page);
                            loadMoreBtn.setAttribute('data-max-pages', state.maxPages);
                        }
                    } else {
                        // No results or error in response
                        if (!append) {
                            if (collegeGrid) collegeGrid.innerHTML = '';
                            updateResultsCount(0, 0);
                            updateNoResultsVisibility(false);
                        }
                    }
                } catch (e) {
                    console.error('Database AJAX parse error:', e);
                    if (!append) {
                        if (collegeGrid) collegeGrid.innerHTML = '';
                        updateNoResultsVisibility(false);
                    }
                }
            } else {
                console.error('Database AJAX request failed:', xhr.status);
            }
        };

        xhr.send(body);
    }

    // ============================================
    // Load More: set loading state on button
    // ============================================
    function setLoadMoreLoading(isLoading) {
        if (!loadMoreBtn) return;
        var textEl = loadMoreBtn.querySelector('.db-archive-load-more__text');
        var spinnerEl = loadMoreBtn.querySelector('.db-archive-load-more__spinner');

        if (isLoading) {
            loadMoreBtn.disabled = true;
            if (textEl) textEl.style.display = 'none';
            if (spinnerEl) spinnerEl.style.display = 'inline-flex';
        } else {
            loadMoreBtn.disabled = false;
            if (textEl) textEl.style.display = 'inline';
            if (spinnerEl) spinnerEl.style.display = 'none';
        }
    }

    // ============================================
    // Trigger a fresh filter (resets to page 1)
    // ============================================
    function triggerFilter() {
        state.page = 1;
        updateURLParams();
        fetchColleges(false);
    }

    // ============================================
    // Reset all filters to defaults
    // ============================================
    function resetAllFilters() {
        state.search = '';
        state.quick_filter = 'all';
        state.ownership = '';
        state.acceptance_rate = '';
        state.gpa = '';
        state.sat = '';
        state.sort = 'name_asc';
        state.page = 1;

        // Reset DOM elements
        if (searchInput) searchInput.value = '';
        if (ownershipSelect) ownershipSelect.value = '';
        if (acceptanceSelect) acceptanceSelect.value = '';
        if (gpaSelect) gpaSelect.value = '';
        if (satSelect) satSelect.value = '';
        if (sortSelect) sortSelect.value = 'name_asc';

        setActivePill('all');
        updateURLParams();
        fetchColleges(false);
    }

    // ============================================
    // Event Listeners
    // ============================================

    // --- Search input with debounce ---
    if (searchInput) {
        searchInput.addEventListener('input', debounce(function () {
            state.search = searchInput.value.trim();
            triggerFilter();
        }, 300));

        // Allow Enter key to trigger search immediately
        searchInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                state.search = searchInput.value.trim();
                triggerFilter();
            }
        });
    }

    // --- Quick filter pills ---
    if (quickPills) {
        quickPills.addEventListener('click', function (e) {
            var pill = e.target.closest('.db-archive-pill');
            if (!pill) return;

            var filter = pill.getAttribute('data-filter');
            state.quick_filter = filter;

            // If a specific quick filter is chosen, clear advanced ownership to avoid conflict
            if (filter === 'public') {
                state.ownership = 'public';
                if (ownershipSelect) ownershipSelect.value = 'public';
            } else if (filter === 'private') {
                state.ownership = 'private';
                if (ownershipSelect) ownershipSelect.value = 'private';
            } else if (filter === 'all') {
                state.ownership = '';
                if (ownershipSelect) ownershipSelect.value = '';
            }

            setActivePill(filter);
            triggerFilter();
        });
    }

    // --- Advanced filters toggle ---
    if (filtersToggle && filtersPanel) {
        filtersToggle.addEventListener('click', function () {
            var isVisible = filtersPanel.style.display !== 'none';
            filtersPanel.style.display = isVisible ? 'none' : 'block';
            filtersToggle.classList.toggle('db-archive-filters__toggle--active', !isVisible);
        });
    }

    // --- Filter dropdowns ---
    if (ownershipSelect) {
        ownershipSelect.addEventListener('change', function () {
            state.ownership = ownershipSelect.value;
            // Sync quick pill if ownership matches
            if (state.ownership === 'public') {
                state.quick_filter = 'public';
                setActivePill('public');
            } else if (state.ownership === 'private') {
                state.quick_filter = 'private';
                setActivePill('private');
            } else {
                state.quick_filter = 'all';
                setActivePill('all');
            }
            triggerFilter();
        });
    }

    if (acceptanceSelect) {
        acceptanceSelect.addEventListener('change', function () {
            state.acceptance_rate = acceptanceSelect.value;
            triggerFilter();
        });
    }

    if (gpaSelect) {
        gpaSelect.addEventListener('change', function () {
            state.gpa = gpaSelect.value;
            triggerFilter();
        });
    }

    if (satSelect) {
        satSelect.addEventListener('change', function () {
            state.sat = satSelect.value;
            triggerFilter();
        });
    }

    if (sortSelect) {
        sortSelect.addEventListener('change', function () {
            state.sort = sortSelect.value;
            triggerFilter();
        });
    }

    // --- Reset filters button ---
    if (filtersReset) {
        filtersReset.addEventListener('click', resetAllFilters);
    }

    // --- No results reset button ---
    if (noResultsReset) {
        noResultsReset.addEventListener('click', resetAllFilters);
    }

    // --- Load more button ---
    if (loadMoreBtn) {
        loadMoreBtn.addEventListener('click', function () {
            if (state.loadingMore) return;
            if (state.page >= state.maxPages) return;

            state.page++;
            fetchColleges(true);
        });
    }

    // ============================================
    // Initialize: read URL params and fetch if needed
    // ============================================
    function init() {
        // Count initial cards rendered by PHP
        var initialCards = collegeGrid ? collegeGrid.querySelectorAll('.db-college-card') : [];
        state.showingCount = initialCards.length;

        // Only fire AJAX if a recognized filter/search param is present.
        // Unrelated params (cache-bust _cb, analytics utm_*, fbclid, etc.) must not wipe PHP-rendered cards.
        var known = ['search', 'filter', 'ownership', 'acceptance', 'gpa', 'sat', 'sort'];
        var params = new URLSearchParams(window.location.search);
        var hasKnownParam = false;
        for (var i = 0; i < known.length; i++) {
            if (params.has(known[i])) { hasKnownParam = true; break; }
        }
        if (hasKnownParam) {
            readURLParams();
            fetchColleges(false);
        } else {
            // Initial state is already rendered by PHP
            updateLoadMoreVisibility();
        }
    }

    // Run init when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
