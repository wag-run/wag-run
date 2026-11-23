<?php

namespace App\Engine\Nodes\Actions;

use App\Engine\Definitions\Field;
use App\Engine\Definitions\Output;
use App\Engine\Node;
use App\Engine\NodeContext;
use App\Engine\NodeResult;
use App\Enums\NodeKind;
use App\Services\ObjectStorage;
use App\Services\WorkflowFiles;
use App\Support\AddressAllowlist;
use Aws\Exception\AwsException;
use League\Flysystem\FilesystemException;
use RuntimeException;

/**
 * Writes to a file in an S3 or S3-compatible bucket, replacing any file
 * already at that key: text, such as an XML backup from an HTTP call, or a
 * file, such as a download, whose bytes are streamed as they are.
 */
class UploadToS3 extends Node
{
    public function __construct(protected ObjectStorage $storage) {}

    public static function type(): string
    {
        return 'core.s3_upload';
    }

    public function label(): string
    {
        return __('nodes.s3_upload.upload_to_s3');
    }

    public function description(): string
    {
        return __('nodes.s3_upload.writes_text_to_a_file');
    }

    public function kind(): NodeKind
    {
        return NodeKind::Action;
    }

    public function category(): string
    {
        return __('nodes.s3_upload.storage');
    }

    public function icon(): string
    {
        return 'cloud-arrow-up';
    }

    public function inputs(): array
    {
        return [
            new Field('credential', __('nodes.s3_upload.storage'), Field::CREDENTIAL, required: true, credentialTypes: ['s3'], description: __('nodes.s3_upload.add_one_under_authentication')),
            new Field('key', __('nodes.s3_upload.key'), Field::TEXT, required: true, templated: true, description: __('nodes.s3_upload.key_help')),
            new Field('content', __('nodes.s3_upload.content'), Field::TEXTAREA, required: true, templated: true, description: __('nodes.s3_upload.content_help')),
            new Field('content_type', __('nodes.s3_upload.content_type'), Field::TEXT, templated: true, description: __('nodes.s3_upload.content_type_help')),
        ];
    }

    public function outputs(array $config = []): array
    {
        return [
            new Output('bucket', __('credentials.s3.bucket'), 'string'),
            new Output('key', __('nodes.s3_upload.key'), 'string'),
            new Output('url', __('nodes.s3_upload.url'), 'string'),
            new Output('size', __('nodes.s3_upload.size_in_bytes'), 'number'),
        ];
    }

    public function timeout(): int
    {
        return 180;
    }

    public function execute(NodeContext $context): NodeResult
    {
        $credential = $context->credential($context->input('credential'));

        if (! $credential || $credential->type !== 's3') {
            return NodeResult::fail(__('nodes.s3_upload.storage_not_found', ['key' => $context->input('credential')]), retryable: false);
        }

        $key = ltrim(trim((string) $context->input('key')), '/');

        if ($key === '') {
            return NodeResult::fail(__('nodes.s3_upload.key_is_empty'), retryable: false);
        }

        $content = $context->input('content');
        $contentType = trim((string) $context->input('content_type')) ?: null;
        $allowlist = new AddressAllowlist($context->workspace()->internal_allowlist ?? []);

        try {
            if (WorkflowFiles::isReference($content)) {
                $file = $context->file($content) ?? throw new RuntimeException(__('run_files.file_not_found'));

                return NodeResult::complete($this->storage->putFile($credential, $allowlist, $key, $file, $contentType));
            }

            $content = is_array($content) ? (string) json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : (string) $content;

            return NodeResult::complete($this->storage->put($credential, $allowlist, $key, $content, $contentType));
        } catch (FilesystemException $e) {
            return $this->failure($e);
        } catch (RuntimeException $e) {
            return NodeResult::fail($e->getMessage(), retryable: false);
        }
    }

    protected function failure(FilesystemException $exception): NodeResult
    {
        $aws = $exception->getPrevious();

        if (! $aws instanceof AwsException) {
            return NodeResult::fail(__('nodes.s3_upload.upload_failed', ['message' => $exception->getMessage()]));
        }

        $status = $aws->getStatusCode();

        if ($status === null) {
            return NodeResult::fail(__('nodes.s3_upload.could_not_reach', ['message' => $aws->getMessage()]));
        }

        return NodeResult::fail(
            __('nodes.s3_upload.storage_answered', ['status' => $status, 'code' => $aws->getAwsErrorCode() ?? '', 'message' => $aws->getAwsErrorMessage() ?? '']),
            retryable: $status >= 500 || $status === 429 || $aws->getAwsErrorCode() === 'SlowDown',
        );
    }
}
