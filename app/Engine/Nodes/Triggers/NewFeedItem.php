<?php

namespace App\Engine\Nodes\Triggers;

use App\Credentials\HttpAuthenticator;
use App\Credentials\OAuthCredentialType;
use App\Engine\Definitions\Field;
use App\Engine\Definitions\Output;
use App\Engine\EventTrigger;
use App\Engine\Node;
use App\Engine\NodeContext;
use App\Engine\NodeResult;
use App\Engine\Templates\TemplateEngine;
use App\Enums\NodeKind;
use App\Models\Credential;
use App\Models\Workspace;
use App\Services\OAuthConnections;
use App\Support\AddressAllowlist;
use App\Support\NetworkGuard;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use SimpleXMLElement;
use Throwable;

/**
 * Starts the workflow for each new item in an RSS or Atom feed, or in a list
 * a JSON API returns, for sources that send no webhooks. It fetches the
 * address every few minutes and remembers the ids it has seen, so the first
 * check only notes what is already there.
 *
 * The address is checked like an HTTP call's, on every redirect hop, and the
 * connection is pinned to the address that was checked. A redirect to another
 * host is followed without the authentication and headers, so they can't leak.
 */
class NewFeedItem extends Node implements EventTrigger
{
    /**
     * How many item ids a check remembers, at the least.
     */
    protected const MEMORY = 300;

    protected const MAX_REDIRECTS = 5;

    protected const MAX_BYTES = 5_242_880;

    protected const MAX_SUMMARY = 10_000;

    public function __construct(protected NetworkGuard $guard, protected TemplateEngine $templates) {}

    public static function type(): string
    {
        return 'core.new_feed_item';
    }

    public function label(): string
    {
        return __('nodes.feed.new_item_in_a_feed_or_list');
    }

    public function description(): string
    {
        return __('nodes.feed.new_item_description');
    }

    public function kind(): NodeKind
    {
        return NodeKind::Trigger;
    }

    public function icon(): string
    {
        return 'rss';
    }

    public function paletteGroup(): array
    {
        return [__('common.triggers'), __('common.integrations')];
    }

    public function inputs(): array
    {
        return [
            new Field('url', __('nodes.http_request.url'), Field::TEXT, required: true, templated: true, description: __('nodes.feed.url_help')),
            new Field('format', __('nodes.feed.format'), Field::SELECT, required: true, default: 'feed', options: [
                'feed' => __('nodes.feed.rss_or_atom_feed'),
                'json' => __('nodes.http_request.json'),
            ]),
            new Field('credential', __('common.authentication'), Field::CREDENTIAL, credentialTypes: ['http_basic', 'api_token']),
            new Field('headers', __('common.headers'), Field::KEY_VALUE, templated: true, description: __('nodes.feed.headers_help')),
            new Field('list_path', __('nodes.feed.list'), Field::TEXT, description: __('nodes.feed.list_help'), visibleWhen: ['format' => 'json']),
            new Field('id_path', __('nodes.feed.item_id'), Field::TEXT, required: true, description: __('nodes.feed.item_id_help'), visibleWhen: ['format' => 'json']),
            new Field('newest_first', __('nodes.feed.newest_first'), Field::BOOLEAN, default: true, description: __('nodes.feed.newest_first_help'), visibleWhen: ['format' => 'json']),
            new Field('poll_minutes', __('nodes.investbrain.check_every_minutes'), Field::NUMBER, default: 15),
        ];
    }

    public function outputs(array $config = []): array
    {
        if (($config['format'] ?? 'feed') === 'json') {
            return [
                new Output('id', __('nodes.feed.id'), 'string'),
                new Output('item', __('nodes.feed.item'), 'object', __('nodes.feed.item_help')),
            ];
        }

        return [
            new Output('id', __('nodes.feed.id'), 'string'),
            new Output('title', __('nodes.feed.title'), 'string'),
            new Output('link', __('nodes.feed.link'), 'string'),
            new Output('summary', __('nodes.feed.summary'), 'string'),
            new Output('author', __('nodes.feed.author'), 'string'),
            new Output('published_at', __('nodes.feed.published_at'), 'string'),
        ];
    }

