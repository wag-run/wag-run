<?php

namespace App\Engine\Nodes\Investbrain;

use App\Engine\Definitions\Field;
use App\Engine\Definitions\Output;
use App\Engine\NodeContext;
use Illuminate\Http\Client\PendingRequest;

/**
 * Transactions, newest first, across every portfolio or in one, optionally
 * of one symbol or one kind.
 */
class ListTransactions extends InvestbrainAction
{
    /**
     * The most transactions one step reads.
     */
    public const MAX_LIMIT = 1000;

    public static function type(): string
    {
        return 'investbrain.list_transactions';
    }

    public function label(): string
    {
        return __('nodes.investbrain.list_transactions');
    }

    public function description(): string
    {
        return __('nodes.investbrain.list_transactions_description');
    }

    public function icon(): string
    {
        return 'queue-list';
    }

    protected function actionInputs(): array
    {
        return [
            new Field('portfolio', __('nodes.investbrain.portfolio'), Field::TEXT, templated: true, description: __('nodes.investbrain.portfolio_filter_help')),
            new Field('symbol', __('nodes.investbrain.symbol'), Field::TEXT, templated: true, description: __('nodes.investbrain.symbol_filter_help')),
            new Field('transaction_type', __('nodes.investbrain.type'), Field::SELECT, default: '', options: [
                '' => __('nodes.investbrain.buys_and_sales'),
                'BUY' => __('nodes.investbrain.buys'),
                'SELL' => __('nodes.investbrain.sales'),
            ]),
            new Field('limit', __('nodes.investbrain.at_most'), Field::NUMBER, default: 100, description: __('nodes.investbrain.at_most_help', ['max' => self::MAX_LIMIT])),
        ];
    }

    public function outputs(array $config = []): array
    {
        return [
            new Output('transactions', __('nodes.investbrain.transactions'), 'array'),
            new Output('count', __('nodes.investbrain.how_many'), 'number'),
        ];
    }

    protected function run(PendingRequest $api, NodeContext $context): array
    {
        $transactions = Investbrain::list($api, '/transaction', [
            'sort' => '-date,-created_at',
            ...$this->filters([
                'portfolio_id' => Investbrain::portfolio($context->input('portfolio')),
                'symbol' => Investbrain::symbol($context->input('symbol')),
                'transaction_type' => (string) $context->input('transaction_type', ''),
            ]),
        ], max(1, min(self::MAX_LIMIT, (int) $context->input('limit', 100))));

        return ['transactions' => $transactions, 'count' => count($transactions)];
    }
}
