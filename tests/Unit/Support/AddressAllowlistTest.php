<?php

namespace Tests\Unit\Support;

use App\Support\AddressAllowlist;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

class AddressAllowlistTest extends TestCase
{
    #[TestWith(['10.0.0.5'])]
    #[TestWith(['fd00::1'])]
    #[TestWith(['10.0.0.0/8'])]
    #[TestWith(['fd00::/8'])]
    #[TestWith(['gitlab.corp.internal'])]
    #[TestWith(['*.corp.internal'])]
    #[TestWith(['localhost'])]
    public function test_accepts_valid_entries(string $entry): void
    {
        $this->assertTrue(AddressAllowlist::isValidEntry($entry));
    }

    #[TestWith(['10.0.0.0/33'])]
    #[TestWith(['10.0.0.0/abc'])]
    #[TestWith(['999.1.1.1/8'])]
    #[TestWith(['http://example.com'])]
    #[TestWith(['bad_host!'])]
    #[TestWith(['*'])]
    public function test_rejects_invalid_entries(string $entry): void
    {
        $this->assertFalse(AddressAllowlist::isValidEntry($entry));
    }

    public function test_parses_lines_and_commas_into_unique_lowercase_entries(): void
    {
        $this->assertSame(
            ['10.0.0.0/8', 'gitlab.corp', '192.168.1.1'],
            AddressAllowlist::parse("10.0.0.0/8\n  GitLab.corp, 192.168.1.1\n\n10.0.0.0/8"),
        );
    }

    /**
     * @return array<string, array{list<string>, string, string|null, bool}>
     */
    public static function requests(): array
    {
        return [
            'ip inside cidr' => [['10.0.0.0/8'], 'svc', '10.20.30.40', true],
            'ip outside cidr' => [['10.0.0.0/8'], 'svc', '11.0.0.1', false],
            'cidr with partial byte prefix' => [['192.168.0.0/22'], 'svc', '192.168.3.255', true],
            'just past partial byte prefix' => [['192.168.0.0/22'], 'svc', '192.168.4.0', false],
            'exact ip' => [['127.0.0.1'], 'localhost', '127.0.0.1', true],
            'ipv6 cidr' => [['fd00::/8'], 'svc', 'fd12:3456::1', true],
            'ipv4 against ipv6 cidr' => [['fd00::/8'], 'svc', '10.0.0.1', false],
            'exact hostname' => [['gitlab.corp.internal'], 'GitLab.corp.internal.', '10.1.1.1', true],
            'wildcard subdomain' => [['*.corp.internal'], 'jira.corp.internal', null, true],
            'wildcard does not match apex' => [['*.corp.internal'], 'corp.internal', null, false],
            'wildcard does not match lookalike' => [['*.corp.internal'], 'evilcorp.internal', null, false],
            'empty allowlist' => [[], 'localhost', '127.0.0.1', false],
        ];
    }

    /**
     * @param  list<string>  $entries
     */
    #[DataProvider('requests')]
    public function test_decides_whether_a_request_is_allowed(array $entries, string $host, ?string $ip, bool $expected): void
    {
        $this->assertSame($expected, (new AddressAllowlist($entries))->allows($host, $ip));
    }
}
