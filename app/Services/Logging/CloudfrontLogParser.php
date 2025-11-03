<?php

namespace App\Services\Logging;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CloudfrontLogParser
{
    private const string TRAFFIC_PATTERN = '/\/ilum\/[^\/]+\/([a-z0-9]+)\//i';
    private const string API_TOKEN_PATTERN = '/token=([a-z0-9_]+)/i';

    public function parseLogLine(string $line): ?array
    {
        if (str_starts_with($line, '#') || str_starts_with($line, "\n")) {
            return null;
        }

        // Detect format: JSON starts with {, TSV does not
        if (str_starts_with(trim($line), '{')) {
            return $this->parseJsonFormat($line);
        }

        return $this->parseTsvFormat($line);
    }

    private function parseJsonFormat(string $line): ?array
    {
        try {
            $data = json_decode($line, true);

            if (!$data || json_last_error() !== JSON_ERROR_NONE) {
                return null;
            }

            // Map JSON fields to our standard format
            return [
                'date' => $data['date'] ?? null,
                'time' => $data['time'] ?? null,
                'edge_location' => $data['x-edge-location'] ?? null,
                'bytes_sent' => (int)($data['sc-bytes'] ?? 0),
                'ip_address' => $data['c-ip'] ?? null,
                'method' => $data['cs-method'] ?? null,
                'host' => $data['cs(Host)'] ?? null,
                'uri' => $data['cs-uri-stem'] ?? null,
                'status_code' => (int)($data['sc-status'] ?? 0),
                'referrer' => $data['cs(Referer)'] ?? null,
                'user_agent' => $data['cs(User-Agent)'] ?? null,
                'query_string' => $data['cs-uri-query'] ?? null,
                'cookie' => $data['cs(Cookie)'] ?? null,
                'edge_result_type' => $data['x-edge-result-type'] ?? null,
                'request_id' => $data['x-edge-request-id'] ?? null,
                'host_header' => $data['x-host-header'] ?? null,
                'protocol' => $data['cs-protocol'] ?? null,
                'bytes_received' => (int)($data['cs-bytes'] ?? 0),
                'time_taken' => (float)($data['time-taken'] ?? 0),
                'forwarded_for' => $data['x-forwarded-for'] ?? null,
                'ssl_protocol' => $data['ssl-protocol'] ?? null,
                'ssl_cipher' => $data['ssl-cipher'] ?? null,
                'edge_response_result_type' => $data['x-edge-response-result-type'] ?? null,
                'protocol_version' => $data['cs-protocol-version'] ?? null,
                'fle_status' => $data['fle-status'] ?? null,
                'fle_encrypted_fields' => $data['fle-encrypted-fields'] ?? null,
                'c_port' => $data['c-port'] ?? null,
                'time_to_first_byte' => (float)($data['time-to-first-byte'] ?? 0),
                'x_edge_detailed_result_type' => $data['x-edge-detailed-result-type'] ?? null,
                'sc_content_type' => $data['sc-content-type'] ?? null,
                'sc_content_len' => $data['sc-content-len'] ?? null,
                'sc_range_start' => $data['sc-range-start'] ?? null,
                'sc_range_end' => $data['sc-range-end'] ?? null,
            ];

        } catch (\Exception $e) {
            Log::warning("Failed to parse JSON log line: {$e->getMessage()}", [
                'line' => substr($line, 0, 200),
            ]);
            return null;
        }
    }

