<?php

namespace App\Engine\Nodes\Actions;

use App\Engine\Definitions\Field;
use App\Engine\Definitions\Output;
use App\Engine\Node;
use App\Engine\NodeContext;
use App\Engine\NodeResult;
use App\Enums\NodeKind;
use RuntimeException;

/**
 * Makes a file from text, or from base64 such as an API's encoded image,
 * for nodes that take files: Upload to S3, Send email attachments, HTTP
 * call uploads and LLM call images.
 */
class CreateFile extends Node
{
    public static function type(): string
    {
        return 'core.create_file';
    }

    public function label(): string
    {
        return __('nodes.create_file.create_file');
    }

    public function description(): string
    {
        return __('nodes.create_file.makes_a_file_from_text');
    }

    public function kind(): NodeKind
    {
        return NodeKind::Action;
    }

    public function category(): string
    {
        return __('nodes.compose.data');
    }

    public function icon(): string
    {
        return 'document-plus';
    }

    public function inputs(): array
    {
        return [
            new Field('name', __('nodes.create_file.file_name'), Field::TEXT, required: true, templated: true, description: __('nodes.create_file.file_name_help')),
            new Field('content', __('nodes.create_file.content'), Field::TEXTAREA, templated: true),
            new Field('encoding', __('nodes.create_file.content_is'), Field::SELECT, default: 'text', options: [
                'text' => __('nodes.create_file.text'),
                'base64' => __('nodes.create_file.base64'),
            ]),
            new Field('content_type', __('nodes.s3_upload.content_type'), Field::TEXT, templated: true, description: __('nodes.create_file.content_type_help')),
        ];
    }

    public function outputs(array $config = []): array
    {
        return [new Output('file', __('nodes.create_file.file'), 'file')];
    }

    public function execute(NodeContext $context): NodeResult
    {
        $name = trim((string) $context->input('name'));
        $content = $context->input('content');
        $content = is_array($content) ? (string) json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : (string) $content;

        if ($name === '') {
            return NodeResult::fail(__('nodes.create_file.name_is_empty'), retryable: false);
        }

        if ($context->input('encoding') === 'base64') {
            $decoded = base64_decode(preg_replace('/\s+/', '', $content) ?? '', true);

            if ($decoded === false) {
                return NodeResult::fail(__('nodes.create_file.not_valid_base64'), retryable: false);
            }

            $content = $decoded;
        }

        try {
            return NodeResult::complete(['file' => $context->storeFile($content, $name, trim((string) $context->input('content_type')) ?: null)]);
        } catch (RuntimeException $e) {
            return NodeResult::fail($e->getMessage(), retryable: false);
        }
    }
}
