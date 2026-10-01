<?php namespace ProcessWire;

/**
 * ProcessWire Selector Tester
 *
 * Admin page (Setup > Selector Tester) to run a selector and inspect what it matches.
 * The form posts over fetch to ./find/ (JSON rows), a row click asks ./page/ (JSON detail).
 * Superuser only: it lists any page, user or system page the selector reaches.
 *
 * Licensed under MIT
 *
 * @author AT
 *
 * @property int $maxResults Rows per chunk ("get more" fetches the next chunk)
 *
 */

class SelectorTester extends Process implements ConfigurableModule {

	public static function getModuleInfo() {
		return array(
			'title' => 'Selector Tester',
			//an integer on purpose: loadModuleFileAssets() casts it into the ?v= of the JS/CSS URLs,
			//where '0.1.0' would become 0 and never bust the browser cache on upgrade. 10 = 0.1.0
			'version' => 10,
			'summary' => 'Run a selector in the admin and inspect the matching pages.',
			'author' => 'AT / atis.pro',
			'href' => 'http://atis.pro',
			'icon' => 'search',
			'singular' => true,
			'requires' => 'ProcessWire>=3.0.200, PHP>=8.1',
			//no 'permission' key on purpose: without one, ProcessController lets superusers in only
			//(ModulesLoader::hasPermission() in strict mode)
			'page' => array(
				'name' => 'selector-tester',
				'parent' => 'setup',
				'title' => 'Selector Tester',
			),
		);
	}

	const maxResultsDefault = 1000;

	const maxResultsLimit = 10000;

	//a selector longer than this is a paste accident, not a query
	const selectorMaxLength = 4096;

	//pages table columns fetched per row, one findRaw() call for the whole chunk
	const rawFields = array(
		'id', 'name', 'title', 'templates_id', 'parent_id', 'status',
		'created', 'modified', 'created_users_id', 'modified_users_id',
	);

	//findRaw() takes these from the selector string too ("objects=1" and the like) unless they are
	//set here (PagesRaw::init()), and any of them would change the shape of what comes back
	const rawOptions = array(
		'indexed' => true,
		'objects' => false,
		'entities' => false,
		'flat' => false,
		'nulls' => true,
		'options' => array(),
	);

	public function __construct() {
		parent::__construct();
		$this->set('maxResults', self::maxResultsDefault);
	}

	/**
	 * The page: form + empty results element, everything else happens over fetch
	 *
	 */
	public function ___execute() {
		$this->requireSuperuser();

		$config = $this->wire()->config;
		$url = $this->wire()->page->url;

		$config->jsConfig('SelectorTester', array(
			'findUrl' => $url . 'find/',
			'pageUrl' => $url . 'page/',
			'rootUrl' => rtrim($config->urls->root, '/'),
			'editUrl' => $config->urls->admin . 'page/edit/?id=',
			'labels' => array(
				'id' => $this->_('ID'),
				'title' => $this->_('Title'),
				'name' => $this->_('Name'),
				'template' => $this->_('Template'),
				'status' => $this->_('Status'),
				'created' => $this->_('Created'),
				'modified' => $this->_('Modified'),
				'createdBy' => $this->_('Created by'),
				'modifiedBy' => $this->_('Modified by'),
				'path' => $this->_('Path'),
				'view' => $this->_('view'),
				'edit' => $this->_('edit'),
				'filter' => $this->_('Filter rows'),
				'matches' => $this->_('%1$s of %2$s'),
				'shown' => $this->_('%s shown'),
				'more' => $this->_('Get more'),
				'none' => $this->_('No pages match.'),
				'noneFiltered' => $this->_('No rows match the filter.'),
				'fields' => $this->_('Fields'),
				'close' => $this->_('Close'),
				'empty' => $this->_('(empty)'),
			),
		));

		return $this->buildForm()->render() . '<selector-tester-results></selector-tester-results>';
	}

