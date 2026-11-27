<?php

namespace Database\Seeders;

use App\Engine\Editor\CanvasMapper;
use App\Engine\Graph\WorkflowGraph;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Workflows;
use Illuminate\Database\Seeder;

/**
 * Published demo workflows in the Default workspace that show the built-in
 * nodes working together. Workflows that already exist by name are left
 * alone, so the seeder can run again safely.
 */
class DemoWorkflowSeeder extends Seeder
{
    public function __construct(protected Workflows $workflows) {}

    public function run(): void
    {
        $workspace = Workspace::query()->firstWhere('slug', 'default');
        $admin = User::query()->firstWhere('email', 'test@example.com');
        $editor = User::query()->firstWhere('email', 'editor@example.com');

        if (! $workspace || ! $admin || ! $editor) {
            return;
        }

        $demos = [
            'Refund approval' => $this->refundApproval($admin, $editor),
            'Order status updates' => $this->orderStatusUpdates($admin, $editor),
            'New hire onboarding' => $this->newHireOnboarding($admin, $editor),
            'Daily post digest' => $this->postDigest($admin),
        ];

        foreach ($demos as $name => [$description, $graph]) {
            if ($workspace->workflows()->where('name', $name)->exists()) {
                continue;
            }

            $workflow = $this->workflows->create($workspace, $name, $admin, WorkflowGraph::fromArray($graph));
            $workflow->forceFill(['description' => $description])->save();
            $this->workflows->publish($workflow, $admin, 'Demo');
        }
    }

    /**
     * Refunds over 100 need one of two approvers; smaller ones go through.
     * The customer hears back either way, and a timed-out approval tells the
     * admin instead of failing the run.
     *
     * @return array{string, array<string, mixed>}
     */
    protected function refundApproval(User $admin, User $editor): array
    {
        return ['Refunds over 100 need approval; smaller ones are approved automatically.', $this->graph([
            $this->node('start', 'core.manual_trigger', 'Refund request', 0, 200, [
                'fields' => [
                    ['name' => 'customer', 'label' => 'Customer', 'type' => 'text', 'required' => true],
                    ['name' => 'email', 'label' => 'Customer email', 'type' => 'email', 'required' => true],
                    ['name' => 'amount', 'label' => 'Amount', 'type' => 'number', 'required' => true],
                    ['name' => 'reason', 'label' => 'Reason', 'type' => 'textarea', 'required' => false],
                ],
            ]),
            $this->node('check', 'core.conditional', 'Over 100?', 300, 200, ['condition' => '{{ trigger.values.amount > 100 }}']),
            $this->node('approve', 'core.approval', 'Manager approval', 600, 80, [
                'approvers' => [$admin->id, $editor->id],
                'required_approvals' => 1,
                'title' => 'Refund {{ number_format(trigger.values.amount, 2) }} for {{ trigger.values.customer }}',
                'message' => '{{ default(trigger.values.reason, "No reason given.") }}',
                'timeout_hours' => 48,
            ], errorPath: true),
            $this->node('auto', 'core.compose', 'Auto-approve', 600, 360, [
                'template' => 'Refund of {{ trigger.values.amount }} approved automatically.',
            ]),
            $this->node('approved_email', 'core.send_email', 'Tell customer: approved', 960, 120, [
                'to' => '{{ trigger.values.email }}',
                'subject' => 'Your refund of {{ number_format(trigger.values.amount, 2) }} is on its way',
                'format' => 'text',
                'body' => "Hi {{ trigger.values.customer }},\n\nGood news: your refund of {{ number_format(trigger.values.amount, 2) }} has been approved.\n\nThanks for your patience.",
            ]),
            $this->node('rejected_email', 'core.send_email', 'Tell customer: declined', 960, -60, [
                'to' => '{{ trigger.values.email }}',
                'subject' => 'About your refund request',
                'format' => 'text',
                'body' => "Hi {{ trigger.values.customer }},\n\nWe could not approve your refund this time{{ nodes.approve.output.comment ? ': ' ~ nodes.approve.output.comment : '.' }}\n\nReply to this email if you have questions.",
            ]),
            $this->node('timed_out', 'core.send_notification', 'Approval timed out', 960, 300, [
                'recipients' => [$admin->id],
                'title' => 'Refund for {{ trigger.values.customer }} was not approved in time',
                'message' => '{{ error.message }}',
            ]),
        ], [
            ['start', 'main', 'check'],
            ['check', 'true', 'approve'],
            ['check', 'false', 'auto'],
            ['approve', 'approved', 'approved_email'],
            ['approve', 'rejected', 'rejected_email'],
            ['approve', 'error', 'timed_out'],
            ['auto', 'main', 'approved_email'],
        ])];
    }

