<?php

namespace App\Services;

use App\Models\Credential;
use App\Models\WorkflowFile;
use App\Support\AddressAllowlist;
use App\Support\NetworkGuard;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Writes to a bucket described by an S3-compatible storage setting. The
 * address the SDK connects to is vetted like any HTTP call's, and pinned so
 * it cannot change between the check and the request.
 */
class ObjectStorage
{
    /**
     * An AWS SDK handler to use instead of the network, for tests.
     *
     * @var callable|null
     */
    public $handler = null;

    public function __construct(protected NetworkGuard $guard, protected WorkflowFiles $files) {}

    /**
     * @return array{bucket: string, key: string, url: string, size: int}
     *
     * @throws RuntimeException when the storage address is not allowed
     */
    public function put(Credential $credential, AddressAllowlist $allowlist, string $key, string $contents, ?string $contentType = null): array
    {
        $disk = $this->disk($credential->config, $allowlist);
        $disk->put($key, $contents, array_filter(['ContentType' => $contentType]));

        return $this->written($credential, $disk, $key, strlen($contents));
    }

    /**
     * Upload a workflow file, streaming its bytes.
     *
     * @return array{bucket: string, key: string, url: string, size: int}
     */
    public function putFile(Credential $credential, AddressAllowlist $allowlist, string $key, WorkflowFile $file, ?string $contentType = null): array
    {
        $disk = $this->disk($credential->config, $allowlist);
        $stream = $this->files->readStream($file);

        try {
            $disk->writeStream($key, $stream, ['ContentType' => $contentType ?? $file->mime]);
        } finally {
            is_resource($stream) && fclose($stream);
        }

        return $this->written($credential, $disk, $key, $file->size);
    }

    /**
     * @return array{bucket: string, key: string, url: string, size: int}
     */
    protected function written(Credential $credential, Filesystem $disk, string $key, int $size): array
    {
        return [
            'bucket' => (string) $credential->config['bucket'],
            'key' => $key,
            'url' => $disk->url($key),
            'size' => $size,
        ];
    }

    /**
     * @param  array<string, mixed>  $config  the storage setting's config
     */
    public function disk(array $config, AddressAllowlist $allowlist): Filesystem
    {
        return Storage::build($this->diskConfig($config, $allowlist));
    }

    /**
     * The Laravel disk config for a storage setting.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public function diskConfig(array $config, AddressAllowlist $allowlist): array
    {
        $pathStyle = ($config['path_style'] ?? 'no') === 'yes';
        $region = trim((string) ($config['region'] ?? '')) ?: 'us-east-1';
        // Amazon S3 gets its regional endpoint spelled out, so the SDK
        // connects to the exact host vetted and pinned below.
        $endpoint = rtrim(trim((string) ($config['endpoint'] ?? '')), '/') ?: "https://s3.{$region}.amazonaws.com";
        $bucket = trim((string) ($config['bucket'] ?? ''));
        $target = $this->guard->check($this->requestUrl($endpoint, $bucket, $pathStyle), $allowlist);
        $ip = str_contains($target['ip'], ':') ? "[{$target['ip']}]" : $target['ip'];

        return array_filter([
            'driver' => 's3',
            'key' => (string) ($config['access_key_id'] ?? ''),
            'secret' => (string) ($config['secret_access_key'] ?? ''),
            'region' => $region,
            'bucket' => $bucket,
            'endpoint' => $endpoint,
            'use_path_style_endpoint' => $pathStyle,
            'throw' => true,
            'http' => [
                'connect_timeout' => 10,
                'timeout' => 120,
                'curl' => [CURLOPT_RESOLVE => ["{$target['host']}:{$target['port']}:{$ip}"]],
            ],
            'handler' => $this->handler,
        ], fn (mixed $value) => $value !== null);
    }

    /**
     * Where requests for the bucket go: the endpoint itself for path-style
     * addresses, else the bucket as a subdomain of it.
     */
    protected function requestUrl(string $endpoint, string $bucket, bool $pathStyle): string
    {
        if ($pathStyle) {
            return $endpoint;
        }

        $parts = parse_url($endpoint) ?: [];
        $port = isset($parts['port']) ? ":{$parts['port']}" : '';

        return ($parts['scheme'] ?? 'https').'://'.$bucket.'.'.($parts['host'] ?? '').$port;
    }
}
