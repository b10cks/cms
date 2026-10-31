<?php

namespace App\Services\Team;

/** Reads TXT records. A class of its own so tests can swap DNS out. */
class DnsTxtLookup
{
    /**
     * @return array<int, string>
     */
    public function records(string $domain): array
    {
        $records = @dns_get_record($domain, DNS_TXT);

        if (! is_array($records)) {
            return [];
        }

        // Long TXT values arrive split into 255-byte chunks under `entries`.
        return array_values(array_map(
            fn (array $record) => isset($record['entries']) ? implode('', $record['entries']) : (string) ($record['txt'] ?? ''),
            $records,
        ));
    }
}
