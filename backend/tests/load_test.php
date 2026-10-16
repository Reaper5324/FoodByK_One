
<?php

declare(strict_types=1);

/**
 * FoodbyK baseline HTTP load test.
 *
 * Run from the repository root:
 *   LOAD_TEST_BASE_URL=http://127.0.0.1:8080 \
 *   php backend/tests/load_test.php
 *
 * This test only sends GET requests to public, read-only endpoints.
 */

$baseUrl = rtrim(
    getenv('LOAD_TEST_BASE_URL') ?: 'http://127.0.0.1:8080',
    '/'
);

$durationSeconds = max(
    5,
    min(300, (int) (getenv('LOAD_TEST_DURATION') ?: 30))
);

$warmupSeconds = max(
    0,
    min(30, (int) (getenv('LOAD_TEST_WARMUP') ?: 5))
);

$concurrency = max(
    1,
    min(20, (int) (getenv('LOAD_TEST_CONCURRENCY') ?: 5))
);

$timeoutSeconds = max(
    1,
    min(30, (int) (getenv('LOAD_TEST_TIMEOUT') ?: 10))
);

$p95LimitMs = max(
    1,
    (float) (getenv('LOAD_TEST_P95_LIMIT_MS') ?: 1000)
);

$errorRateLimit = max(
    0,
    min(100, (float) (getenv('LOAD_TEST_ERROR_LIMIT_PERCENT') ?: 1))
);

$minimumSamples = max(
    1,
    (int) (getenv('LOAD_TEST_MIN_SAMPLES') ?: 20)
);

$endpoints = [
    '/health',
    '/products',
    '/categories',
    '/promotions/active',
];

$reportDirectory = __DIR__;
$jsonPath = $reportDirectory . '/load-test-results.json';
$markdownPath = $reportDirectory . '/load-test-results.md';

if (!extension_loaded('curl')) {
    fwrite(STDERR, "ERROR: PHP cURL extension is required.\n");
    exit(2);
}

if (filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
    fwrite(STDERR, "ERROR: LOAD_TEST_BASE_URL is not a valid URL.\n");
    exit(2);
}

function percentile(array $values, float $percentile): ?float
{
    if ($values === []) {
        return null;
    }

    sort($values, SORT_NUMERIC);

    $index = (int) ceil(($percentile / 100) * count($values)) - 1;
    $index = max(0, min(count($values) - 1, $index));

    return round((float) $values[$index], 2);
}

function summarizeEndpoint(array $samples): array
{
    $latencies = array_column($samples, 'latency_ms');
    $total = count($samples);
    $successes = 0;
    $timeouts = 0;
    $statuses = [];

    foreach ($samples as $sample) {
        if ($sample['status'] >= 200 && $sample['status'] < 300) {
            $successes++;
        }

        if ($sample['error_type'] === 'timeout') {
            $timeouts++;
        }

        $statusKey = $sample['status'] === 0
            ? 'transport_error'
            : (string) $sample['status'];

        $statuses[$statusKey] = ($statuses[$statusKey] ?? 0) + 1;
    }

    sort($latencies, SORT_NUMERIC);

    return [
        'requests' => $total,
        'successes' => $successes,
        'failures' => $total - $successes,
        'timeouts' => $timeouts,
        'error_rate_percent' => $total > 0
            ? round((($total - $successes) / $total) * 100, 2)
            : null,
        'http_statuses' => $statuses,
        'latency_ms' => [
            'min' => $total ? round(min($latencies), 2) : null,
            'mean' => $total
                ? round(array_sum($latencies) / $total, 2)
                : null,
            'max' => $total ? round(max($latencies), 2) : null,
            'p50' => percentile($latencies, 50),
            'p95' => percentile($latencies, 95),
            'p99' => percentile($latencies, 99),
        ],
    ];
}

/**
 * Executes one bounded batch of concurrent requests.
 * Warm-up samples are deliberately not included in the measured results.
 */
