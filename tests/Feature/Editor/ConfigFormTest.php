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
