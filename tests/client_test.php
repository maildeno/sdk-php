<?php

declare(strict_types=1);

use Maildeno\MaildenoClient;
use Maildeno\MaildenoError;
use Maildeno\NativeEngine;

/** Template JSON the fake API returns (empty rows -> deterministic scaffold). */
$BASE = [
    'template_id'    => 't1',
    'template_name'  => 'Welcome',
    'canvas'         => ['bg' => '#fff'],
    'rows'           => [],
    'schema_version' => '1.0',
];

// Constructor validation does not need an engine.
T::group('MaildenoClient constructor');
T::throws('throws on empty apiKey',
    static fn () => new MaildenoClient(['apiKey' => '']),
    MaildenoError::class, 'INVALID_API_KEY');

try {
    $enginePath = NativeEngine::locate();
} catch (MaildenoError) {
    $enginePath = null;
}

if ($enginePath === null) {
    T::group('MaildenoClient (render/cache/errors)');
    T::skip('client suite', 'no maildeno-engine binary bundled for this platform (bin/<platform>/)');
    return;
}

// One real engine, reused across all client instances below.
$engine = new NativeEngine($enginePath);
$apiKey = 'sk_test_' . \str_repeat('a', 64);

// ── Request shape ────────────────────────────────────────────────────────────
T::group('MaildenoClient request shape');

$tr = new FakeTransport(); $tr->pushJson(200, $BASE);
$c = new MaildenoClient(['apiKey' => 'k', 'baseUrl' => 'https://api.example.com/'], $tr, $engine);
try { $c->renderHtml('t1'); } catch (\Throwable) {}
T::ok('strips trailing slash from baseUrl',
    \str_contains($tr->lastUrl(), 'https://api.example.com/v1/sdk/template/t1'));

$tr = new FakeTransport(); $tr->pushJson(200, $BASE);
$c = new MaildenoClient(['apiKey' => 'k'], $tr, $engine);
try { $c->renderHtml('t1'); } catch (\Throwable) {}
T::ok('defaults baseUrl to api.maildeno.com',
    \str_contains($tr->lastUrl(), 'https://api.maildeno.com/v1/sdk/template/t1'));

$tr = new FakeTransport(); $tr->pushJson(200, $BASE);
$c = new MaildenoClient(['apiKey' => $apiKey], $tr, $engine);
try { $c->renderHtml('t1'); } catch (\Throwable) {}
T::ok('GET path contains /v1/sdk/template/{id}', \str_contains($tr->lastUrl(), '/v1/sdk/template/t1'));
T::ok('sends Authorization: Bearer header',
    \in_array('Authorization: Bearer ' . $apiKey, $tr->lastHeaders(), true));

$tr = new FakeTransport(); $tr->pushJson(200, $BASE);
$c = new MaildenoClient(['apiKey' => 'k'], $tr, $engine);
try { $c->renderMjml('t1'); } catch (\Throwable) {}
T::ok('passes target=mjml as query param', \str_contains($tr->lastUrl(), 'target=mjml'));

$tr = new FakeTransport(); $tr->pushJson(200, $BASE);
$c = new MaildenoClient(['apiKey' => 'k'], $tr, $engine);
try { $c->renderReact('t1'); } catch (\Throwable) {}
T::ok('passes target=react-email as query param', \str_contains($tr->lastUrl(), 'target=react-email'));

// ── In-process cache ─────────────────────────────────────────────────────────
T::group('MaildenoClient caching');

$tr = new FakeTransport(); $tr->pushJson(200, $BASE); // ONE response only
$c = new MaildenoClient(['apiKey' => 'k'], $tr, $engine);
$c->renderHtml('t1');
$c->renderHtml('t1'); // must be served from cache
T::eq('fetches once and reuses the cache', 1, $tr->calls);

$tr = new FakeTransport(); $tr->pushJson(200, $BASE); $tr->pushJson(200, $BASE);
$clk = new FakeClock(1_000_000);
$c = new MaildenoClient(['apiKey' => 'k', 'clock' => $clk, 'cache' => ['ttl' => 500]], $tr, $engine);
$c->renderHtml('t1');
$clk->advance(501);
$c->renderHtml('t1');
T::eq('re-fetches after TTL expires', 2, $tr->calls);

$tr = new FakeTransport(); $tr->pushJson(200, $BASE); $tr->pushJson(200, $BASE);
$c = new MaildenoClient(['apiKey' => 'k'], $tr, $engine);
$c->renderHtml('t1'); $c->deleteCached('t1'); $c->renderHtml('t1');
T::eq('deleteCached() forces a fresh fetch', 2, $tr->calls);

$tr = new FakeTransport(); $tr->pushJson(200, $BASE); $tr->pushJson(200, $BASE);
$c = new MaildenoClient(['apiKey' => 'k'], $tr, $engine);
$c->renderHtml('t1'); $c->invalidate('t1'); $c->renderHtml('t1');
T::eq('invalidate() is an alias for deleteCached()', 2, $tr->calls);

$tr = new FakeTransport(); $tr->pushJson(200, $BASE);
$c = new MaildenoClient(['apiKey' => 'k'], $tr, $engine);
$c->renderHtml('t1');
T::ok('listCached() contains the fetched id', \in_array('t1', $c->listCached(), true));
$c->clearCache();
T::ok('listCached() empty after clearCache()', $c->listCached() === []);

// ── Stale-on-error fallback ──────────────────────────────────────────────────
T::group('MaildenoClient stale-on-error');

