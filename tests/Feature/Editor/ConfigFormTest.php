<?php

namespace Tests\Feature\Editor;

use App\Engine\Editor\ConfigForm;
use App\Engine\NodeRegistry;
use Tests\TestCase;

class ConfigFormTest extends TestCase
{
    public function test_round_trips_key_value_fields_and_list_rows(): void
    {
        $task = app(NodeRegistry::class)->make('core.task');
        $config = [
            'assignee' => 4,
            'title' => 'Check {{ trigger.id }}',
            'fields' => [['name' => 'size', 'label' => 'Size', 'type' => 'select', 'required' => true, 'options' => ['s' => 'Small', 'l' => 'Large']]],
            'timeout_hours' => 48,
        ];

        $form = ConfigForm::toForm($task, $config);

        $this->assertSame("s=Small\nl=Large", $form['fields'][0]['options']);
        $this->assertEquals($config, ConfigForm::toConfig($task, $form));
    }

    public function test_fields_hidden_by_another_fields_value_are_not_saved(): void
    {
        $webhook = app(NodeRegistry::class)->make('core.webhook_trigger');
        $task = app(NodeRegistry::class)->make('core.task');

        $form = ConfigForm::toForm($webhook, ['verification' => 'token', 'secret' => 'hook_key', 'header' => 'X-Token']);
        $form['verification'] = 'ip';
        $form['allowed_ips'] = '10.0.0.0/8';

        $this->assertSame(['method' => 'POST', 'verification' => 'ip', 'allowed_ips' => '10.0.0.0/8'], ConfigForm::toConfig($webhook, $form));

        $form = ConfigForm::toForm($task, ['fields' => [['name' => 'size', 'label' => 'Size', 'type' => 'text', 'options' => ['s' => 'Small']]]]);
        $this->assertArrayNotHasKey('options', ConfigForm::toConfig($task, $form)['fields'][0], 'choices only apply to choice fields');
    }

    public function test_workflow_choices_are_stored_as_uuids(): void
    {
        $runWorkflow = app(NodeRegistry::class)->make('core.run_workflow');
        $form = ConfigForm::toForm($runWorkflow, ['workflow' => '01a20578-74f2-7106-8f87-942283c8c857']);

        $this->assertSame('01a20578-74f2-7106-8f87-942283c8c857', ConfigForm::toConfig($runWorkflow, $form)['workflow']);

        $form['workflow'] = '42';
        $this->assertArrayNotHasKey('workflow', ConfigForm::toConfig($runWorkflow, $form));
    }

    public function test_user_lists_are_edited_as_strings_and_stored_as_unique_ids(): void
    {
        $approval = app(NodeRegistry::class)->make('core.approval');

        $form = ConfigForm::toForm($approval, ['approvers' => [4, 9]]);
        $this->assertSame(['4', '9'], $form['approvers']);

        $form['approvers'] = ['9', '4', '9', ''];
        $this->assertSame([9, 4], ConfigForm::toConfig($approval, $form)['approvers']);
    }

    public function test_key_value_rows_without_a_key_are_dropped_and_numbers_are_stored_as_numbers(): void
    {
        $http = app(NodeRegistry::class)->make('core.http_request');

        $config = ConfigForm::toConfig($http, [
            'url' => 'https://x.test',
            'headers' => [['key' => ' Accept ', 'value' => 'json'], ['key' => '', 'value' => 'orphan']],
            'timeout' => '45',
            'follow_redirects' => false,
        ]);

        $this->assertSame(['Accept' => 'json'], $config['headers']);
        $this->assertSame(45, $config['timeout']);
        $this->assertFalse($config['follow_redirects']);
    }
}
