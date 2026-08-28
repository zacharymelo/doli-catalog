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
			state.view = 'browse';
			state.category = 0;
			state.facets = [];
			state.facetsAny = [];
			state.offset = 0;
			el('dcb-search').value = '';
			state.search = '';
			load();
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
			// and caret are rebuilt from one place.
			load();
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
			if (state.view === 'favorites' && !d.favorite) { load(); }
		}).catch(function () { /* favouriting is not critical */ });
	}

	// ------------------------------------------------------------------ load

	function load() {
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
				return;
			}

			if (cats.length) { host.appendChild(renderFolders(cats)); }

			// Between the two on purpose: below the folders you navigate with,
			// above the items they narrow.
			var facetRow = renderFacets(data.facets, data.facetsTruncated || 0);
			if (facetRow) { host.appendChild(facetRow); }

			if (prods.length) { host.appendChild(renderProducts(prods)); }

			renderPager(data);
		}).catch(function () {
			clear(host);
			host.appendChild(make('div', 'dolicatalog-empty error', label('DoliCatalogError', 'Could not load the catalog.')));
		});
	}

	function init() {
		el('dcb-search').addEventListener('input', debounce(function () {
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
			load();
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
				state.warehouse = parseInt(wh.value, 10) || 0;
				state.offset = 0;
				load();
			});
		}

		load();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
