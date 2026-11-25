<?php

namespace App\Engine\Nodes\Investbrain;

use App\Engine\Definitions\Field;
use App\Engine\Definitions\Output;
use App\Engine\NodeContext;
use Illuminate\Http\Client\PendingRequest;

/**
 * One symbol's holding in a portfolio, with its cost, gains, and dividends.
 */
class GetHolding extends InvestbrainAction
{
    public static function type(): string
    {
        return 'investbrain.get_holding';
    }

    public function label(): string
    {
        return __('nodes.investbrain.get_holding');
    }

    public function description(): string
    {
        return __('nodes.investbrain.get_holding_description');
    }

    public function icon(): string
    {
        return 'chart-bar';
    }

    protected function actionInputs(): array
    {
        return [
            new Field('portfolio', __('nodes.investbrain.portfolio'), Field::TEXT, required: true, templated: true, description: __('nodes.investbrain.portfolio_help')),
            new Field('symbol', __('nodes.investbrain.symbol'), Field::TEXT, required: true, templated: true, description: __('nodes.investbrain.symbol_help')),
        ];
    }

    public function outputs(array $config = []): array
    {
        return [
            new Output('symbol', __('nodes.investbrain.symbol'), 'string'),
            new Output('quantity', __('nodes.investbrain.shares'), 'number'),
            new Output('currency', __('nodes.investbrain.currency'), 'string'),
            new Output('average_cost_basis', __('nodes.investbrain.average_cost_basis'), 'number'),
            new Output('total_cost_basis', __('nodes.investbrain.total_cost_basis'), 'number'),
            new Output('total_market_value', __('nodes.investbrain.market_value'), 'number'),
            new Output('market_gain_dollars', __('nodes.investbrain.market_gain'), 'number'),
            new Output('market_gain_percent', __('nodes.investbrain.market_gain_percent'), 'number'),
            new Output('realized_gain_dollars', __('nodes.investbrain.realized_gain'), 'number'),
            new Output('dividends_earned', __('nodes.investbrain.dividends_earned'), 'number'),
            new Output('reinvest_dividends', __('nodes.investbrain.reinvests_dividends'), 'boolean'),
            new Output('portfolio_id', __('nodes.investbrain.portfolio_id'), 'string'),
            new Output('id', __('nodes.investbrain.id'), 'string'),
        ];
    }

    protected function run(PendingRequest $api, NodeContext $context): array
    {
        $portfolio = $this->segment(Investbrain::portfolio($context->input('portfolio')), __('nodes.investbrain.portfolio_is_empty'));
        $symbol = $this->segment(Investbrain::symbol($context->input('symbol')), __('nodes.investbrain.symbol_is_empty'));

        return Investbrain::json($api->get("/holding/{$portfolio}/{$symbol}"));
    }
}
