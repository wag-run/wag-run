<?php

namespace Tests\Feature\Nodes;

use App\Enums\ExecutionStatus;
use App\Models\Credential;
use App\Models\Execution;
use App\Models\Workspace;
use App\Services\ObjectStorage;
use App\Support\HostResolver;
use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Psr\Http\Message\RequestInterface;
use Tests\Concerns\RunsWorkflows;
use Tests\TestCase;

class StorageNodesTest extends TestCase
{
    use RefreshDatabase, RunsWorkflows;

    /**
     * What each request to the storage service carried.
     *
     * @var list<array{command: string, bucket: mixed, key: mixed, body: string, content_type: mixed, storage_class: mixed, url: string}>
     */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(HostResolver::class, new class extends HostResolver
        {
            public function resolve(string $host): array
            {
                return match ($host) {
                    'backups.s3.us-east-1.amazonaws.com' => ['52.216.1.1'],
                    'minio.corp.internal' => ['10.20.0.9'],
                    default => parent::resolve($host),
                };
            }
        });
    }

    /**
     * Answer every storage request with $answer, a result or an exception.
     */
    private function storageAnswers(callable $answer): void
    {
        $storage = $this->app->make(ObjectStorage::class);
        $storage->handler = new MockHandler([function (CommandInterface $command, RequestInterface $request) use ($answer) {
            $this->sent[] = [
                'command' => $command->getName(),
                'bucket' => $command['Bucket'],
                'key' => $command['Key'],
                'body' => (string) $command['Body'],
                'content_type' => $command['ContentType'],
                'storage_class' => $command['StorageClass'],
                'url' => (string) $request->getUri(),
            ];

            return $answer($command);
        }]);
        $this->app->instance(ObjectStorage::class, $storage);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function runUpload(Workspace $workspace, array $config, array $input = []): Execution
    {
        return $this->runWorkflow($this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger', 'upload' => ['core.s3_upload', ['credential' => $this->settingId('backups'), ...$config]]],
            ['start->upload'],
        ), $workspace), ['values' => $input]);
    }

    public function test_uploads_templated_content_to_the_key_on_amazon_s3(): void
    {
        $this->storageAnswers(fn () => new Result([]));
        $workspace = Workspace::factory()->create();
        Credential::factory()->for($workspace)->s3()->create(['id' => $this->settingId('backups'), 'description' => 'backups']);

        $execution = $this->runUpload($workspace, [
            'key' => '/opnsense/{{ trigger.values.host }}.xml',
            'content' => '{{ trigger.values.xml }}',
            'content_type' => 'application/xml',
        ], ['host' => 'fw1', 'xml' => '<opnsense><system/></opnsense>']);

        $this->assertSame([[
            'command' => 'PutObject',
            'bucket' => 'backups',
            'key' => 'opnsense/fw1.xml',
            'body' => '<opnsense><system/></opnsense>',
            'content_type' => 'application/xml',
            'storage_class' => null,
            'url' => 'https://backups.s3.us-east-1.amazonaws.com/opnsense/fw1.xml',
        ]], $this->sent);
        $this->assertSame(
            ['bucket' => 'backups', 'key' => 'opnsense/fw1.xml', 'url' => 'https://backups.s3.us-east-1.amazonaws.com/opnsense/fw1.xml', 'size' => 30],
            $this->stepFor($execution, 'upload')->output,
        );
    }

    public function test_sends_the_chosen_storage_class_for_text_and_files(): void
    {
        Storage::fake('workflow_files');
        $this->storageAnswers(fn () => new Result([]));
        $workspace = Workspace::factory()->create();
        Credential::factory()->for($workspace)->s3()->create(['id' => $this->settingId('backups'), 'description' => 'backups']);

        $this->runUpload($workspace, ['key' => 'config.xml', 'content' => '<a/>', 'storage_class' => 'GLACIER']);
        $this->storageAnswers(fn () => new Result([]));
        $this->runWorkflow($this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger',
                'make' => ['core.create_file', ['name' => 'config.tar', 'content' => 'tar bytes']],
                'upload' => ['core.s3_upload', ['credential' => $this->settingId('backups'), 'key' => 'config.tar', 'content' => '{{ nodes.make.output.file }}', 'storage_class' => 'DEEP_ARCHIVE']]],
            ['start->make', 'make->upload'],
        ), $workspace));

        $this->assertSame(['GLACIER', 'DEEP_ARCHIVE'], array_column($this->sent, 'storage_class'));
    }

    public function test_refuses_a_storage_class_it_does_not_know(): void
    {
        $this->storageAnswers(fn () => new Result([]));
        $workspace = Workspace::factory()->create();
        Credential::factory()->for($workspace)->s3()->create(['id' => $this->settingId('backups'), 'description' => 'backups']);

        $execution = $this->runUpload($workspace, ['key' => 'config.xml', 'content' => '<a/>', 'storage_class' => 'COLD']);

        $this->assertSame('"COLD" is not a storage class this node can use.', $execution->error['message']);
        $this->assertSame([], $this->sent);
    }

    public function test_an_s3_compatible_endpoint_on_an_internal_address_needs_the_workspace_allowlist(): void
    {
        $this->storageAnswers(fn () => new Result([]));
        $minio = ['endpoint' => 'http://minio.corp.internal:9000', 'path_style' => 'yes'];

        $blocked = Workspace::factory()->create();
        Credential::factory()->for($blocked)->s3($minio)->create(['id' => $this->settingId('backups'), 'description' => 'backups']);
        $execution = $this->runUpload($blocked, ['key' => 'config.xml', 'content' => '<a/>']);

        $this->assertSame(ExecutionStatus::Failed, $execution->status);
        $this->assertStringContainsString('10.20.0.9', $execution->error['message']);
        $this->assertSame([], $this->sent);

        $allowed = Workspace::factory()->create(['internal_allowlist' => ['*.corp.internal']]);
        Credential::query()->delete();
        Credential::factory()->for($allowed)->s3($minio)->create(['id' => $this->settingId('backups'), 'description' => 'backups']);
        $execution = $this->runUpload($allowed, ['key' => 'config.xml', 'content' => '<a/>']);

        $this->assertSame(ExecutionStatus::Complete, $execution->status);
        $this->assertSame('http://minio.corp.internal:9000/backups/config.xml', $this->sent[0]['url']);
    }

    public function test_a_refused_upload_reports_the_service_answer_without_the_secret_key(): void
    {
        $this->storageAnswers(fn (CommandInterface $command) => new S3Exception('Access Denied', $command, [
            'code' => 'AccessDenied',
            'message' => 'Access Denied',
            'response' => new Response(403),
        ]));
        $workspace = Workspace::factory()->create();
        $credential = Credential::factory()->for($workspace)->s3()->create(['id' => $this->settingId('backups'), 'description' => 'backups']);

        $execution = $this->runUpload($workspace, ['key' => 'config.xml', 'content' => '<a/>']);

        $this->assertSame('The storage service answered 403 AccessDenied: Access Denied', $execution->error['message']);
        $this->assertSame(1, $this->stepFor($execution, 'upload')->attempt);
        $this->assertStringNotContainsString($credential->config['secret_access_key'], json_encode($execution->steps()->get()->toArray()));
    }

    public function test_fails_when_the_storage_setting_is_missing(): void
    {
        $this->storageAnswers(fn () => new Result([]));
        $workspace = Workspace::factory()->create();
        Credential::factory()->for($workspace)->create(['id' => $this->settingId('backups'), 'description' => 'backups']);

        $execution = $this->runUpload($workspace, ['key' => 'config.xml', 'content' => '<a/>']);

        $this->assertSame('The storage chosen on this node no longer exists. Choose another in the node\'s settings.', $execution->error['message']);
        $this->assertSame([], $this->sent);
    }
}
