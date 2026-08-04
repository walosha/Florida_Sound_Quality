<?php
/**
 * Railway S3-compatible object storage.
 * Archives server-generated scorecard PDFs and optional judge-uploaded paper sheet images.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

/** Max optional paper sheet upload (bytes). */
const PAPER_SHEET_MAX_BYTES = 12 * 1024 * 1024;

/** @var array<string, string> MIME => extension */
const PAPER_SHEET_MIME_MAP = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'image/heic' => 'heic',
    'image/heif' => 'heif',
];

/**
 * Put an object to the configured bucket (SigV4).
 *
 * @return array{ok:bool,key:?string,url:?string,error:?string}
 */
function storeObject(string $key, string $body, string $contentType): array
{
    if (AWS_S3_BUCKET_NAME === '' || AWS_ACCESS_KEY_ID === '' || AWS_SECRET_ACCESS_KEY === '' || AWS_ENDPOINT_URL === '') {
        return ['ok' => false, 'key' => null, 'url' => null, 'error' => 'S3 is not configured.'];
    }

    $endpoint = rtrim(AWS_ENDPOINT_URL, '/');
    $endpointHost = parse_url($endpoint, PHP_URL_HOST) ?: '';
    $endpointScheme = parse_url($endpoint, PHP_URL_SCHEME) ?: 'https';
    $region = AWS_DEFAULT_REGION !== '' ? AWS_DEFAULT_REGION : 'auto';
    $bucket = AWS_S3_BUCKET_NAME;
    $urlStyle = strtolower(AWS_S3_URL_STYLE);

    $encodedKey = implode('/', array_map('rawurlencode', explode('/', $key)));

    if ($urlStyle === 'path') {
        $host = $endpointHost;
        $urlPath = '/' . rawurlencode($bucket) . '/' . $encodedKey;
        $putUrl = $endpoint . $urlPath;
    } else {
        $host = $bucket . '.' . $endpointHost;
        $urlPath = '/' . $encodedKey;
        $putUrl = $endpointScheme . '://' . $host . $urlPath;
    }

    $amzDate = gmdate('Ymd\THis\Z');
    $dateStamp = gmdate('Ymd');
    $payloadHash = hash('sha256', $body);

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
            'Content-Length: ' . (string) strlen($body),
        ],
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
    ]);

    $resp = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);

    if ($resp === false) {
        error_log('S3 put curl error: ' . $cerr);
        return ['ok' => false, 'key' => null, 'url' => null, 'error' => $cerr];
    }

    if ($status < 200 || $status >= 300) {
        error_log('S3 put failed (' . $status . '): ' . substr((string) $resp, 0, 300));
        return ['ok' => false, 'key' => null, 'url' => null, 'error' => 'S3 HTTP ' . $status];
    }

    return ['ok' => true, 'key' => $key, 'url' => $putUrl, 'error' => null];
}

/**
 * @return array{ok:bool,key:?string,url:?string,error:?string}
 */
function storeScorecardPdf(int $scoreId, string $pdfBinary, string $eventName): array
{
    $safeEvent = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $eventName) ?: 'event';
    $key = sprintf('scorecards/%d-%s-%s.pdf', $scoreId, $safeEvent, bin2hex(random_bytes(4)));

    return storeObject($key, $pdfBinary, 'application/pdf');
}

/**
 * Validate optional paper sheet upload from $_FILES entry.
 *
 * @param array<string, mixed>|null $file
 * @return array{ok:bool,skip:bool,error:?string,binary:?string,mime:?string,ext:?string}
 */
function validatePaperSheetUpload(?array $file): array
{
    if ($file === null || !isset($file['error']) || (int) $file['error'] === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'skip' => true, 'error' => null, 'binary' => null, 'mime' => null, 'ext' => null];
    }

    $err = (int) $file['error'];
    if ($err !== UPLOAD_ERR_OK) {
        $messages = [
            UPLOAD_ERR_INI_SIZE   => 'Image is too large.',
            UPLOAD_ERR_FORM_SIZE  => 'Image is too large.',
            UPLOAD_ERR_PARTIAL    => 'Upload was incomplete. Try again.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server upload misconfigured.',
            UPLOAD_ERR_CANT_WRITE => 'Could not save upload.',
            UPLOAD_ERR_EXTENSION  => 'Upload blocked by server.',
        ];
        return [
            'ok' => false,
            'skip' => false,
            'error' => $messages[$err] ?? 'Upload failed.',
            'binary' => null,
            'mime' => null,
            'ext' => null,
        ];
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    $size = (int) ($file['size'] ?? 0);
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'skip' => false, 'error' => 'Invalid upload.', 'binary' => null, 'mime' => null, 'ext' => null];
    }
    if ($size <= 0 || $size > PAPER_SHEET_MAX_BYTES) {
        return [
            'ok' => false,
            'skip' => false,
            'error' => 'Image must be under 12 MB.',
            'binary' => null,
            'mime' => null,
            'ext' => null,
        ];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmp) ?: '';
    if (!isset(PAPER_SHEET_MIME_MAP[$mime])) {
        return [
            'ok' => false,
            'skip' => false,
            'error' => 'Use a JPEG, PNG, WebP, or HEIC photo of the paper sheet.',
            'binary' => null,
            'mime' => null,
            'ext' => null,
        ];
    }

    $binary = file_get_contents($tmp);
    if ($binary === false || $binary === '') {
        return ['ok' => false, 'skip' => false, 'error' => 'Could not read upload.', 'binary' => null, 'mime' => null, 'ext' => null];
    }

    return [
        'ok' => true,
        'skip' => false,
        'error' => null,
        'binary' => $binary,
        'mime' => $mime,
        'ext' => PAPER_SHEET_MIME_MAP[$mime],
    ];
}

/**
 * @return array{ok:bool,key:?string,url:?string,error:?string}
 */
function storePaperSheetImage(int $scoreId, string $binary, string $mime, string $ext, string $eventName): array
{
    $safeEvent = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $eventName) ?: 'event';
    $key = sprintf('paper-sheets/%d-%s-%s.%s', $scoreId, $safeEvent, bin2hex(random_bytes(4)), $ext);

    return storeObject($key, $binary, $mime);
}