	/**
	 * JSON: matching rows for the posted template + selector, one chunk from "start"
	 *
	 */
	public function ___executeFind() {
		$error = $this->checkRequest();
		if($error) return $error;

		$input = $this->wire()->input;
		$sanitizer = $this->wire()->sanitizer;

		$templateName = $sanitizer->templateName((string) $input->post('template'));
		if($templateName !== '' && !$this->wire()->templates->get($templateName)) {
			return $this->jsonError(400, $this->_('Unknown template'));
		}

		//no sanitizer on purpose: text() strips "<" fragments, and "created<123" is a selector.
		//The string only ever reaches Selectors/PageFinder, which parse it and bind its values
		$selector = $input->post('selector');
		$selector = is_string($selector) ? trim($selector) : '';
		if(mb_strlen($selector) > self::selectorMaxLength) {
			return $this->jsonError(400, sprintf($this->_('Selector is longer than %d characters'), self::selectorMaxLength));
		}
		if($selector === '' && $templateName === '') {
			return $this->jsonError(400, $this->_('Enter a selector or choose a template'));
		}

		$start = max(0, (int) $input->post('start'));

		try {
			return $this->jsonOutput($this->findRows($templateName, $selector, $start));
		}
		catch( \Throwable $e ){
			//selector syntax errors, unknown fields and the like: the message is what the user needs to see
			return $this->jsonError(400, $e->getMessage());
		}
	}

	/**
	 * JSON: detail of one page (props + every field rendered by its Fieldtype)
	 *
	 */
	public function ___executePage() {
		$error = $this->checkRequest();
		if($error) return $error;

		$id = (int) $this->wire()->input->post('id');
		if($id < 1) return $this->jsonError(400, $this->_('Missing page ID'));

		//get() by ID: no access or status exclusions, trashed and unpublished pages included
		$page = $this->wire()->pages->get($id);
		if(!$page->id) return $this->jsonError(404, sprintf($this->_('Page %d not found'), $id));

		try {
			return $this->jsonOutput($this->pageDetail($page));
		}
		catch( \Throwable $e ){
			return $this->jsonError(500, $e->getMessage());
		}
	}

	/**
	 * Run the selector and build table rows
	 *
	 * Public for tests/smoke.php. Throws on an invalid selector.
	 *
	 * @param string $templateName Template name or '' for any
	 * @param string $selector User selector, may be ''
	 * @param int $start Offset of the chunk (ignored when the selector paginates by itself)
	 * @return array
	 * @throws WireException
	 *
	 */
	public function findRows($templateName, $selector, $start = 0) {
		$pages = $this->wire()->pages;
		$templates = $this->wire()->templates;
		$timer = Debug::timer();

		$templateName = (string) $templateName;
		$selector = trim((string) $selector);
		if($templateName !== '' && !$templates->get($templateName)) {
			throw new WireException($this->_('Unknown template'));
		}

		$parts = array();
		if($templateName !== '') $parts[] = "template=$templateName";
		if($selector !== '') $parts[] = $selector;
		$base = implode(', ', $parts);
		if($base === '') throw new WireException($this->_('Enter a selector or choose a template'));

		//parsing up front: a syntax error surfaces here, before any query runs
		$selectors = $this->wire(new Selectors());
		$selectors->init($base);

		$userPaginates = $selectors->getSelectorByField('limit') !== null || $selectors->getSelectorByField('start') !== null;
		$limit = max(1, min(self::maxResultsLimit, (int) $this->maxResults));
		$start = $userPaginates ? 0 : max(0, (int) $start);

		$findSelector = $base;
		if(!$userPaginates) {
			//PageFinder has no ORDER BY of its own unless a single parent= supplies one: without a
			//unique last sort, chunk 2 of "get more" could repeat or skip rows of chunk 1
			$hasSort = $selectors->getSelectorByField('sort') !== null;
			$hasParent = $selectors->getSelectorByField('parent') !== null || $selectors->getSelectorByField('parent_id') !== null;
			if($hasSort || !$hasParent) $findSelector .= ', sort=id';
			$findSelector .= ", start=$start, limit=$limit";
		}

		$rows = $pages->findRaw($findSelector, self::rawFields, self::rawOptions);

		//a short chunk is the last one, its end is the total: no count query needed
		if(!$userPaginates && count($rows) < $limit) {
			$total = $start + count($rows);
		} else {
			$total = $pages->count($base);
		}
		$hasMore = !$userPaginates && ($start + count($rows)) < $total;

		$users = $this->userNames($rows);
		$paths = $this->resolvePaths($rows);
		$templateNames = array();
		$out = array();

		foreach($rows as $id => $row) {
			$id = (int) $id;
			$tid = (int) $row['templates_id'];
			if(!isset($templateNames[$tid])) {
				$template = $templates->get($tid);
				$templateNames[$tid] = $template ? $template->name : "#$tid";
			}
			$status = (int) $row['status'];
			$createdBy = (int) $row['created_users_id'];
			$modifiedBy = (int) $row['modified_users_id'];
			$out[] = array(
				'id' => $id,
				'title' => $row['title'] === null ? '' : (string) $row['title'],
				'name' => (string) $row['name'],
				'template' => $templateNames[$tid],
				'status' => $status,
				'statusNames' => array_values(PageProperties::statusToNames($status)),
				'created' => (string) $row['created'],
				'modified' => (string) $row['modified'],
				'createdBy' => isset($users[$createdBy]) ? $users[$createdBy] : "#$createdBy",
				'modifiedBy' => isset($users[$modifiedBy]) ? $users[$modifiedBy] : "#$modifiedBy",
				'path' => isset($paths[$id]) ? $paths[$id] : '',
			);
		}

		return array(
			'ok' => true,
			'rows' => $out,
			'count' => count($out),
			'total' => $total,
			'start' => $start,
			'limit' => $limit,
			'hasMore' => $hasMore,
			'userPaginates' => $userPaginates,
			'selector' => $findSelector,
			'ms' => round(((float) Debug::timer($timer)) * 1000, 1),
		);
	}