function runBatch(
    string $baseUrl,
    array $endpoints,
    int $concurrency,
    int $timeoutSeconds,
    float $deadline,
    array &$samples
): void {
    $multiHandle = curl_multi_init();
    $activeHandles = [];
    $nextEndpoint = 0;

    try {
        do {
            // Fill the concurrency slots.
            while (
                count($activeHandles) < $concurrency
                && microtime(true) < $deadline
            ) {
                $endpoint = $endpoints[
                    $nextEndpoint % count($endpoints)
                ];
                $nextEndpoint++;

                $url = $baseUrl . $endpoint;
                $handle = curl_init($url);

                curl_setopt_array($handle, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_CONNECTTIMEOUT => min(3, $timeoutSeconds),
                    CURLOPT_TIMEOUT => $timeoutSeconds,
                    CURLOPT_NOSIGNAL => true,
                    CURLOPT_HTTPHEADER => [
                        'Accept: application/json',
                        'User-Agent: FoodbyK-LoadTest/1.0',
                    ],
                ]);

                $startedAt = hrtime(true);

                curl_multi_add_handle($multiHandle, $handle);

                $id = spl_object_id($handle);
                $activeHandles[$id] = [
                    'handle' => $handle,
                    'endpoint' => $endpoint,
                    'started_at' => $startedAt,
                ];
            }

            do {
                $multiStatus = curl_multi_exec(
                    $multiHandle,
                    $running
                );
            } while ($multiStatus === CURLM_CALL_MULTI_PERFORM);

            foreach ($activeHandles as $id => $request) {
                $handle = $request['handle'];

                if (!in_array(
                    curl_getinfo($handle, CURLINFO_PRIVATE),
                    [],
                    true
                )) {
                    // Completion is determined from curl_multi_info_read below.
                }
            }

            while ($completed = curl_multi_info_read($multiHandle)) {
                $handle = $completed['handle'];
                $id = spl_object_id($handle);

                if (!isset($activeHandles[$id])) {
                    continue;
                }

                $request = $activeHandles[$id];
                $elapsedMs = (hrtime(true) - $request['started_at'])
                    / 1_000_000;

                $curlError = curl_errno($handle);
                $status = (int) curl_getinfo(
                    $handle,
                    CURLINFO_HTTP_CODE
                );

                $errorType = null;

                if ($curlError !== 0) {
                    $errorType = in_array(
                        $curlError,
                        [CURLE_OPERATION_TIMEDOUT, CURLE_OPERATION_TIMEOUTED],
                        true
                    ) ? 'timeout' : 'transport_error';
                }

                $samples[$request['endpoint']][] = [
                    'latency_ms' => round($elapsedMs, 2),
                    'status' => $status,
                    'error_type' => $errorType,
                ];

                curl_multi_remove_handle($multiHandle, $handle);
                curl_close($handle);
                unset($activeHandles[$id]);
            }

            if ($activeHandles !== []) {
                $selected = curl_multi_select($multiHandle, 0.2);

                if ($selected === -1) {
                    usleep(10_000);
                }
            }
        } while (
            $activeHandles !== []
            || microtime(true) < $deadline
        );
    } finally {
        // Clean up any remaining handles if the batch exits unexpectedly.
        foreach ($activeHandles as $request) {
            curl_multi_remove_handle(
                $multiHandle,
                $request['handle']
            );
            curl_close($request['handle']);
        }

        curl_multi_close($multiHandle);
    }
}

echo "FoodbyK HTTP load test\n";
echo "Target: {$baseUrl}\n";
echo "Warm-up: {$warmupSeconds}s | Measured: {$durationSeconds}s";
echo " | Concurrency: {$concurrency}\n\n";

$warmupSamples = [];

if ($warmupSeconds > 0) {
    echo "Running warm-up (excluded from KPIs)...\n";

    runBatch(
        $baseUrl,
        $endpoints,
        $concurrency,
        $timeoutSeconds,
        microtime(true) + $warmupSeconds,
        $warmupSamples
    );
}

$samples = array_fill_keys($endpoints, []);

$startTime = microtime(true);

runBatch(
    $baseUrl,
    $endpoints,
    $concurrency,
    $timeoutSeconds,
    $startTime + $durationSeconds,
    $samples
);

$elapsedSeconds = microtime(true) - $startTime;
$endpointResults = [];

foreach ($endpoints as $endpoint) {
    $endpointResults[$endpoint] = summarizeEndpoint(
        $samples[$endpoint]
    );
}

$allSamples = [];

foreach ($samples as $endpointSamples) {
    $allSamples = array_merge($allSamples, $endpointSamples);
}

$overall = summarizeEndpoint($allSamples);
$overall['throughput_requests_per_second'] = $elapsedSeconds > 0
    ? round($overall['requests'] / $elapsedSeconds, 2)
    : 0;

$checks = [
    'minimum_sample_count' => $overall['requests'] >= $minimumSamples,
    'p95_latency' => $overall['latency_ms']['p95'] !== null
        && $overall['latency_ms']['p95'] <= $p95LimitMs,
    'error_rate' => $overall['error_rate_percent'] !== null
        && $overall['error_rate_percent'] <= $errorRateLimit,
];

$passed = !in_array(false, $checks, true);

