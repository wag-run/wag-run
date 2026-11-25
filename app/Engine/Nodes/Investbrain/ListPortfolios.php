<?php

namespace App\Engine\Nodes\Investbrain;

use App\Engine\Definitions\Output;
use App\Engine\NodeContext;
use Illuminate\Http\Client\PendingRequest;

/**
 * The portfolios the API token's person can see, each with its holdings.
 * Investbrain lists every transaction with each portfolio too; they're left
 * out, as List transactions reads them a page at a time.
 */
class ListPortfolios extends InvestbrainAction
{
    /**
     * The most portfolios read.
     */
    public const LIMIT = 1000;

    public static function type(): string
    {
        return 'investbrain.list_portfolios';
    }

    public function label(): string
    {
        return __('nodes.investbrain.list_portfolios');
    }

    public function description(): string
    {
        return __('nodes.investbrain.list_portfolios_description');
    }

    public function icon(): string
    {
        return 'briefcase';
    }

    public function outputs(array $config = []): array
    {
        return [
            new Output('portfolios', __('nodes.investbrain.portfolios'), 'array'),
            new Output('count', __('nodes.investbrain.how_many'), 'number'),
        ];
    }

    protected function run(PendingRequest $api, NodeContext $context): array
    {
        $portfolios = array_map(
            fn (array $portfolio) => array_diff_key($portfolio, ['transactions' => true]),
            Investbrain::list($api, '/portfolio', ['sort' => 'title'], self::LIMIT),
        );

        return ['portfolios' => $portfolios, 'count' => count($portfolios)];
    }
}
