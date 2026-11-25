<?php

namespace App\Providers;

use App\Engine\Events\ExecutionFinished;
use App\Engine\Node;
use App\Engine\NodeRegistry;
use App\Engine\Nodes\Actions;
use App\Engine\Nodes\GitHub;
use App\Engine\Nodes\Google;
use App\Engine\Nodes\Note;
use App\Engine\Nodes\Triggers;
use App\Engine\Nodes\WagRun;
use App\Engine\Templates\ExpressionTemplateEngine;
use App\Engine\Templates\TemplateEngine;
use App\Services\Assignments;
use App\Services\RunNotifications;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the engine and the nodes that ship with the app. Custom
 * integrations (self-hosted only) register their nodes the same way from
 * their own service provider.
 */
class WorkflowServiceProvider extends ServiceProvider
{
    /**
     * @var list<class-string<Node>>
     */
    public const BUILT_IN_NODES = [
        Triggers\ManualTrigger::class,
        Triggers\WebhookTrigger::class,
        Triggers\ScheduleTrigger::class,
        Triggers\FormTrigger::class,
        Actions\HttpRequest::class,
        Actions\Conditional::class,
        Actions\SwitchNode::class,
        Actions\ForLoop::class,
        Actions\RunWorkflow::class,
        Actions\Wait::class,
        Actions\Compose::class,
        Actions\Filter::class,
        Actions\CreateFile::class,
        Actions\LlmCall::class,
        Actions\Merge::class,
        Actions\Approval::class,
        Actions\Task::class,
        Actions\Terminate::class,
        Actions\SendEmail::class,
        Actions\SendNotification::class,
        Actions\ChatMessage::class,
        Actions\UploadToS3::class,
        GitHub\NewTag::class,
        GitHub\NewRelease::class,
        GitHub\WorkflowRunFailed::class,
        GitHub\CreateIssue::class,
        GitHub\AddComment::class,
        GitHub\CreateRelease::class,
        GitHub\RunActionsWorkflow::class,
        Google\AppendSheetRow::class,
        WagRun\ListWorkspaces::class,
        WagRun\ListWorkflows::class,
        WagRun\GetWorkflow::class,
        Note::class,
    ];

    public function register(): void
    {
        $this->app->singleton(NodeRegistry::class, function ($app) {
            $registry = new NodeRegistry($app);

            foreach (self::BUILT_IN_NODES as $node) {
                $registry->register($node);
            }

            return $registry;
        });

        $this->app->bind(TemplateEngine::class, ExpressionTemplateEngine::class);
    }

    public function boot(): void
    {
        Event::listen(ExecutionFinished::class, fn (ExecutionFinished $event) => $this->app->make(Assignments::class)->cancelFor($event->execution));
        Event::listen(ExecutionFinished::class, fn (ExecutionFinished $event) => $this->app->make(RunNotifications::class)->notifyOwnerIfFailed($event->execution));
    }
}