	/**
	 * Names of the users who created or modified the given raw rows, one query
	 *
	 * @param array $rows findRaw() rows with created_users_id, modified_users_id
	 * @return array Names indexed by user ID
	 *
	 */
	public function userNames(array $rows) {
		$ids = array();
		foreach($rows as $row) {
			foreach(array('created_users_id', 'modified_users_id') as $key) {
				$id = (int) $row[$key];
				if($id > 0) $ids[$id] = $id;
			}
		}
		if(!count($ids)) return array();
		//a list of IDs skips PageFinder: one plain SELECT, no access or status filters
		$names = $this->wire()->pages->findRaw(array_values($ids), 'name', array('indexed' => true));
		return is_array($names) ? $names : array();
	}

	/**
	 * Paths of the given raw rows, default language
	 *
	 * findRaw() can return "path" only with PagePaths installed, and then picks an arbitrary language
	 * row on multi-language sites. Here: parents are fetched level by level, one query per tree depth
	 * for the whole chunk, then paths are joined like PagesLoader::getPath() does.
	 *
	 * @param array $rows findRaw() rows indexed by ID, with parent_id, name, templates_id
	 * @return array Paths indexed by page ID ('' when a parent is missing)
	 *
	 */
	public function resolvePaths(array $rows) {
		$pages = $this->wire()->pages;
		$templates = $this->wire()->templates;
		$rootId = (int) $this->wire()->config->rootPageID;

		//id => [parent_id, name, templates_id], false for a parent that does not exist
		$nodes = array();
		foreach($rows as $id => $row) {
			$nodes[(int) $id] = array((int) $row['parent_id'], (string) $row['name'], (int) $row['templates_id']);
		}

		for($depth = 0; $depth < 64; $depth++) {
			$missing = array();
			foreach($nodes as $node) {
				if($node === false) continue;
				$parentId = $node[0];
				if($parentId > 0 && !isset($nodes[$parentId])) $missing[$parentId] = $parentId;
			}
			if(!count($missing)) break;
			$found = $pages->findRaw(array_values($missing), array('parent_id', 'name', 'templates_id'), array('indexed' => true));
			foreach($missing as $parentId) {
				$row = isset($found[$parentId]) ? $found[$parentId] : null;
				$nodes[$parentId] = $row ? array((int) $row['parent_id'], (string) $row['name'], (int) $row['templates_id']) : false;
			}
		}

		//id => path with trailing slash (false when broken), shared by all rows below one parent
		$memo = array($rootId => '/');
		$slashUrls = array();
		$paths = array();

		foreach($rows as $id => $row) {
			$id = (int) $id;
			$chain = array();
			$current = $id;
			while(true) {
				if(array_key_exists($current, $memo)) {
					$base = $memo[$current];
					break;
				}
				$node = isset($nodes[$current]) ? $nodes[$current] : false;
				if($node === false || count($chain) > 256) {
					$base = false;
					break;
				}
				$chain[] = $current;
				if($node[0] === 0) {
					//a parentless page other than the root: nothing sane to prefix
					$base = '';
					break;
				}
				$current = $node[0];
			}
			for($i = count($chain) - 1; $i >= 0; $i--) {
				$chainId = $chain[$i];
				if($base !== false) $base = ($base === '' ? '/' : $base) . $nodes[$chainId][1] . '/';
				$memo[$chainId] = $base;
			}

			$path = $memo[$id];
			if($path === false) {
				$paths[$id] = '';
				continue;
			}
			if($path !== '/') {
				$tid = (int) $row['templates_id'];
				if(!isset($slashUrls[$tid])) {
					$template = $templates->get($tid);
					$slashUrls[$tid] = $template ? (bool) $template->slashUrls : true;
				}
				if(!$slashUrls[$tid]) $path = rtrim($path, '/');
			}
			$paths[$id] = $path;
		}

		return $paths;
	}

