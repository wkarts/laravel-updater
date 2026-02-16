<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Support;

use RuntimeException;

class BackupCloudUploader
{
    public function upload(string $localPath, array $settings): array
    {
        if (!is_file($localPath) || !is_readable($localPath)) {
            throw new RuntimeException('Arquivo local inválido para upload.');
        }

        $provider = (string) ($settings['provider'] ?? 'none');
        $prefix = trim((string) ($settings['prefix'] ?? 'updater/backups'), '/');
        $remoteName = ($prefix !== '' ? $prefix . '/' : '') . basename($localPath);

        return match ($provider) {
            'dropbox' => $this->uploadDropbox($localPath, $remoteName, (array) ($settings['dropbox'] ?? [])),
            'google-drive' => $this->uploadGoogleDrive($localPath, basename($localPath), (array) ($settings['google_drive'] ?? [])),
            's3', 'minio' => $this->uploadS3Compatible($localPath, $remoteName, (array) ($settings['s3'] ?? [])),
            default => throw new RuntimeException('Provedor de nuvem não suportado para upload.'),
        };
    }

    private function uploadDropbox(string $localPath, string $remoteName, array $cfg): array
    {
        $token = trim((string) ($cfg['access_token'] ?? ''));
        if ($token === '') {
            throw new RuntimeException('Token do Dropbox não configurado.');
        }

        $targetPath = '/' . ltrim($remoteName, '/');
        $content = (string) file_get_contents($localPath);

        $response = $this->curlJson(
            'https://content.dropboxapi.com/2/files/upload',
            [
                'Authorization: Bearer ' . $token,
                'Dropbox-API-Arg: ' . json_encode([
                    'path' => $targetPath,
                    'mode' => 'overwrite',
                    'autorename' => false,
                    'mute' => true,
                    'strict_conflict' => false,
                ], JSON_UNESCAPED_SLASHES),
                'Content-Type: application/octet-stream',
            ],
            $content
        );

        return [
            'provider' => 'dropbox',
            'remote_path' => (string) ($response['path_display'] ?? $targetPath),
            'id' => (string) ($response['id'] ?? ''),
        ];
    }

    private function uploadGoogleDrive(string $localPath, string $fileName, array $cfg): array
    {
        $clientId = trim((string) ($cfg['client_id'] ?? ''));
        $clientSecret = trim((string) ($cfg['client_secret'] ?? ''));
        $refreshToken = trim((string) ($cfg['refresh_token'] ?? ''));

        if ($clientId === '' || $clientSecret === '' || $refreshToken === '') {
            throw new RuntimeException('Credenciais OAuth do Google Drive incompletas.');
        }

        $tokenData = $this->curlForm(
            'https://oauth2.googleapis.com/token',
            [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'refresh_token' => $refreshToken,
                'grant_type' => 'refresh_token',
            ]
        );

        $accessToken = (string) ($tokenData['access_token'] ?? '');
        if ($accessToken === '') {
            throw new RuntimeException('Não foi possível obter access token do Google Drive.');
        }

        $folderId = trim((string) ($cfg['folder_id'] ?? ''));
        $metadata = ['name' => $fileName];
        if ($folderId !== '') {
            $metadata['parents'] = [$folderId];
        }

        $boundary = '----updater' . bin2hex(random_bytes(8));
        $body = "--{$boundary}\r\n";
        $body .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
        $body .= json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\r\n";
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: application/octet-stream\r\n\r\n";
        $body .= (string) file_get_contents($localPath) . "\r\n";
        $body .= "--{$boundary}--";

        $uploaded = $this->curlJson(
            'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&fields=id,name,webViewLink',
            [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: multipart/related; boundary=' . $boundary,
            ],
            $body
        );

        return [
            'provider' => 'google-drive',
            'id' => (string) ($uploaded['id'] ?? ''),
            'remote_path' => (string) ($uploaded['name'] ?? $fileName),
            'web_view_link' => (string) ($uploaded['webViewLink'] ?? ''),
        ];
    }

