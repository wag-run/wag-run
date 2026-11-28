<?php

namespace App\Engine;

use App\Engine\Masking\MaskPath;
use App\Engine\Masking\MaskRules;
use App\Models\Credential;
use App\Models\Execution;
use App\Models\ExecutionMaskedValue;
use App\Models\Organization;
use App\Models\Workspace;

/**
 * Masks secret values before anything is written to a run's rows, so run
 * logs, the run page, the API and MCP never hold them. A value is secret
 * when:
 *
 *  - it sits under a secret-sounding name, at any depth: a header such as
 *    Authorization, a field such as token or client_secret, or a name the
 *    organization added (matched ignoring case, dashes, and underscores),
 *    including inside text that holds JSON, such as a request body;
 *  - it looks like one: a JWT, "Bearer …", a known key prefix, or
 *    credentials in a URL;
 *  - it is one the run already knows: a workspace secret, a credential's
 *    secret values, or anything masked earlier in the same run
 *    (forExecution); or
 *  - the node says so, with an "Always mask" path (MaskRules).
 *
 * mask() returns the masked copy only, for what is only ever shown (a
 * step's input, an error). split() also returns the real values it
 * replaced, with their paths, so the engine can keep them while the run is
 * in flight and later steps can still use them (see MaskedValues).
 */
class Redactor
{
    public const MASK = '********';

    /**
     * Values shorter than this are not masked by value, so a secret like
     * "1" does not blank out every digit in a log.
     */
    public const MIN_LENGTH = 4;

    /**
     * Header names whose values are always masked.
     */
    public const SENSITIVE_HEADERS = ['Authorization', 'Proxy-Authorization', 'Cookie', 'Set-Cookie', 'X-Api-Key', 'X-Auth-Token', 'X-CSRF-Token'];

    /**
     * Field names whose values are always masked. A leading * stands for
     * one or more characters, so *_token matches refresh_token.
     */
    public const SENSITIVE_FIELDS = ['token', 'password', 'passwd', 'secret', 'jwt', 'api_key', 'private_key', 'client_secret', 'session_id', '*_token', '*_secret', '*_password'];

    /**
     * What values that look like secrets are recognised by, as shown on the
     * organization's settings.
     */
    public const VALUE_PATTERNS = ['JWT', 'Bearer ...', 'Basic ...', 'ghp_...', 'sk-...', 'xoxb-...', 'AKIA...', 'user:pass@host', '?access_token=', 'X-Amz-Signature'];

    /**
     * Query parameters whose values are masked wherever a URL appears,
     * besides those with a secret-sounding name.
     */
    protected const SENSITIVE_QUERY_PARAMETERS = ['accesstoken', 'xamzsignature', 'xamzcredential', 'xamzsecuritytoken'];

    /**
     * Values that look like secrets. Group 1 is kept, group 2 is masked.
     */
    protected const PATTERNS = [
        '/()(\beyJ[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]*)/',
        '/(\bBearer\s+)([A-Za-z0-9\-._~+\/]{8,}=*)/',
        '/()(\b(?:gh[pousr]_[A-Za-z0-9]{20,}|github_pat_[A-Za-z0-9_]{20,}))/',
        '/()(\bsk-[A-Za-z0-9_-]{16,})/',
        '/()(\bxox[abp]-[A-Za-z0-9-]{10,})/',
        '/()(\bAKIA[0-9A-Z]{16}\b)/',
        '/(\b[a-z][a-z0-9+.-]*:\/\/[^\/\s:@"\']+:)([^\/\s@"\']+)(?=@)/i',
    ];

    /**
     * Normalized built-in names, exact or as a suffix, with why each
     * matches, filled on first use.
     *
     * @var array{exact: array<string, array{0: 'header'|'field', 1: string}>, suffix: array<string, array{0: 'field', 1: string}>}|null
     */
    protected static ?array $builtIn = null;

    /**
     * The organization's names, normalized, with how each matches.
     *
     * @var list<array{name: string, text: string, prefix: bool, suffix: bool}>
     */
    protected array $names = [];

    /**
     * @param  list<string>  $values  known secret values, longest first
     * @param  list<string>  $names  the organization's extra names to mask, as entered
     */
    public function __construct(protected array $values = [], array $names = [])
    {
        foreach ($names as $name) {
            $normalized = self::normalize($name);
            $text = trim($normalized, '*');

            if ($text !== '') {
                $this->names[] = ['name' => $name, 'text' => $text, 'prefix' => str_ends_with($normalized, '*'), 'suffix' => str_starts_with($normalized, '*')];
            }
        }
    }

