<?php

declare(strict_types=1);

use Maildeno\MaildenoError;

T::group('MaildenoError::fromStatus code mapping');
T::eq('401 -> INVALID_API_KEY', 'INVALID_API_KEY', MaildenoError::fromStatus(401, 'Invalid or missing API key.')->code);
T::eq('403 -> FORBIDDEN', 'FORBIDDEN', MaildenoError::fromStatus(403, "no access to 'mjml'")->code);
T::eq('404 -> TEMPLATE_NOT_FOUND', 'TEMPLATE_NOT_FOUND', MaildenoError::fromStatus(404, 'Template not found.')->code);
T::eq('422 -> RENDER_ERROR', 'RENDER_ERROR', MaildenoError::fromStatus(422, 'Render failed.')->code);
T::eq('500 -> UNKNOWN', 'UNKNOWN', MaildenoError::fromStatus(500, null)->code);
T::eq('status is preserved', 401, MaildenoError::fromStatus(401, 'x')->status);

T::group('MaildenoError detail formatting');
// String detail used verbatim.
T::eq('string detail as message', "no access to 'mjml'", MaildenoError::fromStatus(403, "no access to 'mjml'")->getMessage());
// Missing / non-issue detail -> HTTP <status>.
T::eq('null detail -> HTTP 500', 'HTTP 500', MaildenoError::fromStatus(500, null)->getMessage());
T::eq('object detail -> HTTP 500', 'HTTP 500', MaildenoError::fromStatus(500, ['foo' => 'bar'])->getMessage());
T::eq('empty-string detail -> HTTP 400', 'HTTP 400', MaildenoError::fromStatus(400, '')->getMessage());

T::group('MaildenoError validation (422) array');
$detail = [[
    'type'  => 'uuid_parsing',
    'loc'   => ['body', 'template_id'],
    'msg'   => 'Input should be a valid UUID, invalid character `z`',
    'input' => 'zzz-not-a-uuid',
]];
$err = MaildenoError::fromStatus(422, $detail);
T::eq('validation code is RENDER_ERROR', 'RENDER_ERROR', $err->code);
T::ok('message contains field name', \str_contains($err->getMessage(), 'template_id'));
T::ok('message contains the msg text', \str_contains($err->getMessage(), 'valid UUID'));
T::ok('message drops leading "body"', !\str_contains($err->getMessage(), 'body.template_id'));
T::eq('message is "field: msg"', 'template_id: Input should be a valid UUID, invalid character `z`', $err->getMessage());
T::eq('issues has one entry', 1, \is_array($err->issues) ? \count($err->issues) : -1);
T::eq('issue loc preserved', ['body', 'template_id'], $err->issues[0]['loc']);

// Multiple issues joined with "; "; non-"body" leading segments are kept.
$multi = [
    ['loc' => ['body', 'a'], 'msg' => 'bad a'],
    ['loc' => ['query', 'b'], 'msg' => 'bad b'],
];
T::eq('multiple issues joined with semicolons', 'a: bad a; query.b: bad b', MaildenoError::fromStatus(422, $multi)->getMessage());

T::group('MaildenoError direct construction');
$n = new MaildenoError('NETWORK_ERROR', 'down');
T::eq('network error status is 0', 0, $n->status);
T::ok('issues null by default', $n->issues === null);
T::ok('is a Throwable', $n instanceof \Throwable);
