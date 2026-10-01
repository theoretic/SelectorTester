<?php namespace ProcessWire;

/**
 * Smoke test: runs SelectorTester against a bootstrapped ProcessWire install. Read-only.
 *
 * Usage: php tests/smoke.php /path/to/pw/webroot
 * Works with or without the module installed there: if the class is not loaded by the
 * install itself, the module file of this repo is required directly.
 *
 * @author AT
 */

if(PHP_SAPI !== 'cli') exit;

$root = rtrim(str_replace('\\', '/', $argv[1] ?? (getenv('PW_ROOT') ?: '')), '/');
if(!is_file("$root/index.php")) exit("Usage: php tests/smoke.php /path/to/pw/webroot\n");

//some site configs read it (StaticPages), CLI has none
$_SERVER['DOCUMENT_ROOT'] = $root;
include "$root/index.php";

$fails = 0;
function check($label, $ok, $info = '') {
	global $fails;
	if(!$ok) $fails++;
	echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok || $info === '' ? '' : "  [$info]") . "\n";
}
function throws(callable $fn) {
	try {
		$fn();
	} catch(\Throwable $e) {
		return $e->getMessage();
	}
	return '';
}

$config = $wire->config;
$pages = $wire->pages;
$users = $wire->users;
$templates = $wire->templates;

$users->setCurrentUser($users->get($config->superUserPageID));

if(!class_exists(__NAMESPACE__ . '\\SelectorTester', false)) {
	if($wire->modules->isInstalled('SelectorTester')) {
		$m = $wire->modules->get('SelectorTester');
	} else {
		require dirname(__DIR__) . '/SelectorTester.module.php';
		$m = $wire->wire(new SelectorTester());
	}
} else {
	$m = $wire->modules->get('SelectorTester');
}
/** @var SelectorTester $m */
$m->set('maxResults', 1000);

echo "PW " . $config->version . ", module from " . (new \ReflectionClass($m))->getFileName() . "\n\n";

$keys = array('id', 'title', 'name', 'template', 'status', 'statusNames', 'created', 'modified', 'createdBy', 'modifiedBy', 'path');

//basic shape
$d = $m->findRows('', 'id>0, sort=id', 0);
$row = $d['rows'][0] ?? array();
check('ok + rows', $d['ok'] === true && count($d['rows']) > 0);
check('row keys', array_keys($row) === $keys, implode(',', array_keys($row)));
check('first row is root, path /', ($row['id'] ?? 0) === 1 && ($row['path'] ?? '') === '/');
check('types', is_int($row['id']) && is_int($row['status']) && is_array($row['statusNames']) && is_string($row['title']));
check('created is Y-m-d H:i:s', (bool) preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $row['created'] ?? ''), $row['created'] ?? '');
check('user name resolved', ($row['createdBy'] ?? '') !== '' && ($row['createdBy'][0] ?? '#') !== '#', $row['createdBy'] ?? '');
check('total >= count', $d['total'] >= $d['count']);
check('JSON encodes', json_encode($d) !== false);

//paths against core, across admin, users, trash
$d = $m->findRows('', 'id>0, include=all, sort=-id, limit=300', 0);
$bad = array();
foreach($d['rows'] as $r) {
	$core = $pages->getPath($r['id']);
	if($core !== $r['path']) $bad[] = "$r[id]: $r[path] != $core";
}
check('paths equal $pages->getPath() for ' . count($d['rows']) . ' rows', !count($bad), implode('; ', array_slice($bad, 0, 5)));

//own limit
$d = $m->findRows('', 'id>0, limit=2', 0);
check('own limit: 2 rows, no more', $d['count'] === 2 && $d['userPaginates'] === true && $d['hasMore'] === false);
check('own limit: total counts all', $d['total'] > 2, (string) $d['total']);

