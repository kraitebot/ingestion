SELECT
    (SELECT COUNT(*) FROM steps WHERE state LIKE '%Pending%' AND dispatch_after IS NOT NULL) as pending_with_dispatch_after,
    (SELECT COUNT(*) FROM steps WHERE state LIKE '%Pending%') as pending,
    (SELECT COUNT(*) FROM steps WHERE state LIKE '%Running%') as running,
    (SELECT COUNT(*) FROM steps WHERE state LIKE '%Skipped%') as skipped,
    (SELECT COUNT(*) FROM steps WHERE is_throttled = 1) as is_throttled,
    (SELECT COUNT(*) FROM steps WHERE state LIKE '%Dispatched%') as dispatched,
    (SELECT COUNT(*) FROM steps WHERE state LIKE '%Completed%' ) as completed,
    (SELECT COUNT(*) FROM steps WHERE state LIKE '%Failed%') as failed,
    (SELECT MAX(dispatch_after) FROM steps WHERE state LIKE '%Pending%' AND dispatch_after IS NOT NULL) as longest_dispatch_after,
    (SELECT MAX(retries) FROM steps WHERE dispatch_after IS NOT NULL) as job_with_max_retries,
    (SELECT COUNT(*) FROM api_request_logs) as total_api_logs,
    (SELECT COUNT(*) FROM api_request_logs WHERE http_response_code = 429) as rate_limit_429,
    (SELECT CONCAT(
      FLOOR(TIMESTAMPDIFF(SECOND, MIN(created_at), MAX(updated_at)) / 3600), 'h ',
      FLOOR((TIMESTAMPDIFF(SECOND, MIN(created_at), MAX(updated_at)) % 3600) / 60), 'm ',
      (TIMESTAMPDIFF(SECOND, MIN(created_at), MAX(updated_at)) % 60), 's'
    ) FROM steps WHERE id BETWEEN 1 AND (SELECT MAX(id) FROM steps)) as total_duration,
    NOW() as now_time,
    (select count(1) from symbols) as total_symbols,
    (select count(1) from exchange_symbols) as total_exchange_symbols,
    (select count(1) from candles) as total_candles;

-- Steps grouped by class with state breakdown
SELECT
    class,
    COUNT(*) as total,
    SUM(CASE WHEN state LIKE '%Pending%' THEN 1 ELSE 0 END) as pending,
    SUM(CASE WHEN state LIKE '%Dispatched%' THEN 1 ELSE 0 END) as dispatched,
    SUM(CASE WHEN state LIKE '%Running%' THEN 1 ELSE 0 END) as running,
    SUM(CASE WHEN state LIKE '%Completed%' THEN 1 ELSE 0 END) as completed,
    SUM(CASE WHEN state LIKE '%Skipped%' THEN 1 ELSE 0 END) as skipped,
    SUM(CASE WHEN state LIKE '%Failed%' THEN 1 ELSE 0 END) as failed,
    SUM(CASE WHEN was_throttled = 1 THEN 1 ELSE 0 END) as was_throttled,
    SUM(CASE WHEN is_throttled = 1 THEN 1 ELSE 0 END) as is_throttled
FROM steps
GROUP BY class
ORDER BY class;

select * from exchange_symbols;