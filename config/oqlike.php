<?php

use Illuminate\Support\Str;

return [
    'use_queue' => filter_var(
        env('OQLIKE_USE_QUEUE', false),
        FILTER_VALIDATE_BOOL,
        FILTER_NULL_ON_FAILURE
    ) ?? false,
    'http_timeout' => (int) env('OQLIKE_HTTP_TIMEOUT', 30),
    'http_retries' => (int) env('OQLIKE_HTTP_RETRIES', 3),
    'http_retry_ms' => (int) env('OQLIKE_HTTP_RETRY_MS', 250),
    'http_verify_tls' => filter_var(
        env('OQLIKE_HTTP_VERIFY_TLS', true),
        FILTER_VALIDATE_BOOL,
        FILTER_NULL_ON_FAILURE
    ) ?? true,
    'http_ca_path' => env('OQLIKE_HTTP_CA_PATH'),
    'rate_limit_ms' => (int) env('OQLIKE_RATE_LIMIT_MS', 150),
    'default_threshold_days' => (int) env('OQLIKE_DEFAULT_THRESHOLD_DAYS', 365),
    'max_samples' => (int) env('OQLIKE_MAX_SAMPLES', 25),
    'page_size' => (int) env('OQLIKE_PAGE_SIZE', 250),
    'duplicates_page_size' => (int) env('OQLIKE_DUPLICATES_PAGE_SIZE', 25),
    'max_full_records_per_class' => (int) env('OQLIKE_MAX_FULL_RECORDS_PER_CLASS', env('OQLIKE_MAX_FULL_RECORDS_WITHOUT_DELTA', 5000)),
    'max_full_records_without_delta' => (int) env('OQLIKE_MAX_FULL_RECORDS_WITHOUT_DELTA', 5000),
    'issue_objects_max_fetch' => (int) env('OQLIKE_ISSUE_OBJECTS_MAX_FETCH', env('OQLIKE_MAX_FULL_RECORDS_WITHOUT_DELTA', 5000)),
    'object_ack_enabled' => filter_var(
        env('OQLIKE_OBJECT_ACK_ENABLED', true),
        FILTER_VALIDATE_BOOL,
        FILTER_NULL_ON_FAILURE
    ) ?? true,
    'object_ack_max_verifications_per_issue' => (int) env('OQLIKE_OBJECT_ACK_MAX_VERIFICATIONS_PER_ISSUE', 250),
    'full_scan_unlimited' => filter_var(
        env('OQLIKE_FULL_SCAN_UNLIMITED', false),
        FILTER_VALIDATE_BOOL,
        FILTER_NULL_ON_FAILURE
    ) ?? false,
    'delta_strict_mode' => filter_var(
        env('OQLIKE_DELTA_STRICT_MODE', false),
        FILTER_VALIDATE_BOOL,
        FILTER_NULL_ON_FAILURE
    ) ?? false,
    'admin_pack_enabled' => filter_var(
        env('OQLIKE_ADMIN_PACK_ENABLED', true),
        FILTER_VALIDATE_BOOL,
        FILTER_NULL_ON_FAILURE
    ) ?? true,
    'admin_pack_max_records_per_check' => (int) env('OQLIKE_ADMIN_PACK_MAX_RECORDS_PER_CHECK', 2000),
    'admin_pack_orphan_check_enabled' => filter_var(
        env('OQLIKE_ADMIN_PACK_ORPHAN_CHECK_ENABLED', true),
        FILTER_VALIDATE_BOOL,
        FILTER_NULL_ON_FAILURE
    ) ?? true,
    'admin_pack_orphan_max_records' => (int) env('OQLIKE_ADMIN_PACK_ORPHAN_MAX_RECORDS', 1200),
    'admin_pack_orphan_max_attributes' => (int) env('OQLIKE_ADMIN_PACK_ORPHAN_MAX_ATTRIBUTES', 6),
    'admin_pack_orphan_priority_fields' => array_values(array_filter(array_map(
        static fn (string $value): string => trim($value),
        explode(',', (string) env('OQLIKE_ADMIN_PACK_ORPHAN_PRIORITY_FIELDS', 'org_id,location_id,parent_id,functionalci_id,contact_id,owner_id,team_id'))
    ), static fn (string $value): bool => $value !== '')),
    'admin_pack_stale_without_owner_enabled' => filter_var(
        env('OQLIKE_ADMIN_PACK_STALE_WITHOUT_OWNER_ENABLED', true),
        FILTER_VALIDATE_BOOL,
        FILTER_NULL_ON_FAILURE
    ) ?? true,
    'admin_pack_classification_drift_enabled' => filter_var(
        env('OQLIKE_ADMIN_PACK_CLASSIFICATION_DRIFT_ENABLED', true),
        FILTER_VALIDATE_BOOL,
        FILTER_NULL_ON_FAILURE
    ) ?? true,
    'admin_pack_excluded_classes' => array_values(array_filter(array_map(
        static fn (string $value): string => trim($value),
        explode(',', (string) env('OQLIKE_ADMIN_PACK_EXCLUDED_CLASSES', ''))
    ), static fn (string $value): bool => $value !== '')),
    'admin_pack_ownership_fields' => array_values(array_filter(array_map(
        static fn (string $value): string => trim($value),
        explode(',', (string) env('OQLIKE_ADMIN_PACK_OWNERSHIP_FIELDS', 'owner_id,team_id,agent_id,manager_id,contact_id,caller_id'))
    ), static fn (string $value): bool => $value !== '')),
    'admin_pack_classification_fields' => array_values(array_filter(array_map(
        static fn (string $value): string => trim($value),
        explode(',', (string) env('OQLIKE_ADMIN_PACK_CLASSIFICATION_FIELDS', 'org_id,location_id'))
    ), static fn (string $value): bool => $value !== '')),
    'admin_pack_placeholder_terms' => array_values(array_filter(array_map(
        static fn (string $value): string => trim($value),
        explode(',', (string) env('OQLIKE_ADMIN_PACK_PLACEHOLDER_TERMS', 'test,tmp,todo,tbd,sample,dummy,unknown,n/a,na,xxx,to define'))
    ), static fn (string $value): bool => $value !== '')),
    'admin_pack_placeholder_min_term_length' => (int) env('OQLIKE_ADMIN_PACK_PLACEHOLDER_MIN_TERM_LENGTH', 2),
    'max_duplicate_scan_records' => (int) env('OQLIKE_MAX_DUPLICATE_SCAN_RECORDS', 10000),
    'duplicates_skip_classes' => array_values(array_filter(array_map(
        static fn (string $value): string => trim($value),
        explode(',', (string) env('OQLIKE_DUPLICATES_SKIP_CLASSES', ''))
    ), static fn (string $value): bool => $value !== '')),
    'max_class_duration_s' => (int) env('OQLIKE_MAX_CLASS_DURATION_S', 0),
    'max_check_duration_s' => (int) env('OQLIKE_MAX_CHECK_DURATION_S', 0),
    'scan_heartbeat_interval_s' => (int) env('OQLIKE_SCAN_HEARTBEAT_INTERVAL_S', 10),
    'watchdog_enabled' => filter_var(
        env('OQLIKE_WATCHDOG_ENABLED', true),
        FILTER_VALIDATE_BOOL,
        FILTER_NULL_ON_FAILURE
    ) ?? true,
    'watchdog_stale_seconds' => (int) env('OQLIKE_WATCHDOG_STALE_SECONDS', 900),
    'itop_loop_max_duration_s' => (int) env('OQLIKE_ITOP_LOOP_MAX_DURATION_S', 0),
    'itop_loop_max_pages' => (int) env('OQLIKE_ITOP_LOOP_MAX_PAGES', 0),
    'itop_loop_heartbeat_pages' => (int) env('OQLIKE_ITOP_LOOP_HEARTBEAT_PAGES', 20),
    'discovery_scan_limit' => (int) env('OQLIKE_DISCOVERY_SCAN_LIMIT', 400),
    'max_connector_classes' => (int) env('OQLIKE_MAX_CONNECTOR_CLASSES', 800),
    'connector_memory_guard_ratio' => (float) env('OQLIKE_CONNECTOR_MEMORY_GUARD_RATIO', 0.70),
    'connector_memory_hard_stop_ratio' => (float) env('OQLIKE_CONNECTOR_MEMORY_HARD_STOP_RATIO', 0.92),
    'connector_class_second_pass' => filter_var(
        env('OQLIKE_CONNECTOR_CLASS_SECOND_PASS', true),
        FILTER_VALIDATE_BOOL,
        FILTER_NULL_ON_FAILURE
    ) ?? true,
    'connector_temp_dir' => (string) env('OQLIKE_CONNECTOR_TEMP_DIR', storage_path('app/oqlike/tmp')),
    'metamodel_fast_path_enabled' => filter_var(
        env('OQLIKE_METAMODEL_FAST_PATH_ENABLED', true),
        FILTER_VALIDATE_BOOL,
        FILTER_NULL_ON_FAILURE
    ) ?? true,
    'metamodel_fast_path_max_age_min' => (int) env('OQLIKE_METAMODEL_FAST_PATH_MAX_AGE_MIN', 240),
    'metamodel_precheck_timeout' => (int) env('OQLIKE_METAMODEL_PRECHECK_TIMEOUT', 12),
    'metamodel_precheck_retries' => (int) env('OQLIKE_METAMODEL_PRECHECK_RETRIES', 1),
    'metamodel_precheck_retry_ms' => (int) env('OQLIKE_METAMODEL_PRECHECK_RETRY_MS', 0),
    'metamodel_allow_cache_on_precheck_failure' => filter_var(
        env('OQLIKE_METAMODEL_ALLOW_CACHE_ON_PRECHECK_FAILURE', true),
        FILTER_VALIDATE_BOOL,
        FILTER_NULL_ON_FAILURE
    ) ?? true,
    'itop_version' => env('OQLIKE_ITOP_REST_VERSION', '1.3'),
    'itop_discovery_root_classes' => array_values(array_filter(array_map(
        static fn (string $value): string => trim($value),
        explode(',', (string) env('OQLIKE_ITOP_DISCOVERY_ROOT_CLASSES', 'cmdbAbstractObject,CMDBAbstractObject,CMDBObject'))
    ), static fn (string $value): bool => $value !== '' && Str::contains($value, ' ' ) === false)),
];
