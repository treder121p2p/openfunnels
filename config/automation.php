<?php

return [
    'max_nodes' => (int) env('AUTOMATION_MAX_NODES', 50),
    'max_condition_depth' => (int) env('AUTOMATION_MAX_CONDITION_DEPTH', 5),
    'max_matches_per_event' => (int) env('AUTOMATION_MAX_MATCHES_PER_EVENT', 20),
    'max_causation_depth' => (int) env('AUTOMATION_MAX_CAUSATION_DEPTH', 5),
    'event_daily_limit' => (int) env('AUTOMATION_EVENT_DAILY_LIMIT', 10000),
    'run_retention_days' => (int) env('AUTOMATION_RUN_RETENTION_DAYS', 90),
    'stale_run_after_minutes' => (int) env('AUTOMATION_STALE_RUN_AFTER_MINUTES', 5),
    'queue' => env('AUTOMATION_QUEUE', 'default'),
    'webhooks' => [
        'allow_private_networks' => (bool) env('AUTOMATION_WEBHOOK_ALLOW_PRIVATE_NETWORKS', false),
        'allow_http' => (bool) env('AUTOMATION_WEBHOOK_ALLOW_HTTP', false),
        'connect_timeout' => (int) env('AUTOMATION_WEBHOOK_CONNECT_TIMEOUT', 3),
        'timeout' => (int) env('AUTOMATION_WEBHOOK_TIMEOUT', 10),
        'max_response_bytes' => (int) env('AUTOMATION_WEBHOOK_MAX_RESPONSE_BYTES', 102400),
    ],
];
