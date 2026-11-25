<?php

namespace Tests\Feature\Nodes;

use App\Enums\ExecutionStatus;
use App\Enums\WorkspaceRole;
use App\Mail\WorkflowEmail;
use App\Models\Credential;
use App\Models\Execution;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowFile;
use App\Models\Workspace;
use App\Services\ExecutionRetention;
use App\Services\ObjectStorage;
use App\Services\WorkflowFiles;
use App\Services\Workflows;
use App\Support\HostResolver;
use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Prompts\AgentPrompt;
use Livewire\Livewire;
use Tests\Concerns\RunsWorkflows;
use Tests\TestCase;

class FilesTest extends TestCase
{
    use RefreshDatabase, RunsWorkflows;

    /** A 1x1 PNG. */
    private const PNG = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89\x00\x00\x00\rIDATx\x9cc\xf8\x0f\x00\x00\x01\x01\x00\x05\x18\xd8N\x00\x00\x00\x00IEND\xaeB`\x82";

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('workflow_files');
        Http::preventStrayRequests();

        $this->app->instance(HostResolver::class, new class extends HostResolver
        {
            public function resolve(string $host): array
            {
                return str_ends_with($host, 'example.com') || str_ends_with($host, 'amazonaws.com') ? ['93.184.215.14'] : parent::resolve($host);
            }
        });
    }

    private function fileOf(Execution $execution, string $nodeId, string $path = 'file'): WorkflowFile
    {
        return WorkflowFile::query()->findOrFail(data_get($this->stepFor($execution, $nodeId)->output, $path)['id']);
    }

    private function bytes(WorkflowFile $file): string
    {
        return Storage::disk('workflow_files')->get($file->path);
    }

    public function test_http_call_saves_a_download_as_a_file_of_the_run(): void
    {
        Http::fake(['cdn.example.com/*' => Http::response(self::PNG, 200, ['Content-Type' => 'image/png', 'Content-Disposition' => 'attachment; filename="logo.png"'])]);

        $execution = $this->runWorkflow($this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger', 'fetch' => ['core.http_request', ['method' => 'GET', 'url' => 'https://cdn.example.com/assets/123', 'save_as_file' => true]]],
            ['start->fetch'],
        )));

        $output = $this->stepFor($execution, 'fetch')->output;
        $file = $this->fileOf($execution, 'fetch');
        $this->assertNull($output['body']);
        $this->assertSame(['kind' => 'file', 'id' => $file->id, 'name' => 'logo.png', 'mime' => 'image/png', 'size' => strlen(self::PNG)], $output['file']);
        $this->assertSame($execution->id, $file->execution_id);
        $this->assertSame(self::PNG, $this->bytes($file));
    }

    public function test_a_response_that_is_not_text_is_saved_as_a_file_without_asking(): void
    {
        Http::fake(['cdn.example.com/*' => Http::response(self::PNG, 200, ['Content-Type' => 'image/png'])]);

        $execution = $this->runWorkflow($this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger', 'fetch' => ['core.http_request', ['method' => 'GET', 'url' => 'https://cdn.example.com/img/pixel.png']]],
            ['start->fetch'],
        )));

        $this->assertSame(ExecutionStatus::Complete, $execution->status);
        $this->assertSame('pixel.png', $this->fileOf($execution, 'fetch')->name);
        $this->assertSame(self::PNG, $this->bytes($this->fileOf($execution, 'fetch')));
    }

    public function test_create_file_decodes_base64_and_placeholders_pass_the_file_whole_or_read_it(): void
    {
        $execution = $this->runWorkflow($this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger',
                'make' => ['core.create_file', ['name' => 'pixel.png', 'content' => '{{ trigger.values.image }}', 'encoding' => 'base64']],
                'note' => ['core.create_file', ['name' => 'note.txt', 'content' => 'Hello {{ trigger.values.who }}']],
                'same' => ['core.compose', ['template' => '{{ nodes.make.output.file }}']],
                'read' => ['core.compose', ['template' => '{{ text(nodes.note.output.file) }} / {{ base64(nodes.make.output.file) }} / {{ nodes.make.output.file.name }}']]],
            ['start->make', 'make->note', 'note->same', 'same->read'],
        ), null), ['values' => ['image' => base64_encode(self::PNG), 'who' => 'Ada']]);

        $png = $this->fileOf($execution, 'make');
        $this->assertSame(['image/png', self::PNG], [$png->mime, $this->bytes($png)]);
        $this->assertSame($this->stepFor($execution, 'make')->output['file'], $this->stepFor($execution, 'same')->output['value']);
        $this->assertSame('Hello Ada / '.base64_encode(self::PNG).' / pixel.png', $this->stepFor($execution, 'read')->output['value']);
    }

    public function test_a_file_inside_other_text_stops_with_a_clear_error(): void
    {
        $execution = $this->runWorkflow($this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger',
                'make' => ['core.create_file', ['name' => 'a.txt', 'content' => 'x']],
                'say' => ['core.compose', ['template' => 'Here it is: {{ nodes.make.output.file }}']]],
            ['start->make', 'make->say'],
        )));

        $this->assertSame(ExecutionStatus::Failed, $execution->status);
        $this->assertStringContainsString('A file cannot be part of text.', $execution->error['message']);
    }

    public function test_a_run_cannot_read_another_workspaces_file(): void
    {
        $other = app(WorkflowFiles::class)->store(Workspace::factory()->create(), null, 'secret plans', 'plans.txt');

        $execution = $this->runWorkflow($this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger', 'read' => ['core.compose', ['template' => '{{ text(trigger.values.file) }}']]],
            ['start->read'],
        )), ['values' => ['file' => $other]]);

        $this->assertSame(ExecutionStatus::Failed, $execution->status);
        $this->assertStringContainsString('That file was not found in this workspace.', $execution->error['message']);
    }

    public function test_upload_to_s3_streams_a_file_with_its_own_content_type(): void
    {
        $sent = [];
        $storage = $this->app->make(ObjectStorage::class);
        $storage->handler = new MockHandler([function (CommandInterface $command) use (&$sent) {
            $sent = ['key' => $command['Key'], 'type' => $command['ContentType'], 'body' => (string) $command['Body']];

            return new Result([]);
        }]);
        $this->app->instance(ObjectStorage::class, $storage);
        $workspace = Workspace::factory()->create();
        Credential::factory()->for($workspace)->s3()->create(['id' => $this->settingId('media'), 'description' => 'media']);

        $execution = $this->runWorkflow($this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger',
                'make' => ['core.create_file', ['name' => 'pixel.png', 'content' => base64_encode(self::PNG), 'encoding' => 'base64']],
                'upload' => ['core.s3_upload', ['credential' => $this->settingId('media'), 'bucket' => 'backups', 'key' => 'images/{{ nodes.make.output.file.name }}', 'content' => '{{ nodes.make.output.file }}']]],
            ['start->make', 'make->upload'],
        ), $workspace));

        $this->assertSame(['key' => 'images/pixel.png', 'type' => 'image/png', 'body' => self::PNG], $sent);
        $this->assertSame(strlen(self::PNG), $this->stepFor($execution, 'upload')->output['size']);
    }

    public function test_send_email_attaches_files(): void
    {
        Mail::fake();

        $this->runWorkflow($this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger',
                'make' => ['core.create_file', ['name' => 'report.csv', 'content' => "id,total\n1,20"]],
                'mail' => ['core.send_email', ['to' => 'ops@example.com', 'subject' => 'Report', 'body' => 'Attached.', 'attachments' => '{{ nodes.make.output.file }}']]],
            ['start->make', 'make->mail'],
        )));

        Mail::assertSent(WorkflowEmail::class, fn (WorkflowEmail $mail) => count($mail->attachments()) === 1
            && $mail->attachments()[0]->as === 'report.csv'
            && $mail->attachments()[0]->mime === 'text/csv');
    }

    public function test_http_call_uploads_a_file_as_the_body_or_inside_a_form(): void
    {
        Http::fake(['api.example.com/*' => Http::response(['ok' => true])]);

        $this->runWorkflow($this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger',
                'make' => ['core.create_file', ['name' => 'pixel.png', 'content' => base64_encode(self::PNG), 'encoding' => 'base64']],
                'raw' => ['core.http_request', ['method' => 'PUT', 'url' => 'https://api.example.com/raw', 'body_type' => 'file', 'file' => '{{ nodes.make.output.file }}']],
                'form' => ['core.http_request', ['method' => 'POST', 'url' => 'https://api.example.com/form', 'body_type' => 'form', 'form' => ['caption' => 'Logo', 'image' => '{{ nodes.make.output.file }}']]]],
            ['start->make', 'make->raw', 'raw->form'],
        )));

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.example.com/raw'
            && $request->body() === self::PNG
            && $request->header('Content-Type') === ['image/png']);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.example.com/form'
            && $request->isMultipart()
            && str_contains($request->body(), 'name="image"; filename="pixel.png"')
            && str_contains($request->body(), "Content-Type: image/png\r\nContent-Disposition: form-data; name=\"image\"")
            && str_contains($request->body(), self::PNG)
            && str_contains($request->body(), "name=\"caption\"\r\n\r\nLogo\r\n"));
    }

    public function test_llm_call_sends_images_and_refuses_files_it_cannot_read(): void
    {
        AnonymousAgent::fake(['A red pixel.', 'unused']);
        $workspace = Workspace::factory()->create();
        Credential::factory()->for($workspace)->aiProvider('anthropic')->create(['id' => $this->settingId('claude'), 'description' => 'claude']);
        $ask = fn (string $name, string $content) => $this->runWorkflow($this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger',
                'make' => ['core.create_file', ['name' => $name, 'content' => $content, 'encoding' => 'base64']],
                'ask' => ['core.llm', ['credential' => $this->settingId('claude'), 'model' => 'claude-sonnet-5-5', 'prompt' => 'What is this?', 'attachments' => '{{ nodes.make.output.file }}']]],
            ['start->make', 'make->ask'],
        ), $workspace));

        $this->assertSame('A red pixel.', $this->stepFor($ask('pixel.png', base64_encode(self::PNG)), 'ask')->output['text']);
        AnonymousAgent::assertPrompted(fn (AgentPrompt $prompt) => $prompt->attachments->count() === 1);

        $refused = $ask('archive.zip', base64_encode('PK...'));
        $this->assertSame('"archive.zip" is application/zip; an LLM call can read images, PDFs, and text files.', $refused->error['message']);
    }

    public function test_a_form_upload_becomes_a_file_of_the_run_it_starts(): void
    {
        $workspace = Workspace::factory()->create();
        $workflow = Workflow::factory()->for($workspace)->create(['draft_graph' => $this->graph(['signup' => ['core.form_trigger', [
            'title' => 'Send your logo', 'access' => 'public',
            'fields' => [['name' => 'logo', 'label' => 'Logo', 'type' => 'file', 'required' => true]],
        ]]])]);
        app(Workflows::class)->publish($workflow);

        Livewire::test('pages::forms.show', ['key' => $workflow->triggers()->sole()->key])
            ->assertSeeHtml('data-test="file-field"')
            ->set('values.logo', UploadedFile::fake()->image('logo.png', 4, 4))
            ->call('submit')
            ->assertHasNoErrors();

        $execution = Execution::sole();
        $reference = $execution->input['values']['logo'];
        $this->assertSame(['file', 'logo.png', 'image/png'], [$reference['kind'], $reference['name'], $reference['mime']]);
        $this->assertSame($execution->id, WorkflowFile::findOrFail($reference['id'])->execution_id);
    }

    public function test_form_uploads_are_accepted_up_to_the_workflow_file_limit(): void
    {
        $workflow = Workflow::factory()->for(Workspace::factory())->create(['draft_graph' => $this->graph(['signup' => ['core.form_trigger', [
            'title' => 'Send your scan', 'access' => 'public',
            'fields' => [['name' => 'scan', 'label' => 'Scan', 'type' => 'file', 'required' => true]],
        ]]])]);
        app(Workflows::class)->publish($workflow);
        $form = Livewire::test('pages::forms.show', ['key' => $workflow->triggers()->sole()->key]);

        $form->upload('values.scan', [UploadedFile::fake()->create('scan.pdf', 40 * 1024, 'application/pdf')])
            ->assertHasNoErrors('values.scan');

        $form->upload('values.scan', [UploadedFile::fake()->create('huge.pdf', 51 * 1024, 'application/pdf')])
            ->assertHasErrors('values.scan');
    }

    public function test_a_webhook_upload_becomes_a_file_of_the_run_it_starts(): void
    {
        $workflow = Workflow::factory()->for(Workspace::factory())->create(['draft_graph' => $this->graph(['hook' => ['core.webhook_trigger', ['method' => 'POST', 'verification' => 'none']]])]);
        app(Workflows::class)->publish($workflow);

        $this->post(route('webhooks.receive', $workflow->triggers()->sole()->key), ['order' => '7', 'receipt' => UploadedFile::fake()->createWithContent('receipt.pdf', '%PDF-1.4 receipt')])
            ->assertAccepted();

        $execution = Execution::sole();
        $file = WorkflowFile::findOrFail($execution->input['files']['receipt']['id']);
        $this->assertSame(['7', 'receipt.pdf', $execution->id], [$execution->input['body']['order'], $file->name, $file->execution_id]);
        $this->assertSame('%PDF-1.4 receipt', $this->bytes($file));
    }

    public function test_people_who_can_see_runs_download_their_files_as_attachments(): void
    {
        $workspace = Workspace::factory()->create();
        $editor = User::factory()->memberOf($workspace, WorkspaceRole::Editor)->create();
        $guest = User::factory()->memberOf($workspace, WorkspaceRole::Guest)->create();
        $execution = $this->runWorkflow($this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger', 'make' => ['core.create_file', ['name' => 'page.html', 'content' => '<script>alert(1)</script>']]],
            ['start->make'],
        ), $workspace));
        $file = $this->fileOf($execution, 'make');
        $url = route('workspaces.executions.files.download', [$workspace, $execution, $file]);

        $this->actingAs($editor)->get(route('workspaces.executions.show', [$workspace, $execution]))->assertOk();
        $this->actingAs($editor)->get($url)
            ->assertOk()
            ->assertHeader('Content-Type', 'application/octet-stream')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertDownload('page.html');
        $this->actingAs($guest)->get($url)->assertForbidden();

        $elsewhere = Workspace::factory()->create();
        $outsider = User::factory()->memberOf($elsewhere, WorkspaceRole::Admin)->create();
        $this->actingAs($outsider)->get(route('workspaces.executions.files.download', [$elsewhere, $execution, $file]))->assertNotFound();
    }

    public function test_files_are_deleted_with_their_runs_logs_and_abandoned_uploads_after_a_day(): void
    {
        $workspace = Workspace::factory()->create();
        $workflow = $this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger', 'make' => ['core.create_file', ['name' => 'old.txt', 'content' => 'old']]],
            ['start->make'],
        ), $workspace);
        $workflow->update(['retention_days' => 7]);
        $execution = $this->runWorkflow($workflow);
        $file = $this->fileOf($execution, 'make');
        $abandoned = WorkflowFile::findOrFail(app(WorkflowFiles::class)->store($workspace, null, 'never used', 'stray.txt')['id']);
        $execution->update(['finished_at' => now()->subDays(8)]);
        $this->travel(2)->days();

        app(ExecutionRetention::class)->purge();

        $this->assertModelMissing($file);
        $this->assertModelMissing($abandoned);
        Storage::disk('workflow_files')->assertMissing($file->path);
        Storage::disk('workflow_files')->assertMissing($abandoned->path);
    }
}
