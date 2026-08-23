/* Copyright (C) 2026 Zachary Melo <zach@digitalproperties.works>
 *
 * Category filter strip on the native product list.
 *
 * Clicking a category drives Dolibarr's own category filter rather than any
 * custom query, so the list keeps its sorting, columns, pagination, mass
 * actions and export. The strip renders inside the list's search form, so
 * submitting that form preserves every other filter the user already set.
 */
(function () {
	'use strict';

	var strip = document.getElementById('dolicatalog-listtree');
	var dataEl = document.getElementById('dolicatalog-listtree-data');
	if (!strip || !dataEl) { return; }

	var SUBTREES = {};
	try {
		SUBTREES = (JSON.parse(dataEl.textContent || dataEl.innerText) || {}).subtrees || {};
	} catch (e) {
		return;
	}

	var form = document.getElementById('searchFormList');
	if (!form) { return; }

	var FILTER_NAME = 'search_category_product_list[]';
	var OPERATOR_NAME = 'search_category_product_operator';
	var INJECTED = 'dolicatalog-injected';

	/** Remove anything a previous click added, so selections never accumulate. */
	function clearInjected() {
		Array.prototype.slice.call(form.querySelectorAll('.' + INJECTED))
			.forEach(function (n) { n.parentNode.removeChild(n); });
	}

	/**
	 * Clear the native category control's own selection.
	 *
	 * Without this the control's value would be submitted alongside the injected
	 * ids and silently widen the filter beyond the branch that was clicked.
	 */
	function clearNativeSelection() {
		Array.prototype.slice.call(form.querySelectorAll('[name="' + FILTER_NAME + '"]'))
			.forEach(function (node) {
				if (node.tagName === 'SELECT') {
					Array.prototype.slice.call(node.options).forEach(function (o) { o.selected = false; });
				} else if (node.type === 'checkbox' || node.type === 'radio') {
					node.checked = false;
				} else {
					node.value = '';
				}
			});
	}

	function hidden(name, value) {
		var i = document.createElement('input');
		i.type = 'hidden';
		i.name = name;
		i.value = value;
		i.className = INJECTED;
		return i;
	}

	function apply(catId) {
		catId = parseInt(catId, 10) || 0;

		clearInjected();
		clearNativeSelection();

		var marker = document.getElementById('dolicatalog_listcat');
		if (marker) { marker.value = catId; }

		if (catId > 0) {
			var ids = SUBTREES[catId] || [catId];

			// The list only reads the operator when formfilteraction is present.
			// Core puts it in this form already, but without it the ids are
			// ANDed instead of ORed and the filter silently returns nothing -
			// a failure that looks like an empty category rather than a bug.
			if (!form.querySelector('[name="formfilteraction"]')) {
				form.appendChild(hidden('formfilteraction', 'list'));
			}

			// Operator 1 collapses the ids into a single IN (...) - i.e. "in any
			// of these". Operator 0 would AND them and match nothing, since no
			// product belongs to a category and all of its siblings at once.
			form.appendChild(hidden(OPERATOR_NAME, '1'));

			ids.forEach(function (id) {
				form.appendChild(hidden(FILTER_NAME, id));
			});
		}

		// Any page beyond the first belongs to the previous filter.
		var pageField = form.querySelector('[name="page"]');
		if (pageField) { pageField.value = 0; }

		form.submit();
	}

	Array.prototype.slice.call(strip.querySelectorAll('[data-cat]')).forEach(function (btn) {
		btn.addEventListener('click', function (ev) {
			ev.preventDefault();
			apply(btn.getAttribute('data-cat'));
		});
	});
})();