    private function parseTsvFormat(string $line): ?array
    {
        $fields = str_getcsv($line, "\t");

        if (count($fields) < 24) {
            return null;
        }

        return [
            'date' => $fields[0] ?? null,
            'time' => $fields[1] ?? null,
            'edge_location' => $fields[2] ?? null,
            'bytes_sent' => (int)($fields[3] ?? 0),
            'ip_address' => $fields[4] ?? null,
            'method' => $fields[5] ?? null,
            'host' => $fields[6] ?? null,
            'uri' => $fields[7] ?? null,
            'status_code' => (int)($fields[8] ?? 0),
            'referrer' => $fields[9] ?? null,
            'user_agent' => $fields[10] ?? null,
            'query_string' => $fields[11] ?? null,
            'cookie' => $fields[12] ?? null,
            'edge_result_type' => $fields[13] ?? null,
            'request_id' => $fields[14] ?? null,
            'host_header' => $fields[15] ?? null,
            'protocol' => $fields[16] ?? null,
            'bytes_received' => (int)($fields[17] ?? 0),
            'time_taken' => (float)($fields[18] ?? 0),
            'forwarded_for' => $fields[19] ?? null,
            'ssl_protocol' => $fields[20] ?? null,
            'ssl_cipher' => $fields[21] ?? null,
            'edge_response_result_type' => $fields[22] ?? null,
            'protocol_version' => $fields[23] ?? null,
        ];
    }

    public function extractSpaceIdFromTraffic(string $uri): ?string
    {
        if (preg_match(self::TRAFFIC_PATTERN, $uri, $matches)) {
            return $matches[1];
        }

        return null;
    }

    public function extractSpaceIdFromApiToken(string $queryString): ?string
    {
        // Handle "-" which CloudFront uses for empty query strings
        if ($queryString === '-' || empty($queryString)) {
            return null;
        }

        if (preg_match(self::API_TOKEN_PATTERN, $queryString, $matches)) {
            $token = $matches[1];

            return $this->getSpaceIdFromToken($token);
        }

        return null;
    }

    private function getSpaceIdFromToken(string $token): ?string
    {
        static $cache = [];

        if (isset($cache[$token])) {
            return $cache[$token];
        }

        try {
            $spaceId = DB::table('tokens')
                ->where('token', $token)
                ->value('space_id');

            $cache[$token] = $spaceId;

            return $spaceId;
        } catch (\Exception $e) {
            Log::warning("Failed to lookup token: {$token}", ['error' => $e->getMessage()]);
            return null;
        }
    }

    public function isApiRequest(string $uri): bool
    {
        return str_contains($uri, '/api/');
    }

    public function isTrafficRequest(string $uri): bool
    {
        return str_contains($uri, '/ilum/');
    }

    public function extractEndpoint(string $uri): string
    {
        $path = parse_url($uri, PHP_URL_PATH);

        if (!$path) {
            return $uri;
        }

        $parts = explode('/', trim($path, '/'));

        if (count($parts) >= 3 && $parts[0] === 'api') {
            return '/' . implode('/', array_slice($parts, 0, 4));
        }

        return $path;
    }

    public function getContentTypeFromUri(string $uri): ?string
    {
        $path = parse_url($uri, PHP_URL_PATH);

        if (!$path) {
            return null;
        }

        $extension = pathinfo($path, PATHINFO_EXTENSION);

        if (empty($extension)) {
            return null;
        }

        $contentTypes = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'pdf' => 'application/pdf',
            'json' => 'application/json',
            'xml' => 'application/xml',
            'css' => 'text/css',
            'js' => 'application/javascript',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'eot' => 'application/vnd.ms-fontobject',
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
        ];

        return $contentTypes[strtolower($extension)] ?? 'application/octet-stream';
    }

    public function isCacheHit(string $edgeResultType): bool
    {
        return in_array($edgeResultType, ['Hit', 'RefreshHit']);
    }

    public function extractCountryFromEdgeLocation(string $edgeLocation): ?string
    {
        if (strlen($edgeLocation) >= 3) {
            return strtoupper(substr($edgeLocation, 0, 3));
        }

        return null;
    }

    public function detectLogFormat(string $content): string
    {
        $lines = explode("\n", $content);

        foreach ($lines as $line) {
            $line = trim($line);

            if (empty($line) || str_starts_with($line, '#')) {
                continue;
            }

            if (str_starts_with($line, '{')) {
                return 'json';
            }

            return 'tsv';
        }

        return 'unknown';
    }
}
