<?php
/* Copyright (C) 2026 Zachary Melo <zach@digitalproperties.works>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    class/dolicatalogbrowser.class.php
 * \ingroup dolicatalog
 * \brief   Read-only catalog access for the Doli Catalog browser.
 *
 * Every query here reads native Dolibarr tables only (llx_categorie,
 * llx_categorie_product, llx_product, llx_product_stock). Nothing in this
 * class writes to a core table.
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/functions.lib.php';

/**
 * Catalog browsing and search for the Doli Catalog modal.
 */
class DoliCatalogBrowser
{
	/** @var DoliDB Database handler */
	public $db;

	/** @var string Last error message */
	public $error = '';

	/** @var string[] Error stack */
	public $errors = array();

	/**
	 * How many tag filters were cut by the cap on the last getFacets() call.
	 *
	 * Zero when nothing was dropped. Exposed so the interface can say the list
	 * is partial rather than quietly omitting options.
	 *
	 * @var int
	 */
	public $facetsTruncated = 0;

	/** Product-type category discriminator in llx_categorie.type */
	const CATEGORY_TYPE_PRODUCT = 0;

	/**
	 * Depth guard for tree walks.
	 *
	 * llx_categorie has no constraint preventing a category from becoming its own
	 * ancestor, so every walk needs a ceiling as well as a seen-set.
	 */
	const MAX_TREE_DEPTH = 64;

	/** Hard ceiling on rows returned regardless of configuration */
	const MAX_ROWS_HARD_LIMIT = 500;

	/**
	 * Constructor.
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Normalise a browsing mode.
	 *
	 * 'sell' restricts to items flagged for sale, 'buy' to items flagged for
	 * purchase, and 'bom' applies neither: a manufactured sub-assembly is often
	 * neither sold nor purchased, so filtering it out would hide real components.
	 *
	 * @param  string $mode Raw mode
	 * @return string       One of 'sell', 'buy', 'bom'
	 */
	public static function normaliseMode($mode)
	{
		return in_array($mode, array('buy', 'bom', 'all'), true) ? $mode : 'sell';
	}

	/**
	 * Configured page size, clamped to something sane.
	 *
	 * @return int Number of rows to return per query
	 */
	public function getRowLimit()
	{
		$limit = getDolGlobalInt('DOLICATALOG_MAX_RESULTS', 50);
		if ($limit < 1) {
			$limit = 50;
		}
		return min($limit, self::MAX_ROWS_HARD_LIMIT);
	}

	/**
	 * Root categories to show at the top level of the browser.
	 *
	 * Honours DOLICATALOG_ROOT_CATEGORIES when set; otherwise every category
	 * with no parent is treated as a root.
	 *
	 * @return array<int,array{id:int,label:string,color:string,description:string,position:int}> Root categories
	 */
	public function getRootCategories($includeArchived = false)
	{
		$configured = trim(getDolGlobalString('DOLICATALOG_ROOT_CATEGORIES', ''));

		$sql = "SELECT c.rowid, c.label, c.color, c.description, c.position";
		$sql .= " FROM ".MAIN_DB_PREFIX."categorie as c";
		$sql .= " WHERE c.type = ".((int) self::CATEGORY_TYPE_PRODUCT);
		$sql .= " AND c.entity IN (".getEntity('category').")";

		if ($configured !== '') {
			$ids = array();
			foreach (explode(',', $configured) as $chunk) {
				$chunk = (int) trim($chunk);
				if ($chunk > 0) {
					$ids[] = $chunk;
				}
			}
			if (empty($ids)) {
				return array();
			}
			$sql .= " AND c.rowid IN (".$this->db->sanitize(implode(',', $ids)).")";
		} else {
			$sql .= " AND (c.fk_parent = 0 OR c.fk_parent IS NULL)";
		}

		$sql .= $this->archivedCategoryClause($includeArchived);
		$sql .= " ORDER BY c.position ASC, c.label ASC";

		return $this->fetchCategoryRows($sql);
	}

	/**
	 * Direct children of a category.
	 *
	 * @param  int $parentId Parent category id
	 * @return array<int,array{id:int,label:string,color:string,description:string,position:int}> Child categories
	 */
	public function getChildCategories($parentId, $includeArchived = false)
	{
		$parentId = (int) $parentId;
		if ($parentId <= 0) {
			return array();
		}

		$sql = "SELECT c.rowid, c.label, c.color, c.description, c.position";
		$sql .= " FROM ".MAIN_DB_PREFIX."categorie as c";
		$sql .= " WHERE c.type = ".((int) self::CATEGORY_TYPE_PRODUCT);
		$sql .= " AND c.entity IN (".getEntity('category').")";
		$sql .= " AND c.fk_parent = ".$parentId;
		$sql .= $this->archivedCategoryClause($includeArchived);
		$sql .= " ORDER BY c.position ASC, c.label ASC";

		return $this->fetchCategoryRows($sql);
	}

	/**
	 * Run a category SELECT and normalise the rows.
	 *
	 * @param  string $sql Query returning rowid, label, color, description, position
	 * @return array<int,array{id:int,label:string,color:string,description:string,position:int}> Normalised rows
	 */
	private function fetchCategoryRows($sql)
	{
		$out = array();

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			$this->errors[] = $this->error;
			return $out;
		}

		while ($obj = $this->db->fetch_object($resql)) {
			$out[] = array(
				'id' => (int) $obj->rowid,
				'label' => (string) $obj->label,
				'color' => (string) $obj->color,
				'description' => (string) $obj->description,
				'position' => (int) $obj->position,
			);
		}
		$this->db->free($resql);

