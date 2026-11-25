<?php

namespace Tests\Feature\Integrations;

use App\Enums\StepStatus;
use App\Models\Credential;
use App\Models\ExecutionStep;
use App\Models\Workspace;
use App\Support\HostResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\RunsWorkflows;
use Tests\TestCase;

class InvestbrainActionsTest extends TestCase
{
    use RefreshDatabase, RunsWorkflows;

    private const PORTFOLIO = '9b1f6c2e-3d4a-4b5c-8d7e-0f1a2b3c4d5e';

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->workspace = Workspace::factory()->create();
        $this->token('cloud', '');

        $this->app->instance(HostResolver::class, new class extends HostResolver
        {
            public function resolve(string $host): array
            {
                return match ($host) {
                    'investbra.in' => ['104.21.80.1'],
                    'investbrain.example.com' => ['93.184.216.34'],
                    'investbrain.corp.internal' => ['10.20.0.9'],
                    default => parent::resolve($host),
                };
            }
        });
    }

    private function token(string $name, string $url, ?Workspace $workspace = null): void
    {
        Credential::factory()->for($workspace ?? $this->workspace)->create(['id' => $this->settingId($name), 'description' => $name, 'type' => 'investbrain', 'config' => ['url' => $url, 'token' => "token-{$name}"]]);
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $input
     */
    private function runAction(string $type, array $config = [], array $input = [], ?Workspace $workspace = null): ExecutionStep
    {
        $execution = $this->runWorkflow($this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger', 'act' => [$type, ['credential' => $this->settingId('cloud'), ...$config], ['max_retries' => 1, 'backoff_seconds' => 0]]],
            ['start->act'],
        ), $workspace ?? $this->workspace), ['values' => $input]);

        return $this->stepFor($execution, 'act');
    }

    public function test_lists_every_page_of_portfolios_from_investbrain_cloud_without_their_transactions(): void
    {
        Http::fake(['investbra.in/api/portfolio*' => Http::sequence()
            ->push(['data' => [['id' => 'p1', 'title' => 'Retirement', 'holdings' => [['symbol' => 'VTI']], 'transactions' => [['id' => 't1']]]], 'meta' => ['last_page' => 2]])
            ->push(['data' => [['id' => 'p2', 'title' => 'Wishlist', 'holdings' => [], 'transactions' => []]], 'meta' => ['last_page' => 2]])]);

        $step = $this->runAction('investbrain.list_portfolios');

        $this->assertSame(['portfolios' => [
            ['id' => 'p1', 'title' => 'Retirement', 'holdings' => [['symbol' => 'VTI']]],
            ['id' => 'p2', 'title' => 'Wishlist', 'holdings' => []],
        ], 'count' => 2], $step->output);
        $this->assertSame(
            ['https://investbra.in/api/portfolio?sort=title&page=1&itemsPerPage=100', 'https://investbra.in/api/portfolio?sort=title&page=2&itemsPerPage=100'],
            Http::recorded()->map(fn (array $pair) => $pair[0]->url())->all(),
        );
        Http::assertSent(fn (Request $request) => $request->header('Authorization') === ['Bearer token-cloud'] && $request->header('Accept') === ['application/json']);
    }

    public function test_lists_holdings_of_a_portfolio_given_by_its_address_leaving_out_sold_ones_unless_asked(): void
    {
        Http::fake(['investbra.in/api/holding*' => Http::response(['data' => [['symbol' => 'AAPL', 'quantity' => 10], ['symbol' => 'AAPL', 'quantity' => 0]], 'meta' => ['last_page' => 1]])]);
        $config = ['portfolio' => 'https://investbra.in/portfolio/'.strtoupper(self::PORTFOLIO), 'symbol' => ' aapl '];

        $held = $this->runAction('investbrain.list_holdings', $config);
        $all = $this->runAction('investbrain.list_holdings', [...$config, 'include_sold' => true]);

        $this->assertSame(['holdings' => [['symbol' => 'AAPL', 'quantity' => 10]], 'count' => 1], $held->output);
        $this->assertSame(2, $all->output['count']);
        Http::assertSent(fn (Request $request) => $request['filter'] === ['portfolio_id' => self::PORTFOLIO, 'symbol' => 'AAPL'] && $request['sort'] === 'symbol');
    }

    public function test_gets_a_holding_keeping_the_symbol_inside_its_path_segment(): void
    {
        $holding = ['id' => 'h1', 'symbol' => 'BRK.B', 'quantity' => 3, 'dividends_earned' => 0];
        Http::fake(['investbra.in/api/holding/*' => Http::response($holding)]);

        $step = $this->runAction('investbrain.get_holding', ['portfolio' => self::PORTFOLIO, 'symbol' => '{{ trigger.values.symbol }}'], ['symbol' => 'brk.b/../../me']);

        $this->assertSame($holding, $step->output);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://investbra.in/api/holding/'.self::PORTFOLIO.'/BRK.B%2F..%2F..%2FME');
    }

    public function test_lists_transactions_newest_first_up_to_the_limit_across_pages(): void
    {
        $page = fn (int $from) => ['data' => array_map(fn (int $i) => ['id' => "t{$i}"], range($from, $from + 99)), 'meta' => ['last_page' => 3]];
        Http::fake(['investbra.in/api/transaction*' => Http::sequence()->push($page(1))->push($page(101))]);

        $step = $this->runAction('investbrain.list_transactions', ['transaction_type' => 'SELL', 'limit' => 150]);

        $this->assertSame(150, $step->output['count']);
        $this->assertSame('t150', $step->output['transactions'][149]['id']);
        $this->assertCount(2, Http::recorded());
        Http::assertSent(fn (Request $request) => $request['sort'] === '-date,-created_at' && $request['filter'] === ['transaction_type' => 'SELL'] && (string) $request['itemsPerPage'] === '100');
    }

    public function test_adds_a_buy_at_its_cost_dated_today_and_a_sale_at_its_price_on_the_date_given(): void
    {
        $this->travelTo('2026-11-25 15:00:00');
        Http::fake(['investbra.in/api/transaction' => Http::response(['id' => 't9', 'symbol' => 'AAPL', 'transaction_type' => 'BUY'], 201)]);

        $buy = $this->runAction('investbrain.add_transaction', ['portfolio' => self::PORTFOLIO, 'symbol' => 'aapl', 'quantity' => '2', 'price' => ' 189.50 ']);
        $this->runAction('investbrain.add_transaction', ['portfolio' => self::PORTFOLIO, 'transaction_type' => 'SELL', 'symbol' => 'AAPL', 'quantity' => '1.5', 'price' => '200', 'currency' => 'cad', 'date' => 'November 20, 2026']);

        $this->assertSame(['id' => 't9', 'symbol' => 'AAPL', 'transaction_type' => 'BUY'], $buy->output);
        $this->assertSame([
            ['portfolio_id' => self::PORTFOLIO, 'symbol' => 'AAPL', 'transaction_type' => 'BUY', 'quantity' => 2, 'cost_basis' => 189.5, 'currency' => 'USD', 'date' => '2026-11-25'],
            ['portfolio_id' => self::PORTFOLIO, 'symbol' => 'AAPL', 'transaction_type' => 'SELL', 'quantity' => 1.5, 'sale_price' => 200, 'currency' => 'CAD', 'date' => '2026-11-20'],
        ], Http::recorded()->map(fn (array $pair) => $pair[0]->data())->all());
    }

    public function test_refusals_fail_the_step_with_investbrains_message_and_only_server_errors_are_retried(): void
    {
        Http::fake([
            'investbra.in/api/transaction' => Http::response(['message' => 'The quantity must not be greater than the available quantity.'], 422),
            'investbra.in/api/market-data/*' => Http::sequence()->push('Service Unavailable', 503)->push(['symbol' => 'AAPL', 'market_value' => 230.1]),
        ]);

        $refused = $this->runAction('investbrain.add_transaction', ['portfolio' => self::PORTFOLIO, 'transaction_type' => 'SELL', 'symbol' => 'AAPL', 'quantity' => '500', 'price' => '200']);
        $this->assertSame(StepStatus::Failed, $refused->status);
        $this->assertSame('Investbrain answered 422: The quantity must not be greater than the available quantity.', $refused->error['message']);
        $this->assertSame(1, $refused->execution->steps()->where('node_id', 'act')->count());

        $quote = $this->runAction('investbrain.get_quote', ['symbol' => 'aapl']);
        $this->sweep();

        $retried = $this->stepFor($quote->execution, 'act', 2);
        $this->assertSame(StepStatus::Complete, $retried->status);
        $this->assertSame(['symbol' => 'AAPL', 'market_value' => 230.1], $retried->output);
    }

    public function test_reaches_a_self_hosted_investbrain_at_the_address_in_the_setting(): void
    {
        $this->token('self', 'https://investbrain.example.com/');
        Http::fake(['investbrain.example.com/api/market-data/*' => Http::response(['symbol' => 'VTI'])]);

        $step = $this->runAction('investbrain.get_quote', ['credential' => $this->settingId('self'), 'symbol' => 'vti']);

        $this->assertSame(['symbol' => 'VTI'], $step->output);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://investbrain.example.com/api/market-data/VTI'
            && $request->header('Authorization') === ['Bearer token-self']);
    }

    public function test_an_investbrain_on_an_internal_address_needs_the_workspaces_allowlist(): void
    {
        $this->token('internal', 'http://investbrain.corp.internal:8000');
        $allowed = Workspace::factory()->create(['internal_allowlist' => ['*.corp.internal']]);
        $this->token('allowed', 'http://investbrain.corp.internal:8000', $allowed);
        Http::fake(['investbrain.corp.internal:8000/api/market-data/*' => Http::response(['symbol' => 'VTI'])]);

        $refused = $this->runAction('investbrain.get_quote', ['credential' => $this->settingId('internal'), 'symbol' => 'VTI']);

        $this->assertSame(StepStatus::Failed, $refused->status);
        $this->assertStringContainsString('10.20.0.9', $refused->error['message']);
        Http::assertNothingSent();

        $this->assertSame(StepStatus::Complete, $this->runAction('investbrain.get_quote', ['credential' => $this->settingId('allowed'), 'symbol' => 'VTI'], workspace: $allowed)->status);
    }

    public function test_explains_a_missing_setting_a_redirect_an_answer_that_isnt_json_and_a_bad_portfolio(): void
    {
        Credential::factory()->for($this->workspace)->create(['id' => $this->settingId('other'), 'description' => 'other']);
        $this->token('plain', 'http://investbrain.example.com');
        Http::fake([
            'http://investbrain.example.com/*' => Http::response('', 301, ['Location' => 'https://investbrain.example.com/api/market-data/VTI']),
            'investbra.in/api/market-data/*' => Http::response('<html>Sign in</html>'),
        ]);

        $this->assertSame('The Investbrain API token setting was not found in this workspace.', $this->runAction('investbrain.get_quote', ['credential' => $this->settingId('other'), 'symbol' => 'VTI'])->error['message']);
        $this->assertSame(
            'Investbrain answered 301, pointing to https://investbrain.example.com/api/market-data/VTI. Check the address in the Investbrain API token setting.',
            $this->runAction('investbrain.get_quote', ['credential' => $this->settingId('plain'), 'symbol' => 'VTI'])->error['message'],
        );
        $this->assertSame('Investbrain did not answer with JSON. Check the address in the Investbrain API token setting.', $this->runAction('investbrain.get_quote', ['symbol' => 'VTI'])->error['message']);
        $this->assertSame('"Retirement" is not a portfolio ID or a portfolio\'s address.', $this->runAction('investbrain.get_holding', ['portfolio' => 'Retirement', 'symbol' => 'VTI'])->error['message']);
    }
}
