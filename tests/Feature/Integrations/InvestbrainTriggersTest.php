<?php

namespace Tests\Feature\Integrations;

use App\Models\Credential;
use App\Models\Execution;
use App\Models\Workflow;
use App\Models\WorkflowTrigger;
use App\Models\Workspace;
use App\Services\Workflows;
use App\Support\HostResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\RunsWorkflows;
use Tests\TestCase;

class InvestbrainTriggersTest extends TestCase
{
    use RefreshDatabase, RunsWorkflows;

    private const PORTFOLIO = '9b1f6c2e-3d4a-4b5c-8d7e-0f1a2b3c4d5e';

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->workspace = Workspace::factory()->create();
        Credential::factory()->for($this->workspace)->create(['id' => $this->settingId('cloud'), 'description' => 'cloud', 'type' => 'investbrain', 'config' => ['url' => '', 'token' => 'token-cloud']]);

        $this->app->instance(HostResolver::class, new class extends HostResolver
        {
            public function resolve(string $host): array
            {
                return $host === 'investbra.in' ? ['104.21.80.1'] : parent::resolve($host);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function publish(array $config = []): Workflow
    {
        $workflow = Workflow::factory()->for($this->workspace)->create(['draft_graph' => $this->graph(['on' => ['investbrain.new_transaction', ['credential' => $this->settingId('cloud'), ...$config]]])]);
        app(Workflows::class)->publish($workflow);

        return $workflow->refresh();
    }

    /**
     * A transaction as Investbrain lists it.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function transaction(string $id, array $overrides = []): array
    {
        return [
            'id' => $id, 'symbol' => 'AAPL', 'portfolio_id' => self::PORTFOLIO, 'transaction_type' => 'BUY', 'quantity' => 2, 'currency' => 'USD',
            'cost_basis' => 189.5, 'sale_price' => null, 'split' => false, 'reinvested_dividend' => false, 'date' => '2026-11-25',
            'created_at' => '2026-11-25T15:00:00.000000Z', 'updated_at' => '2026-11-25T15:00:00.000000Z', ...$overrides,
        ];
    }

    private function poll(): void
    {
        $this->travel(5)->minutes();
        $this->artisan('workflows:poll-triggers')->assertSuccessful();
    }

    public function test_records_existing_transactions_then_starts_a_run_per_new_buy_or_sale_oldest_first(): void
    {
        $workflow = $this->publish();
        $this->assertSame(WorkflowTrigger::POLL, $workflow->triggers()->sole()->type);

        $latest = ['data' => [
            $this->transaction('t5'),
            $this->transaction('t4', ['reinvested_dividend' => true, 'cost_basis' => 0]),
            $this->transaction('t3', ['split' => true, 'cost_basis' => 0]),
            $this->transaction('t2', ['transaction_type' => 'SELL', 'cost_basis' => 150.0, 'sale_price' => 210.25, 'date' => '2026-11-24']),
            $this->transaction('t1'),
        ]];
        Http::fake(['investbra.in/api/transaction*' => Http::sequence()->push(['data' => [$this->transaction('t1')]])->push($latest)->push($latest)]);

        $this->artisan('workflows:poll-triggers')->assertSuccessful();
        $this->assertSame(0, Execution::count(), 'the first poll only records what exists');

        $this->poll();

        $runs = Execution::query()->orderBy('id')->get();
        $this->assertSame(['t2', 't5'], $runs->pluck('input.id')->all(), 'splits and reinvested dividends are left out');
        $this->assertSame('event', $runs[0]->trigger_type);
        $this->assertSame([
            'id' => 't2', 'symbol' => 'AAPL', 'transaction_type' => 'SELL', 'quantity' => 2, 'cost_basis' => 150, 'sale_price' => 210.25, 'currency' => 'USD',
            'date' => '2026-11-24', 'portfolio_id' => self::PORTFOLIO, 'split' => false, 'reinvested_dividend' => false, 'created_at' => '2026-11-25T15:00:00.000000Z',
        ], $runs[0]->input);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://investbra.in/api/transaction?sort=-created_at&itemsPerPage=100'
            && $request->header('Authorization') === ['Bearer token-cloud']);

        $this->poll();
        $this->assertSame(2, Execution::count(), 'transactions already seen start nothing');
    }

    public function test_asks_only_for_the_portfolio_symbol_and_type_chosen_and_can_include_automatic_transactions(): void
    {
        $this->publish(['portfolio' => 'https://investbra.in/portfolio/'.self::PORTFOLIO, 'symbol' => 'msft', 'transaction_type' => 'BUY', 'include_automatic' => true]);
        Http::fake(['investbra.in/api/transaction*' => Http::sequence()->push(['data' => []])->push(['data' => [$this->transaction('t1', ['symbol' => 'MSFT', 'split' => true])]])]);

        $this->artisan('workflows:poll-triggers');
        $this->poll();

        $this->assertTrue(Execution::query()->sole()->input['split']);
        Http::assertSent(fn (Request $request) => $request['filter'] === ['portfolio_id' => self::PORTFOLIO, 'symbol' => 'MSFT', 'transaction_type' => 'BUY']);
    }

    public function test_a_failed_poll_is_noted_on_the_trigger_and_keeps_what_it_had_seen(): void
    {
        $workflow = $this->publish();
        Http::fake(['investbra.in/api/transaction*' => Http::sequence()
            ->push(['data' => [$this->transaction('t1')]])
            ->push(['message' => 'Unauthenticated.'], 401)
            ->push('<html>Sign in</html>')
            ->push(['data' => [$this->transaction('t1')]])]);

        $this->artisan('workflows:poll-triggers');
        $this->poll();
        $this->assertSame('Investbrain answered 401: Unauthenticated.', $workflow->triggers()->sole()->state['error']);

        $this->poll();
        $this->assertSame('Investbrain did not answer with JSON. Check the address in the Investbrain API token setting.', $workflow->triggers()->sole()->state['error']);

        $this->poll();
        $this->assertNull($workflow->triggers()->sole()->state['error']);
        $this->assertSame(0, Execution::count());
    }

    public function test_a_portfolio_that_isnt_one_cant_be_published(): void
    {
        $this->expectException(ValidationException::class);

        $this->publish(['portfolio' => 'Retirement']);
    }
}