$tr = new FakeTransport(); $tr->pushJson(200, $BASE); $tr->pushNetworkError();
$clk = new FakeClock(1_000_000);
$c = new MaildenoClient(['apiKey' => 'k', 'clock' => $clk, 'cache' => ['ttl' => 500]], $tr, $engine);
$c->renderHtml('t1');
$clk->advance(501);
$res = $c->render(['templateId' => 't1', 'target' => 'html']);
T::ok('fromStaleCache=true when server unreachable after TTL', $res->fromStaleCache === true);
T::ok('stale output is non-empty', \strlen($res->output) > 0);

$tr = new FakeTransport(); $tr->pushJson(200, $BASE); $tr->pushRaw(503, 'Service Unavailable');
$clk = new FakeClock(1_000_000);
$c = new MaildenoClient(['apiKey' => 'k', 'clock' => $clk, 'cache' => ['ttl' => 500]], $tr, $engine);
$c->renderHtml('t1'); $clk->advance(501);
$res = $c->render(['templateId' => 't1']);
T::ok('fromStaleCache=true on 5xx after TTL', $res->fromStaleCache === true);

$tr = new FakeTransport(); $tr->pushJson(200, $BASE);
$c = new MaildenoClient(['apiKey' => 'k'], $tr, $engine);
$res = $c->render(['templateId' => 't1']);
T::ok('fromStaleCache=false when fresh', $res->fromStaleCache === false);

$tr = new FakeTransport(); $tr->pushJson(200, $BASE); $tr->pushNetworkError(); $tr->pushJson(200, $BASE);
$clk = new FakeClock(1_000_000);
$c = new MaildenoClient(['apiKey' => 'k', 'clock' => $clk, 'cache' => ['ttl' => 500]], $tr, $engine);
$c->renderHtml('t1'); $clk->advance(501);
$stale = $c->render(['templateId' => 't1']);
T::ok('serves stale during outage', $stale->fromStaleCache === true);
$fresh = $c->render(['templateId' => 't1']);
T::ok('recovers fresh on next successful fetch', $fresh->fromStaleCache === false);

$tr = new FakeTransport(); $tr->pushNetworkError();
$c = new MaildenoClient(['apiKey' => 'k'], $tr, $engine);
T::throws('NETWORK_ERROR when down and no prior cache',
    static fn () => $c->renderHtml('t1'), MaildenoError::class, 'NETWORK_ERROR');

// ── Error mapping ────────────────────────────────────────────────────────────
T::group('MaildenoClient error mapping');
$mkErr = static function (int $status, mixed $detail) use ($engine): MaildenoClient {
    $tr = new FakeTransport();
    $tr->pushJson($status, ['detail' => $detail]);
    return new MaildenoClient(['apiKey' => 'k'], $tr, $engine);
};
T::throws('401 -> INVALID_API_KEY', static fn () => $mkErr(401, 'Invalid or missing API key.')->renderHtml('t1'), MaildenoError::class, 'INVALID_API_KEY');
T::throws('403 -> FORBIDDEN', static fn () => $mkErr(403, "no access to 'mjml'")->renderMjml('t1'), MaildenoError::class, 'FORBIDDEN');
T::throws('404 -> TEMPLATE_NOT_FOUND', static fn () => $mkErr(404, 'Template not found.')->renderHtml('bad'), MaildenoError::class, 'TEMPLATE_NOT_FOUND');
T::throws('422 -> RENDER_ERROR', static fn () => $mkErr(422, 'Render failed.')->renderHtml('t1'), MaildenoError::class, 'RENDER_ERROR');

$tr = new FakeTransport();
$tr->pushJson(422, ['detail' => [[
    'type' => 'uuid_parsing', 'loc' => ['body', 'template_id'],
    'msg' => 'Input should be a valid UUID', 'input' => 'zzz',
]]]);
$c = new MaildenoClient(['apiKey' => 'k'], $tr, $engine);
$err = null;
try { $c->renderHtml('zzz'); } catch (MaildenoError $e) { $err = $e; }
T::ok('422 validation error is a MaildenoError', $err instanceof MaildenoError);
T::ok('422 message includes the field', $err !== null && \str_contains($err->getMessage(), 'template_id'));
T::eq('422 exposes structured issues', 1, $err !== null && \is_array($err->issues) ? \count($err->issues) : -1);

$tr = new FakeTransport(); $tr->pushRaw(500, 'not json');
$c = new MaildenoClient(['apiKey' => 'k'], $tr, $engine);
$err = null;
try { $c->renderHtml('t1'); } catch (MaildenoError $e) { $err = $e; }
T::eq('unparseable 500 body -> UNKNOWN', 'UNKNOWN', $err?->code);
T::eq('unparseable 500 message -> HTTP 500', 'HTTP 500', $err?->getMessage());

$tr = new FakeTransport(); $tr->pushTimeout();
$c = new MaildenoClient(['apiKey' => 'k'], $tr, $engine);
T::throws('timeout -> TIMEOUT', static fn () => $c->renderHtml('t1'), MaildenoError::class, 'TIMEOUT');

// ── Full pipeline (fetch -> render -> minify) ────────────────────────────────
T::group('MaildenoClient render pipeline');
$tr = new FakeTransport(); $tr->pushJson(200, $BASE);
$c = new MaildenoClient(['apiKey' => 'k'], $tr, $engine);
$res = $c->render([
    'templateId'  => 't1',
    'target'      => 'html',
    'dynamicData' => ['merge_tags' => ['text' => ['name' => 'Noruwa']]],
]);
T::ok('output is a well-formed HTML document', \str_starts_with($res->output, '<!DOCTYPE html>'));
T::ok('output is minified (shorter than raw 2032)', \strlen($res->output) < 2032);
T::ok('inter-tag whitespace collapsed somewhere', \str_contains($res->output, '><'));
T::ok('result carries templateId and target', $res->templateId === 't1' && $res->target === 'html');