    /**
     * A shop posts order status changes to the webhook; each status gets its
     * own follow-up.
     *
     * @return array{string, array<string, mixed>}
     */
    protected function orderStatusUpdates(User $admin, User $editor): array
    {
        return ['POST {"order_id": 42, "status": "shipped", "email": "..."} to the webhook URL.', $this->graph([
            $this->node('start', 'core.webhook_trigger', 'Order webhook', 0, 200, ['method' => 'POST', 'verification' => 'none']),
            $this->node('route', 'core.switch', 'By status', 300, 200, [
                'value' => '{{ trigger.body.status }}',
                'cases' => [['value' => 'shipped'], ['value' => 'delayed'], ['value' => 'cancelled']],
                'ignore_case' => true,
            ]),
            $this->node('shipped_email', 'core.send_email', 'Shipping email', 640, 0, [
                'to' => '{{ trigger.body.email }}',
                'subject' => 'Order #{{ trigger.body.order_id }} has shipped',
                'format' => 'text',
                'body' => 'Your order #{{ trigger.body.order_id }} is on its way.',
            ]),
            $this->node('wait', 'core.wait', 'Wait an hour', 640, 160, ['mode' => 'duration', 'amount' => 1, 'unit' => 'hours']),
            $this->node('delayed_email', 'core.send_email', 'Delay email', 940, 160, [
                'to' => '{{ trigger.body.email }}',
                'subject' => 'Order #{{ trigger.body.order_id }} is running late',
                'format' => 'text',
                'body' => 'Sorry, order #{{ trigger.body.order_id }} is delayed. We will email you when it ships.',
            ]),
            $this->node('cancelled', 'core.send_notification', 'Tell the team', 640, 320, [
                'recipients' => [$admin->id, $editor->id],
                'title' => 'Order #{{ trigger.body.order_id }} was cancelled',
                'message' => 'Check whether the customer needs a refund.',
            ]),
            $this->node('ignore', 'core.terminate', 'Ignore other statuses', 640, 480, [
                'status' => 'complete',
                'output' => 'Nothing to do for status "{{ trigger.body.status }}".',
            ]),
        ], [
            ['start', 'main', 'route'],
            ['route', 'case_1', 'shipped_email'],
            ['route', 'case_2', 'wait'],
            ['wait', 'main', 'delayed_email'],
            ['route', 'case_3', 'cancelled'],
            ['route', 'default', 'ignore'],
        ])];
    }

