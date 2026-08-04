<?php
/**
 * Server-side PDF archive on Railway S3-compatible object storage.
 * No client upload — only app-generated scorecards are stored.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * @return array{ok:bool,key:?string,url:?string,error:?string}
 */
function storeScorecardPdf(int $scoreId, string $pdfBinary, string $eventName): array
{
    if (AWS_S3_BUCKET_NAME === '' || AWS_ACCESS_KEY_ID === '' || AWS_SECRET_ACCESS_KEY === '' || AWS_ENDPOINT_URL === '') {
        return ['ok' => false, 'key' => null, 'url' => null, 'error' => 'S3 is not configured.'];
    }

    $safeEvent = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $eventName) ?: 'event';
    $key = sprintf('scorecards/%d-%s-%s.pdf', $scoreId, $safeEvent, bin2hex(random_bytes(4)));

    $endpoint = rtrim(AWS_ENDPOINT_URL, '/');
    $endpointHost = parse_url($endpoint, PHP_URL_HOST) ?: '';
    $endpointScheme = parse_url($endpoint, PHP_URL_SCHEME) ?: 'https';
    $region = AWS_DEFAULT_REGION !== '' ? AWS_DEFAULT_REGION : 'auto';
    $bucket = AWS_S3_BUCKET_NAME;
    $urlStyle = strtolower(AWS_S3_URL_STYLE);

    // Encode key path segments (keep slashes)
    $encodedKey = implode('/', array_map('rawurlencode', explode('/', $key)));

    if ($urlStyle === 'path') {
        $host = $endpointHost;
        $urlPath = '/' . rawurlencode($bucket) . '/' . $encodedKey;
        $putUrl = $endpoint . $urlPath;
    } else {
        // virtual-host (Railway default): https://bucket.endpoint/key
        $host = $bucket . '.' . $endpointHost;
        $urlPath = '/' . $encodedKey;
        $putUrl = $endpointScheme . '://' . $host . $urlPath;
    }

    $amzDate = gmdate('Ymd\THis\Z');
    $dateStamp = gmdate('Ymd');
    $payloadHash = hash('sha256', $pdfBinary);
    $contentType = 'application/pdf';

    $canonicalHeaders = "content-type:{$contentType}\n"
        . "host:{$host}\n"
        . "x-amz-content-sha256:{$payloadHash}\n"
        . "x-amz-date:{$amzDate}\n";
    $signedHeaders = 'content-type;host;x-amz-content-sha256;x-amz-date';

    $canonicalRequest = "PUT\n{$urlPath}\n\n{$canonicalHeaders}\n{$signedHeaders}\n{$payloadHash}";
    $credentialScope = "{$dateStamp}/{$region}/s3/aws4_request";
    $stringToSign = "AWS4-HMAC-SHA256\n{$amzDate}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);

    $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . AWS_SECRET_ACCESS_KEY, true);
    $kRegion = hash_hmac('sha256', $region, $kDate, true);
    $kService = hash_hmac('sha256', 's3', $kRegion, true);
    $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
    $signature = hash_hmac('sha256', $stringToSign, $kSigning);

    $authorization = 'AWS4-HMAC-SHA256 Credential=' . AWS_ACCESS_KEY_ID . '/' . $credentialScope
        . ', SignedHeaders=' . $signedHeaders
        . ', Signature=' . $signature;

    $ch = curl_init($putUrl);
    if ($ch === false) {
        return ['ok' => false, 'key' => null, 'url' => null, 'error' => 'Could not init HTTP client.'];
    }

    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => 'PUT',
        CURLOPT_HTTPHEADER     => [
            'Content-Type: ' . $contentType,
            'Host: ' . $host,
            'x-amz-content-sha256: ' . $payloadHash,
            'x-amz-date: ' . $amzDate,
            'Authorization: ' . $authorization,
            'Content-Length: ' . (string) strlen($pdfBinary),
        ],
        CURLOPT_POSTFIELDS     => $pdfBinary,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
    ]);

    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);

    if ($body === false) {
        error_log('S3 put curl error: ' . $cerr);
        return ['ok' => false, 'key' => null, 'url' => null, 'error' => $cerr];
    }

    if ($status < 200 || $status >= 300) {
        error_log('S3 put failed (' . $status . '): ' . substr((string) $body, 0, 300));
        return ['ok' => false, 'key' => null, 'url' => null, 'error' => 'S3 HTTP ' . $status];
    }

    return ['ok' => true, 'key' => $key, 'url' => $putUrl, 'error' => null];
}
