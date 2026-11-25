<?php

namespace App\Engine\Nodes\Investbrain;

use App\Engine\Definitions\Field;
use App\Engine\Definitions\Output;
use App\Engine\NodeContext;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Records a buy or a sale in a portfolio. The price is per share: the cost
 * of a buy, or what a sale fetched. Investbrain checks the rest, such as a
 * sale not selling more shares than the portfolio held on its date.
 */
class AddTransaction extends InvestbrainAction
{
    public static function type(): string
    {
        return 'investbrain.add_transaction';
    }

    public function label(): string
    {
        return __('nodes.investbrain.add_transaction');
    }

    public function description(): string
    {
        return __('nodes.investbrain.add_transaction_description');
    }

    public function icon(): string
    {
        return 'plus-circle';
    }

    protected function actionInputs(): array
    {
        return [
            new Field('portfolio', __('nodes.investbrain.portfolio'), Field::TEXT, required: true, templated: true, description: __('nodes.investbrain.portfolio_help')),
            new Field('transaction_type', __('nodes.investbrain.type'), Field::SELECT, required: true, default: 'BUY', options: [
                'BUY' => __('nodes.investbrain.buy'),
                'SELL' => __('nodes.investbrain.sale'),
            ]),
            new Field('symbol', __('nodes.investbrain.symbol'), Field::TEXT, required: true, templated: true, description: __('nodes.investbrain.symbol_help')),
            new Field('quantity', __('nodes.investbrain.shares'), Field::TEXT, required: true, templated: true),
            new Field('price', __('nodes.investbrain.price_per_share'), Field::TEXT, required: true, templated: true, description: __('nodes.investbrain.price_per_share_help')),
            new Field('currency', __('nodes.investbrain.currency'), Field::TEXT, required: true, default: 'USD', templated: true, description: __('nodes.investbrain.currency_help')),
            new Field('date', __('nodes.investbrain.date'), Field::TEXT, templated: true, description: __('nodes.investbrain.date_help')),
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
        ];
    }

    protected function run(PendingRequest $api, NodeContext $context): array
    {
        $type = $context->input('transaction_type') === 'SELL' ? 'SELL' : 'BUY';

        return Investbrain::json($api->post('/transaction', [
            'portfolio_id' => Investbrain::portfolio($context->input('portfolio')) ?? throw new RuntimeException(__('nodes.investbrain.portfolio_is_empty')),
            'symbol' => Investbrain::symbol($context->input('symbol')) ?? throw new RuntimeException(__('nodes.investbrain.symbol_is_empty')),
            'transaction_type' => $type,
            'quantity' => $this->number($context->input('quantity')),
            $type === 'BUY' ? 'cost_basis' : 'sale_price' => $this->number($context->input('price')),
            'currency' => strtoupper(trim((string) $context->input('currency', 'USD'))),
            'date' => $this->date($context->input('date')),
        ]));
    }

    /**
     * A number as a number, and anything else as it is, for Investbrain to
     * say what's wrong with it.
     */
    private function number(mixed $value): mixed
    {
        $value = is_string($value) ? trim($value) : $value;

        return is_numeric($value) ? $value + 0 : $value;
    }

    /**
     * The date as Investbrain wants it, today when it's empty.
     *
     * @throws RuntimeException when it isn't a date
     */
    private function date(mixed $value): string
    {
        $value = trim((string) $value);

        try {
            return ($value === '' ? Carbon::now() : Carbon::parse($value))->toDateString();
        } catch (InvalidFormatException) {
            throw new RuntimeException(__('nodes.investbrain.invalid_date', ['date' => $value]));
        }
    }
}
