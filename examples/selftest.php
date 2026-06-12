<?php
// examples/selftest.php
// Offline self-test of the parsing / scoping logic (no network, no backend).
// Validates the Mediative->PayloadCMS option translation and API-key auth.
// Run after any SDK change:  php examples/selftest.php  (exits 0 when all pass).
require __DIR__ . '/../autoload.php';

use Screenover\Api\Query\OptionParser;
use Screenover\Api\ScreenoverApi;
use Screenover\Api\Exception\AuthException;

$fail = 0;
function check($label, $got, $expected) {
    global $fail;
    $ok = $got === $expected;
    if (!$ok) { $fail++; }
    printf("[%s] %s\n", $ok ? 'PASS' : 'FAIL', $label);
    if (!$ok) { echo "   expected: " . var_export($expected, true) . "\n   got:      " . var_export($got, true) . "\n"; }
}

$p = new OptionParser();
check('where', urldecode($p->build(['where'=>'title%%test;created<2014-11-12'])),
    'where[and][0][title][like]=test&where[and][1][createdAt][less_than]=2014-11-12');
check('order', urldecode($p->build(['order'=>'created:DESC,title'])), 'sort=-createdAt,title');
check('order random', urldecode($p->build(['order'=>'random'])), 'sort=random');
check('fields', urldecode($p->build(['fields'=>'Media.id,Media.title'])), 'select[id]=true&select[title]=true');
check('recursive', $p->build(['recursive'=>-1]), 'depth=0');
check('limit offset', urldecode($p->build(['limit'=>'50,25'])), 'limit=25&page=3');
check('limit single', $p->build(['limit'=>'10']), 'limit=10');
check('empty', $p->build([]), '');

$c = new ScreenoverApi('id','KEY','demo.screenover.tv');
$c->auth();
check('apikey token', $c->getToken(), 'KEY');
check('authmode default', $c->getAuthMode(), ScreenoverApi::AUTH_API_KEY);

// domain validation
$threw = false;
try { new ScreenoverApi('a','b','https://x/y'); } catch (AuthException $e) { $threw = true; }
check('domain rejects url', $threw, true);

echo $fail === 0 ? "\nALL TESTS PASSED\n" : "\n$fail TEST(S) FAILED\n";
exit($fail === 0 ? 0 : 1);
