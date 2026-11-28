<?php

namespace Tests\Feature\Engine;

use App\Engine\Masking\MaskPath;
use App\Engine\Masking\MaskRules;
use App\Engine\Redactor;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RedactorTest extends TestCase
{
    private const M = Redactor::MASK;

    public function test_values_under_secret_names_are_masked_at_any_depth_ignoring_case_dashes_and_underscores(): void
    {
        [$masked, $values] = (new Redactor)->split([
            'headers' => ['authorization' => 'Bearer abc', 'Set-Cookie' => ['a=1', 'b=2'], 'Content-Type' => 'application/json'],
            'body' => ['data' => ['user' => ['apiKey' => 'k-1', 'API_KEY' => 'k-2', 'refresh_token' => 'r-1', 'name' => 'Ada']], 'Password' => 'hunter2'],
            'list' => [['name' => 'X-Api-Key', 'value' => 'from-a-row']],
        ]);

        $this->assertSame([
            'headers' => ['authorization' => self::M, 'Set-Cookie' => [self::M, self::M], 'Content-Type' => 'application/json'],
            'body' => ['data' => ['user' => ['apiKey' => self::M, 'API_KEY' => self::M, 'refresh_token' => self::M, 'name' => 'Ada']], 'Password' => self::M],
            'list' => [['name' => 'X-Api-Key', 'value' => self::M]],
        ], $masked);
        $this->assertContains(['path' => ['body', 'data', 'user', 'refresh_token'], 'value' => 'r-1'], $values);
        $this->assertContains(['path' => ['headers', 'Set-Cookie', 1], 'value' => 'b=2'], $values);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function secretLookingValues(): array
    {
        return [
            'a JWT' => ['token eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiJhZG1pbiJ9.c2lnbmF0dXJl expired', 'token '.self::M.' expired'],
            'a bearer token' => ['Bearer abcdefgh12345678', 'Bearer '.self::M],
            'basic credentials' => ['Basic '.base64_encode('ada:hunter2'), 'Basic '.self::M],
            'a GitHub token' => ['use ghp_aBcDeFgHiJkLmNoPqRsTuVwXyZ012345', 'use '.self::M],
            'an sk- key' => ['sk-proj-abcdefghijklmnop1234', self::M],
            'a Slack token' => ['xoxb-1234567890-abcdefghij', self::M],
            'an AWS key id' => ['AKIAIOSFODNN7EXAMPLE', self::M],
            'credentials in a URL' => ['https://ada:hunter2@db.example.com/x', 'https://ada:'.self::M.'@db.example.com/x'],
            'an access token in a query' => ['https://api.example.com/x?access_token=abc123&page=2', 'https://api.example.com/x?access_token='.self::M.'&page=2'],
            'an AWS signature' => ['https://s3.example.com/f?X-Amz-Signature=deadbeef&X-Amz-Expires=60', 'https://s3.example.com/f?X-Amz-Signature='.self::M.'&X-Amz-Expires=60'],
            'a secret-named query parameter' => ['https://api.example.com/x?api_key=abc123', 'https://api.example.com/x?api_key='.self::M],
        ];
    }

    #[DataProvider('secretLookingValues')]
    public function test_values_that_look_like_secrets_are_masked_wherever_they_are(string $value, string $expected): void
    {
        [$masked, $values] = (new Redactor)->split(['note' => $value]);

        $this->assertSame(['note' => $expected], $masked);
        $this->assertSame([['path' => ['note'], 'value' => $value]], $values);
    }

    public function test_ordinary_text_is_left_alone(): void
    {
        $text = ['note' => 'Basic plan, bearer of good news, sk-1, https://example.com/?page=2', 'count' => 12, 'token_count' => 40];

        $this->assertSame($text, (new Redactor)->mask($text));
    }

    public function test_a_value_masked_by_name_is_masked_wherever_else_it_appears(): void
    {
        $masked = (new Redactor)->mask(['token' => 'opaque-1234', 'url' => 'https://x.test/?t=opaque-1234', 'log' => 'sent opaque-1234']);

        $this->assertSame(['token' => self::M, 'url' => 'https://x.test/?t='.self::M, 'log' => 'sent '.self::M], $masked);
    }

    public function test_the_organizations_names_are_masked_with_a_star_for_any_start_or_end(): void
    {
        $redactor = new Redactor([], ['X-Vault-Token', '*_license', 'tenant*']);

        $this->assertSame(
            ['x_vault_token' => self::M, 'pro_license' => self::M, 'TenantKey' => self::M, 'license' => 'free'],
            $redactor->mask(['x_vault_token' => 'v-1', 'pro_license' => 'l-1', 'TenantKey' => 't-1', 'license' => 'free']),
        );
        $this->assertSame(['organization', '*_license'], $redactor->nameMatch('ProLicense'));
    }

    public function test_always_mask_paths_mask_a_place_and_everything_in_it(): void
    {
        $rules = new MaskRules([MaskPath::parse('data[*].license'), MaskPath::parse('meta')]);

        $this->assertSame(
            ['data' => [['license' => self::M, 'id' => 1], ['license' => self::M, 'id' => 2]], 'meta' => ['a' => self::M, 'b' => [self::M]]],
            (new Redactor)->mask(['data' => [['license' => 'L-1', 'id' => 1], ['license' => 'L-2', 'id' => 2]], 'meta' => ['a' => 'x', 'b' => [7]]], $rules),
        );
    }

    public function test_never_mask_turns_off_a_name_match_at_one_path_but_not_a_value_pattern_or_a_known_secret(): void
    {
        $rules = new MaskRules(never: [MaskPath::parse('body.next_page_token'), MaskPath::parse('body.items[*].token'), MaskPath::parse('body.session_token'), MaskPath::parse('body.copy_token')]);
        $redactor = new Redactor(['known-secret-value']);

        $masked = $redactor->mask(['body' => [
            'next_page_token' => 'page-2',
            'other_token' => 'still-masked',
            'items' => [['token' => 'item-1'], ['token' => 'item-2']],
            'session_token' => 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiJhZG1pbiJ9.c2lnbmF0dXJl',
            'copy_token' => 'known-secret-value',
        ]], $rules);

        $this->assertSame([
            'next_page_token' => 'page-2',
            'other_token' => self::M,
            'items' => [['token' => 'item-1'], ['token' => 'item-2']],
            'session_token' => self::M,
            'copy_token' => self::M,
        ], $masked['body']);
    }

    public function test_paths_parse_and_format_keys_with_dots_and_list_positions(): void
    {
        $this->assertSame(['body', 'a.b', 3, null, 'Set-Cookie'], MaskPath::parse('body["a.b"][3][*].Set-Cookie')->segments);
        $this->assertSame('body["a.b"][3].Set-Cookie', MaskPath::format(['body', 'a.b', 3, 'Set-Cookie']));
        $this->assertSame('body.items[*].token', MaskPath::wildcard(['body', 'items', 4, 'token']));

        $this->expectException(\InvalidArgumentException::class);
        MaskPath::parse('body..token');
    }

    public function test_explain_says_why_each_value_of_a_masked_output_was_masked(): void
    {
        $rules = new MaskRules([MaskPath::parse('body.license')], [MaskPath::parse('body.next_page_token')]);
        $redactor = new Redactor([], ['X-Tenant-Key']);

        $reasons = $redactor->explain([
            'headers' => ['Set-Cookie' => [self::M], 'X-Tenant-Key' => self::M],
            'body' => ['token' => self::M, 'license' => self::M, 'next_page_token' => 'p2', 'note' => 'Bearer '.self::M],
        ], $rules);

        $this->assertSame([
            ['headers.Set-Cookie', 'header', 'Set-Cookie', false],
            ['headers.X-Tenant-Key', 'organization', 'X-Tenant-Key', false],
            ['body.token', 'field', 'token', false],
            ['body.license', 'always', null, false],
            ['body.next_page_token', 'field', '*_token', true],
            ['body.note', 'value', null, false],
        ], array_map(fn (array $reason) => [MaskPath::format($reason['path']), $reason['reason'], $reason['detail'], $reason['never']], $reasons));
    }
}
