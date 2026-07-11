<?php

declare(strict_types=1);

use Maildeno\Cache\DiskStore;
use Maildeno\Cache\MemoryStore;
use Maildeno\Cache\TemplateCache;

$tpl = static fn (string $id): array => [
    'template_id'    => $id,
    'template_name'  => 'n',
    'canvas'         => [],
    'rows'           => [],
    'schema_version' => '1.0',
];

// ── MemoryStore ──────────────────────────────────────────────────────────────
T::group('MemoryStore');
$clock = new FakeClock(1000);
$m = new MemoryStore(500, 3, $clock); // ttl 500ms, max 3

T::ok('fresh miss returns null', $m->getFresh('t1') === null);
$m->set('t1', $tpl('t1'));
T::eq('fresh hit returns template', 't1', $m->getFresh('t1')['template_id']);
$clock->advance(501);
T::ok('getFresh returns null when stale', $m->getFresh('t1') === null);
T::eq('getFallback returns stale entry', 't1', $m->getFallback('t1')['template_id']);
T::ok('getFallback null when never set', $m->getFallback('nope') === null);
$m->invalidate('t1');
T::ok('invalidate removes entry', $m->getFallback('t1') === null);
$m->invalidate('ghost'); // no-op
T::ok('invalidate on missing id is a no-op', true);

// Eviction: oldest by fetchedAt is dropped when adding a new key at capacity.
$c2 = new FakeClock(1000);
$m2 = new MemoryStore(100_000, 3, $c2);
$c2->advance(1); $m2->set('a', $tpl('a'));
$c2->advance(1); $m2->set('b', $tpl('b'));
$c2->advance(1); $m2->set('c', $tpl('c'));
$c2->advance(1); $m2->set('d', $tpl('d')); // evicts oldest 'a'
T::ok('evicts oldest at capacity', $m2->getFallback('a') === null);
T::ok('keeps second-oldest', $m2->getFallback('b') !== null);
T::ok('keeps newest', $m2->getFallback('d') !== null);
T::eq('size stays at maxEntries', 3, \count($m2->list()));

// Updating an existing key must NOT trigger eviction.
$c3 = new FakeClock(1000);
$m3 = new MemoryStore(100_000, 2, $c3);
$c3->advance(1); $m3->set('a', $tpl('a'));
$c3->advance(1); $m3->set('b', $tpl('b'));
$c3->advance(1); $m3->set('a', $tpl('a')); // update at capacity, no eviction
T::ok('update does not evict others', $m3->getFallback('b') !== null);
T::ok('update keeps the updated key', $m3->getFallback('a') !== null);

// ── DiskStore ────────────────────────────────────────────────────────────────
T::group('DiskStore');
$dir = \sys_get_temp_dir() . '/mdtest_' . \uniqid('', true);
$dc = new FakeClock(1000);
$d = new DiskStore($dir, 500, 3, $dc);

T::ok('getFresh null on miss (no file)', $d->getFresh('t1') === null);
T::ok('list empty when directory missing', $d->list() === []);
$d->set('t1', $tpl('t1'));
T::ok('set creates the cache directory', \is_dir($dir));
T::eq('getFresh returns template when fresh', 't1', $d->getFresh('t1')['template_id']);
$raw = \file_get_contents($dir . '/t1.json');
T::ok('writes single-line JSON (no newline)', !\str_contains($raw, "\n"));
$dc->advance(501);
T::ok('getFresh null when file is stale', $d->getFresh('t1') === null);
T::eq('getFallback returns stale entry', 't1', $d->getFallback('t1')['template_id']);

\file_put_contents($dir . '/corrupt.json', '{not valid json');
T::ok('getFallback null on corrupted file', $d->getFallback('corrupt') === null);

$d->set('t1', $tpl('t1')); // overwrite
T::eq('set overwrites an existing entry', 't1', $d->getFallback('t1')['template_id']);

\file_put_contents($dir . '/pending.json.tmp', 'x');
T::ok('list excludes .tmp files', !\in_array('pending.json', $d->list(), true) && !\in_array('pending', $d->list(), true));

$d->invalidate('t1');
T::ok('invalidate removes the file', $d->getFallback('t1') === null);
$d->invalidate('ghost');
T::ok('invalidate on missing file is a no-op', true);

$d->set('a/b*c', $tpl('x')); // non-UUID chars must be sanitised
T::ok('sanitises non-UUID characters in filename', \is_file($dir . '/a_b_c.json'));

$d->clear();
T::ok('clear removes all json files', $d->list() === []);
$missing = new DiskStore($dir . '/does-not-exist', 500, 3, $dc);
$missing->clear();
T::ok('clear on non-existent directory is a no-op', true);

// Disk eviction by oldest fetchedAt.
$dir2 = \sys_get_temp_dir() . '/mdtest2_' . \uniqid('', true);
$ec = new FakeClock(1000);
$de = new DiskStore($dir2, 100_000, 2, $ec);
$ec->advance(1); $de->set('a', $tpl('a'));
$ec->advance(1); $de->set('b', $tpl('b'));
$ec->advance(1); $de->set('c', $tpl('c')); // evicts oldest 'a'
T::ok('disk evicts oldest file at capacity', $de->getFallback('a') === null);
T::ok('disk keeps newest', $de->getFallback('c') !== null);
T::eq('disk count equals maxEntries', 2, \count($de->list()));

// Eviction scan must tolerate a corrupted file without throwing.
$dir3 = \sys_get_temp_dir() . '/mdtest3_' . \uniqid('', true);
@\mkdir($dir3, 0777, true);
\file_put_contents($dir3 . '/corrupt.json', '{bad');
$cc = new FakeClock(1000);
$dcorr = new DiskStore($dir3, 100_000, 2, $cc);
$cc->advance(1); $dcorr->set('a', $tpl('a'));
$cc->advance(1); $dcorr->set('b', $tpl('b')); // triggers enforceLimit with corrupt file
T::ok('eviction tolerates a corrupted file', true);

// ── TemplateCache facade ─────────────────────────────────────────────────────
T::group('TemplateCache facade');
$fc = new FakeClock(1000);
$facade = new TemplateCache(new MemoryStore(100_000, 10, $fc));
$facade->set('t1', $tpl('t1'));
T::eq('delegates getFresh', 't1', $facade->getFresh('t1')['template_id']);
T::ok('delegates list', $facade->list() === ['t1']);
$facade->invalidate('t1');
T::ok('delegates invalidate', $facade->getFallback('t1') === null);