//chunks
$m->set('maxResults', 3);
$all = $pages->findIDs('id>0, include=all, sort=id, limit=6');
$c1 = $m->findRows('', 'id>0, include=all', 0);
$c2 = $m->findRows('', 'id>0, include=all', 3);
$ids = array_merge(array_column($c1['rows'], 'id'), array_column($c2['rows'], 'id'));
check('chunk 1: 3 rows, has more', $c1['count'] === 3 && $c1['hasMore'] === true);
check('chunk total = count()', $c1['total'] === $pages->count('id>0, include=all'), "$c1[total]");
check('chunks 1+2 = first 6 by id', $ids === array_map('intval', array_values($all)), implode(',', $ids));
check('added sort=id tiebreak', strpos($c1['selector'], 'sort=id') !== false, $c1['selector']);
$c = $m->findRows('', 'parent=1, include=all', 0);
check('single parent keeps its own sort', strpos($c['selector'], 'sort=id') === false, $c['selector']);
$c = $m->findRows('', 'include=all, sort=-modified', 0);
check('user sort gets id tiebreak', strpos($c['selector'], 'sort=-modified, sort=id') !== false, $c['selector']);
$last = $m->findRows('', 'id>0, include=all', $c1['total'] - 1);
check('last chunk: 1 row, no more', $last['count'] === 1 && $last['hasMore'] === false && $last['total'] === $c1['total']);
$m->set('maxResults', 1000);

//template
$d = $m->findRows('admin', 'include=all', 0);
$tpls = array_unique(array_column($d['rows'], 'template'));
check('template filter', $d['count'] > 0 && $tpls === array('admin'), implode(',', $tpls));
check('template + selector AND', $m->findRows('admin', 'template=user, include=all', 0)['count'] === 0);

//errors
check('unknown template throws', throws(function() use($m) { $m->findRows('no-such-template-xyz', 'id>0'); }) !== '');
check('empty input throws', throws(function() use($m) { $m->findRows('', ''); }) !== '');
$msg = throws(function() use($m) { $m->findRows('', 'no_such_field_xyz=1'); });
check('unknown field throws', $msg !== '', $msg);
$msg = throws(function() use($m) { $m->findRows('', 'title@@@x'); });
check('bad operator throws', $msg !== '', $msg);

//findRaw options in the user selector must not change the shape
$d = $m->findRows('', 'id=1, objects=1, flat=1, entities=1', 0);
check('objects/flat/entities in selector ignored', isset($d['rows'][0]) && array_keys($d['rows'][0]) === $keys);

//status names
$trash = $m->findRows('', 'id=' . $config->trashPageID . ', include=all', 0);
check('status names', in_array('system', $trash['rows'][0]['statusNames'] ?? array(), true) || in_array('systemID', $trash['rows'][0]['statusNames'] ?? array(), true),
	implode(',', $trash['rows'][0]['statusNames'] ?? array()));

//paths edge cases
$paths = $m->resolvePaths(array(
	999999991 => array('parent_id' => 999999990, 'name' => 'orphan', 'templates_id' => 1),
	1 => array('parent_id' => 0, 'name' => 'home', 'templates_id' => 1),
));
check('orphan path empty, root /', $paths[999999991] === '' && $paths[1] === '/');

//detail
$home = $pages->get(1);
$detail = $m->pageDetail($home);
check('detail ok', $detail['ok'] === true && $detail['id'] === 1);
check('detail props [label, html]', count($detail['props']) > 5 && count($detail['props'][0]) === 2);
$fieldsOk = true;
foreach($detail['fields'] as $f) {
	if(!is_string($f['html']) || !is_string($f['name']) || !is_string($f['type'])) $fieldsOk = false;
}
check('detail fields have html (' . count($detail['fields']) . ')', $fieldsOk && count($detail['fields']) === count(array_filter(
	$home->getFields()->getArray(), function($f) { return !$f->type instanceof FieldtypeFieldsetOpen && !$f->type instanceof FieldtypePassword; })));