    /**
     * A public form collects the new hire; IT records their laptop, then the
     * welcome email and the team notification go out in parallel.
     *
     * @return array{string, array<string, mixed>}
     */
    protected function newHireOnboarding(User $admin, User $editor): array
    {
        return ['Share the form link with hiring managers.', $this->graph([
            $this->node('start', 'core.form_trigger', 'New hire form', 0, 200, [
                'title' => 'New hire',
                'description' => 'Tell us who is joining and when.',
                'fields' => [
                    ['name' => 'name', 'label' => 'Full name', 'type' => 'text', 'required' => true],
                    ['name' => 'email', 'label' => 'Work email', 'type' => 'email', 'required' => true],
                    ['name' => 'team', 'label' => 'Team', 'type' => 'select', 'required' => true, 'options' => ['eng' => 'Engineering', 'sales' => 'Sales', 'ops' => 'Operations']],
                    ['name' => 'start_date', 'label' => 'Start date', 'type' => 'date', 'required' => true],
                ],
                'access' => 'public',
                'success_message' => 'Thanks! IT will get everything ready.',
            ]),
            $this->node('setup', 'core.task', 'IT setup', 300, 200, [
                'assignee' => $editor->id,
                'title' => 'Set up accounts and a laptop for {{ trigger.values.name }}',
                'instructions' => 'Starts {{ date(trigger.values.start_date, "l j F") }} on the {{ trigger.values.team }} team.',
                'fields' => [
                    ['name' => 'laptop', 'label' => 'Laptop serial number', 'type' => 'text', 'required' => true],
                    ['name' => 'notes', 'label' => 'Notes', 'type' => 'textarea', 'required' => false],
                ],
                'timeout_hours' => 72,
            ]),
            $this->node('welcome', 'core.send_email', 'Welcome email', 620, 80, [
                'to' => '{{ trigger.values.email }}',
                'subject' => 'Welcome aboard, {{ trigger.values.name }}!',
                'format' => 'text',
                'body' => "Hi {{ trigger.values.name }},\n\nWe are excited to have you start on {{ date(trigger.values.start_date, 'l j F') }}. Your laptop is ready for you.",
            ]),
            $this->node('announce', 'core.send_notification', 'Tell the admin', 620, 320, [
                'recipients' => [$admin->id],
                'title' => '{{ trigger.values.name }} is set up',
                'message' => 'Laptop {{ nodes.setup.output.values.laptop }}, prepared by {{ nodes.setup.output.responded_by_name }}.',
            ]),
            $this->node('join', 'core.merge', 'Both done', 920, 200),
            $this->node('summary', 'core.compose', 'Summary', 1200, 200, [
                'template' => '{{ trigger.values.name }} onboarded: welcome email sent to {{ join(nodes.welcome.output.to, ", ") }}.',
            ]),
        ], [
            ['start', 'main', 'setup'],
            ['setup', 'main', 'welcome'],
            ['setup', 'main', 'announce'],
            ['welcome', 'main', 'join'],
            ['announce', 'main', 'join'],
            ['join', 'main', 'summary'],
        ])];
    }

    /**
     * Fetches posts from a public test API, formats each one in a loop and
     * sends the list as one notification.
     *
     * @return array{string, array<string, mixed>}
     */
    protected function postDigest(User $admin): array
    {
        return ['Uses the public JSONPlaceholder test API. Run it manually, or swap in a Schedule trigger.', $this->graph([
            $this->node('start', 'core.manual_trigger', 'Run digest', 0, 200),
            $this->node('fetch', 'core.http_request', 'Fetch posts', 300, 200, [
                'method' => 'GET',
                'url' => 'https://jsonplaceholder.typicode.com/posts',
                'query' => ['userId' => '1', '_limit' => '5'],
                'timeout' => 30,
                'follow_redirects' => true,
                'fail_on_error' => true,
            ]),
            $this->node('each', 'core.for_loop', 'Each post', 600, 200, ['items' => '{{ nodes.fetch.output.body }}']),
            $this->node('line', 'core.compose', 'Format post', 900, 80, [
                'template' => '{{ item.id }}. {{ capitalize(item.title) }}',
            ]),
            $this->node('digest', 'core.send_notification', 'Send digest', 900, 320, [
                'recipients' => [$admin->id],
                'title' => '{{ nodes.each.output.count }} new posts',
                'message' => "{{ join(column(column(column(nodes.each.output.results, 'line'), 'output'), 'value'), '\\n') }}",
            ]),
        ], [
            ['start', 'main', 'fetch'],
            ['fetch', 'main', 'each'],
            ['each', 'item', 'line'],
            ['each', 'done', 'digest'],
        ])];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    protected function node(string $id, string $type, string $name, int $x, int $y, array $config = [], bool $errorPath = false): array
    {
        return array_filter([
            'id' => $id,
            'type' => $type,
            'name' => $name,
            'config' => $config,
            'position' => ['x' => $x, 'y' => $y],
            'error_path' => $errorPath ?: null,
        ], fn (mixed $value) => $value !== null);
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<array{string, string, string}>  $edges  source, port, target
     * @return array<string, mixed>
     */
    protected function graph(array $nodes, array $edges): array
    {
        return [
            'version' => WorkflowGraph::FORMAT_VERSION,
            'nodes' => $nodes,
            'edges' => array_map(fn (array $edge) => [
                'id' => CanvasMapper::edgeId(...$edge),
                'source' => $edge[0],
                'source_port' => $edge[1],
                'target' => $edge[2],
            ], $edges),
        ];
    }
}