		return $out;
	}

	/**
	 * Breadcrumb trail from the root down to the given category.
	 *
	 * Walks fk_parent upwards with a depth guard so a corrupted cycle in
	 * llx_categorie cannot spin forever.
	 *
	 * @param  int $catId Category id
	 * @return array<int,array{id:int,label:string}> Trail ordered root first
	 */
	public function getBreadcrumb($catId)
	{
		$catId = (int) $catId;
		$trail = array();
		$seen = array();
		$guard = 0;

		while ($catId > 0 && $guard < self::MAX_TREE_DEPTH) {
			$guard++;
			if (isset($seen[$catId])) {
				break; // cycle in the category tree
			}
			$seen[$catId] = true;

			$sql = "SELECT c.rowid, c.label, c.fk_parent";
			$sql .= " FROM ".MAIN_DB_PREFIX."categorie as c";
			$sql .= " WHERE c.rowid = ".$catId;
			$sql .= " AND c.type = ".((int) self::CATEGORY_TYPE_PRODUCT);
			$sql .= " AND c.entity IN (".getEntity('category').")";

			$resql = $this->db->query($sql);
			if (!$resql) {
				break;
			}
			$obj = $this->db->fetch_object($resql);
			$this->db->free($resql);
			if (!$obj) {
				break;
			}

			array_unshift($trail, array('id' => (int) $obj->rowid, 'label' => (string) $obj->label));
			$catId = (int) $obj->fk_parent;
		}

		return $trail;
	}

	/**
	 * All descendant category ids of a category, including the category itself.
	 *
	 * Iterative breadth-first descent rather than a recursive CTE, so the
	 * module stays portable across MySQL, MariaDB and PostgreSQL.
	 *
	 * @param  int $catId Category id
	 * @return int[]      Category ids
	 */
	public function getDescendantIds($catId)
	{
		$catId = (int) $catId;
		if ($catId <= 0) {
			return array();
		}

		$all = array($catId => $catId);
		$frontier = array($catId);
		$guard = 0;

		while (!empty($frontier) && $guard < self::MAX_TREE_DEPTH) {
			$guard++;

			$sql = "SELECT c.rowid FROM ".MAIN_DB_PREFIX."categorie as c";
			$sql .= " WHERE c.type = ".((int) self::CATEGORY_TYPE_PRODUCT);
			$sql .= " AND c.entity IN (".getEntity('category').")";
			$sql .= " AND c.fk_parent IN (".$this->db->sanitize(implode(',', $frontier)).")";

			$resql = $this->db->query($sql);
			if (!$resql) {
				break;
			}

			$next = array();
			while ($obj = $this->db->fetch_object($resql)) {
				$id = (int) $obj->rowid;
				if (!isset($all[$id])) {
					$all[$id] = $id;
					$next[] = $id;
				}
			}
			$this->db->free($resql);

			$frontier = $next;
		}

		return array_values($all);
	}

	/**
	 * Categories whose label matches a search term, plus everything beneath them.
	 *
	 * This is what lets somebody who knows the catalogue by shape rather than by
	 * product name find things: typing a category name surfaces its contents,
	 * even when no product's own text contains the word.
	 *
	 * @param  string $term Search term
	 * @return int[]        Category ids, descendants included
	 */
	public function findCategoryIdsByLabel($term)
	{
		$term = trim((string) $term);
		if ($term === '') {
			return array();
		}

		$needle = $this->db->escape($this->db->escapeforlike($term));

		$sql = "SELECT c.rowid FROM ".MAIN_DB_PREFIX."categorie as c";
		$sql .= " WHERE c.type = ".((int) self::CATEGORY_TYPE_PRODUCT);
		$sql .= " AND c.entity IN (".getEntity('category').")";
		$sql .= " AND c.label LIKE '%".$needle."%'";

		$matched = array();
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($o = $this->db->fetch_object($resql)) {
				$matched[] = (int) $o->rowid;
			}
			$this->db->free($resql);
		}

		if (empty($matched)) {
			return array();
		}

		// Matching a parent must also surface what sits under it.
		$all = array();
		foreach ($matched as $id) {
			foreach ($this->getDescendantIds($id) as $d) {
				$all[$d] = $d;
			}
		}

		return array_values($all);
	}

	/**
	 * Categories whose label matches a term, as browsable folders.
	 *
	 * Returned in the same shape as getChildCategories() so a search can offer
	 * "open this category" alongside the product hits, turning a search into a
	 * navigation shortcut.
	 *
	 * @param  string                        $term    Search term
	 * @param  array<string,mixed>           $filters Filters for the product counts
	 * @return array<int,array<string,mixed>>         Matching categories
	 */
	public function searchCategories($term, $filters = array())
	{
		$term = trim((string) $term);
		if ($term === '') {
			return array();
		}

		$needle = $this->db->escape($this->db->escapeforlike($term));

		$sql = "SELECT c.rowid, c.label, c.color, c.description, c.position";
		$sql .= " FROM ".MAIN_DB_PREFIX."categorie as c";
		$sql .= " WHERE c.type = ".((int) self::CATEGORY_TYPE_PRODUCT);
		$sql .= " AND c.entity IN (".getEntity('category').")";
		$sql .= " AND c.label LIKE '%".$needle."%'";

		// When the caller is searching inside a branch, the folders offered must
		// come from that same branch. Otherwise a scoped search can advertise a
		// category with items while listing no products, because the products
		// were filtered to the branch and the folders were not.
		$scope = isset($filters['category']) ? (int) $filters['category'] : 0;
		if ($scope > 0) {
			$inScope = $this->getDescendantIds($scope);
			if (empty($inScope)) {
				return array();
			}
			$sql .= " AND c.rowid IN (".$this->db->sanitize(implode(',', $inScope)).")";
		}

		$sql .= $this->archivedCategoryClause(!empty($filters['includeArchived']));
		$sql .= " ORDER BY c.label ASC";
		$sql .= $this->db->plimit(20, 0);

		return $this->fetchCategoryRows($sql);
	}

	/**
	 * Every product category as id => "Parent / Child / Leaf".
	 *
	 * Built from one flat SELECT and assembled in PHP, so showing a path on a
	 * search result costs nothing per row.
	 *
	 * @return array<int,string> Category id => display path
	 */
	public function getCategoryPathMap()
	{
		$sql = "SELECT c.rowid, c.label, c.fk_parent";
		$sql .= " FROM ".MAIN_DB_PREFIX."categorie as c";
		$sql .= " WHERE c.type = ".((int) self::CATEGORY_TYPE_PRODUCT);
		$sql .= " AND c.entity IN (".getEntity('category').")";

		$nodes = array();
		$resql = $this->db->query($sql);
		if (!$resql) {
			return array();
		}
		while ($o = $this->db->fetch_object($resql)) {
			$nodes[(int) $o->rowid] = array('label' => (string) $o->label, 'parent' => (int) $o->fk_parent);
		}
		$this->db->free($resql);

		$paths = array();
		foreach ($nodes as $id => $node) {
			$parts = array();
			$cur = $id;
			$seen = array();
			$guard = 0;

			// Depth-guarded: llx_categorie permits a cycle.
			while ($cur > 0 && isset($nodes[$cur]) && !isset($seen[$cur]) && $guard < self::MAX_TREE_DEPTH) {
				$seen[$cur] = true;
				$guard++;
				array_unshift($parts, $nodes[$cur]['label']);
				$cur = $nodes[$cur]['parent'];
			}

			$paths[$id] = implode(' / ', $parts);
		}

		return $paths;
	}

	/**
	 * Number of distinct products reachable under a category, subcategories included.
	 *
	 * @param  int                                        $catId   Category id
	 * @param  array{mode?:string,type?:int}              $filters Same filter shape as listProducts()
	 * @return int                                                 Product count
	 */
	public function countProductsInCategory($catId, $filters = array())
	{
		$ids = $this->getDescendantIds($catId);
		if (empty($ids)) {
			return 0;
		}

		$mode = self::normaliseMode(isset($filters['mode']) ? $filters['mode'] : '');
		$supplier = isset($filters['supplier']) ? (int) $filters['supplier'] : 0;

		$sql = "SELECT COUNT(DISTINCT p.rowid) as cnt";
		$sql .= " FROM ".MAIN_DB_PREFIX."categorie_product as cp";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product as p ON p.rowid = cp.fk_product";
		// The supplier filter must narrow the count too, otherwise a folder
		// advertises more items than opening it actually shows.
		if ($supplier > 0) {
			$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product_fournisseur_price as pfp";
			$sql .= " ON pfp.fk_product = p.rowid AND pfp.fk_soc = ".$supplier;
		}
		$sql .= " WHERE cp.fk_categorie IN (".$this->db->sanitize(implode(',', $ids)).")";
		$sql .= " AND p.entity IN (".getEntity('product').")";
		// Same exclusion as the listing, or a folder advertises a count the
		// catalogue then refuses to show.
		if (empty($filters['includeArchived'])) {
			$archivedIds = $this->getArchivedCategoryIds();
			if (!empty($archivedIds)) {
				$sql .= " AND NOT EXISTS (SELECT 1 FROM ".MAIN_DB_PREFIX."categorie_product as cpa";
				$sql .= " WHERE cpa.fk_product = p.rowid";
				$sql .= " AND cpa.fk_categorie IN (".$this->db->sanitize(implode(',', $archivedIds)).") )";
			}
		}
		$sql .= $this->saleStatusClause($mode);

		if (isset($filters['type']) && $filters['type'] >= 0) {
			$sql .= " AND p.fk_product_type = ".((int) $filters['type']);
		}

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return 0;
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);

		return $obj ? (int) $obj->cnt : 0;
	}

	/**
	 * SQL excluding the archived subtree from a category listing.
	 *
	 * Hiding archived products but still listing the folder they live in leaves a
	 * folder that opens onto nothing, which reads as a bug rather than as a
	 * policy.
	 *
	 * @param  bool   $includeArchived Skip the exclusion entirely
	 * @param  string $alias           Table alias for llx_categorie
	 * @return string                  SQL fragment, empty when nothing to exclude
	 */
	private function archivedCategoryClause($includeArchived, $alias = 'c')
	{
		if ($includeArchived) {
			return '';
		}

		$ids = $this->getArchivedCategoryIds();
		if (empty($ids)) {
			return '';
		}

		return " AND ".$alias.".rowid NOT IN (".$this->db->sanitize(implode(',', $ids)).")";
	}

	/**
	 * The archived category and everything beneath it.
	 *
	 * A single configured category marks a product as withdrawn. Descendants are
	 * included so the reason for archiving can be sub-categorised without every
	 * sub-category needing to be configured separately.
	 *
	 * @return int[] Category ids, empty when the setting is unset
	 */
	public function getArchivedCategoryIds()
	{
		$archived = getDolGlobalInt('DOLICATALOG_ARCHIVED_CATEGORY');
		if ($archived <= 0) {
			return array();
		}

		return $this->getDescendantIds($archived);
	}

	/**
	 * The JOIN and WHERE that define which products a set of filters selects.
	 *
	 * Shared by listProducts() and getFacets() so the counts beside a facet can
	 * never disagree with the list it filters. Display-only joins (warehouse
	 * stock, supplier price column) stay in listProducts; only conditions that
	 * change *which* rows match belong here.
	 *
	 * @param  array<string,mixed> $filters Filters
	 * @return array{joins:string,where:string,catIds:int[]} SQL fragments
	 */
	private function buildProductFilterSql($filters)
	{
		$mode = self::normaliseMode(isset($filters['mode']) ? $filters['mode'] : '');
		$supplier = isset($filters['supplier']) ? (int) $filters['supplier'] : 0;

		$joins = '';
		$where = " WHERE p.entity IN (".getEntity('product').")";
		$where .= $this->saleStatusClause($mode);

		// Category scoping.
		$catIds = array();
		if (!empty($filters['category'])) {
			$catId = (int) $filters['category'];
			$catIds = !empty($filters['deep']) ? $this->getDescendantIds($catId) : array($catId);
		}
		if (!empty($catIds)) {
			$joins .= " INNER JOIN ".MAIN_DB_PREFIX."categorie_product as cp ON cp.fk_product = p.rowid";
			$where .= " AND cp.fk_categorie IN (".$this->db->sanitize(implode(',', $catIds)).")";
		}

		// Supplier restriction as EXISTS rather than a join, so the same condition
		// can be reused without dragging the price column along with it.
		if ($supplier > 0) {
			$where .= " AND EXISTS (SELECT 1 FROM ".MAIN_DB_PREFIX."product_fournisseur_price as pfx";
			$where .= " WHERE pfx.fk_product = p.rowid AND pfx.fk_soc = ".$supplier.")";
		}

		if (isset($filters['type']) && $filters['type'] >= 0) {
			$where .= " AND p.fk_product_type = ".((int) $filters['type']);
		}

		// Explicit id list (favourites / recents rehydration).
		if (!empty($filters['ids']) && is_array($filters['ids'])) {
			$clean = array();
			foreach ($filters['ids'] as $one) {
				$one = (int) $one;
				if ($one > 0) {
					$clean[] = $one;
				}
			}
			if (empty($clean)) {
				$where .= " AND 1 = 0";
			} else {
				$where .= " AND p.rowid IN (".$this->db->sanitize(implode(',', $clean)).")";
			}
		}

		// Archived products are withdrawn from the catalogue unless explicitly
		// asked for. Applied in the shared filter rather than at each call site, so
		// it holds for the picker, the browse page, search, favourites and recents
		// alike, and so facet counts agree with the list they describe.
		if (empty($filters['includeArchived'])) {
			$archivedIds = $this->getArchivedCategoryIds();
			if (!empty($archivedIds)) {
				$where .= " AND NOT EXISTS (SELECT 1 FROM ".MAIN_DB_PREFIX."categorie_product as cpa";
				$where .= " WHERE cpa.fk_product = p.rowid";
				$where .= " AND cpa.fk_categorie IN (".$this->db->sanitize(implode(',', $archivedIds)).") )";
			}
		}

		// Cross-cutting tag filter.
		//
		// Attributes always narrow each other: Material AND Thread Size. Within
		// one attribute the caller chooses, because both readings are useful on
		// real data - two Thread Sizes selected means the reducer carrying both
		// (all) or anything in either size (any).
		$facets = $this->cleanFacetIds(isset($filters['facets']) ? $filters['facets'] : array());
		if (!empty($facets)) {
			$anyGroups = $this->cleanFacetIds(isset($filters['facetsAny']) ? $filters['facetsAny'] : array());
			$alias = 0;

			foreach ($this->bucketFacetsByGroup($facets) as $groupId => $ids) {
				// "Any" only means something with more than one value, and never
				// applies to loose tags, which have no attribute to be any of.
				$useAny = ($groupId > 0 && count($ids) > 1 && in_array($groupId, $anyGroups, true));

				if ($useAny) {
					$a = 'cpf'.$alias++;
					$where .= " AND EXISTS (SELECT 1 FROM ".MAIN_DB_PREFIX."categorie_product as ".$a;
					$where .= " WHERE ".$a.".fk_product = p.rowid";
					$where .= " AND ".$a.".fk_categorie IN (".$this->db->sanitize(implode(',', $ids)).") )";
					continue;
				}

				// One EXISTS per value: a single IN here would match a product
				// carrying any one of them, which is the other question.
				foreach ($ids as $one) {
					$a = 'cpf'.$alias++;
					$where .= " AND EXISTS (SELECT 1 FROM ".MAIN_DB_PREFIX."categorie_product as ".$a;
					$where .= " WHERE ".$a.".fk_product = p.rowid";
					$where .= " AND ".$a.".fk_categorie = ".((int) $one).")";
				}
			}
		}

		// Free-text search across a product's own text, plus the names of the
		// categories it belongs to. The product-text clauses are the original
		// behaviour and stay first; the category clause only ever widens the match.
		$search = isset($filters['search']) ? trim((string) $filters['search']) : '';
		if ($search !== '') {
			$needle = $this->db->escape($this->db->escapeforlike($search));
			$where .= " AND (p.ref LIKE '%".$needle."%'";
			$where .= " OR p.label LIKE '%".$needle."%'";
			$where .= " OR p.description LIKE '%".$needle."%'";
			$where .= " OR p.barcode LIKE '%".$needle."%'";

			$labelCatIds = $this->findCategoryIdsByLabel($search);
			if (!empty($labelCatIds)) {
				$where .= " OR EXISTS (SELECT 1 FROM ".MAIN_DB_PREFIX."categorie_product as cpm";
				$where .= " WHERE cpm.fk_product = p.rowid";
				$where .= " AND cpm.fk_categorie IN (".$this->db->sanitize(implode(',', $labelCatIds)).") )";
			}

			$where .= ")";
		}

		return array('joins' => $joins, 'where' => $where, 'catIds' => $catIds);
	}

	/**
	 * Group a facet selection by the attribute each value belongs to.
	 *
	 * Loose tags collect under key 0; they have no attribute, so they always
	 * narrow individually.
	 *
	 * @param  int[] $facets Selected category ids
	 * @return array<int,int[]> Attribute id => its selected values
	 */
	private function bucketFacetsByGroup($facets)
	{
		$groupMap = $this->getAttributeGroupMap();
		$buckets = array();

		foreach ($facets as $id) {
			$gid = isset($groupMap[$id]) ? (int) $groupMap[$id]['id'] : 0;
			$buckets[$gid][] = (int) $id;
		}

		return $buckets;
	}

	/**
	 * Normalise a facet selection to positive integers.
	 *
	 * @param  mixed $facets Raw selection
	 * @return int[]         Category ids
	 */
	private function cleanFacetIds($facets)
	{
		if (empty($facets) || !is_array($facets)) {
			return array();
		}

		$out = array();
		foreach ($facets as $one) {
			$one = (int) $one;
			if ($one > 0) {
				$out[$one] = $one;
			}
		}

		return array_values($out);
	}

	/**
	 * The configured roots whose children name an attribute.
	 *
	 * @return int[] Category ids
	 */
	public function getAttributeRootIds()
	{
		$raw = trim(getDolGlobalString('DOLICATALOG_ATTRIBUTE_ROOTS', ''));
		if ($raw === '') {
			return array();
		}

		$out = array();
		foreach (explode(',', $raw) as $chunk) {
			$id = (int) trim($chunk);
			if ($id > 0) {
				$out[$id] = $id;
			}
		}

		return array_values($out);
	}

	/**
	 * Map every category beneath an attribute root to the group that names it.
	 *
	 * The group is the ancestor whose parent is the attribute root, not the
	 * category's immediate parent. Those differ as soon as an attribute has any
	 * depth of its own - a value nested two levels down still belongs to the
	 * attribute at the top, not to the value above it.
	 *
	 * @return array<int,array{id:int,label:string,position:int}> Category id => its group
	 */
	public function getAttributeGroupMap()
	{
		$roots = $this->getAttributeRootIds();
		if (empty($roots)) {
			return array();
		}

		$sql = "SELECT c.rowid, c.label, c.fk_parent, c.position";
		$sql .= " FROM ".MAIN_DB_PREFIX."categorie as c";
		$sql .= " WHERE c.type = ".((int) self::CATEGORY_TYPE_PRODUCT);
		$sql .= " AND c.entity IN (".getEntity('category').")";

		$nodes = array();
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return array();
		}
		while ($o = $this->db->fetch_object($resql)) {
			$nodes[(int) $o->rowid] = array(
				'label' => (string) $o->label,
				'parent' => (int) $o->fk_parent,
				'position' => (int) $o->position,
			);
		}
		$this->db->free($resql);

		$rootLookup = array_flip($roots);
		$map = array();

		foreach ($nodes as $id => $node) {
			// A group node names an attribute; it is not itself a value.
			if (isset($rootLookup[$id]) || isset($rootLookup[$node['parent']])) {
				continue;
			}

			$cur = $id;
			$seen = array();
			$guard = 0;

			while ($cur > 0 && isset($nodes[$cur]) && !isset($seen[$cur]) && $guard < self::MAX_TREE_DEPTH) {
				$seen[$cur] = true;
				$guard++;

				$parent = $nodes[$cur]['parent'];
				if (isset($rootLookup[$parent])) {
					$map[$id] = array(
						'id' => $cur,
						'label' => $nodes[$cur]['label'],
						'position' => $nodes[$cur]['position'],
					);
					break;
				}

				$cur = $parent;
			}
		}

		return $map;
	}

	/**
	 * Tags carried by the products the current filters select.
	 *
	 * Dolibarr categories double as tags, so a product sitting in a broad
	 * category usually also carries cross-cutting ones - a range, a fitting, a
	 * status. This surfaces those with counts, so a broad category can be
	 * narrowed without leaving it.
	 *
	 * Selected tags narrow together: picking two returns products carrying both.
	 *
	 * Categories that define the current position are excluded, as are any the
	 * caller names in 'excludeCategories' - typically the folders it is drawing
	 * directly above. Both are already navigation, and offering them as filters
	 * would duplicate a control the user can see.
	 * Currently selected facets are always returned, even at zero, so a selected
	 * chip never disappears and strand the user with no way to switch it off.
	 *
	 * @param  array<string,mixed> $filters Same filters as listProducts()
	 * @param  int                 $limit   Maximum facets returned
	 * @return array<int,array<string,mixed>> Facets, most common first
	 */
	public function getFacets($filters = array(), $limit = 0)
	{
		// Rank-capped, not count-capped. Whatever count happens to sit at the
		// boundary reads as a minimum threshold to anyone using the filter, so the
		// cap is generous by default and the caller is told when it bites.
		if ($limit <= 0) {
			$limit = getDolGlobalInt('DOLICATALOG_MAX_FACETS', 200);
		}
		$limit = max(1, min((int) $limit, 500));

		$this->facetsTruncated = 0;

		$selected = $this->cleanFacetIds(isset($filters['facets']) ? $filters['facets'] : array());
		$anyGroups = $this->cleanFacetIds(isset($filters['facetsAny']) ? $filters['facetsAny'] : array());
		$groupMap = $this->getAttributeGroupMap();

		// Counts reflect every filter currently applied, so the number beside a
		// value is what you are left with after adding it.
		$rows = $this->countFacetRows($filters, $limit, null);

		// Only worth a second query when the cap was actually reached.
		if (count($rows) >= $limit) {
			$total = $this->countDistinctFacets($filters);
			$this->facetsTruncated = max(0, $total - count($rows));
		}

		// An attribute set to "any" is the exception. Counting its own values
		// against its own selection would leave every unselected one at zero, so
		// a third value could never be added to the set. Those are recounted
		// with that attribute's selection lifted - and only that one; the other
		// attributes still narrow it.
		foreach ($anyGroups as $groupId) {
			$inGroup = array();
			foreach ($selected as $id) {
				if (isset($groupMap[$id]) && (int) $groupMap[$id]['id'] === (int) $groupId) {
					$inGroup[] = $id;
				}
			}
			if (count($inGroup) < 1) {
				continue;
			}

			$siblings = array();
			foreach ($groupMap as $catId => $g) {
				if ((int) $g['id'] === (int) $groupId) {
					$siblings[] = (int) $catId;
				}
			}
			if (empty($siblings)) {
				continue;
			}

			$relaxed = $filters;
			$relaxed['facets'] = array_values(array_diff($selected, $inGroup));

			foreach ($this->countFacetRows($relaxed, $limit, $siblings) as $id => $row) {
				$rows[$id] = $row;
			}
		}

		$out = array();
		$seen = array();
		foreach ($rows as $id => $row) {
			$seen[$id] = true;
			$out[] = array(
				'id' => $id,
				'label' => $row['label'],
				'color' => $row['color'],
				'position' => $row['position'],
				'count' => $row['count'],
				'selected' => in_array($id, $selected, true) ? 1 : 0,
				'group_id' => isset($groupMap[$id]) ? $groupMap[$id]['id'] : 0,
				'group_label' => isset($groupMap[$id]) ? $groupMap[$id]['label'] : '',
				'group_position' => isset($groupMap[$id]) ? $groupMap[$id]['position'] : 0,
			);
		}

		// A selected facet pushed past the limit must still render, or it cannot
		// be unselected.
		$missing = array();
		foreach ($selected as $id) {
			if (!isset($seen[$id])) {
				$missing[] = $id;
			}
		}
		if (!empty($missing)) {
			$sql = "SELECT rowid, label, color FROM ".MAIN_DB_PREFIX."categorie";
			$sql .= " WHERE rowid IN (".$this->db->sanitize(implode(',', $missing)).")";
			$sql .= " AND entity IN (".getEntity('category').")";
			$resql = $this->db->query($sql);
			if ($resql) {
				while ($o = $this->db->fetch_object($resql)) {
					$mid = (int) $o->rowid;
					array_unshift($out, array(
						'id' => $mid,
						'label' => (string) $o->label,
						'color' => (string) $o->color,
						'position' => 0,
						'count' => 0,
						'selected' => 1,
						'group_id' => isset($groupMap[$mid]) ? $groupMap[$mid]['id'] : 0,
						'group_label' => isset($groupMap[$mid]) ? $groupMap[$mid]['label'] : '',
						'group_position' => isset($groupMap[$mid]) ? $groupMap[$mid]['position'] : 0,
					));
				}
				$this->db->free($resql);
			}
		}

		$this->sortFacets($out);

		return $out;
	}

	/**
	 * Count how many products in scope carry each category.
	 *
	 * @param  array<string,mixed> $filters    Filters defining the scope
	 * @param  int                 $limit      Maximum rows
	 * @param  int[]|null          $restrictTo Only count these categories, or null for all
	 * @return array<int,array<string,mixed>>  Category id => label/colour/position/count
	 */
	/**
	 * How many distinct tags the current filters could offer, ignoring the cap.
	 *
	 * Used only to report how many were dropped, so it runs solely when the cap
	 * was reached.
	 *
	 * @param  array<string,mixed> $filters Same filters as getFacets()
	 * @return int                          Distinct tag count
	 */
	private function countDistinctFacets($filters)
	{
		$scope = $this->buildProductFilterSql($filters);

		$sql = "SELECT COUNT(DISTINCT c.rowid) as cnt";
		$sql .= " FROM ".MAIN_DB_PREFIX."product as p";
		$sql .= $scope['joins'];
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."categorie_product as cpx ON cpx.fk_product = p.rowid";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."categorie as c ON c.rowid = cpx.fk_categorie";
		$sql .= $scope['where'];
		$sql .= " AND c.type = ".((int) self::CATEGORY_TYPE_PRODUCT);
		$sql .= " AND c.entity IN (".getEntity('category').")";

		$resql = $this->db->query($sql);
		if (!$resql) {
			return 0;
		}
		$o = $this->db->fetch_object($resql);
		$this->db->free($resql);

		return $o ? (int) $o->cnt : 0;
	}

	private function countFacetRows($filters, $limit, $restrictTo = null)
	{
		$scope = $this->buildProductFilterSql($filters);

		$exclude = array();
		if (!empty($scope['catIds'])) {
			$exclude = $scope['catIds'];
		}

		// Anything already on screen as a folder is redundant here: filtering by
		// it does the same as opening it, so offering both is just clutter. The
		// caller passes the folders it is about to draw, since only it knows
		// which ones survived its own hide-empty and search rules.
		foreach ($this->cleanFacetIds(isset($filters['excludeCategories']) ? $filters['excludeCategories'] : array()) as $id) {
			$exclude[] = $id;
		}
		$exclude = array_values(array_unique($exclude));

		$sql = "SELECT c.rowid, c.label, c.color, c.position, COUNT(DISTINCT p.rowid) as cnt";
		$sql .= " FROM ".MAIN_DB_PREFIX."product as p";
		$sql .= $scope['joins'];
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."categorie_product as cpx ON cpx.fk_product = p.rowid";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."categorie as c ON c.rowid = cpx.fk_categorie";
		$sql .= $scope['where'];
		$sql .= " AND c.type = ".((int) self::CATEGORY_TYPE_PRODUCT);
		$sql .= " AND c.entity IN (".getEntity('category').")";
		if (!empty($exclude)) {
			$sql .= " AND c.rowid NOT IN (".$this->db->sanitize(implode(',', $exclude)).")";
		}
		if (is_array($restrictTo)) {
			if (empty($restrictTo)) {
				return array();
			}
			$sql .= " AND c.rowid IN (".$this->db->sanitize(implode(',', $restrictTo)).")";
		}
		$sql .= " GROUP BY c.rowid, c.label, c.color, c.position";
		// Orders the *cap*, not the display: when there are more tags than the
		// limit allows, the most used are the ones worth keeping. Display order
		// is Dolibarr's category Position, applied in sortFacets().
		$sql .= " ORDER BY cnt DESC, c.label ASC";
		$sql .= $this->db->plimit($limit, 0);

		$out = array();
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return array();
		}
		while ($o = $this->db->fetch_object($resql)) {
			$out[(int) $o->rowid] = array(
				'label' => (string) $o->label,
				'color' => (string) $o->color,
				'position' => (int) $o->position,
				'count' => (int) $o->cnt,
			);
		}
		$this->db->free($resql);

		return $out;
	}

	/**
	 * Order facets for display, following Dolibarr's own category Position.
	 *
	 * This adds no ordering concept of its own: position is the native
	 * llx_categorie column (default 0), the same field the folder navigation in
	 * this class already sorts by. Setting it in Dolibarr is what makes a size
	 * list read 1/8, 1/4, 1/2 rather than alphabetically, where "1/2 in." would
	 * come first.
	 *
	 * The only thing done here beyond ordering is keeping a group's values
	 * adjacent, which the facet query cannot express because a facet's group is
	 * resolved by walking ancestors rather than by a column.
	 *
	 * @param  array<int,array<string,mixed>> $facets Facets, by reference
	 * @return void
	 */
	private function sortFacets(&$facets)
	{
		usort($facets, function ($a, $b) {
			$ag = !empty($a['group_id']);
			$bg = !empty($b['group_id']);

			// Attribute groups first, loose tags after them.
			if ($ag !== $bg) {
				return $ag ? -1 : 1;
			}

			// Keep a group's values together, groups in their own Position order.
			if ($ag) {
				if ($a['group_position'] !== $b['group_position']) {
					return $a['group_position'] - $b['group_position'];
				}
				if ($a['group_id'] !== $b['group_id']) {
					return strcasecmp($a['group_label'], $b['group_label']);
				}
			}

			if ($a['position'] !== $b['position']) {
				return $a['position'] - $b['position'];
			}

			return strcasecmp($a['label'], $b['label']);
		});
	}

	/**
	 * List products for the browser.
	 *
	 * @param array{
	 *   category?:int,
	 *   search?:string,
	 *   mode?:string,
	 *   type?:int,
	 *   warehouse?:int,
	 *   supplier?:int,
	 *   deep?:int,
	 *   ids?:int[],
	 *   userid?:int,
	 *   limit?:int
	 * } $filters Query filters
	 *
	 * @return array{rows:array<int,array<string,mixed>>,truncated:bool,total:int} Result set
	 */
	public function listProducts($filters = array())
	{
		global $user;

		$limit = isset($filters['limit']) ? (int) $filters['limit'] : $this->getRowLimit();
		$limit = max(1, min($limit, self::MAX_ROWS_HARD_LIMIT));

		$mode = self::normaliseMode(isset($filters['mode']) ? $filters['mode'] : '');
		$warehouse = isset($filters['warehouse']) ? (int) $filters['warehouse'] : 0;
		$supplier = isset($filters['supplier']) ? (int) $filters['supplier'] : 0;
		$userid = isset($filters['userid']) ? (int) $filters['userid'] : (is_object($user) ? (int) $user->id : 0);

		$scope = $this->buildProductFilterSql($filters);

		$sql = "SELECT DISTINCT p.rowid, p.ref, p.label, p.description, p.barcode,";
		$sql .= " p.price, p.price_ttc, p.price_base_type, p.tva_tx, p.fk_product_type,";
		$sql .= " p.duration, p.fk_unit, p.stock, p.tosell, p.tobuy, p.entity, p.cost_price";
		if ($warehouse > 0) {
			$sql .= ", ps.reel as stock_warehouse";
		}
		// On a purchase document the sale price is meaningless; surface the
		// best supplier unit price instead (scoped to the supplier when filtered).
		if ($mode === 'buy') {
			$sql .= ", pfpmin.buyprice as buyprice";
		}

		$sql .= " FROM ".MAIN_DB_PREFIX."product as p";
		$sql .= $scope['joins'];

		// Display-only joins. Neither may narrow the result set: the supplier
		// restriction lives in the shared filter as an EXISTS, so that facet
		// counts and this listing always agree on which products match.
		if ($warehouse > 0) {
			$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product_stock as ps ON ps.fk_product = p.rowid AND ps.fk_entrepot = ".$warehouse;
		}

		if ($mode === 'buy') {
			$sub = "SELECT fk_product, MIN(unitprice) as buyprice";
			$sub .= " FROM ".MAIN_DB_PREFIX."product_fournisseur_price";
			$sub .= " WHERE entity IN (".getEntity('product').")";
			if ($supplier > 0) {
				$sub .= " AND fk_soc = ".$supplier;
			}
			$sub .= " GROUP BY fk_product";

			$sql .= " LEFT JOIN (".$sub.") as pfpmin ON pfpmin.fk_product = p.rowid";
		}

		$sql .= $scope['where'];

		$sql .= " ORDER BY p.ref ASC";
		// Fetch one extra row so we can tell the caller the list was cut short.
		// One row beyond the page tells the caller more exists, without the cost
		// of a separate COUNT over the same filters.
		$offset = isset($filters['offset']) ? max(0, (int) $filters['offset']) : 0;
		$sql .= $this->db->plimit($limit + 1, $offset);

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			$this->errors[] = $this->error;
			return array('rows' => array(), 'truncated' => false, 'total' => 0);
		}

		$rows = array();
		$count = 0;
		$truncated = false;

		while ($obj = $this->db->fetch_object($resql)) {
			$count++;
			if ($count > $limit) {
				$truncated = true;
				break;
			}

			if ($mode === 'buy') {
				// Best supplier price, else the product's own cost price.
				$unit = (isset($obj->buyprice) && $obj->buyprice !== null)
					? (float) $obj->buyprice
					: (float) $obj->cost_price;
				$unitTtc = 0.0; // no reliable tax-inclusive purchase price to show
			} elseif ($mode === 'bom') {
				// A BOM cares about what a component costs, not what it sells for.
				$unit = (float) $obj->cost_price;
				$unitTtc = 0.0;
			} else {
				$unit = (float) $obj->price;
				$unitTtc = (float) $obj->price_ttc;
			}

			$rows[] = array(
				'id' => (int) $obj->rowid,
				'ref' => (string) $obj->ref,
				'label' => (string) $obj->label,
				// Strip first, then truncate: truncating raw HTML can cut mid-tag.
				'description' => dol_trunc($this->plainDescription($obj->description), 160),
				'barcode' => (string) $obj->barcode,
				'type' => (int) $obj->fk_product_type,
				'price' => $unit,
				'price_ttc' => $unitTtc,
				'price_base_type' => (string) $obj->price_base_type,
				'tva_tx' => (float) $obj->tva_tx,
				'duration' => (string) $obj->duration,
				'fk_unit' => (int) $obj->fk_unit,
				'stock' => ($warehouse > 0)
					? (float) (isset($obj->stock_warehouse) ? $obj->stock_warehouse : 0)
					: (float) $obj->stock,
			);
		}
		$this->db->free($resql);

		// Decorate with per-user flags and thumbnails only for the rows we kept.
		$this->decorateRows($rows, $userid);

		return array('rows' => $rows, 'truncated' => $truncated, 'total' => count($rows), 'offset' => $offset);
	}

	/**
	 * A product description reduced to readable plain text.
	 *
	 * Descriptions routinely contain markup entered through Dolibarr's rich text
	 * editor. The picker and the catalogue page both render this with
	 * textContent, so raw HTML would appear literally on screen as
	 * "<strong>Pressure:</strong> 20psi<br />".
	 *
	 * Block-level boundaries become spaces before the tags are removed, otherwise
	 * consecutive paragraphs run together into one word.
	 *
	 * @param  string $html Raw description
	 * @return string       Plain text, whitespace collapsed
	 */
	private function plainDescription($html)
	{
		$html = (string) $html;
		if ($html === '') {
			return '';
		}

		$text = preg_replace('/<\s*(br|\/p|\/div|\/li|\/tr|\/h[1-6]|\/td)\s*\/?>/i', ' ', $html);
		$text = dol_string_nohtmltag($text, 1);
		$text = preg_replace('/\s+/', ' ', (string) $text);

		return trim((string) $text);
	}

	/**
	 * Attach favourite flags and thumbnail URLs to a result set.
	 *
	 * @param  array<int,array<string,mixed>> $rows   Rows to decorate, by reference
	 * @param  int                            $userid User id for favourite lookup
	 * @return void
	 */
	private function decorateRows(&$rows, $userid)
	{
		if (empty($rows)) {
			return;
		}

		$ids = array();
		foreach ($rows as $r) {
			$ids[] = (int) $r['id'];
		}

		$favs = $this->getFavoriteIds($userid);
		$showImages = getDolGlobalInt('DOLICATALOG_SHOW_IMAGES', 1);

		// Where each product sits, so a search hit says what it belongs to
		// instead of appearing as a bare row with no context.
		$pathMap = $this->getCategoryPathMap();
		$memberships = array();

		$sql = "SELECT cp.fk_product, cp.fk_categorie";
		$sql .= " FROM ".MAIN_DB_PREFIX."categorie_product as cp";
		$sql .= " WHERE cp.fk_product IN (".$this->db->sanitize(implode(',', $ids)).")";

		$resql = $this->db->query($sql);
		if ($resql) {
			while ($o = $this->db->fetch_object($resql)) {
				$cid = (int) $o->fk_categorie;
				if (isset($pathMap[$cid])) {
					$memberships[(int) $o->fk_product][] = $pathMap[$cid];
				}
			}
			$this->db->free($resql);
		}

		foreach ($rows as $k => $r) {
			$pid = (int) $r['id'];
			$paths = isset($memberships[$pid]) ? $memberships[$pid] : array();
			sort($paths);

			$rows[$k]['is_favorite'] = in_array($pid, $favs, true) ? 1 : 0;
			if ($showImages) {
				$urls = $this->getThumbnailUrls($pid, (string) $r['ref']);
				// image paints immediately; image_hi is the better file to swap in.
				$rows[$k]['image'] = $urls['mini'];
				$rows[$k]['image_hi'] = $urls['small'];
			} else {
				$rows[$k]['image'] = '';
				$rows[$k]['image_hi'] = '';
			}
			$rows[$k]['paths'] = $paths;
		}
	}

	/**
	 * URL of the first photo of a product, or an empty string when it has none.
	 *
	 * Mirrors the two layouts Dolibarr supports for the product photo directory.
	 *
	 * @param  int    $productId Product id
	 * @param  string $ref       Product ref
	 * @return string            viewimage.php URL or ''
	 */
	/**
	 * Both thumbnail sizes for a product, plus the original as a last resort.
	 *
	 * Dolibarr generates exactly two: _mini at 128x72 and _small at 480x270.
	 * Picking whichever sorted first meant always taking _mini, which is fine
	 * behind a 38px row thumbnail and visibly upscaled behind a 220x132 card.
	 *
	 * Both are returned so the caller can choose by the size it actually renders
	 * at, and so a large surface can paint the small file immediately and swap in
	 * the better one when it arrives.
	 *
	 * @param  int    $productId Product id
	 * @param  string $ref       Product ref
	 * @return array{mini:string,small:string} URLs, empty strings when absent
	 */
	public function getThumbnailUrls($productId, $ref)
	{
		global $conf;

		include_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';

		$empty = array('mini' => '', 'small' => '');

		$base = $conf->product->dir_output;
		if (empty($base)) {
			return $empty;
		}

		if (getDolGlobalInt('PRODUCT_USE_OLD_PATH_FOR_PHOTO')) {
			$relative = get_exdir($productId, 2, 0, 0, null, 'product').$productId.'/photos';
		} else {
			$relative = dol_sanitizeFileName($ref);
		}

		$dir = $base.'/'.$relative;
		if (!is_dir($dir)) {
			return $empty;
		}

		$thumbs = dol_dir_list($dir.'/thumbs', 'files', 0, '\.(jpg|jpeg|png|gif|webp)$', '', 'name', SORT_ASC, 0, 1);

		$mini = '';
		$small = '';
		foreach ($thumbs as $t) {
			if ($mini === '' && strpos($t['name'], '_mini.') !== false) {
				$mini = $relative.'/thumbs/'.$t['name'];
			}
			if ($small === '' && strpos($t['name'], '_small.') !== false) {
				$small = $relative.'/thumbs/'.$t['name'];
			}
		}

		// No generated thumbnails: fall back to the original for both, rather than
		// showing nothing.
		if ($mini === '' && $small === '') {
			$originals = dol_dir_list($dir, 'files', 0, '\.(jpg|jpeg|png|gif|webp)$', '', 'name', SORT_ASC, 0, 1);
			if (empty($originals)) {
				return $empty;
			}
			$mini = $small = $relative.'/'.$originals[0]['name'];
		}

		// Only one size present: use it for both so nothing renders blank.
		if ($mini === '') {
			$mini = $small;
		}
		if ($small === '') {
			$small = $mini;
		}

		return array('mini' => $this->imageUrl($mini), 'small' => $this->imageUrl($small));
	}

	/**
	 * Wrap a document-relative product image path as a viewimage URL.
	 *
	 * @param  string $file Path relative to the product image directory
	 * @return string       URL, or '' when the path is empty
	 */
	private function imageUrl($file)
	{
		global $conf;

		if ($file === '') {
			return '';
		}

		return DOL_URL_ROOT.'/viewimage.php?modulepart=product&entity='.((int) $conf->entity).'&file='.urlencode($file);
	}

	/**
	 * Smallest thumbnail for a product.
	 *
	 * Retained for callers that render at row size, where _mini is correct.
	 *
	 * @param  int    $productId Product id
	 * @param  string $ref       Product ref
	 * @return string            URL or ''
	 */
	public function getThumbnailUrl($productId, $ref)
	{
		$urls = $this->getThumbnailUrls($productId, $ref);

		return $urls['mini'];
	}

	/**
	 * Product ids favourited by a user.
	 *
	 * @param  int   $userid User id
	 * @return int[]         Product ids
	 */
	public function getFavoriteIds($userid)
	{
		$userid = (int) $userid;
		if ($userid <= 0 || !getDolGlobalInt('DOLICATALOG_ENABLE_FAVORITES', 1)) {
			return array();
		}

		$sql = "SELECT f.fk_product FROM ".MAIN_DB_PREFIX."dolicatalog_favorite as f";
		$sql .= " WHERE f.fk_user = ".$userid;
		$sql .= " AND f.entity IN (".getEntity('dolicatalog').")";

		$out = array();
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$out[] = (int) $obj->fk_product;
			}
			$this->db->free($resql);
		}

		return $out;
	}

	/**
	 * Product ids most recently picked by a user, most recent first.
	 *
	 * @param  int   $userid User id
	 * @return int[]         Product ids
	 */
	public function getRecentIds($userid)
	{
		$userid = (int) $userid;
		if ($userid <= 0 || !getDolGlobalInt('DOLICATALOG_ENABLE_RECENT', 1)) {
			return array();
		}

		$limit = getDolGlobalInt('DOLICATALOG_RECENT_COUNT', 12);
		if ($limit < 1) {
			$limit = 12;
		}

		$sql = "SELECT r.fk_product FROM ".MAIN_DB_PREFIX."dolicatalog_recent as r";
		$sql .= " WHERE r.fk_user = ".$userid;
		$sql .= " AND r.entity IN (".getEntity('dolicatalog').")";
		$sql .= " ORDER BY r.date_last DESC";
		$sql .= $this->db->plimit($limit, 0);

		$out = array();
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$out[] = (int) $obj->fk_product;
			}
			$this->db->free($resql);
		}

		return $out;
	}

	/**
	 * Toggle a product in a user's favourites.
	 *
	 * @param  int $userid    User id
	 * @param  int $productId Product id
	 * @return int            1 if now a favourite, 0 if removed, -1 on error
	 */
	public function toggleFavorite($userid, $productId)
	{
		global $conf;

		$userid = (int) $userid;
		$productId = (int) $productId;
		if ($userid <= 0 || $productId <= 0) {
			$this->error = 'BadParameters';
			return -1;
		}

		$this->db->begin();

		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."dolicatalog_favorite";
		$sql .= " WHERE fk_user = ".$userid;
		$sql .= " AND fk_product = ".$productId;
		$sql .= " AND entity = ".((int) $conf->entity);

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		$existing = $this->db->fetch_object($resql);
		$this->db->free($resql);

		if ($existing) {
			$del = "DELETE FROM ".MAIN_DB_PREFIX."dolicatalog_favorite WHERE rowid = ".((int) $existing->rowid);
			if (!$this->db->query($del)) {
				$this->error = $this->db->lasterror();
				$this->db->rollback();
				return -1;
			}
			$this->db->commit();
			return 0;
		}

		$ins = "INSERT INTO ".MAIN_DB_PREFIX."dolicatalog_favorite (entity, fk_user, fk_product, date_creation)";
		$ins .= " VALUES (".((int) $conf->entity).", ".$userid.", ".$productId.", '".$this->db->idate(dol_now())."')";
		if (!$this->db->query($ins)) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}

		$this->db->commit();
		return 1;
	}

	/**
	 * Record that a user picked a set of products, and trim their history.
	 *
	 * @param  int   $userid     User id
	 * @param  int[] $productIds Product ids that were just added to a document
	 * @return void
	 */
	public function recordRecent($userid, $productIds)
	{
		global $conf;

		$userid = (int) $userid;
		if ($userid <= 0 || empty($productIds) || !getDolGlobalInt('DOLICATALOG_ENABLE_RECENT', 1)) {
			return;
		}

		$now = $this->db->idate(dol_now());

		foreach ($productIds as $pid) {
			$pid = (int) $pid;
			if ($pid <= 0) {
				continue;
			}

			$sql = "SELECT rowid, pick_count FROM ".MAIN_DB_PREFIX."dolicatalog_recent";
			$sql .= " WHERE fk_user = ".$userid." AND fk_product = ".$pid;
			$sql .= " AND entity = ".((int) $conf->entity);

			$resql = $this->db->query($sql);
			$row = $resql ? $this->db->fetch_object($resql) : null;
			if ($resql) {
				$this->db->free($resql);
			}

			if ($row) {
				$upd = "UPDATE ".MAIN_DB_PREFIX."dolicatalog_recent";
				$upd .= " SET pick_count = ".((int) $row->pick_count + 1).", date_last = '".$now."'";
				$upd .= " WHERE rowid = ".((int) $row->rowid);
				$this->db->query($upd);
			} else {
				$ins = "INSERT INTO ".MAIN_DB_PREFIX."dolicatalog_recent (entity, fk_user, fk_product, pick_count, date_last)";
				$ins .= " VALUES (".((int) $conf->entity).", ".$userid.", ".$pid.", 1, '".$now."')";
				$this->db->query($ins);
			}
		}

		$this->trimRecent($userid);
	}

	/**
	 * SQL fragment restricting products by their sale/purchase flags.
	 *
	 * @param  string $mode Normalised mode
	 * @return string       SQL fragment, possibly empty
	 */
	private function saleStatusClause($mode)
	{
		if ($mode === 'buy') {
			return " AND p.tobuy = 1";
		}
		if ($mode === 'bom' || $mode === 'all') {
			// Components need no sale or purchase flag, and 'all' is for callers
			// such as the product list, which shows everything regardless.
			return "";
		}

		return " AND p.tosell = 1";
	}

	/**
	 * Drop history rows beyond the configured retention count for a user.
	 *
	 * @param  int $userid User id
	 * @return void
	 */
	private function trimRecent($userid)
	{
		global $conf;

		$keep = getDolGlobalInt('DOLICATALOG_RECENT_COUNT', 12);
		if ($keep < 1) {
			$keep = 12;
		}

		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."dolicatalog_recent";
		$sql .= " WHERE fk_user = ".((int) $userid);
		$sql .= " AND entity = ".((int) $conf->entity);
		$sql .= " ORDER BY date_last DESC";
		$sql .= $this->db->plimit(1000, $keep);

		$resql = $this->db->query($sql);
		if (!$resql) {
			return;
		}

		$stale = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$stale[] = (int) $obj->rowid;
		}
		$this->db->free($resql);

		if (!empty($stale)) {
			$this->db->query("DELETE FROM ".MAIN_DB_PREFIX."dolicatalog_recent WHERE rowid IN (".$this->db->sanitize(implode(',', $stale)).")");
		}
	}
}