check('detail JSON encodes', json_encode($detail) !== false);
$userDetail = $m->pageDetail($users->get($config->superUserPageID));
$names = array_column($userDetail['fields'], 'name');
check('password field skipped', !in_array('pass', $names, true), implode(',', $names));

//form
$build = new \ReflectionMethod($m, 'buildForm');
$html = $build->invoke($m)->render();
check('form has CSRF token', strpos($html, '_post_token') !== false);
check('form has template options', strpos($html, "value='admin'") !== false || strpos($html, 'value="admin"') !== false);

//endpoints, as an ajax POST would reach them (no HTTP layer: status codes are not visible in CLI)
$input = $wire->input;
$csrf = $wire->session->CSRF;
$post = function(array $data, $token = true) use($input, $csrf) {
	foreach(array('template', 'selector', 'start', 'id', $csrf->getTokenName()) as $key) $input->post->remove($key);
	if($token) $data[$csrf->getTokenName()] = $csrf->getTokenValue();
	foreach($data as $key => $value) $input->post->set($key, $value);
};
$call = function($method) use($m) {
	$json = $m->$method();
	$data = json_decode((string) $json, true);
	return is_array($data) ? $data : array('raw' => $json);
};
$_SERVER['REQUEST_METHOD'] = 'POST';
$config->ajax = true;

$post(array('template' => '', 'selector' => 'id=1'));
$r = $call('executeFind');
check('find endpoint: rows', ($r['ok'] ?? false) === true && ($r['rows'][0]['id'] ?? 0) === 1, json_encode($r));
$post(array('template' => '', 'selector' => 'id=1'), false);
$r = $call('executeFind');
check('find endpoint: no token refused', ($r['error'] ?? false) === true && !isset($r['rows']), json_encode($r));
$post(array('template' => 'no-such-template-xyz', 'selector' => 'id=1'));
check('find endpoint: unknown template', ($call('executeFind')['error'] ?? false) === true);
$post(array('template' => '', 'selector' => ''));
check('find endpoint: empty input', ($call('executeFind')['error'] ?? false) === true);
$post(array('template' => '', 'selector' => str_repeat('x', 5000)));
check('find endpoint: too long', ($call('executeFind')['error'] ?? false) === true);
$post(array('template' => '', 'selector' => 'title@@@x'));
$r = $call('executeFind');
check('find endpoint: syntax error message', ($r['error'] ?? false) === true && ($r['message'] ?? '') !== '', json_encode($r));
$post(array('selector' => array('id=1')));
check('find endpoint: array input', ($call('executeFind')['error'] ?? false) === true);

$post(array('id' => 1));
$r = $call('executePage');
check('page endpoint: detail', ($r['ok'] ?? false) === true && ($r['id'] ?? 0) === 1, json_encode($r));
$post(array('id' => 999999999));
$r = $call('executePage');
check('page endpoint: 404 is an error', ($r['error'] ?? false) === true, json_encode($r));
$post(array('id' => 'x'));
check('page endpoint: bad id', ($call('executePage')['error'] ?? false) === true);

$_SERVER['REQUEST_METHOD'] = 'GET';
$post(array('id' => 1));
check('page endpoint: GET refused', ($call('executePage')['error'] ?? false) === true);
$_SERVER['REQUEST_METHOD'] = 'POST';

$users->setCurrentUser($users->getGuestUser());
$post(array('id' => 1));
check('page endpoint: guest refused', ($call('executePage')['error'] ?? false) === true);
$users->setCurrentUser($users->get($config->superUserPageID));
$config->ajax = false;

//speed: the whole tree in one chunk
$m->set('maxResults', 10000);
$t = microtime(true);
$d = $m->findRows('', 'id>0, include=all', 0);
echo sprintf("\n%d rows of %d in %.1f ms (findRows ms=%s)\n", $d['count'], $d['total'], (microtime(true) - $t) * 1000, $d['ms']);

echo $fails ? "\n$fails FAILED\n" : "\nall passed\n";
exit($fails ? 1 : 0);
