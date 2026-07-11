<?php

require __DIR__ . '/../autoload.php';


use Maildeno\MaildenoClient;

$client = new MaildenoClient([
    'apiKey' => \getenv('MAILDENO_API_KEY') ?: 'MAILDENO_API_KEY',
]);

$html = $client->renderHtml(
    'f0c729ac-c184-494a-baf6-9433edb63e7f',
    [
        'merge_tags' => [
            'text' => [
                'name' => 'James',
            ],
        ],
        'context'    => [
            'plan' => 'standard',
        ],
    ]
);

echo $html;