/* Copyright (C) 2026 Zachary Melo <zach@digitalproperties.works>
 *
 * Standalone catalogue browser.
 *
 * Shares ajax/catalog.php with the in-document picker, so navigation, search
 * and favourites behave identically in both. The presentation differs on
 * purpose: the picker is a dense table optimised for selecting quickly, this is
 * a card grid optimised for looking around.
 */
(function () {
	'use strict';

	var cfgEl = document.getElementById('dolicatalog-browse-config');
	if (!cfgEl) { return; }

	var CFG;
	try {
		CFG = JSON.parse(cfgEl.textContent || cfgEl.innerText);
	} catch (e) {
		return;
	}

	var L = CFG.labels || {};

	var state = {
		view: 'browse',   // browse | search | favorites | recent
		category: 0,
		search: '',
		type: -1,
		warehouse: 0,
		// Archived products are hidden unless the user asks to see them.
		archived: 0,
		// Cross-cutting tag ids. Several OR with each other and AND with the
		// category, search and type filters.
		facets: [],
		// Attribute ids switched from "all" to "any". Per attribute, so one can
		// widen while the others keep narrowing.
		facetsAny: [],
		offset: 0,
		breadcrumb: [],
		seq: 0
	};

	function el(id) { return document.getElementById(id); }

	function label(key, fallback) {
		return (L[key] && L[key] !== key) ? L[key] : (fallback || key);
	}

	function make(tag, cls, text) {
		var n = document.createElement(tag);
		if (cls) { n.className = cls; }
		if (text !== undefined && text !== null) { n.textContent = String(text); }
		return n;
	}

	function clear(node) {
		while (node && node.firstChild) { node.removeChild(node.firstChild); }
	}

	function debounce(fn, wait) {
		var t = null;
		return function () {
			var a = arguments, s = this;
			clearTimeout(t);
			t = setTimeout(function () { fn.apply(s, a); }, wait);
		};
	}

	function money(v) {
		var n = parseFloat(v);
		if (isNaN(n)) { return ''; }
		try {
			return new Intl.NumberFormat(undefined, { style: 'currency', currency: CFG.currency || 'EUR' }).format(n);
		} catch (e) {
			return n.toFixed(2);
		}
	}

	// ------------------------------------------------------- history handling

	/*
	 * Navigating the catalogue never leaves the page, so without help the Back
	 * button skips the whole visit and leaves the page it lands on: a look at a
	 * product card costs you the folder you were in, and the walk back down from
	 * the root to reach it again.
	 *
	 * Every navigation therefore writes the state into the URL fragment. Back
	 * then walks the catalogue the way it walks any other site — out of a
	 * category, back through a tag, out of a search — and Forward walks it again.
	 * Returning from a product card lands in the folder you left.
	 *
	 * This is history handling and nothing more. Arriving at browse.php with a
	 * bare URL opens the root, as it always has; nothing is resumed from a
	 * previous visit, and nothing is stored on the server.
	 *
	 * Scroll offsets ride along per view, since landing at the top of a long list
	 * is the other half of what Back normally does for you on an ordinary page.
	 * They stay out of the fragment: a link that jumped someone 800px down a list
	 * would be a strange thing to send them.
	 */

	/** Scroll offsets, keyed by the same string the fragment carries. */
	var SCROLL_KEY = 'dolicatalog.browse.scroll';

	/** How many views keep an offset before the oldest is dropped. */
	var SCROLL_KEEP = 20;

	var VIEWS = ['browse', 'search', 'favorites', 'recent'];

	/**
	 * Read a JSON value out of session storage.
	 *
	 * @param  {string} key Storage key
	 * @return {*}          Parsed value, or null when absent or unreadable
	 */
	function stored(key) {
		try {
			var raw = window.sessionStorage.getItem(key);
			return raw ? JSON.parse(raw) : null;
		} catch (e) {
			// Private browsing refuses storage, and a truncated value throws on
			// parse. Either way there is simply nothing to restore.
			return null;
		}
	}

	/**
	 * Write a JSON value to session storage.
	 *
	 * @param  {string} key   Storage key
	 * @param  {*}      value Anything JSON can carry
	 * @return {void}
	 */
	function store(key, value) {
		try {
			window.sessionStorage.setItem(key, JSON.stringify(value));
		} catch (e) {
			// Remembering the place is a convenience, never a requirement.
		}
	}

	/**
	 * The navigable state, as a query string.
	 *
	 * breadcrumb and seq are left out: the endpoint rebuilds the first from the
	 * category, and the second only means anything within one page's lifetime.
	 *
	 * @return {string} Encoded state, empty at an untouched root
	 */
	function encodeState() {
		var q = new URLSearchParams();
		var listed = (state.view === 'browse' || state.view === 'search');

		if (state.view !== 'browse') { q.set('v', state.view); }
		if (listed && state.category > 0) { q.set('c', state.category); }
		if (state.search) { q.set('q', state.search); }
		if (state.type === 0 || state.type === 1) { q.set('t', state.type); }
		if (state.warehouse > 0) { q.set('w', state.warehouse); }
		if (state.archived) { q.set('a', '1'); }
		if (state.facets.length) { q.set('f', state.facets.join('.')); }
		if (state.facetsAny.length) { q.set('fa', state.facetsAny.join('.')); }
		if (state.offset > 0) { q.set('o', state.offset); }

		return q.toString();
	}

	/**
	 * Category ids from a dot-separated list, deduplicated.
	 *
	 * @param  {string} raw Encoded list
	 * @return {Array}      Positive integers
	 */
	function idList(raw) {
		var out = [];
		String(raw || '').split('.').forEach(function (part) {
			var n = parseInt(part, 10);
			if (n > 0 && out.indexOf(n) === -1) { out.push(n); }
		});
		return out;
	}

	/**
	 * Read a state back.
	 *
	 * Everything is treated as hostile: a fragment is user-editable and a stored
	 * value can outlive the shape that wrote it. Anything unrecognised falls back
	 * to its default rather than reaching the endpoint.
	 *
	 * @param  {string} encoded Query string from encodeState()
	 * @return {Object}         State fields
	 */
	function decodeState(encoded) {
		var q = new URLSearchParams(encoded || '');

		var view = q.get('v') || 'browse';
		if (VIEWS.indexOf(view) === -1) { view = 'browse'; }

		var search = (q.get('q') || '').trim();
		// The two have to agree. A search view with no term shows nothing, and a
		// term anywhere else would sit in the box being ignored.
		if (view === 'search' && !search) { view = 'browse'; }
		if (view === 'browse' && search) { view = 'search'; }
		if (view === 'favorites' || view === 'recent') { search = ''; }

		var type = parseInt(q.get('t'), 10);
		if (type !== 0 && type !== 1) { type = -1; }

		var listed = (view === 'browse' || view === 'search');

		return {
			view: view,
			search: search,
			category: listed ? Math.max(0, parseInt(q.get('c'), 10) || 0) : 0,
			type: type,
			warehouse: Math.max(0, parseInt(q.get('w'), 10) || 0),
			archived: q.get('a') === '1' ? 1 : 0,
			facets: idList(q.get('f')),
			facetsAny: idList(q.get('fa')),
			offset: Math.max(0, parseInt(q.get('o'), 10) || 0)
		};
	}

	/**
	 * Adopt a decoded state, toolbar included.
	 *
	 * @param  {Object} next Fields from decodeState()
	 * @return {void}
	 */
	function applyState(next) {
		state.view = next.view;
		state.category = next.category;
		state.search = next.search;
		state.type = next.type;
		state.warehouse = next.warehouse;
		state.archived = next.archived;
		state.facets = next.facets;
		state.facetsAny = next.facetsAny;
		state.offset = next.offset;
		syncControls();
	}

	/**
	 * Point the toolbar at the current state.
	 *
	 * Restoring a filter without moving the control that owns it would leave the
	 * page contradicting itself, which is worse than not restoring it at all.
	 *
	 * @return {void}
	 */
	function syncControls() {
		var search = el('dcb-search');
		if (search) { search.value = state.search; }

		var type = el('dcb-type');
		if (type) { type.value = String(state.type); }

		var archived = el('dcb-archived');
		if (archived) { archived.checked = !!state.archived; }

		var wh = document.querySelector('[name="dcb_warehouse"]');
		if (wh) {
			if (state.warehouse > 0) { wh.value = String(state.warehouse); }

			// The warehouse may have been deleted since, or belong to an entity
			// this user cannot see, in which case the select refuses the value.
			// Fall back to the option that means every warehouse — found by its
			// value rather than its position, which the core select owns.
			if (state.warehouse <= 0 || parseInt(wh.value, 10) !== state.warehouse) {
				var all = Array.prototype.filter.call(wh.options, function (o) {
					return !(parseInt(o.value, 10) > 0);
				})[0];
				if (all) { wh.value = all.value; }
				// Follow the select rather than filter by something it cannot show.
				state.warehouse = Math.max(0, parseInt(wh.value, 10) || 0);
			}
		}
	}

	/** @return {string} The URL fragment without its leading hash */
	function fragment() {
		var h = window.location.hash || '';
		return h.charAt(0) === '#' ? h.slice(1) : h;
	}

	/** @return {number} Current vertical scroll offset */
	function scrollNow() {
		return Math.round(window.pageYOffset || document.documentElement.scrollTop || 0);
	}

	/**
	 * The view currently on screen, empty while one is being fetched.
	 *
	 * Offsets are filed against this rather than against the live state: between
	 * a click and its response the state already describes where we are going,
	 * and replacing the list with one line of "Loading…" makes the page jump.
	 * Recording that would file the wrong offset against the wrong view.
	 */
	var renderedKey = '';

	/**
	 * File the current scroll offset against the view on screen.
	 *
	 * @return {void}
	 */
	function rememberScroll() {
		if (!renderedKey) { return; }

		var map = stored(SCROLL_KEY);
		if (!map || typeof map !== 'object') { map = {}; }

		// Deleted before it is written so the freshest view sorts last and the
		// stalest falls off the front once the map is full.
		var key = renderedKey;
		delete map[key];
		map[key] = scrollNow();

		var keys = Object.keys(map);
		while (keys.length > SCROLL_KEEP) { delete map[keys.shift()]; }

		store(SCROLL_KEY, map);
	}

	/**
	 * The offset filed against a view.
	 *
	 * @param  {string} key Encoded state
	 * @return {number}     Offset, 0 when none was kept
	 */
	function recallScroll(key) {
		var map = stored(SCROLL_KEY);
		if (!map || typeof map !== 'object') { return 0; }
		return parseInt(map[key], 10) || 0;
	}

	/**
	 * Put the window back where it was, once there is a list to scroll through.
	 *
	 * Cards reserve their image height, so the grid does not grow as thumbnails
	 * arrive and a single frame after the render is enough to measure against.
	 *
	 * @param  {number} y Offset to restore; 0 or absent leaves the page alone
	 * @return {void}
	 */
	function restoreScroll(y) {
		if (!y) { return; }
		window.requestAnimationFrame(function () { window.scrollTo(0, y); });
	}

	/**
	 * Put the current state in the address bar.
	 *
	 * @param  {boolean} replace True to overwrite the current entry rather than
	 *                           add one
	 * @return {void}
	 */
	function writeUrl(replace) {
		if (!window.history || !window.history.pushState) { return; }

		var encoded = encodeState();
		// An empty state drops the fragment entirely rather than leaving a bare
		// hash behind, so the root keeps the URL it was linked with.
		var url = encoded ? '#' + encoded : (window.location.pathname + window.location.search);

		try {
			window.history[replace ? 'replaceState' : 'pushState'](null, '', url);
		} catch (e) {
			// Some embeddings forbid history writes. The page still works.
		}
	}

	/**
	 * Go back to the top of the catalogue.
	 *
	 * The toolbar is left alone: the type, warehouse and archived choices are
	 * visible, and the user made them on purpose.
	 *
	 * @return {void}
	 */
	function resetToRoot() {
		state.view = 'browse';
		state.category = 0;
		state.search = '';
		state.facets = [];
		state.facetsAny = [];
		state.offset = 0;
		syncControls();
		load();
	}

	// ------------------------------------------------------------------ data

	function fetchCatalog() {
		var q = new URLSearchParams();
		q.set('mode', CFG.mode);
		q.set('type', state.type);
		q.set('warehouse', state.warehouse);
		q.set('offset', state.offset);
		if (state.archived) { q.set('archived', 1); }
		state.facets.forEach(function (id) { q.append('facets[]', id); });
		state.facetsAny.forEach(function (id) { q.append('facetsany[]', id); });

		if (state.view === 'search') {
			q.set('action', 'search');
			q.set('q', state.search);
			if (state.category) { q.set('category', state.category); }
		} else if (state.view === 'favorites' || state.view === 'recent') {
			q.set('action', state.view);
		} else {
			q.set('action', 'browse');
			if (state.category) { q.set('category', state.category); }
		}

		var seq = ++state.seq;

		return fetch(CFG.urlCatalog + '?' + q.toString(), {
			credentials: 'same-origin',
			headers: { 'X-Requested-With': 'XMLHttpRequest' }
		}).then(function (r) { return r.json(); }).then(function (d) {
			// Drop a response a newer request has already superseded.
			return (seq === state.seq) ? d : null;
		});
	}

	// ------------------------------------------------------------- rendering

	function renderBreadcrumb() {
		var host = el('dcb-breadcrumb');
		clear(host);

		var nav = make('div', 'dolicatalog-crumbs');

		function crumb(text, onClick, current) {
			var n = make(current ? 'span' : 'a', 'dolicatalog-crumb' + (current ? ' current' : ''), text);
			if (!current) {
				n.href = '#';
				n.addEventListener('click', function (ev) { ev.preventDefault(); onClick(); });
			}
			return n;
		}

		nav.appendChild(crumb(label('DoliCatalogRoot', 'Catalog'), function () {
			resetToRoot(false);
		}, state.view === 'browse' && !state.category));

		if (state.view === 'favorites' || state.view === 'recent') {
			nav.appendChild(make('span', 'dolicatalog-crumb-sep', '›'));
			nav.appendChild(crumb(
				state.view === 'favorites' ? label('DoliCatalogFavorites', 'Favorites') : label('DoliCatalogRecent', 'Recently used'),
				null, true
			));
		} else {
			state.breadcrumb.forEach(function (item, i) {
				nav.appendChild(make('span', 'dolicatalog-crumb-sep', '›'));
				nav.appendChild(crumb(item.label, function () {
					state.view = 'browse';
					state.category = item.id;
					state.facets = [];
				state.facetsAny = [];
					state.offset = 0;
					load();
				}, i === state.breadcrumb.length - 1));
			});
		}
		host.appendChild(nav);

		var tabs = make('div', 'dolicatalog-tabs');
		if (CFG.enableFavorites) { tabs.appendChild(tab(label('DoliCatalogFavorites', 'Favorites'), 'fa-star', 'favorites')); }
		if (CFG.enableRecent) { tabs.appendChild(tab(label('DoliCatalogRecent', 'Recently used'), 'fa-history', 'recent')); }
		host.appendChild(tabs);
	}

	function tab(text, icon, view) {
		var b = make('button', 'dolicatalog-tab' + (state.view === view ? ' active' : ''));
		b.type = 'button';
		b.appendChild(make('span', 'fa ' + icon));
		b.appendChild(document.createTextNode(' ' + text));
		b.addEventListener('click', function () {
			state.view = (state.view === view) ? 'browse' : view;
			if (state.view === 'browse') { state.category = 0; }
			state.facets = [];
			state.facetsAny = [];
			state.offset = 0;
			el('dcb-search').value = '';
			state.search = '';
			load();
		});
		return b;
	}

	/**
	 * Tag chips, returned as a node so the caller decides where they sit.
	 *
	 * They belong below the category folders: the folders are how you move
	 * around, and the tags narrow whatever that move landed you on. Placing
	 * them above read as though they filtered the folders too.
	 *
	 * @param  {Array} facets Facet rows from the endpoint
	 * @return {Node|null}    The chip row, or null when there is nothing to show
	 */
	/** Where the collapsed/expanded choice is remembered between page loads. */
	var FACETS_COLLAPSED_KEY = 'dolicatalog.facets.collapsed';

	/**
	 * Whether the refine panel is collapsed.
	 *
	 * Kept in localStorage rather than page state: filtering reloads the list,
	 * and someone who has folded the panel away does not want it unfolding
	 * again on every click.
	 *
	 * @return {boolean} True when collapsed
	 */
	function facetsCollapsed() {
		try {
			return window.localStorage.getItem(FACETS_COLLAPSED_KEY) === '1';
		} catch (e) {
			// Private browsing and similar can refuse storage; default to open.
			return false;
		}
	}

	/**
	 * Remember the collapsed choice.
	 *
	 * @param  {boolean} collapsed Desired state
	 * @return {void}
	 */
	function setFacetsCollapsed(collapsed) {
		try {
			window.localStorage.setItem(FACETS_COLLAPSED_KEY, collapsed ? '1' : '0');
		} catch (e) {
			// Not being able to remember it is not worth failing the click over.
		}
	}

	/** How many values a group shows before collapsing the rest behind a toggle. */
	var FACET_VISIBLE = 12;

	/**
	 * One selectable value.
	 *
	 * @param  {Object} f Facet from the endpoint
	 * @return {Element}  Chip
	 */
	function facetChip(f) {
		var chip = make('button', 'dcb-facet' + (f.selected ? ' on' : ''));
		chip.type = 'button';
		if (f.color) { chip.style.borderLeft = '3px solid #' + f.color; }
		chip.appendChild(document.createTextNode(f.label));
		chip.appendChild(make('span', 'dcb-facet-count', f.count));

		chip.addEventListener('click', function () {
			var i = state.facets.indexOf(f.id);
			if (i === -1) { state.facets.push(f.id); } else { state.facets.splice(i, 1); }
			state.offset = 0;
			load();
		});

		return chip;
	}

	/**
	 * One attribute: its name, then its values.
	 *
	 * @param  {string} name   Attribute name, empty for loose tags
	 * @param  {Array}  values Facets belonging to it
	 * @return {Element}       Row
	 */
	function facetGroup(name, values) {
		var row = make('div', 'dcb-facet-group');
		row.appendChild(make('span', 'dcb-facet-group-label',
			name || label('DoliCatalogOtherTags', 'Other')));

		var box = make('div', 'dcb-facet-values');

		// A selected value must stay visible even if it sits past the cut, or it
		// could not be switched off without expanding first.
		var alwaysShow = values.filter(function (f) { return f.selected; });
		var head = values.slice(0, FACET_VISIBLE);
		var tail = values.slice(FACET_VISIBLE);
		alwaysShow.forEach(function (f) {
			if (head.indexOf(f) === -1) {
				head.push(f);
				tail.splice(tail.indexOf(f), 1);
			}
		});

		var modeToggle = null;

		// Only meaningful once two values of this attribute are selected: with
		// one, "all" and "any" describe the same set.
		var groupId = values.length ? (values[0].group_id || 0) : 0;
		var selectedHere = values.filter(function (f) { return f.selected; }).length;

		if (groupId > 0 && selectedHere > 1) {
			var isAny = state.facetsAny.indexOf(groupId) !== -1;
			var toggle = make('button', 'dcb-facet-mode' + (isAny ? ' any' : ''));
			toggle.type = 'button';
			toggle.title = isAny
				? label('DoliCatalogMatchAnyHint', 'Showing items matching any selected value. Click to require all.')
				: label('DoliCatalogMatchAllHint', 'Showing items matching all selected values. Click to allow any.');
			toggle.appendChild(make('span', 'dcb-facet-mode-on', isAny
				? label('DoliCatalogMatchAny', 'Any')
				: label('DoliCatalogMatchAll', 'All')));

			toggle.addEventListener('click', function () {
				var i = state.facetsAny.indexOf(groupId);
				if (i === -1) { state.facetsAny.push(groupId); } else { state.facetsAny.splice(i, 1); }
				state.offset = 0;
				load();
			});

			modeToggle = toggle;
		}

		head.forEach(function (f) { box.appendChild(facetChip(f)); });

		if (tail.length) {
			var more = make('button', 'dcb-facet-more', '+' + tail.length + ' ' + label('DoliCatalogMoreValues', 'more'));
			more.type = 'button';
			more.addEventListener('click', function () {
				tail.forEach(function (f) { box.insertBefore(facetChip(f), more); });
				more.parentNode.removeChild(more);
			});
			box.appendChild(more);
		}

		// After the values: the switch describes what they do together.
		if (modeToggle) { box.appendChild(modeToggle); }

		row.appendChild(box);

		return row;
	}

	function renderFacets(facets, truncated) {
		if (!facets || !facets.length) { return null; }

		var collapsed = facetsCollapsed();
		var host = make('div', 'dolicatalog-facets' + (collapsed ? ' collapsed' : ''));

		var header = make('button', 'dcb-facet-heading');
		header.type = 'button';
		header.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
		header.appendChild(make('span', 'dcb-facet-caret', collapsed ? '\u25B8' : '\u25BE'));
		header.appendChild(make('span', 'dcb-facet-heading-text', label('DoliCatalogRefineBy', 'Refine by')));

		// Folded away, the panel must still say that it is filtering something,
		// or a short result list looks like an empty catalogue.
		if (collapsed && state.facets.length) {
			header.appendChild(make('span', 'dcb-facet-active',
				state.facets.length + ' ' + label('DoliCatalogFiltersActive', 'active')));
		}

		var body = make('div', 'dcb-facet-body');

		header.addEventListener('click', function () {
			var next = !host.classList.contains('collapsed');
			setFacetsCollapsed(next);
			// Re-render rather than toggling a class, so the active-count badge
			// and caret are rebuilt from one place. Nothing about the view has
			// changed, so it must not land in history as somewhere to go back to.
			load({ history: 'replace' });
		});

		host.appendChild(header);
		host.appendChild(body);

		// Already ordered by the server; walking in sequence keeps a group's
		// values together without re-sorting them here.
		var currentId = null;
		var currentName = null;
		var bucket = [];

		function flush() {
			if (bucket.length) { body.appendChild(facetGroup(currentName, bucket)); }
			bucket = [];
		}

		facets.forEach(function (f) {
			var gid = f.group_id || 0;
			if (currentId !== null && gid !== currentId) { flush(); }
			currentId = gid;
			currentName = f.group_label || '';
			bucket.push(f);
		});
		flush();

		// A filter that silently omits options is worse than one that admits it.
		if (truncated > 0) {
			var note = make('span', 'dcb-facet-more',
				'+' + truncated + ' ' + label('DoliCatalogMoreTagsHidden', 'more not shown'));
			note.title = label('DoliCatalogMoreTagsHiddenTooltip',
				'Raise the tag filter limit in the module setup to show more.');
			body.appendChild(note);
		}

		if (state.facets.length) {
			var clearBtn = make('button', 'dcb-facet-clear', label('DoliCatalogClearTags', 'Clear all'));
			clearBtn.type = 'button';
			clearBtn.addEventListener('click', function () {
				state.facets = [];
				state.facetsAny = [];
				state.offset = 0;
				load();
			});
			body.appendChild(clearBtn);
		}

		return host;
	}

	function renderFolders(cats) {
		var section = make('div', 'dolicatalog-section');
		section.appendChild(make('div', 'dolicatalog-section-title',
			state.view === 'search'
				? label('DoliCatalogMatchingCategories', 'Matching categories')
				: label('DoliCatalogCategories', 'Categories')));

		var grid = make('div', 'dolicatalog-folders');
		cats.forEach(function (c) {
			var card = make('button', 'dolicatalog-folder');
			card.type = 'button';
			if (c.color) { card.style.borderLeftColor = '#' + String(c.color).replace('#', ''); }
			card.appendChild(make('span', 'fa fa-folder dolicatalog-folder-icon'));

			var body = make('span', 'dolicatalog-folder-body');
			body.appendChild(make('span', 'dolicatalog-folder-label', c.label));
			body.appendChild(make('span', 'dolicatalog-folder-count', c.count + ' ' + label('DoliCatalogItems', 'items')));
			card.appendChild(body);

			card.addEventListener('click', function () {
				state.view = 'browse';
				state.category = c.id;
				state.facets = [];
				state.facetsAny = [];
				state.offset = 0;
				el('dcb-search').value = '';
				state.search = '';
				load();
			});
			grid.appendChild(card);
		});
		section.appendChild(grid);

		return section;
	}

	function renderProducts(products) {
		var section = make('div', 'dolicatalog-section');
		section.appendChild(make('div', 'dolicatalog-section-title', label('DoliCatalogItems', 'Items')));

		var grid = make('div', 'dcb-grid');
		products.forEach(function (p) { grid.appendChild(productCard(p)); });
		section.appendChild(grid);

		return section;
	}

	function productCard(p) {
		var card = make('div', 'dcb-card');

		if (CFG.showImages) {
			var media = make('div', 'dcb-media');
			if (p.image) {
				var img = document.createElement('img');
				img.alt = '';
				img.loading = 'lazy';

				// Paint the 128px file straight away, then swap in the 480px one
				// once it has decoded. A card renders at roughly 220x132, so the
				// small file alone would be visibly upscaled, and waiting for the
				// large one would leave the grid empty while it loads.
				img.src = p.image;
				if (p.image_hi && p.image_hi !== p.image) {
					img.classList.add('dcb-lowres');
					var hi = new Image();
					hi.onload = function () {
						img.src = p.image_hi;
						img.classList.remove('dcb-lowres');
					};
					hi.src = p.image_hi;
				}

				media.appendChild(img);
			} else {
				media.appendChild(make('span', 'fa ' + (p.type === 1 ? 'fa-cogs' : 'fa-cube') + ' dcb-placeholder'));
			}
			card.appendChild(media);
		}

		var body = make('div', 'dcb-body');

		var refLine = make('div', 'dcb-refline');
		// The whole reference is the link: on a page built for looking around,
		// the product card is the natural next click.
		var refLink = document.createElement('a');
		refLink.className = 'dcb-ref';
		refLink.href = CFG.urlProduct + '?id=' + encodeURIComponent(p.id);
		refLink.title = label('DoliCatalogOpenProduct', 'Open product card');
		refLink.textContent = p.ref;
		refLine.appendChild(refLink);

		if (p.type === 1) { refLine.appendChild(make('span', 'dcb-badge', label('Services', 'Service'))); }
		body.appendChild(refLine);

		body.appendChild(make('div', 'dcb-title', p.label));

		if (state.view !== 'browse' && p.paths && p.paths.length) {
			var path = make('div', 'dcb-path');
			path.appendChild(make('span', 'fa fa-folder-open dolicatalog-pathicon'));
			path.appendChild(document.createTextNode(' ' + p.paths[0]));
			path.title = p.paths.join('\n');
			body.appendChild(path);
		} else if (p.description) {
			body.appendChild(make('div', 'dcb-desc', p.description));
		}

		var meta = make('div', 'dcb-meta');
		meta.appendChild(make('span', 'dcb-price', money(p.price)));
		if (CFG.showTtc) { meta.appendChild(make('span', 'dcb-price-ttc', money(p.price_ttc))); }

		if (CFG.showStock && p.type !== 1) {
			var v = parseFloat(p.stock) || 0;
			meta.appendChild(make('span', 'dolicatalog-stock ' + (v > 0 ? 'ok' : 'out'), v));
		}
		if (CFG.showDuration && p.type === 1 && p.duration) {
			meta.appendChild(make('span', 'dcb-duration', p.duration));
		}
		body.appendChild(meta);

		card.appendChild(body);

		if (CFG.enableFavorites) {
			var star = make('button', 'dcb-fav' + (p.is_favorite ? ' on' : ''));
			star.type = 'button';
			star.title = p.is_favorite
				? label('DoliCatalogRemoveFavorite', 'Remove from favorites')
				: label('DoliCatalogAddFavorite', 'Add to favorites');
			star.appendChild(make('span', 'fa fa-star'));
			star.addEventListener('click', function (ev) {
				ev.preventDefault();
				ev.stopPropagation();
				toggleFavorite(p, star);
			});
			card.appendChild(star);
		}

		return card;
	}

	function renderPager(data) {
		var host = el('dcb-pager');
		clear(host);

		var size = CFG.pageSize;
		var hasPrev = state.offset > 0;
		var hasNext = !!data.truncated;
		if (!hasPrev && !hasNext) { return; }

		var prev = make('button', 'button button-cancel', '‹ ' + label('DoliCatalogPrevious', 'Previous'));
		prev.type = 'button';
		prev.disabled = !hasPrev;
		prev.addEventListener('click', function () {
			state.offset = Math.max(0, state.offset - size);
			load();
		});

		var next = make('button', 'button', label('DoliCatalogNext', 'Next') + ' ›');
		next.type = 'button';
		next.disabled = !hasNext;
		next.addEventListener('click', function () {
			state.offset += size;
			load();
		});

		var from = state.offset + 1;
		var to = state.offset + (data.products ? data.products.length : 0);
		host.appendChild(make('span', 'dcb-pageinfo', from + '–' + to));
		host.appendChild(prev);
		host.appendChild(next);
	}

	function toggleFavorite(p, button) {
		var body = new URLSearchParams();
		body.set('token', CFG.token);
		body.set('productid', p.id);

		fetch(CFG.urlFavorite, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded',
				'X-Requested-With': 'XMLHttpRequest'
			},
			body: body.toString()
		}).then(function (r) { return r.json(); }).then(function (d) {
			if (!d.ok) { return; }
			p.is_favorite = d.favorite;
			button.classList.toggle('on', !!d.favorite);
			// Unstarring from the favourites list drops it out of the list; the
			// view itself has not changed, so it is a redraw, not a navigation.
			if (state.view === 'favorites' && !d.favorite) { load({ history: 'replace' }); }
		}).catch(function () { /* favouriting is not critical */ });
	}

	// ------------------------------------------------------------------ load

	/**
	 * Fetch and render the current state.
	 *
	 * @param  {Object} [opts]         Options
	 * @param  {string} [opts.history] 'push' to add a history entry (the default,
	 *                                 since almost every call is a navigation),
	 *                                 'replace' to overwrite the current one, or
	 *                                 'none' when the address bar is already
	 *                                 right — coming back through history
	 * @param  {number} [opts.scroll]  Offset to restore once rendered
	 * @return {void}
	 */
	function load(opts) {
		opts = opts || {};

		if (opts.history !== 'none') { writeUrl(opts.history === 'replace'); }

		// Nothing on screen belongs to a view any more until the response lands.
		renderedKey = '';

		var host = el('dcb-results');
		clear(host);
		host.appendChild(make('div', 'dolicatalog-empty', label('DoliCatalogLoading', 'Loading…')));

		fetchCatalog().then(function (data) {
			if (!data) { return; }

			clear(host);
			if (!data.ok) {
				host.appendChild(make('div', 'dolicatalog-empty error', label('DoliCatalogError', 'Could not load the catalog.')));
				return;
			}

			state.breadcrumb = data.breadcrumb || [];
			renderBreadcrumb();

			var cats = data.categories || [];
			var prods = data.products || [];

			if (!cats.length && !prods.length) {
				var msg = state.view === 'search'
					? label('DoliCatalogNoResults', 'No matching items.')
					: (state.category
						? label('DoliCatalogEmptyCategory', 'This category is empty.')
						: label('DoliCatalogBrowseEmpty', 'No product categories exist yet.'));
				host.appendChild(make('div', 'dolicatalog-empty', msg));
				clear(el('dcb-pager'));
			} else {
				if (cats.length) { host.appendChild(renderFolders(cats)); }

				// Between the two on purpose: below the folders you navigate with,
				// above the items they narrow.
				var facetRow = renderFacets(data.facets, data.facetsTruncated || 0);
				if (facetRow) { host.appendChild(facetRow); }

				if (prods.length) { host.appendChild(renderProducts(prods)); }

				renderPager(data);
			}

			renderedKey = encodeState();
			restoreScroll(opts.scroll);
		}).catch(function () {
			clear(host);
			host.appendChild(make('div', 'dolicatalog-empty error', label('DoliCatalogError', 'Could not load the catalog.')));
		});
	}

	/**
	 * Decide which view the page opens on.
	 *
	 * A fragment is followed: it is the Back button returning to a view this page
	 * put in the address bar, or a link someone was sent. A bare URL opens the
	 * root, the same as it always has — arriving at the catalogue is not the same
	 * as coming back to it, and nothing from a previous visit is reapplied here.
	 *
	 * @return {void}
	 */
	function start() {
		var frag = fragment();
		if (frag) {
			applyState(decodeState(frag));
			load({ history: 'replace', scroll: recallScroll(encodeState()) });
			return;
		}

		load({ history: 'replace' });
	}

	function init() {
		// Left to itself the browser restores the offset before the grid has
		// arrived, lands at the top of a short page, and leaves us to correct it
		// a moment later. Doing it after the render instead avoids the jump.
		if (window.history && 'scrollRestoration' in window.history) {
			window.history.scrollRestoration = 'manual';
		}

		el('dcb-search').addEventListener('input', debounce(function () {
			// Editing a term already being searched refines one thought, so it
			// overwrites its own history entry. Otherwise every correction would
			// have to be undone keystroke by keystroke to leave the page.
			var refining = (state.view === 'search');

			var t = el('dcb-search').value.trim();
			state.search = t;
			state.view = t ? 'search' : 'browse';
			// Searching leaves the current folder. This page is for people who
			// do not know where something lives, so a search that silently
			// excluded everything outside the folder they happened to be in
			// would be the opposite of useful.
			state.category = 0;
			state.facets = [];
			state.facetsAny = [];
			state.offset = 0;
			load({ history: refining ? 'replace' : 'push' });
		}, 250));

		el('dcb-type').addEventListener('change', function () {
			state.type = parseInt(el('dcb-type').value, 10);
			state.offset = 0;
			load();
		});

		var arch = el('dcb-archived');
		if (arch) {
			arch.addEventListener('change', function () {
				state.archived = arch.checked ? 1 : 0;
				state.offset = 0;
				load();
			});
		}

		var wh = document.querySelector('[name="dcb_warehouse"]');
		if (wh) {
			wh.addEventListener('change', function () {
				state.warehouse = Math.max(0, parseInt(wh.value, 10) || 0);
				state.offset = 0;
				load();
			});
		}

		// Back and Forward. The address bar already says where we are going, so
		// this load must not write to history itself.
		window.addEventListener('popstate', function () {
			applyState(decodeState(fragment()));
			load({ history: 'none', scroll: recallScroll(encodeState()) });
		});

		// Anything can take someone off this page — a product card, the menu, a
		// bookmark — and only some of it announces itself first. pagehide is the
		// one event that covers all of them, back/forward cache included.
		window.addEventListener('pagehide', rememberScroll);
		window.addEventListener('scroll', debounce(rememberScroll, 250), { passive: true });

		start();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
