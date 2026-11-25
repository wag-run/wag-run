<?php

namespace App\Engine\Nodes\Investbrain;

use App\Engine\Definitions\Field;
use App\Engine\Definitions\Output;
use App\Engine\NodeContext;
use Illuminate\Http\Client\PendingRequest;

/**
 * A symbol's price and fundamentals, from the market data Investbrain keeps.
 * The symbol doesn't need to be held in a portfolio.
 */
class GetQuote extends InvestbrainAction
{
    public static function type(): string
    {
        return 'investbrain.get_quote';
    }

    public function label(): string
    {
        return __('nodes.investbrain.get_quote');
    }

    public function description(): string
    {
        return __('nodes.investbrain.get_quote_description');
    }

    public function icon(): string
    {
        return 'currency-dollar';
    }

    protected function actionInputs(): array
    {
        return [
            new Field('symbol', __('nodes.investbrain.symbol'), Field::TEXT, required: true, templated: true, description: __('nodes.investbrain.symbol_help')),
        ];
    }

    public function outputs(array $config = []): array
    {
        return [
            new Output('symbol', __('nodes.investbrain.symbol'), 'string'),
            new Output('name', __('common.name'), 'string'),
            new Output('market_value', __('nodes.investbrain.price'), 'number'),
            new Output('fifty_two_week_low', __('nodes.investbrain.fifty_two_week_low'), 'number'),
            new Output('fifty_two_week_high', __('nodes.investbrain.fifty_two_week_high'), 'number'),
            new Output('dividend_yield', __('nodes.investbrain.dividend_yield'), 'number'),
            new Output('last_dividend_amount', __('nodes.investbrain.last_dividend_amount'), 'number'),
            new Output('last_dividend_date', __('nodes.investbrain.last_dividend_date'), 'string'),
            new Output('market_cap', __('nodes.investbrain.market_cap'), 'number'),
            new Output('trailing_pe', __('nodes.investbrain.trailing_pe'), 'number'),
            new Output('forward_pe', __('nodes.investbrain.forward_pe'), 'number'),
            new Output('book_value', __('nodes.investbrain.book_value'), 'number'),
            new Output('updated_at', __('nodes.investbrain.updated_at'), 'string'),
        ];
    }

    protected function run(PendingRequest $api, NodeContext $context): array
    {
        $symbol = $this->segment(Investbrain::symbol($context->input('symbol')), __('nodes.investbrain.symbol_is_empty'));

        return Investbrain::json($api->get("/market-data/{$symbol}"));
    }
}
