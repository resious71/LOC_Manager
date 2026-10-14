<?php
declare(strict_types=1);

/*
 * 측정 API
 *   POST { jobId, mode: byFolder|single, excludeDirs: [], excludeExts: [], excludeFiles: [] }
 *        → { engine, mode, source, excludes, extracted, components: [{ name, ok, error?, runner, totals, languages, warnings, ignored }], skipped?, elapsedMs }
 *        응답 전에 work/<jobId> 를 지운다 (성공·실패 모두).
 *   GET ?jobId=...  → 측정 진행 상태 { phase, done, total, current }
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'jobs.php';

run_api(function (): void {
    $method = require_method('GET', 'POST');

    if ($method === 'GET') {
        $progress = read_progress($_GET['jobId'] ?? null);
        if ($progress === null) {
            throw new ApiError('진행 중인 작업이 없습니다.', 404);
        }
        json_response($progress);
    }

    cleanup_stale_work();
    ignore_user_abort(true);      // 브라우저가 끊어도 정리까지 마친다
    if (function_usable('set_time_limit')) {
        set_time_limit(0);        // 컴포넌트별 제한 시간은 cloc_run 이 따로 지킨다
    }
    json_response(measure_job(read_json_body()));
});
