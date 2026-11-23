<?php

namespace App\Support;

use App\Models\Execution;
use Illuminate\Http\Request;

/**
 * Carries a run's place in a chain of runs through an HTTP call to this
 * install, such as an HTTP call node posting to one of its own webhooks.
 *
 * The call is signed with the app key, so only this install can claim that
 * a request came from one of its runs. The run it starts is then one level
 * deeper in the chain, and stops at the organization's maximum chain depth
 * like a run started by a Trigger other workflow node.
 */
class RunChain
{
    public const HEADER = 'X-Wag-Run';

    /**
     * Whether a request to $host goes to this install.
     */
    public static function isOwnHost(string $host): bool
    {
        $own = parse_url((string) config('app.url'), PHP_URL_HOST);

        return is_string($own) && strcasecmp(rtrim($host, '.'), $own) === 0;
    }

    /**
     * The header value naming $execution as the run making the request.
     */
    public static function headerFor(Execution $execution): string
    {
        return $execution->id.'.'.self::signature($execution->id);
    }

    /**
     * The run that made $request, when it carries a valid header.
     */
    public static function from(Request $request): ?Execution
    {
        $value = (string) $request->header(self::HEADER, '');
        [$id, $signature] = array_pad(explode('.', $value, 2), 2, '');

        if ($id === '' || $signature === '' || ! hash_equals(self::signature($id), $signature)) {
            return null;
        }

        return Execution::query()->find($id);
    }

    protected static function signature(string $executionId): string
    {
        return hash_hmac('sha256', 'run-chain|'.$executionId, (string) config('app.key'));
    }
}