	/**
	 * Detail of one page for the modal
	 *
	 * Public for tests/smoke.php. Values in "props" and "fields" are HTML, entity encoded here
	 * or by the Fieldtype; "title" is plain text.
	 *
	 * @param Page $page
	 * @return array
	 *
	 */
	public function pageDetail(Page $page) {
		$sanitizer = $this->wire()->sanitizer;
		$config = $this->wire()->config;

		$e = function($value) use($sanitizer) {
			return $sanitizer->entities((string) $value);
		};
		$date = function($ts) use($e) {
			$ts = (int) $ts;
			if(!$ts) return '';
			return $e(wireDate('Y-m-d H:i:s', $ts)) . ' <span class="uk-text-muted">(' . $e(wireDate('relative', $ts)) . ')</span>';
		};
		$link = function($url, $label) use($e) {
			return "<a href='" . $e($url) . "' target='_blank' rel='noopener'>" . $e($label) . '</a>';
		};
		$muted = function($text) use($e) {
			return ' <span class="uk-text-muted">' . $e($text) . '</span>';
		};

		$template = $page->template;
		$parent = $page->parent;
		$statusNames = $page->status(true);

		$props = array(
			array($this->_('ID'), $e($page->id)),
			array($this->_('Name'), $e($page->name)),
			array($this->_('Path'), $link($page->httpUrl(), $page->path())),
			array($this->_('Template'), $template
				? $link($config->urls->admin . 'setup/template/edit?id=' . $template->id, $template->name)
					. ($template->label ? $muted($template->label) : '')
				: ''),
			array($this->_('Parent'), $parent->id ? $e($parent->path()) . $muted('#' . $parent->id) : ''),
			array($this->_('Status'), $e($page->status) . (count($statusNames) ? $muted(implode(', ', $statusNames)) : '')),
			array($this->_('Public'), $page->isPublic() ? $this->_('yes') : $this->_('no')),
			array($this->_('Children'), $e($page->numChildren())),
			array($this->_('Sort'), $e($page->sort)),
			array($this->_('Created'), $date($page->created) . $muted($page->createdUser->name)),
			array($this->_('Modified'), $date($page->modified) . $muted($page->modifiedUser->name)),
			array($this->_('Published'), $date($page->published)),
			array($this->_('Class'), $e(wireClassName($page, true))),
		);

		$fields = array();
		foreach($page->getFields() as $field) {
			/** @var Field $field */
			$type = $field->type;
			if(!$type || $type instanceof FieldtypeFieldsetOpen || $type instanceof FieldtypePassword) continue;
			//one broken Fieldtype (missing module, bad data) must not take the whole modal down
			try {
				$html = trim((string) $type->markupValue($page, $field));
			}
			catch( \Throwable $ex ){
				$html = '<em class="uk-text-danger">' . $e($ex->getMessage()) . '</em>';
			}
			$fields[] = array(
				'name' => $field->name,
				'label' => $field->getLabel(),
				'type' => $type->shortName,
				'html' => $html,
			);
		}

		return array(
			'ok' => true,
			'id' => $page->id,
			'title' => (string) $page->get('title|name'),
			'viewUrl' => $page->httpUrl(),
			'editUrl' => $page->editUrl(),
			'props' => $props,
			'fields' => $fields,
		);
	}