    /**
     * A workspace's secrets and credentials, and the organization's names.
     */
    public static function forWorkspace(Workspace $workspace): self
    {
        return new self(self::known(self::workspaceValues($workspace)), self::organizationNames());
    }

    /**
     * What forWorkspace() knows, plus every value masked so far in this
     * run, so a token a step received is masked wherever it shows up
     * again: in a URL, a message body, or an error.
     */
    public static function forExecution(Execution $execution): self
    {
        $values = self::workspaceValues($execution->workspace);

        foreach (ExecutionMaskedValue::query()->where('execution_id', $execution->id)->get() as $row) {
            foreach ($row->values ?? [] as $entry) {
                array_push($values, ...self::secretsIn($entry['value'] ?? null));
            }
        }

        return new self(self::known($values), self::organizationNames());
    }

    /**
     * The masked copy of $value. Use it for what is only shown, never read
     * back by a later step.
     */
    public function mask(mixed $value, ?MaskRules $rules = null): mixed
    {
        return $this->split($value, $rules)[0];
    }

    /**
     * The masked copy of $value, and the real values it replaced: each
     * with its path, a list of keys and positions from $value's top, such
     * as ["body", "items", 3, "secret"]. The values found are added to
     * what this redactor knows, so they are masked in what it sees next.
     *
     * @return array{0: mixed, 1: list<array{path: list<string|int>, value: mixed}>}
     */
    public function split(mixed $value, ?MaskRules $rules = null): array
    {
        $found = [];
        $masked = $this->byName($value, [], null, $rules ?? MaskRules::none(), $found);

        $this->learn(array_map(fn (array $entry) => $entry['value'], $found));

        $masked = $this->byValue($masked, [], $found);

        return [$masked, array_map(fn (array $entry) => ['path' => $entry['path'], 'value' => $entry['value']], $found)];
    }

    /**
     * Why each value in $value would be masked, without masking it: for a
     * name, at the key that matched; for an "Always mask" path, at each
     * value. A value masked by an earlier run's rules (it holds MASK) that
     * the names don't explain is reported as looking like a secret.
     *
     * @return list<array{path: list<string|int>, reason: string, detail: string|null, never: bool}>
     */
    public function explain(mixed $value, ?MaskRules $rules = null): array
    {
        $reasons = [];
        $this->explainIn($value, [], null, $rules ?? MaskRules::none(), $reasons);

        return array_values($reasons);
    }

    /**
     * Why a key's value is secret by its name, or null when it isn't:
     * ['header', 'Authorization'], ['field', 'token'], or
     * ['organization', 'X-Vault-Token'].
     *
     * @return array{0: 'header'|'field'|'organization', 1: string}|null
     */
    public function nameMatch(string|int $key): ?array
    {
        if (is_int($key)) {
            return null;
        }

        $normalized = self::normalize($key);
        $builtIn = self::builtIn();

        if (isset($builtIn['exact'][$normalized])) {
            return $builtIn['exact'][$normalized];
        }

        foreach ($builtIn['suffix'] as $suffix => $match) {
            if (strlen($normalized) > strlen($suffix) && str_ends_with($normalized, $suffix)) {
                return $match;
            }
        }

        foreach ($this->names as $name) {
            $longer = strlen($normalized) > strlen($name['text']);
            $matches = match (true) {
                $name['prefix'] && $name['suffix'] => $longer && str_contains($normalized, $name['text']),
                $name['prefix'] => $longer && str_starts_with($normalized, $name['text']),
                $name['suffix'] => $longer && str_ends_with($normalized, $name['text']),
                default => $normalized === $name['text'],
            };

            if ($matches) {
                return ['organization', $name['name']];
            }
        }

        return null;
    }

    /**
     * A name as matching compares it: lower case, without dashes and
     * underscores, so "X-Api-Key", "x_api_key" and "xApiKey" are the same.
     */
    public static function normalize(string $name): string
    {
        return strtolower(str_replace(['-', '_', ' '], '', $name));
    }

    /**
     * Whether an organization name is one the settings accept: letters,
     * digits, dashes, underscores and dots, with an optional * at the
     * start or end.
     */
    public static function isValidName(string $name): bool
    {
        return (bool) preg_match('/^\*?[A-Za-z0-9._-]+\*?$/', $name);
    }