$report = [
    'test' => 'FoodbyK HTTP baseline load test',
    'generated_at_utc' => gmdate('c'),
    'target' => $baseUrl,
    'configuration' => [
        'warmup_seconds' => $warmupSeconds,
        'configured_duration_seconds' => $durationSeconds,
        'actual_duration_seconds' => round($elapsedSeconds, 2),
        'concurrency' => $concurrency,
        'timeout_seconds' => $timeoutSeconds,
    ],
    'thresholds' => [
        'minimum_samples' => $minimumSamples,
        'maximum_p95_latency_ms' => $p95LimitMs,
        'maximum_error_rate_percent' => $errorRateLimit,
    ],
    'overall' => $overall,
    'endpoints' => $endpointResults,
    'checks' => $checks,
    'result' => $passed ? 'PASS' : 'FAIL',
    'limitations' => [
        'This is a baseline test, not a production capacity certification.',
        'It tests public GET endpoints only.',
        'Results depend on the runner, database, and environment.',
    ],
];

$json = json_encode(
    $report,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
);

if ($json === false || file_put_contents($jsonPath, $json . PHP_EOL) === false) {
    fwrite(STDERR, "ERROR: Could not write JSON report.\n");
    exit(2);
}

$format = static fn ($value): string =>
    $value === null ? 'N/A' : (string) $value;

$markdown = "# FoodbyK Load Test Results\n\n";
$markdown .= "- Generated (UTC): {$report['generated_at_utc']}\n";
$markdown .= "- Target: `{$baseUrl}`\n";
$markdown .= "- Actual measured duration: {$format($report['configuration']['actual_duration_seconds'])} seconds\n";
$markdown .= "- Concurrency: {$concurrency}\n";
$markdown .= "- Overall result: **{$report['result']}**\n\n";

$markdown .= "## Overall KPIs\n\n";
$markdown .= "| KPI | Result |\n|---|---:|\n";
$markdown .= "| Total requests | {$overall['requests']} |\n";
$markdown .= "| Successful requests | {$overall['successes']} |\n";
$markdown .= "| Failed requests | {$overall['failures']} |\n";
$markdown .= "| Timeouts | {$overall['timeouts']} |\n";
$markdown .= "| Error rate | {$format($overall['error_rate_percent'])}% |\n";
$markdown .= "| Throughput | {$overall['throughput_requests_per_second']} requests/second |\n";
$markdown .= "| Mean latency | {$format($overall['latency_ms']['mean'])} ms |\n";
$markdown .= "| Minimum latency | {$format($overall['latency_ms']['min'])} ms |\n";
$markdown .= "| Maximum latency | {$format($overall['latency_ms']['max'])} ms |\n";
$markdown .= "| p50 latency | {$format($overall['latency_ms']['p50'])} ms |\n";
$markdown .= "| p95 latency | {$format($overall['latency_ms']['p95'])} ms |\n";
$markdown .= "| p99 latency | {$format($overall['latency_ms']['p99'])} ms |\n\n";

$markdown .= "## Endpoint breakdown\n\n";
$markdown .= "| Endpoint | Requests | Failures | Error rate | Mean ms | p95 ms | Max ms |\n";
$markdown .= "|---|---:|---:|---:|---:|---:|---:|\n";

foreach ($endpointResults as $endpoint => $result) {
    $markdown .= "| `{$endpoint}` | {$result['requests']} | {$result['failures']} | ";
    $markdown .= "{$format($result['error_rate_percent'])}% | ";
    $markdown .= "{$format($result['latency_ms']['mean'])} | ";
    $markdown .= "{$format($result['latency_ms']['p95'])} | ";
    $markdown .= "{$format($result['latency_ms']['max'])} |\n";
}

$markdown .= "\n## Threshold checks\n\n";

foreach ($checks as $name => $checkPassed) {
    $markdown .= "- **{$name}:** " . ($checkPassed ? 'PASS' : 'FAIL') . "\n";
}

$markdown .= "\n## Interpretation and limitations\n\n";
$markdown .= "- p95 means 95% of measured request latencies were at or below that value.\n";
$markdown .= "- The error rate includes non-2xx responses and transport failures.\n";
$markdown .= "- Throughput is measured successful and failed HTTP attempts per second combined.\n";
$markdown .= "- A low sample count makes percentile estimates less reliable.\n";
$markdown .= "- This controlled baseline does not prove production capacity or simulate real user journeys.\n";

if (file_put_contents($markdownPath, $markdown) === false) {
    fwrite(STDERR, "ERROR: Could not write Markdown report.\n");
    exit(2);
}

echo $markdown;
echo "\nReports written:\n";
echo "- {$jsonPath}\n";
echo "- {$markdownPath}\n";

exit($passed ? 0 : 1);
