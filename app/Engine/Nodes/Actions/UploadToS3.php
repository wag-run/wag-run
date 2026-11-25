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
    /**
     * Amazon S3 storage classes an upload can ask for. Other S3-compatible
     * services may accept only some of them.
     *
     * @var list<string>
     */
    public const STORAGE_CLASSES = ['STANDARD', 'INTELLIGENT_TIERING', 'STANDARD_IA', 'ONEZONE_IA', 'GLACIER_IR', 'GLACIER', 'DEEP_ARCHIVE'];

    /**
     * What a bucket name may look like. The name becomes part of the address
     * the upload goes to, so nothing else is let through.
     */
    public const BUCKET_NAME = '/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/';

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
            new Field('credential', __('nodes.s3_upload.storage'), Field::CREDENTIAL, required: true, credentialTypes: ['s3'], description: __('nodes.s3_upload.add_one_under_credentials')),
            new Field('bucket', __('nodes.s3_upload.bucket'), Field::TEXT, required: true, templated: true, description: __('nodes.s3_upload.bucket_help')),
            new Field('key', __('nodes.s3_upload.key'), Field::TEXT, required: true, templated: true, description: __('nodes.s3_upload.key_help')),
            new Field('content', __('nodes.s3_upload.content'), Field::TEXTAREA, required: true, templated: true, description: __('nodes.s3_upload.content_help')),
            new Field('content_type', __('nodes.s3_upload.content_type'), Field::TEXT, templated: true, description: __('nodes.s3_upload.content_type_help')),
            new Field('storage_class', __('nodes.s3_upload.storage_class'), Field::SELECT, default: '', options: [
                '' => __('nodes.s3_upload.bucket_default'),
                ...array_combine(self::STORAGE_CLASSES, array_map(fn (string $class) => __('nodes.s3_upload.storage_classes.'.strtolower($class)), self::STORAGE_CLASSES)),
            ], description: __('nodes.s3_upload.storage_class_help')),
        ];
    }

    public function outputs(array $config = []): array
    {
        return [
            new Output('bucket', __('nodes.s3_upload.bucket'), 'string'),
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
            return NodeResult::fail(__('nodes.s3_upload.storage_not_found'), retryable: false);
        }

        $bucket = trim((string) $context->input('bucket'));

        if ($bucket === '') {
            return NodeResult::fail(__('nodes.s3_upload.bucket_is_empty'), retryable: false);
        }

        if (preg_match(self::BUCKET_NAME, $bucket) !== 1) {
            return NodeResult::fail(__('nodes.s3_upload.not_a_bucket_name', ['bucket' => $bucket]), retryable: false);
        }

        $key = ltrim(trim((string) $context->input('key')), '/');

        if ($key === '') {
            return NodeResult::fail(__('nodes.s3_upload.key_is_empty'), retryable: false);
        }

        $content = $context->input('content');
        $contentType = trim((string) $context->input('content_type')) ?: null;
        $storageClass = (string) $context->input('storage_class') ?: null;

        if ($storageClass !== null && ! in_array($storageClass, self::STORAGE_CLASSES, true)) {
            return NodeResult::fail(__('nodes.s3_upload.unknown_storage_class', ['class' => $storageClass]), retryable: false);
        }

        $allowlist = new AddressAllowlist($context->workspace()->internal_allowlist ?? []);

        try {
            if (WorkflowFiles::isReference($content)) {
                $file = $context->file($content) ?? throw new RuntimeException(__('run_files.file_not_found'));

                return NodeResult::complete($this->storage->putFile($credential, $allowlist, $bucket, $key, $file, $contentType, $storageClass));
            }

            $content = is_array($content) ? (string) json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : (string) $content;

            return NodeResult::complete($this->storage->put($credential, $allowlist, $bucket, $key, $content, $contentType, $storageClass));
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