    /**
     * The secrets in a value the engine kept: what in it looks like a
     * secret, or the whole value when nothing does (it was masked by name).
     *
     * @return list<string>
     */
    protected static function secretsIn(mixed $value): array
    {
        if (! is_string($value)) {
            return [];
        }

        $secrets = [];

        foreach (self::PATTERNS as $pattern) {
            preg_match_all($pattern, $value, $matches);
            array_push($secrets, ...$matches[2]);
        }

        return $secrets === [] ? [$value] : $secrets;
    }

    /**
     * Mask what sits under a secret name or an "Always mask" path, and note
     * the real values.
     *
     * @param  list<string|int>  $path
     * @param  list<string|int>|null  $matchedAt  where a secret name above this value matched
     * @param  list<array{path: list<string|int>, value: mixed}>  $found
     */
    protected function byName(mixed $value, array $path, ?array $matchedAt, MaskRules $rules, array &$found): mixed
    {
        if (is_array($value)) {
            $pairName = $this->pairName($value);

            foreach ($value as $key => $item) {
                $childPath = [...$path, $key];
                $childMatch = $matchedAt ?? ($this->nameMatch($key) !== null ? $childPath : null);

                if ($childMatch === null && $pairName !== null && $key === 'value') {
                    $childMatch = $childPath;
                }

                $value[$key] = $this->byName($item, $childPath, $childMatch, $rules, $found);
            }

            return $value;
        }

        if ((is_int($value) || is_float($value)) && $rules->alwaysMasks($path)) {
            $found[] = ['path' => $path, 'value' => $value];

            return self::MASK;
        }

        if (! is_string($value) || $value === '' || $value === self::MASK) {
            return $value;
        }

        $byName = $matchedAt !== null && ! $rules->cancels($matchedAt, $path);

        if ($byName || $rules->alwaysMasks($path)) {
            $found[] = ['path' => $path, 'value' => $value];

            return self::MASK;
        }

        return $value;
    }

