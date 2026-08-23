/* Copyright (C) 2026 Zachary Melo <zach@digitalproperties.works>
 *
 * Doli Catalog — client side catalog browser.
 * No framework dependency: the modal is built with plain DOM APIs so it works
 * on every Dolibarr theme without fighting the host page's jQuery version.
 */
(function () {
	'use strict';

	var configEl = document.getElementById('dolicatalog-config');
	if (!configEl) {
		return;
	}

	var CFG;
	try {
		CFG = JSON.parse(configEl.textContent || configEl.innerText);
	} catch (e) {
		return;
	}

	var L = CFG.labels || {};

	/** Current browser state. */
	var state = {
		view: 'browse',      // browse | search | favorites | recent
		category: 0,
		search: '',
		type: -1,
		warehouse: 0,
		breadcrumb: [],
		selection: {},        // productId -> {id, ref, label, qty}
		loading: false,
		requestSeq: 0
	};

	// ---------------------------------------------------------------- helpers

	function el(id) {
		return document.getElementById(id);
	}

	function label(key, fallback) {
		return (L[key] && L[key] !== key) ? L[key] : (fallback || key);
	}

	function money(value) {
		var num = parseFloat(value);
		if (isNaN(num)) {
			return '';
		}
		try {
			return new Intl.NumberFormat(undefined, {
				style: 'currency',
				currency: CFG.currency || 'EUR'
			}).format(num);
		} catch (e) {
			return num.toFixed(2);
		}
	}

	/** Substitute {0}, {1}, ... in a translated string. */
	function fmt(template) {
		var args = Array.prototype.slice.call(arguments, 1);
		return String(template).replace(/\{(\d+)\}/g, function (m, i) {
			return args[i] !== undefined ? args[i] : m;
		});
	}

	function makeEl(tag, className, text) {
		var node = document.createElement(tag);
		if (className) {
			node.className = className;
		}
		if (text !== undefined && text !== null) {
			node.textContent = String(text);
		}
		return node;
	}

	function clear(node) {
		while (node.firstChild) {
			node.removeChild(node.firstChild);
		}
	}

	function debounce(fn, wait) {
		var timer = null;
		return function () {
			var args = arguments, self = this;
			clearTimeout(timer);
			timer = setTimeout(function () {
				fn.apply(self, args);
			}, wait);
		};
	}

	// ------------------------------------------------------------ data access

	function fetchCatalog(params) {
		var query = new URLSearchParams();
		query.set('action', params.action);
		query.set('mode', CFG.mode);
		query.set('type', state.type);
		query.set('warehouse', state.warehouse);
		// In buy mode the supplier filter is a user choice, not an automatic
		// restriction: without it a purchasable product with no supplier price
		// would silently vanish from the catalog.
		if (CFG.mode === 'buy' && CFG.socid) {
			var supOnly = el('dolicatalog-supplier-only');
			if (!supOnly || supOnly.checked) {
				query.set('supplier', CFG.socid);
			}
		}
		if (params.category) {
			query.set('category', params.category);
		}
		if (params.q) {
			query.set('q', params.q);
		}

		var seq = ++state.requestSeq;

		return fetch(CFG.urlCatalog + '?' + query.toString(), {
			credentials: 'same-origin',
			headers: { 'X-Requested-With': 'XMLHttpRequest' }
		}).then(function (r) {
			return r.json();
		}).then(function (data) {
			// Drop responses that a newer request has already superseded.
			if (seq !== state.requestSeq) {
				return null;
			}
			return data;
		});
	}

	// ------------------------------------------------------------- rendering

	function renderLoading() {
		var results = el('dolicatalog-results');
		clear(results);
		results.appendChild(makeEl('div', 'dolicatalog-empty', label('DoliCatalogLoading', 'Loading…')));
	}

	function renderBreadcrumb() {
		var bc = el('dolicatalog-breadcrumb');
		clear(bc);

		var nav = makeEl('div', 'dolicatalog-crumbs');

		function crumb(text, onClick, isCurrent) {
			var node = makeEl(isCurrent ? 'span' : 'a', 'dolicatalog-crumb' + (isCurrent ? ' current' : ''), text);
			if (!isCurrent) {
				node.href = '#';
				node.addEventListener('click', function (ev) {
					ev.preventDefault();
					onClick();
				});
			}
			return node;
		}

		nav.appendChild(crumb(label('DoliCatalogRoot', 'Catalog'), function () {
			state.view = 'browse';
			state.category = 0;
			el('dolicatalog-search').value = '';
			state.search = '';
			load();
		}, state.view === 'browse' && state.category === 0));

		if (state.view === 'favorites' || state.view === 'recent') {
			nav.appendChild(makeEl('span', 'dolicatalog-crumb-sep', '›'));
			nav.appendChild(crumb(
				state.view === 'favorites' ? label('DoliCatalogFavorites', 'Favorites') : label('DoliCatalogRecent', 'Recently used'),
				null,
				true
			));
		} else {
			state.breadcrumb.forEach(function (item, idx) {
				nav.appendChild(makeEl('span', 'dolicatalog-crumb-sep', '›'));
				var isLast = (idx === state.breadcrumb.length - 1);
				nav.appendChild(crumb(item.label, function () {
					state.view = 'browse';
					state.category = item.id;
					load();
				}, isLast));
			});
		}

		bc.appendChild(nav);

		// Quick-access tabs live alongside the trail.
		var tabs = makeEl('div', 'dolicatalog-tabs');
		if (CFG.enableFavorites) {
			tabs.appendChild(tabButton(label('DoliCatalogFavorites', 'Favorites'), 'fa-star', 'favorites'));
		}
		if (CFG.enableRecent) {
			tabs.appendChild(tabButton(label('DoliCatalogRecent', 'Recently used'), 'fa-history', 'recent'));
		}
		bc.appendChild(tabs);
	}

	function tabButton(text, icon, view) {
		var btn = makeEl('button', 'dolicatalog-tab' + (state.view === view ? ' active' : ''));
		btn.type = 'button';
		btn.title = (view === 'favorites')
			? label('DoliCatalogFavoritesTooltip', 'Show only the items you starred')
			: label('DoliCatalogRecentTooltip', 'Show the items you picked most recently');
		btn.appendChild(makeEl('span', 'fa ' + icon));
		btn.appendChild(document.createTextNode(' ' + text));
		btn.addEventListener('click', function () {
			state.view = (state.view === view) ? 'browse' : view;
			if (state.view === 'browse') {
				state.category = 0;
			}
			el('dolicatalog-search').value = '';
			state.search = '';
			load();
		});
		return btn;
	}

	function renderCategories(categories) {
		if (!categories.length) {
			return null;
		}

		var section = makeEl('div', 'dolicatalog-section');
		section.appendChild(makeEl('div', 'dolicatalog-section-title',
			state.view === 'search'
				? label('DoliCatalogMatchingCategories', 'Matching categories')
				: label('DoliCatalogCategories', 'Categories')));

		var grid = makeEl('div', 'dolicatalog-folders');
		categories.forEach(function (cat) {
			var card = makeEl('button', 'dolicatalog-folder');
			card.type = 'button';
			if (cat.color) {
				card.style.borderLeftColor = '#' + String(cat.color).replace('#', '');
			}

			// The category description is fetched anyway; surface it as the tooltip
			// so long descriptions stay available without cluttering the card.
			card.title = cat.description
				? cat.label + ' — ' + cat.description
				: fmt(label('DoliCatalogOpenCategory', 'Open {0}'), cat.label);

			card.appendChild(makeEl('span', 'fa fa-folder dolicatalog-folder-icon'));

			var body = makeEl('span', 'dolicatalog-folder-body');
			body.appendChild(makeEl('span', 'dolicatalog-folder-label', cat.label));
			body.appendChild(makeEl('span', 'dolicatalog-folder-count', cat.count + ' ' + label('DoliCatalogItems', 'items')));
			card.appendChild(body);

			card.addEventListener('click', function () {
				state.view = 'browse';
				state.category = cat.id;
				load();
			});

			grid.appendChild(card);
		});

		section.appendChild(grid);
		return section;
	}

	function renderProducts(products, truncated) {
		var section = makeEl('div', 'dolicatalog-section');
		section.appendChild(makeEl('div', 'dolicatalog-section-title', label('DoliCatalogItems', 'Items')));

		var table = makeEl('table', 'dolicatalog-table');
		var thead = makeEl('thead');
		var hrow = makeEl('tr');

		hrow.appendChild(makeEl('th', 'ppc-select'));
		if (CFG.showImages) {
			hrow.appendChild(makeEl('th', 'ppc-img'));
		}
		hrow.appendChild(makeEl('th', 'ppc-ref', label('Ref', 'Ref')));
		hrow.appendChild(makeEl('th', 'ppc-label', label('Label', 'Label')));
		hrow.appendChild(makeEl('th', 'ppc-price', CFG.mode === 'bom'
			? label('CostPrice', 'Cost price')
			: label('PriceUHT', 'Unit price')));
		if (CFG.showTtc) {
			hrow.appendChild(makeEl('th', 'ppc-price', label('PriceUTTC', 'Unit price (incl. tax)')));
		}
		if (CFG.showStock) {
			hrow.appendChild(makeEl('th', 'ppc-stock', label('Stock', 'Stock')));
		}
		if (CFG.showDuration) {
			hrow.appendChild(makeEl('th', 'ppc-duration', label('Duration', 'Duration')));
		}
		hrow.appendChild(makeEl('th', 'ppc-qty', label('Qty', 'Qty')));
		if (CFG.enableFavorites) {
			hrow.appendChild(makeEl('th', 'ppc-fav'));
		}

		thead.appendChild(hrow);
		table.appendChild(thead);

		var tbody = makeEl('tbody');
		products.forEach(function (p) {
			tbody.appendChild(renderProductRow(p));
		});
		table.appendChild(tbody);
		section.appendChild(table);

		if (truncated) {
			section.appendChild(makeEl('div', 'dolicatalog-truncated', label('DoliCatalogTruncated', 'More results exist — refine your search.')));
		}

		return section;
	}

	function renderProductRow(p) {
		var row = makeEl('tr', 'dolicatalog-row');
		row.dataset.productId = p.id;
		if (state.selection[p.id]) {
			row.classList.add('selected');
		}

		// Selection checkbox
		var tdSel = makeEl('td', 'ppc-select');
		var cb = document.createElement('input');
		cb.type = 'checkbox';
		cb.title = fmt(label('DoliCatalogSelectItem', 'Select {0}'), p.ref);
		cb.checked = !!state.selection[p.id];
		cb.addEventListener('change', function () {
			toggleSelection(p, cb.checked, row);
		});
		tdSel.appendChild(cb);
		row.appendChild(tdSel);

		// Thumbnail
		if (CFG.showImages) {
			var tdImg = makeEl('td', 'ppc-img');
			if (p.image) {
				var img = document.createElement('img');
				img.src = p.image;
				img.alt = '';
				img.loading = 'lazy';
				img.className = 'dolicatalog-thumb';
				tdImg.appendChild(img);
			} else {
				tdImg.appendChild(makeEl('span', 'fa ' + (p.type === 1 ? 'fa-cogs' : 'fa-cube') + ' dolicatalog-thumb-placeholder'));
			}
			row.appendChild(tdImg);
		}

		var tdRef = makeEl('td', 'ppc-ref');
		var refText = makeEl('span', 'dolicatalog-reftext', p.ref);
		refText.title = p.barcode
			? p.ref + ' — ' + label('BarCode', 'Barcode') + ': ' + p.barcode
			: p.ref;
		tdRef.appendChild(refText);

		// Opens the product card in a new tab. It must never be the current tab:
		// the picker sits inside a half-written document, and navigating away
		// would discard whatever the user has already entered.
		if (CFG.urlProduct) {
			var open = document.createElement('a');
			open.className = 'dolicatalog-openproduct';
			open.href = CFG.urlProduct + '?id=' + encodeURIComponent(p.id);
			open.target = '_blank';
			open.rel = 'noopener noreferrer';
			open.title = label('DoliCatalogOpenProduct', 'Open product card in a new tab');
			open.setAttribute('aria-label', open.title);
			open.appendChild(makeEl('span', 'fa fa-external-link-alt'));
			// Keep the click off the row so it cannot disturb the selection.
			open.addEventListener('click', function (ev) { ev.stopPropagation(); });
			tdRef.appendChild(open);
		}

		row.appendChild(tdRef);

		var tdLabel = makeEl('td', 'ppc-label');
		tdLabel.appendChild(makeEl('div', 'dolicatalog-label-main', p.label));

		// Browsing already shows position via the breadcrumb. Searching,
		// favourites and recents do not, so a bare row leaves the reader with
		// no idea what the item belongs to. Showing the path also teaches the
		// catalogue's shape to somebody who does not know it yet.
		var showPath = (state.view !== 'browse') && p.paths && p.paths.length;
		if (showPath) {
			var pathLine = makeEl('div', 'dolicatalog-label-path');
			pathLine.appendChild(makeEl('span', 'fa fa-folder-open dolicatalog-pathicon'));
			pathLine.appendChild(document.createTextNode(' ' + p.paths[0]));
			if (p.paths.length > 1) {
				pathLine.appendChild(makeEl('span', 'dolicatalog-pathmore', '+' + (p.paths.length - 1)));
			}
			tdLabel.appendChild(pathLine);
		} else if (p.description) {
			tdLabel.appendChild(makeEl('div', 'dolicatalog-label-desc', p.description));
		}
		// Both label and description are clipped by CSS, so keep the full text here.
		var tip = p.label;
		if (p.description) { tip += '\n' + p.description; }
		if (p.paths && p.paths.length) { tip += '\n\n' + p.paths.join('\n'); }
		tdLabel.title = tip;
		row.appendChild(tdLabel);

		row.appendChild(makeEl('td', 'ppc-price', money(p.price)));
		if (CFG.showTtc) {
			row.appendChild(makeEl('td', 'ppc-price', money(p.price_ttc)));
		}

		if (CFG.showStock) {
			var tdStock = makeEl('td', 'ppc-stock');
			if (p.type === 1) {
				tdStock.textContent = '—'; // services are not stocked
				tdStock.title = label('DoliCatalogServiceNoStock', 'Services are not stocked');
			} else {
				var stockValue = parseFloat(p.stock) || 0;
				var badge = makeEl('span', 'dolicatalog-stock ' + (stockValue > 0 ? 'ok' : 'out'), stockValue);
				// Say which stock figure this is: one warehouse, or every warehouse.
				badge.title = state.warehouse > 0
					? label('DoliCatalogStockWarehouse', 'Stock in the selected warehouse')
					: label('DoliCatalogStockAll', 'Stock across all warehouses');
				tdStock.appendChild(badge);
			}
			row.appendChild(tdStock);
		}

		if (CFG.showDuration) {
			row.appendChild(makeEl('td', 'ppc-duration', p.type === 1 ? (p.duration || '') : ''));
		}

		// Quantity
		var tdQty = makeEl('td', 'ppc-qty');
		var qty = document.createElement('input');
		qty.type = 'number';
		qty.min = '0';
		qty.step = 'any';
		qty.className = 'dolicatalog-qty';
		qty.title = label('DoliCatalogQtyTooltip', 'Quantity to add. Typing here selects the item.');
		qty.value = state.selection[p.id] ? state.selection[p.id].qty : CFG.defaultQty;
		qty.addEventListener('input', function () {
			if (state.selection[p.id]) {
				state.selection[p.id].qty = parseFloat(qty.value) || 1;
				renderSelection();
			}
		});
		// Typing a quantity is an implicit selection.
		qty.addEventListener('focus', function () {
			if (!state.selection[p.id]) {
				cb.checked = true;
				toggleSelection(p, true, row);
			}
		});
		tdQty.appendChild(qty);
		row.appendChild(tdQty);

		// Favourite toggle
		if (CFG.enableFavorites) {
			var tdFav = makeEl('td', 'ppc-fav');
			var star = makeEl('button', 'dolicatalog-fav' + (p.is_favorite ? ' on' : ''));
			star.type = 'button';
			star.title = p.is_favorite ? label('DoliCatalogRemoveFavorite', 'Remove from favorites') : label('DoliCatalogAddFavorite', 'Add to favorites');
			star.appendChild(makeEl('span', 'fa fa-star'));
			star.addEventListener('click', function () {
				toggleFavorite(p, star);
			});
			tdFav.appendChild(star);
			row.appendChild(tdFav);
		}

		return row;
	}

	function renderResults(data) {
		var results = el('dolicatalog-results');
		clear(results);

		var hasCategories = data.categories && data.categories.length;
		var hasProducts = data.products && data.products.length;

		if (!hasCategories && !hasProducts) {
			var msg = state.view === 'search'
				? label('DoliCatalogNoResults', 'No matching items.')
				: label('DoliCatalogEmptyCategory', 'This category is empty.');
			results.appendChild(makeEl('div', 'dolicatalog-empty', msg));
			return;
		}

		if (hasCategories) {
			results.appendChild(renderCategories(data.categories));
		}
		if (hasProducts) {
			results.appendChild(renderProducts(data.products, data.truncated));
		}
	}

	// ------------------------------------------------------------- selection

	function toggleSelection(product, on, row) {
		if (on) {
			var input = row ? row.querySelector('.dolicatalog-qty') : null;
			var qty = input ? (parseFloat(input.value) || CFG.defaultQty) : CFG.defaultQty;
			state.selection[product.id] = { id: product.id, ref: product.ref, label: product.label, qty: qty };
			if (row) {
				row.classList.add('selected');
			}
		} else {
			delete state.selection[product.id];
			if (row) {
				row.classList.remove('selected');
			}
		}
		renderSelection();
	}

	function selectionCount() {
		return Object.keys(state.selection).length;
	}

	function renderSelection() {
		var box = el('dolicatalog-selection');
		clear(box);

		var count = selectionCount();
		el('dolicatalog-add').disabled = (count === 0);

		if (count === 0) {
			box.appendChild(makeEl('span', 'dolicatalog-selection-empty', label('DoliCatalogNothingSelected', 'Nothing selected')));
			return;
		}

		box.appendChild(makeEl('span', 'dolicatalog-selection-count', count + ' ' + label('DoliCatalogSelected', 'selected')));

		var chips = makeEl('span', 'dolicatalog-chips');
		Object.keys(state.selection).forEach(function (id) {
			var item = state.selection[id];
			var chip = makeEl('span', 'dolicatalog-chip');
			chip.appendChild(makeEl('span', 'dolicatalog-chip-label', item.ref + ' ×' + item.qty));

			var remove = makeEl('button', 'dolicatalog-chip-remove', '×');
			remove.type = 'button';
			remove.title = fmt(label('DoliCatalogRemoveItem', 'Remove {0} from the selection'), item.ref);
			remove.addEventListener('click', function () {
				delete state.selection[id];
				var row = document.querySelector('.dolicatalog-row[data-product-id="' + id + '"]');
				if (row) {
					row.classList.remove('selected');
					var cb = row.querySelector('input[type=checkbox]');
					if (cb) {
						cb.checked = false;
					}
				}
				renderSelection();
			});
			chip.appendChild(remove);
			chips.appendChild(chip);
		});
		box.appendChild(chips);

		var clearBtn = makeEl('button', 'dolicatalog-clear', label('DoliCatalogClearSelection', 'Clear'));
		clearBtn.type = 'button';
		clearBtn.title = label('DoliCatalogClearSelectionTooltip', 'Remove every item from the selection');
		clearBtn.addEventListener('click', function () {
			state.selection = {};
			document.querySelectorAll('.dolicatalog-row.selected').forEach(function (row) {
				row.classList.remove('selected');
				var cb = row.querySelector('input[type=checkbox]');
				if (cb) {
					cb.checked = false;
				}
			});
			renderSelection();
		});
		box.appendChild(clearBtn);
	}

	// ------------------------------------------------------------- favourites

	function toggleFavorite(product, button) {
		var body = new URLSearchParams();
		body.set('token', CFG.token);
		body.set('productid', product.id);

		fetch(CFG.urlFavorite, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded',
				'X-Requested-With': 'XMLHttpRequest'
			},
			body: body.toString()
		}).then(function (r) {
			return r.json();
		}).then(function (data) {
			if (!data.ok) {
				return;
			}
			product.is_favorite = data.favorite;
			button.classList.toggle('on', !!data.favorite);
			button.title = data.favorite
				? label('DoliCatalogRemoveFavorite', 'Remove from favorites')
				: label('DoliCatalogAddFavorite', 'Add to favorites');

			// The favourites view must drop a row the moment it stops qualifying.
			if (state.view === 'favorites' && !data.favorite) {
				load();
			}
		}).catch(function () { /* silent: favouriting is not critical */ });
	}

	// ------------------------------------------------------------------ load

	function load() {
		if (state.loading) {
			// A newer request supersedes the old one via requestSeq; keep going.
		}
		state.loading = true;
		renderLoading();

		var params;
		if (state.view === 'search') {
			params = { action: 'search', q: state.search, category: state.category };
		} else if (state.view === 'favorites') {
			params = { action: 'favorites' };
		} else if (state.view === 'recent') {
			params = { action: 'recent' };
		} else {
			params = { action: 'browse', category: state.category };
		}

		fetchCatalog(params).then(function (data) {
			state.loading = false;
			if (!data) {
				return; // superseded
			}
			if (!data.ok) {
				var results = el('dolicatalog-results');
				clear(results);
				results.appendChild(makeEl('div', 'dolicatalog-empty error', label('DoliCatalogError', 'Could not load the catalog.') + ' (' + (data.error || '') + ')'));
				return;
			}
			state.breadcrumb = data.breadcrumb || [];
			renderBreadcrumb();
			renderResults(data);
			renderSelection();
		}).catch(function () {
			state.loading = false;
			var results = el('dolicatalog-results');
			clear(results);
			results.appendChild(makeEl('div', 'dolicatalog-empty error', label('DoliCatalogError', 'Could not load the catalog.')));
		});
	}

	// -------------------------------------------------------------- add lines

	function addSelected() {
		var items = Object.keys(state.selection).map(function (id) {
			return { id: state.selection[id].id, qty: state.selection[id].qty };
		});
		if (!items.length) {
			return;
		}

		var addBtn = el('dolicatalog-add');
		addBtn.disabled = true;
		addBtn.textContent = label('DoliCatalogAdding', 'Adding…');

		var body = new URLSearchParams();
		body.set('token', CFG.token);
		body.set('doctype', CFG.docType);
		body.set('docid', CFG.docId);
		body.set('items', JSON.stringify(items));

		fetch(CFG.urlAddLines, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded',
				'X-Requested-With': 'XMLHttpRequest'
			},
			body: body.toString()
		}).then(function (r) {
			return r.json();
		}).then(function (data) {
			if (data.ok) {
				// Reload with a GET so the native line table re-renders, and so a
				// browser refresh never replays the previous POST.
				window.location.assign(window.location.pathname + window.location.search);
				return;
			}
			addBtn.disabled = false;
			addBtn.textContent = label('DoliCatalogAddSelected', 'Add selected');
			alert(label('DoliCatalogError', 'Could not add the lines.') + '\n' + (data.messages || []).join('\n') + (data.error ? '\n' + data.error : ''));
		}).catch(function () {
			addBtn.disabled = false;
			addBtn.textContent = label('DoliCatalogAddSelected', 'Add selected');
			alert(label('DoliCatalogError', 'Could not add the lines.'));
		});
	}

	// ----------------------------------------------------------------- modal

	function openModal() {
		el('dolicatalog-overlay').hidden = false;
		document.body.classList.add('dolicatalog-open');
		if (!state.breadcrumb.length && !el('dolicatalog-results').firstChild) {
			load();
		} else {
			renderBreadcrumb();
			renderSelection();
		}
		setTimeout(function () {
			el('dolicatalog-search').focus();
		}, 50);
	}

	function closeModal() {
		el('dolicatalog-overlay').hidden = true;
		document.body.classList.remove('dolicatalog-open');
	}

	// ------------------------------------------------------------------ wire

	function init() {
		el('dolicatalog-open').addEventListener('click', function (ev) {
			ev.preventDefault();
			openModal();
		});
		el('dolicatalog-close').addEventListener('click', closeModal);
		el('dolicatalog-cancel').addEventListener('click', closeModal);
		el('dolicatalog-add').addEventListener('click', addSelected);

		el('dolicatalog-overlay').addEventListener('click', function (ev) {
			if (ev.target === el('dolicatalog-overlay')) {
				closeModal();
			}
		});

		document.addEventListener('keydown', function (ev) {
			if (ev.key === 'Escape' && !el('dolicatalog-overlay').hidden) {
				closeModal();
			}
		});

		var onSearch = debounce(function () {
			var term = el('dolicatalog-search').value.trim();
			state.search = term;
			state.view = term ? 'search' : 'browse';
			load();
		}, 250);
		el('dolicatalog-search').addEventListener('input', onSearch);

		el('dolicatalog-type').addEventListener('change', function () {
			state.type = parseInt(el('dolicatalog-type').value, 10);
			load();
		});

		var supOnly = el('dolicatalog-supplier-only');
		if (supOnly) {
			supOnly.addEventListener('change', load);
		}

		var warehouse = document.querySelector('[name="dolicatalog_warehouse"]');
		if (warehouse) {
			warehouse.addEventListener('change', function () {
				state.warehouse = parseInt(warehouse.value, 10) || 0;
				load();
			});
		}

		renderSelection();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