    public function configProblems(array $config): array
    {
        $problems = [];

        if (($config['format'] ?? 'feed') === 'json' && trim((string) ($config['id_path'] ?? '')) === '') {
            $problems[] = __('nodes.feed.item_id_is_required');
        }

        $templates = [(string) ($config['url'] ?? ''), ...array_map(fn (mixed $value) => (string) $value, array_values((array) ($config['headers'] ?? [])))];

        foreach ($templates as $template) {
            if (preg_match_all('/\{\{(.*?)\}\}/s', $template, $matches) && array_any($matches[1], fn (string $expression) => preg_match('/(?<![.\w])(trigger|nodes|execution|item|loop|error)\b/', $expression) === 1)) {
                $problems[] = __('nodes.feed.only_vars_and_secrets');

                break;
            }
        }

        return $problems;
    }

    /**
     * Fetch the feed or list now and say what a check would find: how many
     * items it lists, how many have no ID and would be skipped, and the
     * newest few as a run would receive them, for the editor's Check now.
     *
     * @param  array<string, mixed>  $config
     * @return array{total: int, skipped: int, events: list<array<string, mixed>>, warning: string|null}
     *
     * @throws RuntimeException when the source can't be read with these settings
     */
    public function preview(array $config, Workspace $workspace, int $limit = 3): array
    {
        ['items' => $items, 'total' => $total] = $this->items($this->fetch($config, $workspace), $config);
        $skipped = $total - count($items);

        $warning = $this->previewWarning($config, $total, count($items));

        return [
            'total' => $total,
            'skipped' => $skipped,
            'events' => array_map(fn (array $item) => $item['event'], array_slice(array_reverse($items), 0, $limit)),
            'warning' => $warning,
        ];
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
        return max(1, (int) ($config['poll_minutes'] ?? 15));
    }

    public function poll(array $config, Workspace $workspace, ?array $state): array
    {
        $items = $this->items($this->fetch($config, $workspace), $config)['items'];

        $ids = array_map(fn (array $item) => (string) $item['id'], $items);
        $seen = $state === null ? $ids : (array) ($state['seen'] ?? []);
        $new = array_values(array_filter($items, fn (array $item) => ! in_array((string) $item['id'], $seen, true)));

        return [
            'events' => array_map(fn (array $item) => $item['event'], $new),
            'state' => ['seen' => array_slice(array_values(array_unique([...$ids, ...$seen])), 0, max(self::MEMORY, count($ids)))],
        ];
    }

    public function subscribe(array $config, Workspace $workspace, string $url, string $secret): array
    {
        throw new RuntimeException(__('nodes.feed.no_webhooks'));
    }

    public function unsubscribe(array $config, Workspace $workspace, array $subscription): void {}

    public function receive(array $config, Request $request, string $secret): ?array
    {
        return null;
    }

    /**
     * What Check now should point out about what a check found, if anything.
     *
     * @param  array<string, mixed>  $config
     */
    protected function previewWarning(array $config, int $total, int $kept): ?string
    {
        if ($total === 0) {
            return __('nodes.feed.nothing_listed');
        }

        if ($kept === 0 && ($config['format'] ?? 'feed') === 'json') {
            return __('nodes.feed.no_item_has_an_id', ['total' => $total, 'path' => trim((string) ($config['id_path'] ?? ''))]);
        }

        if ($kept === 0) {
            return __('nodes.feed.no_entry_has_an_id', ['total' => $total]);
        }

        if ($kept < $total) {
            return trans_choice('nodes.feed.some_items_skipped', $total - $kept, ['skipped' => $total - $kept, 'total' => $total]);
        }

        return null;
    }

    /**
     * The items in a body, oldest first, and how many it listed, counting
     * those without an ID that are skipped.
     *
     * @param  array<string, mixed>  $config
     * @return array{items: list<array{id: string, event: array<string, mixed>}>, total: int}
     *
     * @throws RuntimeException when the body isn't what the format says
     */
    protected function items(string $body, array $config): array
    {
        return ($config['format'] ?? 'feed') === 'json' ? $this->jsonItems($body, $config) : $this->feedItems($body);
    }

