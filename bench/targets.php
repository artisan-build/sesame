<?php

// Writes vegeta JSON targets: distinct SES v2 SendEmail (Content.Raw) requests
// shaped exactly like Laravel's ses-v2 mailer sends them, ~24 KB MIME each
// (the size of Growth's benchmark fixture). Usage: php bench/targets.php URL AKID COUNT > targets.json
[$_, $url, $akid, $count] = $argv + [null, 'http://127.0.0.1:8000', 'AKIAEXAMPLE0000000', 1000];
$body = str_repeat('Lorem ipsum dolor sit amet, consectetur adipiscing elit. ', 420);
for ($i = 0; $i < (int) $count; $i++) {
    $to = "load-{$i}@load.crm.test";
    $mime = "From: Growth <growth@example.com>\r\nTo: {$to}\r\nSubject: Load message {$i}\r\nMessage-ID: <".bin2hex(random_bytes(8))."@example.com>\r\nDate: ".gmdate('D, d M Y H:i:s O')."\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=utf-8\r\n\r\n{$body}";
    $payload = json_encode([
        'ConfigurationSetName' => 'crm-load',
        'EmailTags' => [['Name' => 'outbound_message_id', 'Value' => (string) $i], ['Name' => 'contact_id', 'Value' => (string) $i]],
        'Source' => 'growth@example.com',
        'Destination' => ['ToAddresses' => [$to]],
        'Content' => ['Raw' => ['Data' => base64_encode($mime)]],
    ]);
    echo json_encode([
        'method' => 'POST',
        'url' => rtrim($url, '/').'/v2/email/outbound-emails',
        'body' => base64_encode($payload),
        'header' => [
            'Content-Type' => ['application/json'],
            'X-Amz-Date' => [gmdate('Ymd\THis\Z')],
            'Authorization' => ["AWS4-HMAC-SHA256 Credential={$akid}/".gmdate('Ymd').'/us-east-1/ses/aws4_request, SignedHeaders=content-type;host;x-amz-date, Signature='.hash('sha256', (string) $i)],
        ],
    ]), "\n";
}
