<?php

namespace App\Support;

/**
 * Reads a DNS name's TXT records, for verifying that an organization controls
 * an email domain. Kept in its own class so tests can swap it out.
 */
class DnsTxtLookup
{
    /**
     * @return list<string>
     */
    public function records(string $name): array
    {
        $records = @dns_get_record($name, DNS_TXT);

        if (! is_array($records)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (array $record): ?string => isset($record['txt']) ? (string) $record['txt'] : null,
            $records,
        )));
    }
}