    /**
     * Mask what looks like a secret or is a known one, in every text value
     * left, and note the real values.
     *
     * @param  list<string|int>  $path
     * @param  list<array{path: list<string|int>, value: mixed}>  $found
     */
    protected function byValue(mixed $value, array $path, array &$found): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->byValue($item, [...$path, $key], $found);
            }

            return $value;
        }

        if (! is_string($value) || $value === self::MASK) {
            return $value;
        }

        $masked = $this->maskText($value);

        if ($masked !== $value) {
            $found[] = ['path' => $path, 'value' => $value];
        }

        return $masked;
    }

    /**
     * Mask secrets inside a piece of text: known values first, longest
     * first, then anything that looks like a secret.
     */
    protected function maskText(string $text): string
    {
        $this->learnFromJson($text);

        if ($this->values !== []) {
            $text = str_replace($this->values, self::MASK, $text);
        }

        foreach (self::PATTERNS as $pattern) {
            $text = (string) preg_replace_callback($pattern, function (array $match): string {
                $this->learn([$match[2]]);

                return $match[1].self::MASK;
            }, $text);
        }

        $text = (string) preg_replace_callback('/(\bBasic\s+)([A-Za-z0-9+\/]{6,}={0,2})/', function (array $match): string {
            $decoded = base64_decode($match[2], true);

            return $decoded !== false && str_contains($decoded, ':') ? $match[1].self::MASK : $match[0];
        }, $text);

        return (string) preg_replace_callback('/([?&]([^=&#\s"\']+)=)([^&#\s"\']+)/', function (array $match): string {
            $name = rawurldecode($match[2]);

            return in_array(self::normalize($name), self::SENSITIVE_QUERY_PARAMETERS, true) || $this->nameMatch($name) !== null
                ? $match[1].self::MASK
                : $match[0];
        }, $text);
    }

    /**
     * Text that holds JSON, such as a request body as a step was given
     * it, is read for values under secret names, which are then masked
     * inside the text.
     */
    protected function learnFromJson(string $text): void
    {
        $trimmed = ltrim($text);

        if ($trimmed === '' || ! in_array($trimmed[0], ['{', '['], true)) {
            return;
        }

        $decoded = json_decode($text, true);

        if (! is_array($decoded)) {
            return;
        }

        $found = [];
        $this->byName($decoded, [], null, MaskRules::none(), $found);
        $this->learn(array_map(fn (array $entry) => $entry['value'], $found));
    }

    /**
     * @param  list<string|int>  $path
     * @param  list<string|int>|null  $matchedAt
     * @param  array<string, array{path: list<string|int>, reason: string, detail: string|null, never: bool}>  $reasons
     */
    protected function explainIn(mixed $value, array $path, ?array $matchedAt, MaskRules $rules, array &$reasons): void
    {
        if (is_array($value)) {
            $pairName = $this->pairName($value);

            foreach ($value as $key => $item) {
                $childPath = [...$path, $key];
                $match = $matchedAt === null ? $this->nameMatch($key) : null;

                if ($match === null && $matchedAt === null && $pairName !== null && $key === 'value') {
                    $match = $pairName;
                }

                if ($match !== null && $this->holdsText($item)) {
                    $reasons[MaskPath::format($childPath)] = [
                        'path' => $childPath,
                        'reason' => $match[0],
                        'detail' => $match[1],
                        'never' => $rules->cancels($childPath, $childPath),
                    ];
                }

                $this->explainIn($item, $childPath, $matchedAt ?? ($match !== null ? $childPath : null), $rules, $reasons);
            }

            return;
        }

        if (! is_string($value) || $value === '' || $matchedAt !== null) {
            return;
        }

        $key = MaskPath::format($path);

        if ($rules->alwaysMasks($path)) {
            $reasons[$key] = ['path' => $path, 'reason' => 'always', 'detail' => null, 'never' => false];
        } elseif (str_contains($value, self::MASK)) {
            $reasons[$key] = ['path' => $path, 'reason' => 'value', 'detail' => null, 'never' => false];
        }
    }

    /**
     * For a {name, value} or {key, value} row, as a list of headers can be
     * written, why the name is secret.
     *
     * @param  array<array-key, mixed>  $row
     * @return array{0: 'header'|'field'|'organization', 1: string}|null
     */
    protected function pairName(array $row): ?array
    {
        if (! array_key_exists('value', $row) || count($row) > 3) {
            return null;
        }

        $name = $row['name'] ?? $row['key'] ?? null;

        return is_string($name) ? $this->nameMatch($name) : null;
    }

    protected function holdsText(mixed $value): bool
    {
        if (is_array($value)) {
            return array_any($value, fn (mixed $item) => $this->holdsText($item));
        }

        return is_string($value) && $value !== '';
    }

    /**
     * Add values to the ones this redactor masks wherever they appear.
     *
     * @param  list<mixed>  $values
     */
    protected function learn(array $values): void
    {
        $values = self::known([...$this->values, ...$values]);

        if ($values !== $this->values) {
            $this->values = $values;
        }
    }

    /**
     * @param  list<mixed>  $values
     * @return list<string> those long enough to mask, longest first
     */
    protected static function known(array $values): array
    {
        $values = array_values(array_unique(array_filter(
            array_map(fn (mixed $value) => is_scalar($value) ? (string) $value : '', $values),
            fn (string $value) => mb_strlen($value) >= self::MIN_LENGTH && $value !== self::MASK,
        )));

        usort($values, fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));

        return $values;
    }

    /**
     * @return list<mixed>
     */
    protected static function workspaceValues(Workspace $workspace): array
    {
        $values = $workspace->secrets()->get()->pluck('value')->all();

        foreach ($workspace->credentials()->get() as $credential) {
            /** @var Credential $credential */
            array_push($values, ...$credential->secretValues());
        }

        return array_values($values);
    }

    /**
     * @return list<string>
     */
    protected static function organizationNames(): array
    {
        return array_values(array_filter(Organization::current()->sensitive_keys ?? [], 'is_string'));
    }

    /**
     * @return array{exact: array<string, array{0: 'header'|'field', 1: string}>, suffix: array<string, array{0: 'field', 1: string}>}
     */
    protected static function builtIn(): array
    {
        if (self::$builtIn !== null) {
            return self::$builtIn;
        }

        $exact = [];
        $suffix = [];

        foreach (self::SENSITIVE_HEADERS as $header) {
            $exact[self::normalize($header)] = ['header', $header];
        }

        foreach (self::SENSITIVE_FIELDS as $field) {
            if (str_starts_with($field, '*')) {
                $suffix[self::normalize(ltrim($field, '*'))] = ['field', $field];
            } else {
                $exact[self::normalize($field)] ??= ['field', $field];
            }
        }

        return self::$builtIn = ['exact' => $exact, 'suffix' => $suffix];
    }
}
