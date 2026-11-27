# Creating nodes

Every trigger and action in wag.run is a PHP class that extends `App\Engine\Node`. The nodes that ship with the app are written against the same class, so anything they can do, yours can do too. This page shows how to write an action, a trigger, and a credential type to go with them.

Custom nodes are for self-hosted installs. They are PHP code in the app, so adding one means running your own build: see [Local development](development.md) to set up a checkout and [Building the image yourself](self-hosting.md#building-the-image-yourself) to deploy it.

## How a node fits in

A node class tells the app four things:

| The class declares | Which the app uses for |
|---|---|
| A **type**, such as `acme.create_ticket` | Saving the node in a workflow. It must be unique and must never change once workflows use it. |
| Its **inputs** | The fields in the editor's settings panel, and the values the node receives when it runs |
| Its **outputs** | What the data picker offers to later nodes |
| `execute()` | The work itself, which returns a result: complete, fail, wait, and so on |

You then register the class, and it appears in the palette.

## Where the code goes

Follow the layout the built-in nodes use:

| What | Where |
|---|---|
| Node classes | `app/Engine/Nodes/<App>/`, such as `app/Engine/Nodes/Acme/CreateTicket.php` |
| Credential types | `app/Credentials/Types/` |
| Registration | A service provider of your own, such as `app/Providers/AcmeServiceProvider.php`, listed in `bootstrap/providers.php` |
| Tests | `tests/Feature/Nodes/` or `tests/Feature/Integrations/` |

Create the files with Artisan:

```bash
php artisan make:class Engine/Nodes/Acme/CreateTicket
```

```bash
php artisan make:provider AcmeServiceProvider
```

`make:provider` adds the provider to `bootstrap/providers.php` for you.

## An action

This action creates a ticket in a made-up helpdesk, using an **API token** credential from the workspace.

```php
<?php

namespace App\Engine\Nodes\Acme;

use App\Credentials\HttpAuthenticator;
use App\Engine\Definitions\Field;
use App\Engine\Definitions\Output;
use App\Engine\Node;
use App\Engine\NodeContext;
use App\Engine\NodeResult;
use App\Enums\NodeKind;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class CreateTicket extends Node
{
    public static function type(): string
    {
        return 'acme.create_ticket';
    }

    public function label(): string
    {
        return 'Create ticket';
    }

    public function description(): string
    {
        return 'Opens a ticket in the Acme helpdesk.';
    }

    public function kind(): NodeKind
    {
        return NodeKind::Action;
    }

    public function category(): string
    {
        return 'Acme';
    }

    public function icon(): string
    {
        return 'ticket';
    }

    public function inputs(): array
    {
        return [
            new Field('credential', 'Acme API token', Field::CREDENTIAL, required: true, credentialTypes: ['api_token']),
            new Field('subject', 'Subject', Field::TEXT, required: true, templated: true),
            new Field('body', 'Body', Field::TEXTAREA, templated: true),
            new Field('priority', 'Priority', Field::SELECT, default: 'normal', options: ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High']),
        ];
    }

    public function outputs(array $config = []): array
    {
        return [
            new Output('id', 'Ticket ID', 'number'),
            new Output('url', 'URL', 'string'),
        ];
    }

    public function execute(NodeContext $context): NodeResult
    {
        $credential = $context->credential($context->input('credential'));
        $type = $credential?->credentialType();

        if (! $credential || ! $type instanceof HttpAuthenticator) {
            return NodeResult::fail('Choose an API token for Acme.', retryable: false);
        }

        try {
            $response = $type->authenticate(Http::timeout(30)->acceptJson(), $credential->config)
                ->post('https://helpdesk.example.com/api/tickets', [
                    'subject' => (string) $context->input('subject'),
                    'body' => (string) $context->input('body', ''),
                    'priority' => $context->input('priority', 'normal'),
                ]);
        } catch (ConnectionException $e) {
            return NodeResult::fail("Could not reach Acme: {$e->getMessage()}");
        }

        if (! $response->successful()) {
            return NodeResult::fail(
                "Acme answered {$response->status()}.",
                retryable: $response->serverError() || $response->status() === 429,
            );
        }

        return NodeResult::complete([
            'id' => $response->json('id'),
            'url' => $response->json('url'),
        ]);
    }
}
```

What to notice:

- **`templated: true`** lets people use `{{ }}` placeholders in the field. `$context->input('subject')` gives you the value with placeholders already filled in. A field that is one placeholder keeps the value's type, so it may be a number or a list, not text.
- **The output is an array keyed by your `Output` names.** A later node reads it as `{{ nodes.<id>.output.url }}`, and the data picker lists `id` and `url` because you declared them.
- **Failures are returned, not thrown.** `retryable: false` is for problems a retry won't fix, such as a missing credential or a 4xx answer. Everything else follows the retry setting on the node. An exception you don't catch fails the step as retryable, with the exception's message.
- **The step's input is recorded.** Every declared input is saved on the step row for the run page, with secrets and credential values masked.

### Registering it

```php
<?php

namespace App\Providers;

use App\Engine\NodeRegistry;
use App\Engine\Nodes\Acme\CreateTicket;
use Illuminate\Support\ServiceProvider;

class AcmeServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->make(NodeRegistry::class)->register(CreateTicket::class);
    }
}
```

Reload the editor and the node is in the palette under **Acme**.

## Fields

`new Field(name, label, type, ...)` describes one setting. The name is the key the value is saved under and the one you pass to `$context->input()`.

| Type | The editor shows | Your node receives |
|---|---|---|
| `Field::TEXT`, `Field::TEXTAREA` | A one-line or multi-line text box | Text, or any type when templated and the field is a single placeholder |
| `Field::NUMBER` | A number box | A number |
| `Field::BOOLEAN` | A switch | `true` or `false` |
| `Field::SELECT` | A list of the `options` you give, as value => label | The chosen value |
| `Field::JSON` | A JSON editor | The JSON as text, with placeholders filled in when templated. Decode it yourself with `json_decode()`. |
| `Field::KEY_VALUE` | Rows of name and value | An array of name => value |
| `Field::LIST` | Rows, each with the sub-fields you give in `fields` | A list of arrays, one per row |
| `Field::CREDENTIAL` | The workspace's credentials of the types in `credentialTypes` | The credential's ID. Load it with `$context->credential($id)`. |
| `Field::SECRET` | The workspace's secrets | The secret's key. Read the value with `$context->secret($key)`. |
| `Field::WORKFLOW` | The workspace's workflows | The workflow's ID |
| `Field::STORE_ENTRY` | The workspace's data store entries | The entry's name. Read its value with `$context->store($key)`. |
| `Field::VARIABLE` | The workflow's variables | The variable's name. Read its value with `$context->variable($name)`. |
| `Field::USER`, `Field::USERS` | A person, or several | A user ID, or a list of them |
| `Field::CRON`, `Field::TIMEZONE` | A cron expression put into words, and a time zone list | Text |
| `Field::DURATION`, `Field::SCHEMA` | Used by built-in nodes (timeouts, the LLM call's structured output) | See `Approval` and `LlmCall` for how they are read |

Other arguments:

| Argument | What it does |
|---|---|
| `required: true` | Publishing and test runs refuse the workflow while the field is empty |
| `default:` | The value a new node starts with |
| `description:` | Help text under the field |
| `templated: true` | Accepts `{{ }}` placeholders and shows the **Insert data** picker |
| `visibleWhen: ['mode' => 'advanced']` | Shows and saves the field only while the sibling field `mode` has that value (or one of a list of values) |
| `jsonWhen: ['body_type' => 'json']` | Edits the field as JSON while a sibling field has that value |
| `optionsWhen: ['append' => ['entry.type' => ['string', 'array']]]` | For a select, offers that option only while the sibling field has one of those values. A `Field::STORE_ENTRY` field named `entry` adds `entry.type` and `entry.collection` (`yes` or `no`) to what `visibleWhen` and `optionsWhen` can test, and a `Field::VARIABLE` field named `variable` adds `variable.type`. |

To check settings beyond "required", override `configProblems(array $config): array` and return a list of messages. They are reported when someone publishes or tests the workflow.

## Outputs

`new Output(name, label, type)` describes one value your node returns. The type is one of `string`, `number`, `boolean`, `object`, `array`, `file`, or `any`. Give an object or an array `children` to let people pick its properties:

```php
new Output('customer', 'Customer', 'object', children: [
    new Output('name', 'Name', 'string'),
    new Output('email', 'Email', 'string'),
]),
```

`outputs()` receives the node's settings, so the list can depend on them. The LLM call node does this: its outputs are the structured fields configured on the node.

Outputs are a description for the editor. Nothing checks that `execute()` returns exactly those keys, so keep the two in step.

## What `execute()` can use

`NodeContext` gives a node everything it needs for one run:

| Call | Returns |
|---|---|
| `$context->input('name', $default)` | One input, with placeholders filled in |
| `$context->inputs()` | Every declared input |
| `$context->config('name')` | The setting exactly as saved, placeholders untouched |
| `$context->render($template, $extra)` | A template rendered against the run's data, with `$extra` added on top. Filter uses this to check each item. |
| `$context->credential($id)` | The workspace credential with that ID, or null. Its `config` is the decrypted values. |
| `$context->secret($key)` | A workspace secret |
| `$context->variable($name)` | One of the workflow's variables, as it is in this run. Null when the workflow declares none by that name. |
| `$context->store($key)`, `$context->store($key, $itemKey)` | A data store entry's value as it is now, or one item of a collection. Null when there is none. |
| `$context->workspace()` | The workspace the run belongs to |
| `$context->triggerInput()` | What the trigger received |
| `$context->file($reference)`, `$context->files($value)` | Files an input points to |
| `$context->storeFile($contents, $name, $mime)` | Saves a file for this run and returns a reference to put in your output |
| `$context->heartbeat()` | Tells the app a long step is still alive |
| `$context->step`, `$context->execution`, `$context->node` | The step row, the run, and the node as saved in the graph |

Node classes are built by Laravel's container, so a constructor can ask for services:

```php
public function __construct(protected NetworkGuard $guard) {}
```

### Calling addresses people type in

If the address your node calls comes from a setting, check it with `App\Support\NetworkGuard` first, as the HTTP call and Chat message nodes do. It blocks private, loopback, and link-local addresses unless the workspace's allowlist permits them. See `ChatMessage::execute()` for the pattern. A fixed public address, as in the example above, doesn't need it.

## What `execute()` can return

| Result | What happens |
|---|---|
| `NodeResult::complete($output)` | The step completes and the flow continues from the `main` output |
| `NodeResult::complete($output, 'approved')` | Continues from a named output. Pass a list of names to run several branches at once. |
| `NodeResult::fail($message, retryable: false, output: $output)` | The attempt fails. Retryable failures are retried by the node's retry setting; after that the flow follows the node's **on error** connection, or the run fails. |
| `NodeResult::waitUntil($time, $state)` | Parks the step with no worker held, and resumes it at `$time` |
| `NodeResult::waitForResponse($timeout, $state)` | Parks the step until something answers it, or until the timeout |
| `NodeResult::iterate($items)` | Runs the part of the flow on the `item` output once per item, then continues from `done`. The step's output is `results`, with every node of each iteration, `count`, and `last`, as for a [For loop](actions.md#for-loop). |
| `NodeResult::repeat()` | Runs the part of the flow on the `pass` output once, then calls the node's `resume()` so it can decide whether to run it again. See [Repeating](#repeating). |
| `NodeResult::runChild($workflow, $input)` | Starts another workflow and waits for it |
| `NodeResult::updateVariable($name, $operation, $operand)` | Changes one of the workflow's variables and completes the step. The engine applies the change, so parallel branches can't lose each other's, and the step's output is the value before and after. `$operation` is an `App\Enums\ValueOperation`. |
| `NodeResult::terminate($status, $output)` | Ends the whole run with that status |

### More than one output

A node with several outputs lists them in `ports()` and names the one to take when it completes:

```php
public function ports(array $config = []): array
{
    return ['found', 'missing'];
}

public function execute(NodeContext $context): NodeResult
{
    // ...
    return NodeResult::complete(['customer' => $customer], $customer ? 'found' : 'missing');
}
```

Override `portLabel()` to give an output a friendlier label in the editor. Every action also has an **on error** output unless `hasErrorPort()` returns false.

### Waiting

A node that waits returns `waitUntil()` or `waitForResponse()` with any `$state` it will need later, and the step is parked. When it resumes, the app calls `resume()` instead of `execute()`, and `$context->state()` returns what you saved:

```php
public function resume(NodeContext $context, Resumption $resumption): NodeResult
{
    if ($resumption->reason === ResumeReason::TimerElapsed) {
        return NodeResult::fail('Nobody answered in time.', retryable: false);
    }

    return NodeResult::complete($resumption->payload);
}
```

The default `resume()` completes the step when a timer passes or an answer arrives, so a simple timer needs no override. To answer a step that is waiting for a response, call `app(App\Engine\Engine::class)->respond($step, $payload)` from wherever the answer arrives, such as a controller. The Approval and Task nodes work this way.

### Repeating

A node that repeats part of the flow without knowing how often, as the [While loop](actions.md#while-loop) does, returns `NodeResult::repeat()` from `execute()` and declares `repeats()` as true. The steps connected to its `pass` output run once. When they have all finished, the app calls `resume()` with `ResumeReason::PassFinished`, and the node returns `repeat()` for another pass or `complete()` to stop:

```php
public function repeats(): bool
{
    return true;
}

public function resume(NodeContext $context, Resumption $resumption): NodeResult
{
    if ($resumption->reason !== ResumeReason::PassFinished) {
        return parent::resume($context, $resumption);
    }

    $passes = $context->state()['index'] + 1;

    if ($passes < 50 && $context->input('keep_going')) {
        return NodeResult::repeat();
    }

    return NodeResult::complete(['count' => $passes], 'done');
}
```

In that call the node's inputs are filled in from the run's data as a node inside the finished pass reads it, so a placeholder such as `{{ nodes.fetch.output.done }}` refers to that pass. `$context->state()['index']` is the position of the pass that just finished, from 0.

Complete with the number of passes as `count`, and the app adds `results` and `last` to the step's output, as for a While loop. Always limit the passes yourself: nothing else stops a node that keeps returning `repeat()`. The default `resume()` stops after one pass.

### Other things to override

| Method | Default | Override to |
|---|---|---|
| `defaultRetryPolicy()` | No retries | Retry by default: `new RetryPolicy(maxRetries: 3, backoffSeconds: 30)`. People can still change it on the node. |
| `timeout()` | 300 seconds | Allow a longer attempt before the app treats the worker as dead. Call `$context->heartbeat()` during long work. |
| `paletteGroup()` | The node's category | Nest the node in the palette, such as `['Triggers', 'Other apps', 'Acme']` |
| `joinsBranches()` | `false` | Wait for every incoming branch before running, as Merge does |
| `labelFor(array $config)` | `label()` | Change the node's label with its settings, as a While loop that checks after each pass reads "Do while loop". A node still called by its label is renamed with it. |
| `fieldHelp(Field $field, array $config)` | The field's description | Change the help text under a field with the node's other settings |

## A trigger

A trigger is a node whose `kind()` is `NodeKind::Trigger`. Its `execute()` almost always passes on what started the run:

```php
public function execute(NodeContext $context): NodeResult
{
    return NodeResult::complete($context->triggerInput());
}
```

Its outputs describe that data, which later nodes read as `{{ trigger.<name> }}`.

What makes a trigger fire is separate from the node contract. A trigger on **another app's events** implements `App\Engine\EventTrigger`, and the app does the rest: when the workflow is published it starts polling or registers a webhook, and each event starts a run. A trigger that doesn't implement it can only be started by hand or from the API.

### Polling

This trigger starts a run for each new ticket in the same made-up helpdesk.

```php
<?php

namespace App\Engine\Nodes\Acme;

use App\Credentials\HttpAuthenticator;
use App\Engine\Definitions\Field;
use App\Engine\Definitions\Output;
use App\Engine\EventTrigger;
use App\Engine\Node;
use App\Engine\NodeContext;
use App\Engine\NodeResult;
use App\Enums\NodeKind;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class NewTicket extends Node implements EventTrigger
{
    public static function type(): string
    {
        return 'acme.new_ticket';
    }

    public function label(): string
    {
        return 'New ticket';
    }

    public function kind(): NodeKind
    {
        return NodeKind::Trigger;
    }

    public function paletteGroup(): array
    {
        return [__('common.triggers'), __('common.other_apps'), 'Acme'];
    }

    public function inputs(): array
    {
        return [
            new Field('credential', 'Acme API token', Field::CREDENTIAL, required: true, credentialTypes: ['api_token']),
            new Field('poll_minutes', 'Check every (minutes)', Field::NUMBER, default: 5),
        ];
    }

    public function outputs(array $config = []): array
    {
        return [
            new Output('id', 'Ticket ID', 'number'),
            new Output('subject', 'Subject', 'string'),
        ];
    }

    public function execute(NodeContext $context): NodeResult
    {
        return NodeResult::complete($context->triggerInput());
    }

    public function listensBy(array $config): string
    {
        return EventTrigger::POLL;
    }

    public function pollEvery(array $config): int
    {
        return max(1, (int) ($config['poll_minutes'] ?? 5));
    }

    public function poll(array $config, Workspace $workspace, ?array $state): array
    {
        $credential = $workspace->credentials()->whereKey($config['credential'] ?? null)->first();
        $type = $credential?->credentialType();

        if (! $credential || ! $type instanceof HttpAuthenticator) {
            throw new RuntimeException('Choose an API token for Acme.');
        }

        $response = $type->authenticate(Http::timeout(30)->acceptJson(), $credential->config)
            ->get('https://helpdesk.example.com/api/tickets', ['sort' => 'newest']);

        if (! $response->successful()) {
            throw new RuntimeException("Acme answered {$response->status()}.");
        }

        $tickets = (array) $response->json('tickets');
        $newestId = (int) ($tickets[0]['id'] ?? 0);

        // The first poll after publishing only records where things stand.
        if ($state === null) {
            return ['events' => [], 'state' => ['last_id' => $newestId]];
        }

        $new = array_filter($tickets, fn (array $ticket) => $ticket['id'] > $state['last_id']);

        return [
            'events' => array_map(
                fn (array $ticket) => ['id' => $ticket['id'], 'subject' => $ticket['subject']],
                array_reverse(array_values($new)),
            ),
            'state' => ['last_id' => max($newestId, $state['last_id'])],
        ];
    }

    public function subscribe(array $config, Workspace $workspace, string $url, string $secret): array
    {
        throw new RuntimeException('This trigger polls.');
    }

    public function unsubscribe(array $config, Workspace $workspace, array $subscription): void {}

    public function receive(array $config, Request $request, string $secret): ?array
    {
        return null;
    }
}
```

How polling works:

- **`poll()` receives the trigger's settings, the workspace, and whatever `state` the last poll returned.** There is no `NodeContext` here: no run exists yet.
- **`state` is yours.** Return whatever you need to tell new from old next time: a last ID, a timestamp, a list of IDs seen. It is `null` on the first poll after publishing.
- **The first poll should start nothing.** Record what already exists and return no events, or publishing a workflow would start a run for every old item.
- **Each event is one run's trigger input**, oldest first. Its keys should match the trigger's outputs.
- **Throw to report a problem.** The message is saved on the trigger and the next poll tries again.
- **Polls are checked once a minute**, so `pollEvery()` can't go lower than one minute.

Register it the same way as an action.

### Webhooks

To be told about events instead of asking, return `EventTrigger::WEBHOOK` from `listensBy()` and fill in the other three methods:

| Method | When it is called | What it does |
|---|---|---|
| `subscribe($config, $workspace, $url, $secret)` | When the workflow is published, or the trigger's settings change | Registers a webhook with the other app that sends events to `$url`, signed with `$secret`. Returns whatever `unsubscribe()` will need, such as the webhook's ID. |
| `unsubscribe($config, $workspace, $subscription)` | When the trigger changes or the workflow is unpublished | Removes that webhook |
| `receive($config, $request, $secret)` | For each request to the trigger's address | Returns a list of events (each one starts a run), an empty list to ignore the request, or `null` when the signature is wrong |

```php
public function receive(array $config, Request $request, string $secret): ?array
{
    $expected = hash_hmac('sha256', $request->getContent(), $secret);

    if (! hash_equals($expected, (string) $request->header('X-Acme-Signature', ''))) {
        return null;
    }

    if ($request->json('event') !== 'ticket.created') {
        return [];
    }

    return [['id' => $request->json('ticket.id'), 'subject' => $request->json('ticket.subject')]];
}
```

Always verify the signature with `hash_equals()`. The address is public, and anyone who learns it can otherwise start runs.

To offer both, add a setting that picks one and return it from `listensBy()`. The GitHub triggers do this: `App\Engine\Nodes\GitHub\GitHubTrigger` is the fullest example, with polling that sends ETags and remembers the items it has seen.

One limit: `subscribe()` is called once per trigger with that trigger's own address. An app that sends every event to one address for the whole install (Slack's Events API, for example) does not fit this contract yet.

## A credential type

If the built-in **API token**, **HTTP Basic**, or **OAuth 2.0** types don't fit, add your own. A credential type declares its fields, and the app handles the form, encryption, masking, and the audit log.

```php
<?php

namespace App\Credentials\Types;

use App\Credentials\CredentialField;
use App\Credentials\CredentialType;
use App\Credentials\HttpAuthenticator;
use Illuminate\Http\Client\PendingRequest;

class AcmeAccount extends CredentialType implements HttpAuthenticator
{
    public function key(): string
    {
        return 'acme_account';
    }

    public function label(): string
    {
        return 'Acme account';
    }

    public function description(): string
    {
        return 'A subdomain and API key for the Acme helpdesk.';
    }

    public function fields(): array
    {
        return [
            CredentialField::text('subdomain', 'Subdomain', help: 'The part before .acme.example'),
            CredentialField::secret('api_key', 'API key'),
        ];
    }

    public function authenticate(PendingRequest $request, array $config): PendingRequest
    {
        return $request->withHeaders(['X-Acme-Key' => (string) ($config['api_key'] ?? '')]);
    }
}
```

Register it in the same provider:

```php
$this->app->make(CredentialTypeRegistry::class)->register(new AcmeAccount);
```

- **`key()`** is saved on each credential, so treat it like a node type: unique, and never changed.
- **`CredentialField::secret()`** fields are masked in the UI and in run logs.
- **`HttpAuthenticator`** is optional. Implementing it lets the HTTP call node use the credential too.
- **On a node**, accept it with `credentialTypes: ['acme_account']`.
- **For OAuth**, extend `App\Credentials\OAuthCredentialType` instead. It handles the authorization code flow with PKCE and refreshes tokens before use. `GoogleOAuth` is a short example.

## Text and translations

The examples use plain English text to stay short. The app's own nodes take every label from `lang/en/nodes.php` with `__('nodes.acme.create_ticket')`, and credential types from `lang/en/credentials.php`. Do the same for anything you contribute to wag.run.

## Testing a node

The `Tests\Concerns\RunsWorkflows` trait builds a workflow from a short description, runs it, and lets you read each step. Runs go through the real engine, with the queue running inline.

```php
<?php

namespace Tests\Feature\Nodes;

use App\Enums\ExecutionStatus;
use App\Models\Credential;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\RunsWorkflows;
use Tests\TestCase;

class AcmeCreateTicketTest extends TestCase
{
    use RefreshDatabase, RunsWorkflows;

    public function test_it_creates_a_ticket_with_the_rendered_subject(): void
    {
        Http::fake(['helpdesk.example.com/*' => Http::response(['id' => 42, 'url' => 'https://helpdesk.example.com/tickets/42'])]);

        $workspace = Workspace::factory()->create();
        $credential = Credential::factory()->for($workspace)->create([
            'type' => 'api_token',
            'config' => ['header' => 'Authorization', 'prefix' => 'Bearer', 'token' => 'secret-token'],
        ]);

        $workflow = $this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger', 'ticket' => ['acme.create_ticket', [
                'credential' => $credential->id,
                'subject' => 'Refund for {{ trigger.values.customer }}',
            ]]],
            ['start->ticket'],
        ), $workspace);

        $execution = $this->runWorkflow($workflow, ['values' => ['customer' => 'Ada']]);

        $this->assertSame(ExecutionStatus::Complete, $execution->status);
        $this->assertSame(42, $this->stepFor($execution, 'ticket')->output['id']);
        Http::assertSent(fn ($request) => $request['subject'] === 'Refund for Ada' && $request->hasHeader('Authorization', 'Bearer secret-token'));
    }
}
```

`graph()` takes nodes as `id => type` or `id => [type, settings]`, and connections as `'a->b'` or `'a:port->b'` for a named output.

Worth testing for every node: the successful path, a failure the node should not retry, and anything it does with credentials. For a polling trigger, call `poll()` directly: once with `null` state to check it starts nothing, then again with the returned state and a newer item.

Run the test with:

```bash
php artisan test --compact --filter=AcmeCreateTicketTest
```

## Contributing a node to wag.run

A node meant for everyone goes in with the built-in ones:

1. Add the class under `app/Engine/Nodes/` and list it in `WorkflowServiceProvider::BUILT_IN_NODES`. Credential types are registered in `AppServiceProvider`.
2. Put every label in `lang/en/nodes.php` or `lang/en/credentials.php`.
3. Add tests.
4. Describe it in [Actions](actions.md) or [Triggers](triggers.md), and credential types in [Workspaces](workspaces.md).

## Changing a node later

Workflows save a node's type and its settings by field name, and runs in progress keep using the version they started on. So:

- **Never rename a type or a credential key.** Workflows that use it would no longer publish, and steps would fail.
- **Renaming or removing a field** loses that setting in existing workflows. Add a new field and keep reading the old name instead.
- **Renaming or removing an output or a port** breaks placeholders and connections that use it.
- **Adding** fields (with a default), outputs, and ports is safe.
