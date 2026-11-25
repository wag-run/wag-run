<?php

namespace App\Engine\Nodes\Investbrain;

use App\Engine\Definitions\Field;
use App\Engine\Definitions\Output;
use App\Engine\NodeContext;
use Illuminate\Http\Client\PendingRequest;

/**
 * Holdings across every portfolio, or one portfolio's, optionally of one
 * symbol. Investbrain keeps a holding after its last share is sold; those
 * are left out unless asked for.
 */
class ListHoldings extends InvestbrainAction
{
    /**
     * The most holdings read.
     */
    public const LIMIT = 1000;

    public static function type(): string
    {
        return 'investbrain.list_holdings';
    }

    public function label(): string
    {
        return __('nodes.investbrain.list_holdings');
    }

    public function description(): string
    {
        return __('nodes.investbrain.list_holdings_description');
    }

    public function icon(): string
    {
        return 'chart-pie';
    }

    protected function actionInputs(): array
    {
        return [
            new Field('portfolio', __('nodes.investbrain.portfolio'), Field::TEXT, templated: true, description: __('nodes.investbrain.portfolio_filter_help')),
            new Field('symbol', __('nodes.investbrain.symbol'), Field::TEXT, templated: true, description: __('nodes.investbrain.symbol_filter_help')),
            new Field('include_sold', __('nodes.investbrain.include_sold'), Field::BOOLEAN, default: false, description: __('nodes.investbrain.include_sold_help')),
        ];
    }

    public function outputs(array $config = []): array
    {
        return [
            new Output('holdings', __('nodes.investbrain.holdings'), 'array'),
            new Output('count', __('nodes.investbrain.how_many'), 'number'),
        ];
    }

    protected function run(PendingRequest $api, NodeContext $context): array
    {
        $holdings = Investbrain::list($api, '/holding', [
            'sort' => 'symbol',
            ...$this->filters([
                'portfolio_id' => Investbrain::portfolio($context->input('portfolio')),
                'symbol' => Investbrain::symbol($context->input('symbol')),
            ]),
        ], self::LIMIT);

        if (! $context->input('include_sold', false)) {
            $holdings = array_values(array_filter($holdings, fn (array $holding) => (float) ($holding['quantity'] ?? 0) > 0));
        }

        return ['holdings' => $holdings, 'count' => count($holdings)];
    }
}
