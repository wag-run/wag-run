<?php

namespace App\Engine\Nodes\Investbrain;

use App\Engine\Definitions\Field;
use App\Engine\Definitions\Output;
use App\Engine\EventTrigger;
use App\Engine\Node;
use App\Engine\NodeContext;
use App\Engine\NodeResult;
use App\Enums\NodeKind;
use App\Models\Workspace;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Starts the workflow when a transaction is added in Investbrain, in any
 * portfolio or one, optionally of one symbol or one kind. Investbrain has no
 * webhooks, so it polls the newest transactions every few minutes and
 * remembers the ones it has seen.
 *
 * Investbrain adds transactions of its own for stock splits and reinvested
 * dividends; they're left out unless asked for.
 */
class NewTransaction extends Node implements EventTrigger
{
    /**
     * How many transaction ids a poll remembers.
     */
    protected const MEMORY = 300;

    public static function type(): string
    {
        return 'investbrain.new_transaction';
    }

    public function label(): string
    {
        return __('nodes.investbrain.new_transaction');
    }

    public function description(): string
    {
        return __('nodes.investbrain.new_transaction_description');
    }

    public function kind(): NodeKind
    {
        return NodeKind::Trigger;
    }

    public function icon(): string
    {
        return 'arrows-right-left';
    }

    public function paletteGroup(): array
    {
        return [__('common.triggers'), __('common.other_apps'), __('nodes.investbrain.investbrain')];
    }

    public function inputs(): array
    {
        return [
            new Field('credential', __('nodes.investbrain.api_token'), Field::CREDENTIAL, required: true, credentialTypes: [Investbrain::CREDENTIAL_TYPE], description: __('nodes.investbrain.api_token_help')),
            new Field('portfolio', __('nodes.investbrain.portfolio'), Field::TEXT, description: __('nodes.investbrain.portfolio_filter_help')),
            new Field('symbol', __('nodes.investbrain.symbol'), Field::TEXT, description: __('nodes.investbrain.symbol_filter_help')),
            new Field('transaction_type', __('nodes.investbrain.type'), Field::SELECT, default: '', options: [
                '' => __('nodes.investbrain.buys_and_sales'),
                'BUY' => __('nodes.investbrain.buys'),
                'SELL' => __('nodes.investbrain.sales'),
            ]),
            new Field('include_automatic', __('nodes.investbrain.include_automatic'), Field::BOOLEAN, default: false, description: __('nodes.investbrain.include_automatic_help')),
            new Field('poll_minutes', __('nodes.investbrain.check_every_minutes'), Field::NUMBER, default: 5),
        ];
    }

    public function outputs(array $config = []): array
    {
        return [
            new Output('id', __('nodes.investbrain.id'), 'string'),
            new Output('symbol', __('nodes.investbrain.symbol'), 'string'),
            new Output('transaction_type', __('nodes.investbrain.type'), 'string'),
            new Output('quantity', __('nodes.investbrain.shares'), 'number'),
            new Output('cost_basis', __('nodes.investbrain.cost_basis'), 'number'),
            new Output('sale_price', __('nodes.investbrain.sale_price'), 'number'),
            new Output('currency', __('nodes.investbrain.currency'), 'string'),
            new Output('date', __('nodes.investbrain.date'), 'string'),
            new Output('portfolio_id', __('nodes.investbrain.portfolio_id'), 'string'),
            new Output('split', __('nodes.investbrain.from_a_split'), 'boolean'),
            new Output('reinvested_dividend', __('nodes.investbrain.from_a_reinvested_dividend'), 'boolean'),
            new Output('created_at', __('nodes.investbrain.added_at'), 'string'),
        ];
    }

    public function configProblems(array $config): array
    {
        try {
            Investbrain::portfolio($config['portfolio'] ?? '');
        } catch (RuntimeException $e) {
            return [$e->getMessage()];
        }

        return [];
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
        $filters = array_filter([
            'portfolio_id' => Investbrain::portfolio($config['portfolio'] ?? ''),
            'symbol' => Investbrain::symbol($config['symbol'] ?? ''),
            'transaction_type' => (string) ($config['transaction_type'] ?? ''),
        ], fn (?string $value) => $value !== null && $value !== '');

        $response = Investbrain::request($workspace, $config['credential'] ?? null)->get('/transaction', array_filter([
            'sort' => '-created_at',
            'itemsPerPage' => Investbrain::PAGE_SIZE,
            'filter' => $filters,
        ]));

        if (! $response->successful()) {
            throw new RuntimeException(Investbrain::failure($response));
        }

        $data = Investbrain::json($response)['data'] ?? null;

        if (! is_array($data) || ! array_is_list($data)) {
            throw new RuntimeException(__('nodes.investbrain.unexpected_answer'));
        }

        $transactions = array_values(array_filter($data, fn (mixed $transaction) => is_array($transaction) && isset($transaction['id'])));
        $ids = array_map(fn (array $transaction) => (string) $transaction['id'], $transactions);
        $seen = $state === null ? $ids : (array) ($state['seen'] ?? []);

        $new = array_filter($transactions, fn (array $transaction) => ! in_array((string) $transaction['id'], $seen, true)
            && (($config['include_automatic'] ?? false) || (! ($transaction['split'] ?? false) && ! ($transaction['reinvested_dividend'] ?? false))));

        return [
            'events' => array_map(fn (array $transaction) => $this->event($transaction), array_reverse(array_values($new))),
            'state' => ['seen' => array_slice(array_values(array_unique([...$ids, ...$seen])), 0, self::MEMORY)],
        ];
    }

    public function subscribe(array $config, Workspace $workspace, string $url, string $secret): array
    {
        throw new RuntimeException(__('nodes.investbrain.no_webhooks'));
    }

    public function unsubscribe(array $config, Workspace $workspace, array $subscription): void {}

    public function receive(array $config, Request $request, string $secret): ?array
    {
        return null;
    }

    /**
     * @param  array<string, mixed>  $transaction
     * @return array<string, mixed>
     */
    protected function event(array $transaction): array
    {
        return [
            'id' => $transaction['id'],
            'symbol' => $transaction['symbol'] ?? null,
            'transaction_type' => $transaction['transaction_type'] ?? null,
            'quantity' => $transaction['quantity'] ?? null,
            'cost_basis' => $transaction['cost_basis'] ?? null,
            'sale_price' => $transaction['sale_price'] ?? null,
            'currency' => $transaction['currency'] ?? null,
            'date' => $transaction['date'] ?? null,
            'portfolio_id' => $transaction['portfolio_id'] ?? null,
            'split' => (bool) ($transaction['split'] ?? false),
            'reinvested_dividend' => (bool) ($transaction['reinvested_dividend'] ?? false),
            'created_at' => $transaction['created_at'] ?? null,
        ];
    }
}