    /**
     * The body of the feed or list, with the address and headers' placeholders
     * (workspace variables and secrets) filled in.
     *
     * The authentication and headers go only to the address's own host, and
     * never over plain HTTP when the address is HTTPS.
     *
     * @param  array<string, mixed>  $config
     *
     * @throws RuntimeException when the address isn't allowed or the source didn't answer
     */
    protected function fetch(array $config, Workspace $workspace): string
    {
        $data = [
            'vars' => $workspace->variables()->pluck('value', 'key')->all(),
            'secrets' => $workspace->secrets()->get()->pluck('value', 'key')->all(),
        ];
        $url = trim((string) $this->templates->render((string) ($config['url'] ?? ''), $data));
        $headers = [];

        foreach ((array) ($config['headers'] ?? []) as $name => $value) {
            if ($name !== '') {
                $headers[$name] = (string) $this->templates->render((string) $value, $data);
            }
        }

        $allowlist = new AddressAllowlist($workspace->internal_allowlist ?? []);
        $origin = strtolower((string) parse_url($url, PHP_URL_HOST));
        $originIsSecure = strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https';

        try {
            for ($hop = 0; ; $hop++) {
                $target = $this->guard->check($url, $allowlist);
                $trusted = $target['host'] === $origin && (! $originIsSecure || strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https');
                $response = $this->request($target, $trusted ? $headers : [], $trusted ? $this->credential($workspace, $config['credential'] ?? null) : null)->get($url);

                if (! $response->redirect() || ! $response->header('Location')) {
                    break;
                }

                if ($hop >= self::MAX_REDIRECTS) {
                    throw new RuntimeException(__('nodes.http_request.too_many_redirects'));
                }

                $url = (string) UriResolver::resolve(new Uri($url), new Uri($response->header('Location')));
            }
        } catch (ConnectionException $e) {
            throw new RuntimeException(__('nodes.http_request.connection_failed_message', ['message' => $e->getMessage()]));
        } catch (RequestException $e) {
            throw new RuntimeException($e->getPrevious()?->getMessage() ?? $e->getMessage());
        }

        if (! $response->successful()) {
            throw new RuntimeException(__('nodes.feed.answered', ['status' => $response->status(), 'reason' => $response->reason()]));
        }

        return $response->body();
    }

    /**
     * @param  array{host: string, port: int, ip: string}  $target
     * @param  array<string, string>  $headers
     *
     * @throws RuntimeException when the authentication can't be applied
     */
    protected function request(array $target, array $headers, ?Credential $credential): PendingRequest
    {
        $ip = str_contains($target['ip'], ':') ? "[{$target['ip']}]" : $target['ip'];
        $limit = self::MAX_BYTES;

        $request = Http::timeout(30)
            ->withOptions([
                'allow_redirects' => false,
                'curl' => [CURLOPT_RESOLVE => ["{$target['host']}:{$target['port']}:{$ip}"]],
                'progress' => function (int $expected, int $received) use ($limit) {
                    if ($expected > $limit || $received > $limit) {
                        throw new RuntimeException(__('nodes.feed.too_large', ['limit' => round($limit / 1048576).' MB']));
                    }
                },
            ])
            ->withHeaders($headers);

        if ($credential) {
            $type = $credential->credentialType();

            if (! $type instanceof HttpAuthenticator) {
                throw new RuntimeException(__('nodes.http_request.credential_cannot_be_used_for_http', ['name' => $credential->description]));
            }

            $config = $type instanceof OAuthCredentialType
                ? [...$credential->config, 'access_token' => app(OAuthConnections::class)->accessToken($credential)]
                : $credential->config;

            $request = $type->authenticate($request, $config);
        }

        return $request;
    }

    /**
     * @throws RuntimeException when a credential is set but doesn't exist in the workspace
     */
    protected function credential(Workspace $workspace, mixed $id): ?Credential
    {
        if ($id === null || $id === '') {
            return null;
        }

        $credential = is_string($id) && Str::isUuid($id) ? $workspace->credentials()->whereKey($id)->first() : null;

        return $credential ?? throw new RuntimeException(__('nodes.feed.authentication_not_found'));
    }

    /**
     * The entries of an RSS 2.0, RSS 1.0, or Atom feed, as ids and events,
     * oldest first, and how many entries it has.
     *
     * @return array{items: list<array{id: string, event: array<string, mixed>}>, total: int}
     *
     * @throws RuntimeException when the body isn't a feed
     */
    protected function feedItems(string $body): array
    {
        $entries = $this->parseFeed($body);
        $items = [];

        foreach ($entries as $entry) {
            $link = $this->link($entry);
            $title = $this->text($entry, 'title');
            $id = $this->text($entry, 'guid', 'id') ?: $link;

            if ($id === '') {
                continue;
            }

            $items[] = ['id' => $id, 'event' => [
                'id' => $id,
                'title' => $title,
                'link' => $link,
                'summary' => $this->summary($entry),
                'author' => $this->author($entry),
                'published_at' => $this->date($this->text($entry, 'pubDate', 'published', 'updated', 'date')),
            ]];
        }

        return ['items' => array_reverse($items), 'total' => count($entries)];
    }

    /**
     * @return list<SimpleXMLElement>
     *
     * @throws RuntimeException when the body isn't a feed
     */
    protected function parseFeed(string $body): array
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $xml = preg_match('/<!(DOCTYPE|ENTITY)/i', $body) ? false : simplexml_load_string($body, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
            $entries = $xml ? $xml->xpath('//*[local-name()="item" or local-name()="entry"]') : false;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (! $xml || ! in_array(strtolower($xml->getName()), ['rss', 'feed', 'rdf'], true)) {
            throw new RuntimeException(__('nodes.feed.not_a_feed'));
        }

        return array_values($entries ?: []);
    }

    protected function child(SimpleXMLElement $parent, string ...$names): ?SimpleXMLElement
    {
        foreach ($names as $name) {
            $found = $parent->xpath('*[local-name()="'.$name.'"]');

            if ($found) {
                return $found[0];
            }
        }

        return null;
    }

    protected function text(SimpleXMLElement $parent, string ...$names): string
    {
        return trim((string) $this->child($parent, ...$names));
    }

    protected function link(SimpleXMLElement $entry): string
    {
        $links = $entry->xpath('*[local-name()="link"]') ?: [];

        foreach ($links as $link) {
            $rel = (string) $link['rel'];

            if (trim((string) $link['href']) !== '' && ($rel === '' || $rel === 'alternate')) {
                return trim((string) $link['href']);
            }
        }

        foreach ($links as $link) {
            if (trim((string) $link) !== '') {
                return trim((string) $link);
            }
        }

        return trim((string) ($links[0]['href'] ?? ''));
    }

    protected function author(SimpleXMLElement $entry): string
    {
        $author = $this->child($entry, 'author', 'creator');

        return $author === null ? '' : ($this->text($author, 'name') ?: trim((string) $author));
    }

    protected function summary(SimpleXMLElement $entry): string
    {
        $text = $this->text($entry, 'summary', 'description', 'content');
        $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5)));

        return Str::limit($text, self::MAX_SUMMARY, '');
    }

    protected function date(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toIso8601String();
        } catch (Throwable) {
            return $value;
        }
    }

    /**
     * The items of a JSON list, as ids and events, oldest first, and how
     * many items it has.
     *
     * @param  array<string, mixed>  $config
     * @return array{items: list<array{id: string, event: array<string, mixed>}>, total: int}
     *
     * @throws RuntimeException when the body isn't JSON, or holds no list where the settings say
     */
    protected function jsonItems(string $body, array $config): array
    {
        $decoded = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException(__('nodes.feed.not_json'));
        }

        $path = trim((string) ($config['list_path'] ?? ''));
        $list = $path === '' ? $decoded : data_get($decoded, $path);

        if (! is_array($list) || ! array_is_list($list)) {
            throw new RuntimeException($path === '' ? __('nodes.feed.not_a_list') : __('nodes.feed.no_list_at', ['path' => $path]));
        }

        $items = [];

        foreach ($list as $item) {
            $id = is_array($item) ? data_get($item, trim((string) $config['id_path'])) : null;

            if (is_scalar($id) && (string) $id !== '') {
                $items[] = ['id' => (string) $id, 'event' => ['id' => (string) $id, 'item' => $item]];
            }
        }

        return ['items' => ($config['newest_first'] ?? true) ? array_reverse($items) : $items, 'total' => count($list)];
    }
}