	public function getModuleConfigInputfields(InputfieldWrapper $inputfields) {
		/** @var InputfieldInteger $f */
		$f = $this->wire()->modules->get('InputfieldInteger');
		$f->attr('name', 'maxResults');
		$f->attr('value', (int) $this->maxResults);
		$f->label = $this->_('Rows per chunk');
		$f->description = $this->_('How many rows one search returns. "Get more" under the table fetches the next chunk. A limit= in the selector itself is used as is.');
		$f->inputType = 'number';
		$f->min = 1;
		$f->max = self::maxResultsLimit;
		$f->required = true;
		$inputfields->add($f);
	}

	/**
	 * Search form built from core Inputfields; InputfieldForm adds the CSRF token by itself
	 *
	 */
	protected function buildForm() {
		$modules = $this->wire()->modules;

		/** @var InputfieldForm $form */
		$form = $modules->get('InputfieldForm');
		$form->attr('id', 'SelectorTesterForm');
		$form->attr('method', 'post');
		$form->attr('action', './find/');

		$options = array();
		foreach($this->wire()->templates as $template) {
			/** @var Template $template */
			$label = $template->getLabel();
			$options[$template->name] = $label !== $template->name ? "$template->name ($label)" : $template->name;
		}
		ksort($options, SORT_NATURAL | SORT_FLAG_CASE);

		/** @var InputfieldSelect $f */
		$f = $modules->get('InputfieldSelect');
		$f->attr('id+name', 'template');
		$f->label = $this->_('Template');
		$f->addOption('', $this->_('Any template'));
		$f->addOptions($options);
		$f->columnWidth = 25;
		$form->add($f);

		/** @var InputfieldText $f */
		$f = $modules->get('InputfieldText');
		$f->attr('id+name', 'selector');
		$f->attr('maxlength', self::selectorMaxLength);
		$f->attr('placeholder', 'parent=/, sort=-modified');
		$f->attr('autocomplete', 'off');
		$f->attr('spellcheck', 'false');
		$f->label = $this->_('Selector');
		$f->notes = $this->_('Runs as superuser. Unpublished and trashed pages need include=unpublished or include=all. A limit= here turns off "get more". The template above is added with AND.');
		$f->columnWidth = 75;
		$form->add($f);

		/** @var InputfieldSubmit $f */
		$f = $modules->get('InputfieldSubmit');
		$f->attr('id+name', 'submit_find');
		$f->attr('value', $this->_('Find'));
		$f->icon = 'search';
		$form->add($f);

		return $form;
	}

	/**
	 * Common guard of both JSON endpoints, returns error JSON or '' when the request may go on
	 *
	 * The endpoints never throw for expected failures: admin.php would turn the exception into
	 * JSON with HTTP 200, and a Wire404Exception even into {"error":false}.
	 *
	 */
	protected function checkRequest() {
		$config = $this->wire()->config;
		$session = $this->wire()->session;

		//direct navigation to ./find/ or ./page/ lands on the tool, not on raw JSON
		if(!$config->ajax) $session->redirect('../');

		if(!$this->wire()->user->isSuperuser()) return $this->jsonError(403, $this->_('Superuser only'));
		if(!$this->wire()->input->requestMethod('POST')) return $this->jsonError(405, $this->_('POST only'));
		if($config->protectCSRF && !$session->CSRF->hasValidToken()) {
			return $this->jsonError(403, $this->_('Invalid CSRF token, reload the page'));
		}
		return '';
	}

	protected function requireSuperuser() {
		if(!$this->wire()->user->isSuperuser()) throw new WirePermissionException($this->_('Superuser only'));
	}

	protected function jsonOutput(array $data, $status = 200) {
		if(!headers_sent()) {
			http_response_code($status);
			header('Content-Type: application/json; charset=utf-8');
		}
		$json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
		if($json === false) {
			if(!headers_sent()) http_response_code(500);
			$json = json_encode(array('ok' => false, 'error' => true, 'message' => 'JSON: ' . json_last_error_msg()));
		}
		return $json;
	}

	protected function jsonError($status, $message) {
		//"error" mirrors ProcessController::jsonMessage(), so the client has one error path for both
		return $this->jsonOutput(array('ok' => false, 'error' => true, 'message' => (string) $message), $status);
	}

}
