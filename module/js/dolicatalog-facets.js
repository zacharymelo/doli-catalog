/* Copyright (C) 2026 Zachary Melo <zach@digitalproperties.works>
 *
 * Tag filter ("refine by") panel, shared by the standalone browser and the
 * in-document picker.
 *
 * Both surfaces call the same endpoint and get the same facet payload back, so
 * the only thing that used to differ was that one of them rendered it. Rather
 * than keep two copies of this in step by hand, the panel is built here and the
 * host passes in its own DOM helpers and selection state.
 *
 * The host owns the arrays: this mutates them in place and then asks for a
 * reload, so there is still exactly one source of truth for what is selected.
 */
(function () {
	'use strict';

	/** How many values a group shows before collapsing the rest behind a toggle. */
	var FACET_VISIBLE = 12;

	/**
	 * @param  {Object} ctx Host bindings:
	 *                      make(tag, cls, text) -> Element
	 *                      label(key, fallback) -> string
	 *                      storageKey            collapse memory, per surface
	 *                      defaultCollapsed()    optional, folded state before
	 *                                            the user has expressed one
	 *                      selected()            live array of selected facet ids
	 *                      anyGroups()           live array of group ids set to "any"
	 *                      onChange()            selection changed, reload from offset 0
	 *                      onToggle()            panel folded/unfolded, re-render only
	 * @return {Object}     { render: function (facets, truncated) -> Element|null }
	 */
	function create(ctx) {
		var make = ctx.make;
		var label = ctx.label;

		function collapsed() {
			var stored = null;
			try {
				stored = window.localStorage.getItem(ctx.storageKey);
			} catch (e) {
				// Private browsing and similar can refuse storage; fall through to
				// the host's default rather than failing the render.
			}

			// Only an explicit choice overrides the default, so a host that starts
			// folded on small screens stops doing so the moment the user unfolds it.
			if (stored === '1') { return true; }
			if (stored === '0') { return false; }

			return ctx.defaultCollapsed ? !!ctx.defaultCollapsed() : false;
		}

		function setCollapsed(value) {
			try {
				window.localStorage.setItem(ctx.storageKey, value ? '1' : '0');
			} catch (e) {
				// Not being able to remember it is not worth failing the click over.
			}
		}

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
				var sel = ctx.selected();
				var i = sel.indexOf(f.id);
				if (i === -1) { sel.push(f.id); } else { sel.splice(i, 1); }
				ctx.onChange();
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
				var isAny = ctx.anyGroups().indexOf(groupId) !== -1;
				var toggle = make('button', 'dcb-facet-mode' + (isAny ? ' any' : ''));
				toggle.type = 'button';
				toggle.title = isAny
					? label('DoliCatalogMatchAnyHint', 'Showing items matching any selected value. Click to require all.')
					: label('DoliCatalogMatchAllHint', 'Showing items matching all selected values. Click to allow any.');
				toggle.appendChild(make('span', 'dcb-facet-mode-on', isAny
					? label('DoliCatalogMatchAny', 'Any')
					: label('DoliCatalogMatchAll', 'All')));

				toggle.addEventListener('click', function () {
					var any = ctx.anyGroups();
					var i = any.indexOf(groupId);
					if (i === -1) { any.push(groupId); } else { any.splice(i, 1); }
					ctx.onChange();
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

		/**
		 * @param  {Array}  facets    Facets from the endpoint, already ordered
		 * @param  {number} truncated How many the server had to leave out
		 * @return {Element|null}     Panel, or null when there is nothing to refine by
		 */
		function render(facets, truncated) {
			if (!facets || !facets.length) { return null; }

			var isCollapsed = collapsed();
			var host = make('div', 'dolicatalog-facets' + (isCollapsed ? ' collapsed' : ''));

			var header = make('button', 'dcb-facet-heading');
			header.type = 'button';
			header.setAttribute('aria-expanded', isCollapsed ? 'false' : 'true');
			header.appendChild(make('span', 'dcb-facet-caret', isCollapsed ? '▸' : '▾'));
			header.appendChild(make('span', 'dcb-facet-heading-text', label('DoliCatalogRefineBy', 'Refine by')));

			// Folded away, the panel must still say that it is filtering something,
			// or a short result list looks like an empty catalogue.
			if (isCollapsed && ctx.selected().length) {
				header.appendChild(make('span', 'dcb-facet-active',
					ctx.selected().length + ' ' + label('DoliCatalogFiltersActive', 'active')));
			}

			var body = make('div', 'dcb-facet-body');

			header.addEventListener('click', function () {
				setCollapsed(!host.classList.contains('collapsed'));
				// Re-render rather than toggling a class, so the active-count badge
				// and caret are rebuilt from one place.
				ctx.onToggle();
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

			if (ctx.selected().length) {
				var clearBtn = make('button', 'dcb-facet-clear', label('DoliCatalogClearTags', 'Clear all'));
				clearBtn.type = 'button';
				clearBtn.addEventListener('click', function () {
					ctx.selected().length = 0;
					ctx.anyGroups().length = 0;
					ctx.onChange();
				});
				body.appendChild(clearBtn);
			}

			return host;
		}

		return { render: render };
	}

	window.DoliCatalogFacets = { create: create };
})();