    private function uploadS3Compatible(string $localPath, string $remoteName, array $cfg): array
    {
        $endpoint = rtrim((string) ($cfg['endpoint'] ?? ''), '/');
        $region = trim((string) ($cfg['region'] ?? 'us-east-1'));
        $bucket = trim((string) ($cfg['bucket'] ?? ''));
        $accessKey = trim((string) ($cfg['access_key'] ?? ''));
        $secretKey = trim((string) ($cfg['secret_key'] ?? ''));
        $pathStyle = (bool) ($cfg['path_style'] ?? true);

        if ($endpoint === '' || $bucket === '' || $accessKey === '' || $secretKey === '') {
            throw new RuntimeException('Credenciais S3/MinIO incompletas.');
        }

        $method = 'PUT';
        $service = 's3';
        $host = parse_url($endpoint, PHP_URL_HOST);
        $scheme = parse_url($endpoint, PHP_URL_SCHEME) ?: 'https';
        if (!is_string($host) || $host === '') {
            throw new RuntimeException('Endpoint S3/MinIO inválido.');
        }

        $encodedKey = str_replace('%2F', '/', rawurlencode($remoteName));
        $canonicalUri = $pathStyle ? '/' . $bucket . '/' . ltrim($encodedKey, '/') : '/' . ltrim($encodedKey, '/');
        $requestHost = $pathStyle ? $host : $bucket . '.' . $host;
        $url = $scheme . '://' . $requestHost . $canonicalUri;

        $payload = (string) file_get_contents($localPath);
        $payloadHash = hash('sha256', $payload);

        $amzDate = gmdate('Ymd\THis\Z');
        $dateStamp = gmdate('Ymd');

        $canonicalHeaders = 'host:' . $requestHost . "\n" . 'x-amz-content-sha256:' . $payloadHash . "\n" . 'x-amz-date:' . $amzDate . "\n";
        $signedHeaders = 'host;x-amz-content-sha256;x-amz-date';
        $canonicalRequest = implode("\n", [
            $method,
            $canonicalUri,
            '',
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);

        $algorithm = 'AWS4-HMAC-SHA256';
        $credentialScope = $dateStamp . '/' . $region . '/' . $service . '/aws4_request';
        $stringToSign = implode("\n", [
            $algorithm,
            $amzDate,
            $credentialScope,
            hash('sha256', $canonicalRequest),
        ]);

        $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $secretKey, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        $authorization = $algorithm
            . ' Credential=' . $accessKey . '/' . $credentialScope
            . ', SignedHeaders=' . $signedHeaders
            . ', Signature=' . $signature;

        $this->curlRaw(
            $url,
            [
                'Authorization: ' . $authorization,
                'x-amz-content-sha256: ' . $payloadHash,
                'x-amz-date: ' . $amzDate,
                'Content-Type: application/octet-stream',
            ],
            $payload,
            'PUT'
        );

        return [
            'provider' => 's3',
            'remote_path' => $remoteName,
            'bucket' => $bucket,
            'endpoint' => $endpoint,
        ];
    }

    private function curlForm(string $url, array $fields): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Falha ao iniciar cURL.');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT => 60,
        ]);

        $response = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if (!is_string($response) || $code >= 300) {
            throw new RuntimeException('Falha HTTP ao obter token OAuth: ' . ($error ?: $response ?: 'erro desconhecido'));
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Resposta inválida do endpoint OAuth.');
        }

        return $decoded;
    }

    private function curlJson(string $url, array $headers, string $body): array
    {
        $raw = $this->curlRaw($url, $headers, $body, 'POST');
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Resposta JSON inválida do provedor de nuvem.');
        }

        return $decoded;
    }

    private function curlRaw(string $url, array $headers, string $body, string $method = 'POST'): string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Falha ao iniciar cURL.');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 120,
        ]);

        $response = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if (!is_string($response) || $code >= 300) {
            throw new RuntimeException('Falha no upload para nuvem: ' . ($error ?: $response ?: 'erro desconhecido'));
        }

        return $response;
    }
}
