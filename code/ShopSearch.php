<?php
/**
 * Fulltext search index for shop buyables
 *
 * @author Mark Guinn <mark@adaircreative.com>
 * @date 08.29.2013
 * @package shop_search
 */
class ShopSearch extends Object
{
	/** @var string - class name of adapter class to use */
	private static $adapter_class = 'ShopSearchMysqlSimple';

	/** @var array - these classes will be added to the index - e.g. Category, Page, etc. */
	private static $searchable = array();

	/** @var bool - if true, all buyable models will be added to the index automatically  */
	private static $buyables_are_searchable = true;

	/** @var int - how many suggestions to provide */
	private static $suggest_limit = 10;

	/** @var bool */
	private static $suggest_enabled = true;

	/** @var string - these allow you to use different querystring params in you need to */
	private static $qs_query = 'q';
	private static $qs_filters = 'f';
	private static $qs_parent_search = '__ps';
	private static $qs_title = '__t';

	/**
	 * @var array - default search facets (price, category, etc)
	 *   Key    field name - e.g. Price - can be a VirtualFieldIndex field
	 *   Value  facet label - e.g. Search By Category - if the value is a relation or returns an array or
	 *          list all values will be faceted individually
	 */
	private static $facets = array();

	/**
	 * @return array
	 */
	public static function get_searchable_classes() {
		// First get any explicitly declared searchable classes
		$searchable = Config::inst()->get('ShopSearch', 'searchable');
		if (is_string($searchable) && strlen($searchable) > 0) {
			$searchable = array($searchable);
		} elseif (!is_array($searchable)) {
			$searchable = array();
		}

		// Add in buyables automatically if asked
		if (Config::inst()->get('ShopSearch', 'buyables_are_searchable')) {
			$buyables = SS_ClassLoader::instance()->getManifest()->getImplementorsOf('Buyable');
			if (is_array($buyables) && count($buyables) > 0) {
				foreach ($buyables as $c) {
					$searchable[] = $c;
				}
			}
		}

		return array_unique($searchable);
	}

	/**
	 * Returns an array of categories suitable for a dropdown menu
	 * TODO: cache this
	 *
	 * @param int $parentID [optional]
	 * @param string $prefix [optional]
	 * @return array
	 * @static
	 */
	public static function get_category_hierarchy($parentID = 0, $prefix = '') {
		$out = array();
		$cats = ProductCategory::get()->filter('ParentID', $parentID)->sort('Sort');

		foreach ($cats as $cat) {
			$out[$cat->ID] = $prefix . $cat->Title;
			$out += self::get_category_hierarchy($cat->ID, $prefix . $cat->Title . ' > ');
		}

		return $out;
	}

	/**
	 * @return ShopSearchAdapter
	 */
	public static function adapter() {
		$adapterClass = Config::inst()->get('ShopSearch', 'adapter_class');
		return Injector::inst()->get($adapterClass);
	}

	/**
	 * @return ShopSearch
	 */
	public static function inst() {
		return Injector::inst()->get('ShopSearch');
	}

	/**
	 * The result will contain at least the following:
	 *      Matches - SS_List of results
	 *      TotalMatches - total # of results, unlimited
	 *      Query - query string
	 * Also saves a log record.
	 *
	 * @param array $vars
	 * @param bool $logSearch [optional]
	 * @return ArrayData
	 */
	public function search(array $vars, $logSearch=true) {
		$qs_q   = $this->config()->get('qs_query');
		$qs_f   = $this->config()->get('qs_filters');
		$qs_ps  = $this->config()->get('qs_parent_search');
		$qs_t   = $this->config()->get('qs_title');
		$facets = $this->config()->get('facets');
		if (!is_array($facets)) $facets = array();

		// do the search
		$keywords = !empty($vars[$qs_q]) ? $vars[$qs_q] : '';
		$filters  = !empty($vars[$qs_f]) ? $vars[$qs_f] : array();
		$results  = self::adapter()->searchFromVars($keywords, $filters, $facets);

		// massage the results a bit
		if (!empty($keywords) && !$results->hasValue('Query')) $results->Query = $keywords;
		if (!empty($filters) && !$results->hasValue('Filters')) $results->Filters = new ArrayData($filters);
		if (!$results->hasValue('TotalMatches')) $results->TotalMatches = $results->Matches->count();

		// TODO: Paging
		// TODO: don't log multiple times for paging

		// save the log record
		if ($logSearch && (!empty($keywords) || !empty($filters))) {
			$log = new SearchLog(array(
				'Query'         => strtolower($results->Query),
				'Title'         => !empty($vars[$qs_t]) ? $vars[$qs_t] : '',
				'Link'          => Controller::curr()->getRequest()->getURL(true), // I'm not 100% happy with this, but can't think of a better way
				'NumResults'    => $results->TotalMatches,
				'MemberID'      => Member::currentUserID(),
				'Filters'       => !empty($filters) ? json_encode($filters) : null,
				'ParentSearchID'=> !empty($vars[$qs_ps]) ? $vars[$qs_ps] : 0,
			));
			$log->write();
			$results->SearchLogID = $log->ID;
			$results->SearchBreadcrumbs = $log->getBreadcrumbs();
		}

		return $results;
	}

	/**
	 * @param string $str
	 * @return SS_Query
	 */
	public function getSuggestQuery($str='') {
		$q = new SQLQuery();
		$q = $q->setSelect('"SearchLog"."Query"')
			// TODO: what to do with filter?
			->selectField('count(distinct "SearchLog"."ID")', 'SearchCount')
			->selectField('max("SearchLog"."Created")', 'LastSearch')
			->selectField('max("SearchLog"."NumResults")', 'NumResults')
			->setFrom('"SearchLog"')
			->setGroupBy('"SearchLog"."Query"')
			->setOrderBy(array('HasResults DESC', 'SearchCount DESC'))
			->setLimit(Config::inst()->get('ShopSearch', 'suggest_limit'))
		;

		if (DB::getConn() instanceof MySQLDatabase) {
			$q = $q->selectField('if(max("SearchLog"."NumResults") > 0, 1, 0)', 'HasResults');
		} else {
			// sqlite3 - should give 1 if there are any results and 0 otherwise
			$q = $q->selectField('min(1, max("SearchLog"."NumResults"))', 'HasResults');
		}

		if (strlen($str) > 0) {
			$q = $q->addWhere(sprintf('"SearchLog"."Query" LIKE \'%%%s%%\'', Convert::raw2sql($str)));
		}

		return $q;
	}

	/**
	 * @param string $str
	 * @return array
	 */
	public function suggest($str='') {
		return $this->getSuggestQuery($str)->execute()->column('Query');
	}
}