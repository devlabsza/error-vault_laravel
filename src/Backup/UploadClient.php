<?php

namespace ErrorVault\Laravel\Backup;

use ErrorVault\Laravel\ErrorVault;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin HTTP wrapper around the portal's chunked multipart upload endpoints.
 * Uses ErrorVault::endpointFor() so users can configure either
 * `https://error-vault.com/api/v1/errors` or the bare `.../api/v1` base.
 */
class UploadClient
{
    public function __construct(
        protected string $apiEndpoint,
        protected string $apiToken,
    ) {
    }

    /**
     * @return array{upload_id: string}
     */
    public function initiate(int $backupId, ?string $checksum, array $metadata): array
    {
        $response = $this->http()->post(
            ErrorVault::endpointFor($this->apiEndpoint, "backups/{$backupId}/upload/initiate"),
            [
                'checksum' => $checksum,
                'metadata' => $metadata,
            ]
        );

        if (!$response->successful()) {
            throw new RuntimeException('Initiate upload failed: HTTP ' . $response->status() . ' ' . $this->preview($response->body()));
        }

        $uploadId = $response->json('upload_id') ?? $response->json('data.upload_id');
        if (!$uploadId) {
            throw new RuntimeException('Initiate upload response missing upload_id: ' . $this->preview($response->body()));
        }

        return ['upload_id' => (string) $uploadId];
    }

    /**
     * Upload a single chunk with up to 3 retries (exponential backoff).
     *
     * @return array{etag: string}
     */
    public function uploadPart(int $backupId, string $uploadId, int $partNumber, string $body): array
    {
        $maxRetries = 3;
        $attempt = 0;
        $lastError = 'unknown';

        while ($attempt <= $maxRetries) {
            if ($attempt > 0) {
                sleep(2 ** $attempt);
            }

            try {
                $response = Http::withHeaders([
                    'X-API-Token' => $this->apiToken,
                    'Content-Type' => 'application/octet-stream',
                    'X-Upload-ID' => $uploadId,
                    'X-Part-Number' => (string) $partNumber,
                    'User-Agent' => 'ErrorVault-Laravel/' . ErrorVault::VERSION,
                ])
                    ->timeout(120)
                    ->withBody($body, 'application/octet-stream')
                    ->post(ErrorVault::endpointFor($this->apiEndpoint, "backups/{$backupId}/upload/part"));

                if ($response->successful()) {
                    $etag = $response->json('etag') ?? md5($body);
                    return ['etag' => (string) $etag];
                }

                $lastError = 'HTTP ' . $response->status() . ' ' . $this->preview($response->body());
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
            }

            $attempt++;
        }

        throw new RuntimeException("Part {$partNumber} upload failed after {$maxRetries} retries: {$lastError}");
    }

    public function complete(int $backupId, string $uploadId, array $parts): void
    {
        $response = $this->http()->post(
            ErrorVault::endpointFor($this->apiEndpoint, "backups/{$backupId}/upload/complete"),
            [
                'upload_id' => $uploadId,
                'parts' => $parts,
            ]
        );

        if (!$response->successful()) {
            throw new RuntimeException('Complete upload failed: HTTP ' . $response->status() . ' ' . $this->preview($response->body()));
        }
    }

    public function abort(int $backupId, string $uploadId): void
    {
        try {
            $this->http()->post(
                ErrorVault::endpointFor($this->apiEndpoint, "backups/{$backupId}/upload/abort"),
                ['upload_id' => $uploadId]
            );
        } catch (\Throwable $e) {
            // Abort is best-effort; don't mask the original error.
        }
    }

    public function fail(int $backupId, string $reason): void
    {
        try {
            $this->http()->post(
                ErrorVault::endpointFor($this->apiEndpoint, "backups/{$backupId}/fail"),
                ['reason' => mb_substr($reason, 0, 500)]
            );
        } catch (\Throwable $e) {
            // Nothing useful we can do if telling the portal also fails.
        }
    }

    /**
     * @return array{has_pending_backup: bool, backup: ?array}
     */
    public function pollPending(): array
    {
        $response = Http::withHeaders([
            'X-API-Token' => $this->apiToken,
            'User-Agent' => 'ErrorVault-Laravel/' . ErrorVault::VERSION,
        ])
            ->timeout(20)
            ->get(ErrorVault::endpointFor($this->apiEndpoint, 'backups/pending'));

        if (!$response->successful()) {
            throw new RuntimeException('Poll failed: HTTP ' . $response->status() . ' ' . $this->preview($response->body()));
        }

        $payload = $response->json('data', []);
        return [
            'has_pending_backup' => (bool) ($payload['has_pending_backup'] ?? false),
            'backup' => $payload['backup'] ?? null,
        ];
    }

    protected function http()
    {
        return Http::withHeaders([
            'X-API-Token' => $this->apiToken,
            'Content-Type' => 'application/json',
            'User-Agent' => 'ErrorVault-Laravel/' . ErrorVault::VERSION,
        ])->timeout(60);
    }

    protected function preview(string $body): string
    {
        return mb_strlen($body) > 200 ? mb_substr($body, 0, 200) . '…' : $body;
    }
}
