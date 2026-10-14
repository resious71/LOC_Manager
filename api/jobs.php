<?php
declare(strict_types=1);

/*
 * 작업(job) 처리 함수 모음: 조각 업로드, 안전한 압축 해제, 컴포넌트 결정, 측정, 정리.
 * api/upload.php, api/measure.php, tests/run.php 가 사용하며 직접 호출하지 않는다 (api/.htaccess 로 차단).
 *
 * 작업 폴더 구조: work/<jobId>/
 *   state.json    업로드 상태 (조각 수, 받은 조각, 소스 종류/이름)
 *   upload.part   받는 중인 파일  →  upload.zip | upload.tar | upload.tgz  업로드 완료 (형식은 state.json 의 archive)
 *   measure.lock  측정 중 표시 (중복 측정 방지)
 *   progress.json 측정 진행 상태
 *   src/          압축 해제 위치
 *   root/         byFolder 모드에서 최상위 파일(_root 컴포넌트)을 옮겨 둔 곳
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'common.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'cloc_runner.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'rules_store.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'archive.php';

const JOB_ID_PATTERN = '/^[a-f0-9]{24}$/';  // 서버가 발급하는 작업 ID: 소문자 16진수 24자
const DS = DIRECTORY_SEPARATOR;

// ================================================================ 작업 폴더

function new_job_id(): string
{
    return bin2hex(random_bytes(12));
}

/** 작업 ID 형식을 검사하고 작업 폴더 경로를 돌려준다 (경로 조작 방지). */
function job_dir(mixed $jobId): string
{
    if (!is_string($jobId) || !preg_match(JOB_ID_PATTERN, $jobId)) {
        throw new ApiError('작업 ID 형식이 올바르지 않습니다.');
    }
    return WORK_DIR . DS . $jobId;
}

/** 폴더를 통째로 지운다. 링크(정션)는 따라가지 않고 링크만 지운다. */
function remove_dir(string $path): void
{
    if (is_link($path)) {
        @unlink($path) || @rmdir($path);
        return;
    }
    if (is_file($path)) {
        @chmod($path, 0666);
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $e) {
        if ($e !== '.' && $e !== '..') {
            remove_dir($path . DS . $e);
        }
    }
    @rmdir($path);
}

/** 보관 시간(WORK_RETENTION_SEC)이 지난 작업 폴더와 work/_tmp 잔여물을 지운다. 요청마다 호출한다. */
function cleanup_stale_work(?int $now = null): void
{
    if (!is_dir(WORK_DIR)) {
        return;
    }
    $limit = ($now ?? time()) - WORK_RETENTION_SEC;
    foreach (scandir(WORK_DIR) ?: [] as $e) {
        $p = WORK_DIR . DS . $e;
        if (preg_match(JOB_ID_PATTERN, $e) && is_dir($p)) {
            $t = max((int)@filemtime($p), (int)@filemtime($p . DS . 'state.json'), (int)@filemtime($p . DS . 'progress.json'));
            if ($t < $limit) {
                remove_dir($p);
            }
        }
    }
    if (is_dir(WORK_TMP_DIR)) {
        foreach (scandir(WORK_TMP_DIR) ?: [] as $e) {
            $p = WORK_TMP_DIR . DS . $e;
            if ($e !== '.' && $e !== '..' && (int)@filemtime($p) < $limit) {
                remove_dir($p);
            }
        }
    }
}

// ================================================================ 업로드

/**
 * zip 조각 하나를 받는다.
 *
 * @param array{jobId?: mixed, index?: mixed, total?: mixed, sourceType?: mixed, sourceName?: mixed} $fields
 * @param string $chunkFile 받은 조각이 있는 파일 경로
 * @return array{jobId: string, received: int, total: int, complete: bool}
 */
function upload_chunk(array $fields, string $chunkFile): array
{
    $maxChunks = intdiv(UPLOAD_MAX_BYTES + UPLOAD_CHUNK_BYTES - 1, UPLOAD_CHUNK_BYTES);
    $index = parse_uint($fields['index'] ?? null, 'index');
    $total = parse_uint($fields['total'] ?? null, 'total');
    if ($total < 1 || $total > $maxChunks) {
        throw new ApiError('조각 수(total)가 올바르지 않습니다. 파일은 최대 ' . format_bytes(UPLOAD_MAX_BYTES) . '까지 올릴 수 있습니다.', $total > $maxChunks ? 413 : 400);
    }
    if ($index >= $total) {
        throw new ApiError('조각 번호(index)가 조각 수(total)보다 큽니다.');
    }
    $size = @filesize($chunkFile);
    if ($size === false || $size === 0) {
        throw new ApiError('받은 조각이 비어 있습니다.');
    }
    if ($size > UPLOAD_CHUNK_BYTES) {
        throw new ApiError('조각 하나가 ' . format_bytes(UPLOAD_CHUNK_BYTES) . '를 넘습니다.', 413);
    }

    $jobId = is_string($fields['jobId'] ?? null) ? trim($fields['jobId']) : '';
    if ($jobId === '') {
        if ($index !== 0) {
            throw new ApiError('첫 조각(index 0)이 아닌데 작업 ID가 없습니다.');
        }
        ensure_dir(WORK_DIR);
        do {
            $jobId = new_job_id();
            $dir = job_dir($jobId);
        } while (!@mkdir($dir, 0775));
    } else {
        $dir = job_dir($jobId);
        if (!is_dir($dir)) {
            throw new ApiError('업로드 작업을 찾을 수 없습니다. 시간이 지나 정리되었을 수 있으니 처음부터 다시 올려 주세요.', 404);
        }
    }

    $statePath = $dir . DS . 'state.json';
    $partPath = $dir . DS . 'upload.part';
    $removeJob = false;
    $sh = fopen($statePath, 'c+');
    if ($sh === false) {
        throw new ApiError('작업 상태 파일을 열 수 없습니다. work 폴더 쓰기 권한을 확인해 주세요.', 500);
    }
    try {
        flock($sh, LOCK_EX);
        $state = json_decode((string)stream_get_contents($sh), true);
        if (!is_array($state)) {
            if ($index !== 0) {
                throw new ApiError('업로드 상태가 없습니다. 처음부터 다시 올려 주세요.', 409);
            }
            $sourceType = in_array($fields['sourceType'] ?? null, ['folder', 'zip'], true) ? $fields['sourceType'] : 'zip';
            $state = [
                'total' => $total, 'received' => 0, 'bytes' => 0, 'prevBytes' => 0, 'complete' => false,
                'sourceType' => $sourceType, 'sourceName' => clean_source_name($fields['sourceName'] ?? ''),
            ];
        }
        if ($state['complete']) {
            throw new ApiError('이미 업로드가 끝난 작업입니다.', 409);
        }
        if ($total !== $state['total']) {
            throw new ApiError('조각 수(total)가 첫 조각과 다릅니다.', 409);
        }

        if ($index === $state['received']) {
            $offset = $state['bytes'];              // 다음 조각
        } elseif ($index === $state['received'] - 1) {
            $offset = $state['prevBytes'];          // 직전 조각 재전송 (응답을 못 받은 재시도)
        } else {
            throw new ApiError('조각 순서가 맞지 않습니다. ' . $state['received'] . '번 조각을 보내야 하는데 ' . $index . '번이 왔습니다.', 409);
        }
        if ($offset + $size > UPLOAD_MAX_BYTES) {
            $removeJob = true;
            throw new ApiError('업로드 파일이 최대 크기(' . format_bytes(UPLOAD_MAX_BYTES) . ')를 넘습니다.', 413);
        }

        append_chunk($partPath, $chunkFile, $offset);
        $state['prevBytes'] = $offset;
        $state['bytes'] = $offset + $size;
        $state['received'] = $index + 1;

        if ($state['received'] === $state['total']) {
            // 파일 앞부분으로 형식을 정한다 (zip / tar / tar.gz)
            $format = detect_archive_format($partPath);
            if ($format === null) {
                $removeJob = true;
                throw new ApiError('올린 파일이 zip, tar, tar.gz 형식이 아닙니다. 압축 파일을 확인해 주세요.', 422);
            }
            if (!@rename($partPath, $dir . DS . 'upload.' . $format)) {
                throw new ApiError('업로드 파일을 확정하지 못했습니다.', 500);
            }
            $state['archive'] = $format;
            $state['complete'] = true;
        }

        ftruncate($sh, 0);
        rewind($sh);
        fwrite($sh, json_encode($state, JSON_UNESCAPED_UNICODE));
        fflush($sh);
    } finally {
        flock($sh, LOCK_UN);
        fclose($sh);
        if ($removeJob) {
            remove_dir($dir);
        }
    }
    return ['jobId' => $jobId, 'received' => $state['received'], 'total' => $state['total'], 'complete' => $state['complete']];
}

/** $partPath 의 $offset 위치부터 조각을 쓴다 (그 뒤는 잘라낸다). */
function append_chunk(string $partPath, string $chunkFile, int $offset): void
{
    $out = fopen($partPath, 'c');
    $in = fopen($chunkFile, 'rb');
    if ($out === false || $in === false) {
        throw new ApiError('업로드 조각을 저장할 수 없습니다. work 폴더 쓰기 권한을 확인해 주세요.', 500);
    }
    try {
        ftruncate($out, $offset);
        fseek($out, $offset);
        $expected = filesize($chunkFile);
        if (stream_copy_to_stream($in, $out) !== $expected) {
            throw new ApiError('업로드 조각을 끝까지 저장하지 못했습니다. 디스크 공간을 확인해 주세요.', 500);
        }
    } finally {
        fclose($in);
        fclose($out);
    }
}

function parse_uint(mixed $v, string $name): int
{
    if (is_int($v) && $v >= 0) {
        return $v;
    }
    if (is_string($v) && preg_match('/^\d{1,9}$/', $v)) {
        return (int)$v;
    }
    throw new ApiError($name . ' 값이 올바르지 않습니다.');
}

/** 소스 이름은 경로 없이 마지막 이름만 남긴다. */
function clean_source_name(mixed $v): string
{
    if (!is_string($v) || !preg_match('//u', $v)) {
        return '';
    }
    $v = last_segment($v);
    return trim(utf8_truncate(preg_replace('/[\x00-\x1F\x7F]/u', '', $v), 120));
}

function format_bytes(int $n): string
{
    return $n >= 1024 ** 3 ? round($n / 1024 ** 3, 1) . 'GB' : round($n / 1024 ** 2, 1) . 'MB';
}

// ================================================================ 압축 해제

/** zip 항목 이름(원시 바이트)을 UTF-8 로 바꾼다. UTF-8 이 아니면 CP949(한국어 Windows 압축 프로그램)로 본다. */
function decode_zip_name(string $raw): string
{
    if (preg_match('//u', $raw)) {
        return $raw;
    }
    if (function_exists('mb_convert_encoding')) {
        $s = mb_convert_encoding($raw, 'UTF-8', 'CP949');
    } else {
        $s = (string)@iconv('CP949', 'UTF-8//IGNORE', $raw);
    }
    return preg_match('//u', $s) ? $s : (function_exists('mb_scrub') ? mb_scrub($raw, 'UTF-8') : preg_replace('/[\x80-\xFF]/', '?', $raw));
}

/**
 * cloc(Windows exe)은 ASCII 가 아닌 경로의 파일을 읽지 못하고 조용히 건너뛴다.
 * 그래서 디스크에는 cloc 이 읽을 수 있는 이름만 쓴다. 이 이름이 그대로 쓸 수 있는 이름인지 검사한다.
 */
function is_cloc_safe_segment(string $seg): bool
{
    return preg_match('/^[\x20-\x7E]{1,150}$/', $seg) === 1
        && strpbrk($seg, '<>:"/\\|?*') === false
        && !preg_match('/[ .]$/', $seg)
        && !preg_match('/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(\..*)?$/i', $seg);
}

/** 쓸 수 없는 이름을 대신할 ASCII 이름. 확장자(최대 두 단계, 예: .min.js)는 언어 판별을 위해 유지한다. */
function substitute_segment(string $seg, int $n): string
{
    $ext = preg_match('/((?:\.[A-Za-z0-9_+-]{1,16}){1,2})$/', $seg, $m) ? $m[1] : '';
    return '_u' . $n . $ext;
}

function lower(string $s): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
}

/**
 * 압축 파일(zip / tar / tar.gz)을 $destDir 에 안전하게 푼다.
 * - `../`, 절대 경로, 드라이브 문자가 있는 항목이 하나라도 있으면 전체를 거부한다 (zip slip).
 * - 파일 수·총 크기(선언값과 실제 풀린 양 모두)가 상한을 넘으면 중단한다 (압축 폭탄).
 * - 한글 등 cloc 이 읽지 못하는 이름은 ASCII 이름으로 바꿔 풀고, 원래 이름은 names/paths 로 돌려준다.
 * - tar 의 링크 항목 등 풀 수 없는 항목은 건너뛰고 skipped 에 적는다.
 *
 * @param 'zip'|'tar'|'tgz' $format
 * @return array{files: int, bytes: int, names: array<string, string>, paths: array<string, string>, skipped: list<array{path: string, reason: string}>}
 *         names: 소문자 디스크 상대 경로(폴더, 깊이 2까지) → 원래 상대 경로
 *         paths: 이름을 바꿔 푼 파일의 소문자 디스크 상대 경로 → 원래 상대 경로
 */
function extract_archive_safely(string $archivePath, string $format, string $destDir, int $maxBytes = EXTRACT_MAX_BYTES, int $maxFiles = EXTRACT_MAX_FILES): array
{
    $label = ARCHIVE_FORMATS[$format] ?? '압축';
    $tooBig = fn() => new ApiError('압축을 푼 크기가 최대 ' . format_bytes($maxBytes) . '를 넘습니다. 제외 폴더를 늘리거나 컴포넌트를 나눠 올려 주세요.', 413);
    $src = open_archive($archivePath, $format);
    try {
        // ---- 1차: 경로 검사, 개수·크기 검사, 형제 이름 수집
        $entries = [];
        $siblings = [];      // 원래 부모 경로(소문자) => [원래 이름(소문자) => true]
        $skipped = [];
        $fileCount = 0;
        $declared = 0;
        $src->walk(function (int $i, array $meta) use (&$entries, &$siblings, &$skipped, &$fileCount, &$declared, $maxFiles, $maxBytes, $label, $tooBig): void {
            $name = decode_zip_name($meta['raw']);
            $norm = str_replace('\\', '/', $name);
            if (str_contains($norm, "\0") || str_starts_with($norm, '/') || preg_match('#^[A-Za-z]:#', $norm)
                || preg_match('#(^|/)\.\.(/|$)#', $norm)) {
                throw new ApiError('안전하지 않은 경로(' . utf8_truncate($name, 80) . ')가 들어 있는 ' . $label . ' 파일입니다. 상위 폴더(../)나 절대 경로가 든 압축 파일은 받을 수 없습니다.', 400);
            }
            $segs = array_values(array_filter(explode('/', $norm), fn($s) => $s !== '' && $s !== '.'));
            if ($segs === []) {
                return;
            }
            if ($meta['skip'] !== null) {
                $skipped[] = ['path' => utf8_truncate(implode('/', $segs), 200), 'reason' => $meta['skip']];
                return;
            }
            if (!$meta['isDir']) {
                if (++$fileCount > $maxFiles) {
                    throw new ApiError($label . ' 안의 파일이 너무 많습니다. 최대 ' . number_format($maxFiles) . '개까지 처리할 수 있습니다.', 413);
                }
                $declared += $meta['size'];
                if ($declared > $maxBytes) {
                    throw $tooBig();
                }
            }
            $parent = '';
            foreach ($segs as $s) {
                $siblings[$parent][lower($s)] = true;
                $parent .= '/' . lower($s);
            }
            $entries[] = [$i, $segs, $meta['isDir']];
        });

        // ---- 2차: 디스크 이름 정하기
        $assigned = [];      // 원래 부모(소문자) => [원래 이름(소문자) => 디스크 이름]
        $usedSafe = [];      // 원래 부모(소문자) => [디스크 이름(소문자) => true]
        $counter = [];
        $kind = [];          // 디스크 상대 경로(소문자) => 'd' | 'f'
        $names = [];
        $paths = [];         // 이름을 바꾼 파일: 소문자 디스크 상대 경로 → 원래 상대 경로
        $plan = [];          // 항목 번호 => [디스크 경로 조각, 폴더인지, 원래 경로]
        foreach ($entries as [$i, $segs, $isDir]) {
            $parentKey = '';
            $safeSegs = [];
            $origSegs = [];
            $conflict = false;
            foreach ($segs as $depth => $s) {
                $low = lower($s);
                if (!isset($assigned[$parentKey][$low])) {
                    if (is_cloc_safe_segment($s)) {
                        $safe = $s;
                    } else {
                        do {
                            $counter[$parentKey] = ($counter[$parentKey] ?? 0) + 1;
                            $safe = substitute_segment($s, $counter[$parentKey]);
                        } while (isset($siblings[$parentKey][strtolower($safe)]) || isset($usedSafe[$parentKey][strtolower($safe)]));
                    }
                    $assigned[$parentKey][$low] = $safe;
                    $usedSafe[$parentKey][strtolower($safe)] = true;
                }
                $safeSegs[] = $assigned[$parentKey][$low];
                $origSegs[] = $s;
                $parentKey .= '/' . $low;

                $rel = strtolower(implode('/', $safeSegs));
                $last = $depth === count($segs) - 1;
                $want = ($last && !$isDir) ? 'f' : 'd';
                if (isset($kind[$rel]) && ($kind[$rel] !== $want || $want === 'f')) {
                    $conflict = true;   // 같은 이름의 파일이 두 번, 또는 파일/폴더 이름 충돌
                    break;
                }
                if ($want === 'd' && !isset($kind[$rel]) && $depth < 2) {
                    $names[$rel] = implode('/', $origSegs);
                }
                if (!$last || $want === 'f') {
                    $kind[$rel] ??= $want;
                }
            }
            if ($conflict) {
                $skipped[] = ['path' => utf8_truncate(implode('/', $segs), 200), 'reason' => '같은 이름의 항목이 이미 있음'];
                continue;
            }
            $kind[strtolower(implode('/', $safeSegs))] = $isDir ? 'd' : 'f';
            if (!$isDir && implode('/', $safeSegs) !== implode('/', $segs)) {
                $paths[strtolower(implode('/', $safeSegs))] = implode('/', $segs);
            }
            $plan[$i] = [$safeSegs, $isDir, implode('/', $segs)];
        }

        // ---- 3차: 풀기 (tar 는 처음부터 다시 읽으며 계획에 있는 항목만 쓴다)
        ensure_dir($destDir);
        $base = realpath($destDir);
        if ($base === false) {
            throw new RuntimeException('압축 해제 폴더를 만들 수 없습니다.');
        }
        foreach ($plan as [$safeSegs, $isDir]) {
            if ($isDir) {
                ensure_dir($base . DS . implode(DS, $safeSegs));
            }
        }
        $written = 0;
        $files = 0;
        $src->walk(function (int $i, array $meta, callable $read) use (&$written, &$files, &$skipped, $plan, $base, $maxBytes, $tooBig): void {
            if (!isset($plan[$i]) || $plan[$i][1]) {
                return;
            }
            [$safeSegs, , $orig] = $plan[$i];
            $target = $base . DS . implode(DS, $safeSegs);
            ensure_dir(dirname($target));
            $parentReal = realpath(dirname($target));
            if ($parentReal === false || !str_starts_with($parentReal . DS, $base . DS)) {
                throw new ApiError('안전하지 않은 경로가 들어 있는 압축 파일입니다.', 400);
            }
            $first = $read(1024 * 1024);
            if ($first === false) {
                $skipped[] = ['path' => utf8_truncate($orig, 200), 'reason' => '읽을 수 없는 항목 (암호화 또는 지원하지 않는 압축 방식)'];
                return;
            }
            $out = @fopen($target, 'xb');
            if ($out === false) {
                $skipped[] = ['path' => utf8_truncate($orig, 200), 'reason' => '파일을 만들 수 없음'];
                return;
            }
            try {
                for ($buf = $first; $buf !== '' && $buf !== false; $buf = $read(1024 * 1024)) {
                    $written += strlen($buf);
                    if ($written > $maxBytes) {
                        throw $tooBig();
                    }
                    fwrite($out, $buf);
                }
            } finally {
                fclose($out);
            }
            $files++;
        });
        return ['files' => $files, 'bytes' => $written, 'names' => $names, 'paths' => $paths, 'skipped' => $skipped];
    } finally {
        $src->close();
    }
}

/** zip 전용 이름 (예전 호출과 테스트용) */
function extract_zip_safely(string $zipPath, string $destDir, int $maxBytes = EXTRACT_MAX_BYTES, int $maxFiles = EXTRACT_MAX_FILES): array
{
    return extract_archive_safely($zipPath, 'zip', $destDir, $maxBytes, $maxFiles);
}

// ================================================================ 컴포넌트

function is_excluded_dir(string $name, array $excludeDirs): bool
{
    foreach ($excludeDirs as $d) {
        if (strcasecmp($name, $d) === 0 || (str_contains($d, '*') && fnmatch($d, $name, FNM_CASEFOLD))) {
            return true;
        }
    }
    return false;
}

function list_entries(string $dir): array
{
    return array_values(array_filter(scandir($dir) ?: [], fn($e) => $e !== '.' && $e !== '..'));
}

/**
 * 측정할 컴포넌트를 정한다 (prd.md FR-2).
 * 각 컴포넌트의 safeRel/origRel 은 src/ 기준 (디스크/원래) 상대 경로로, 폴더별·파일 타입별 집계에서 원래 이름을 찾는 데 쓴다.
 * @return array{components: list<array{name: string, dir: string, safeRel: string, origRel: string}>, skipped: list<array{path: string, reason: string}>}
 */
function resolve_components(string $jobDir, string $srcDir, string $mode, array $source, array $excludeDirs, array $names): array
{
    $orig = fn(string $rel): string => $names[strtolower($rel)] ?? $rel;
    $root = $srcDir;
    $rootRel = '';
    $entries = list_entries($root);
    // 최상위가 폴더 하나뿐이면 그 안쪽을 기준으로 한다.
    if (count($entries) === 1 && is_dir($root . DS . $entries[0]) && !is_excluded_dir($entries[0], $excludeDirs)) {
        $rootRel = $entries[0];
        $root .= DS . $entries[0];
        $entries = list_entries($root);
    }

    // 컴포넌트들을 담은 상위 폴더 이름 (측정 이력의 '측정 묶음' 표시에 쓴다)
    $parentName = $rootRel !== '' ? last_segment($orig($rootRel))
        : (string)preg_replace('/\.(zip|tar|tar\.gz|tgz)$/i', '', (string)($source['name'] ?? ''));

    if ($mode === 'single') {
        $name = (string)($source['name'] ?? '');
        if (($source['type'] ?? '') === 'zip') {
            $name = preg_replace('/\.(zip|tar|tar\.gz|tgz)$/i', '', $name);
        }
        if (trim($name) === '') {
            $name = $rootRel !== '' ? last_segment($orig($rootRel)) : 'source';
        }
        try {
            $name = sanitize_component_name($name);
        } catch (ApiError) {
            $name = 'source';
        }
        // 최상위 폴더 하나 안쪽을 기준으로 해야 하위 폴더별 집계가 그 안의 폴더로 나온다 (cloc 수치는 같다).
        return ['components' => [['name' => $name, 'dir' => $root, 'safeRel' => $rootRel, 'origRel' => $rootRel === '' ? '' : $orig($rootRel)]], 'skipped' => [], 'parent' => $parentName];
    }

    $components = [];
    $skipped = [];
    $rootFiles = [];
    foreach ($entries as $e) {
        $p = $root . DS . $e;
        $rel = $rootRel === '' ? $e : $rootRel . '/' . $e;
        if (is_dir($p)) {
            if (is_excluded_dir($e, $excludeDirs)) {
                $skipped[] = ['path' => $orig($rel), 'reason' => '제외 폴더'];
            } else {
                $components[] = ['name' => last_segment($orig($rel)), 'dir' => $p, 'safeRel' => $rel, 'origRel' => $orig($rel)];
            }
        } else {
            $rootFiles[] = $e;
        }
    }
    usort($components, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));

    if ($rootFiles !== []) {
        // 바로 아래 파일만 따로 모아 _root 컴포넌트로 측정한다.
        $rootDir = $jobDir . DS . 'root';
        ensure_dir($rootDir);
        foreach ($rootFiles as $f) {
            if (!@rename($root . DS . $f, $rootDir . DS . $f)) {
                $skipped[] = ['path' => $orig($rootRel === '' ? $f : $rootRel . '/' . $f), 'reason' => '_root 로 옮길 수 없음'];
            }
        }
        $components[] = ['name' => '_root', 'dir' => $rootDir, 'safeRel' => $rootRel, 'origRel' => $rootRel === '' ? '' : $orig($rootRel)];
    }
    return ['components' => $components, 'skipped' => $skipped, 'parent' => $parentName];
}

// ================================================================ 측정

/**
 * 컴포넌트마다 순서대로 cloc 을 실행한다. 하나가 실패해도 나머지는 계속한다.
 * 컴포넌트 항목에 'timeout'(초)이 있으면 그 값을 쓴다 (테스트용, 요청 값으로는 받지 않음).
 */
function measure_components(array $components, array $excludeDirs, array $excludeExts, array $engine, string $jobDir, array $paths = [], array $excludeFiles = []): array
{
    $results = [];
    $total = count($components);
    foreach (array_values($components) as $n => $c) {
        write_progress($jobDir, ['phase' => 'measuring', 'done' => $n, 'total' => $total, 'current' => $c['name']]);
        $base = ['name' => $c['name']];
        if (!is_dir($c['dir'])) {
            $results[] = $base + ['ok' => false, 'error' => '컴포넌트 폴더를 찾을 수 없습니다.', 'runner' => $engine['runner'],
                'totals' => cloc_sum([]), 'languages' => [], 'folders' => [], 'exts' => [], 'warnings' => [], 'ignored' => ['count' => 0, 'reasons' => []]];
            continue;
        }
        $timeout = (int)($c['timeout'] ?? CLOC_TIMEOUT_SEC);
        $ignoredFile = $jobDir . DS . 'ignored_' . $n . '.txt';
        $runner = $engine['runner'];
        $r = cloc_run($c['dir'], $excludeDirs, $excludeExts, $runner, $timeout, $ignoredFile, $excludeFiles);
        if (!$r['ok'] && $runner === 'exe' && empty($r['timedOut']) && cloc_pl_version() !== null) {
            $runner = 'pl';
            $r = cloc_run($c['dir'], $excludeDirs, $excludeExts, $runner, $timeout, $ignoredFile, $excludeFiles);
        }
        $row = $base + ['ok' => $r['ok']];
        if (!$r['ok']) {
            $row['error'] = $r['error'];
        }
        [$folders, $exts] = component_breakdowns($r['files'], $c, $paths);
        $results[] = $row + ['runner' => $runner, 'totals' => $r['totals'], 'languages' => $r['languages'],
            'folders' => $folders, 'exts' => $exts, 'warnings' => $r['warnings'], 'ignored' => $r['ignored']];
    }
    write_progress($jobDir, ['phase' => 'done', 'done' => $total, 'total' => $total, 'current' => null]);
    return $results;
}

const BREAKDOWN_MAX = 300;   // 하위 폴더별·파일 타입별 항목 수 상한 (넘으면 '(그 외 N개)' 로 합침)
const ROOT_FILES_LABEL = '(바로 아래 파일)';
const NO_EXT_LABEL = '(확장자 없음)';

/**
 * 파일별 cloc 결과를 컴포넌트 안의 하위 폴더별(한 단계), 파일 타입(확장자)별로 합친다.
 * 이름을 바꿔 푼 파일은 원래 이름으로 돌려 집계한다. 파일 이름·경로 자체는 돌려주지 않는다.
 * @return array{0: list<array>, 1: list<array>} [folders, exts]
 */
function component_breakdowns(array $files, array $c, array $paths): array
{
    $safeBase = (string)($c['safeRel'] ?? '');
    $origBase = (string)($c['origRel'] ?? '');
    $relOf = function (array $f) use ($safeBase, $origBase, $paths): string {
        $full = $safeBase === '' ? $f['path'] : $safeBase . '/' . $f['path'];
        $orig = $paths[strtolower($full)] ?? null;
        if ($orig === null) {
            return $f['path'];
        }
        if ($origBase === '') {
            return $orig;
        }
        return str_starts_with($orig, $origBase . '/') ? substr($orig, strlen($origBase) + 1) : $f['path'];
    };
    $withRel = array_map(fn(array $f): array => ['rel' => $relOf($f)] + $f, $files);
    $folders = cloc_group($withRel, fn(array $f): string => str_contains($f['rel'], '/') ? strstr($f['rel'], '/', true) : ROOT_FILES_LABEL, 'name', BREAKDOWN_MAX);
    $exts = cloc_group($withRel, fn(array $f): string => file_type_label(last_segment($f['rel'])), 'name', BREAKDOWN_MAX);
    return [$folders, $exts];
}

/** 파일 이름의 확장자 ('.js' 처럼 소문자). 없으면 '(확장자 없음)'. */
function file_type_label(string $name): string
{
    return preg_match('/.\.([A-Za-z0-9_+-]{1,16})$/', $name, $m) ? '.' . strtolower($m[1]) : NO_EXT_LABEL;
}

function write_progress(string $jobDir, array $progress): void
{
    @file_put_contents($jobDir . DS . 'progress.json', json_encode($progress, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

/** 측정 진행 상태. 작업이 없으면(끝났거나 없는 ID) null. */
function read_progress(mixed $jobId): ?array
{
    $dir = job_dir($jobId);
    $p = @file_get_contents($dir . DS . 'progress.json');
    $data = $p === false ? null : json_decode($p, true);
    return is_array($data) ? $data : (is_dir($dir) ? ['phase' => 'waiting', 'done' => 0, 'total' => 0, 'current' => null] : null);
}

/**
 * 업로드가 끝난 작업을 측정하고, 결과와 상관없이 작업 폴더를 지운다.
 *
 * @param array{jobId?: mixed, mode?: mixed, excludeDirs?: mixed, excludeExts?: mixed, excludeFiles?: mixed} $body
 * @param array $limits 테스트용 상한 덮어쓰기: maxBytes, maxFiles, componentHook(callable)
 */
function measure_job(array $body, array $limits = []): array
{
    $dir = job_dir($body['jobId'] ?? null);
    $mode = $body['mode'] ?? 'byFolder';
    if (!in_array($mode, ['byFolder', 'single'], true)) {
        throw new ApiError('mode 는 byFolder 또는 single 이어야 합니다.');
    }
    // 제외 규칙을 보내지 않으면 관리 탭에서 사용 중으로 둔 규칙(없으면 config.php 기본값)을 쓴다
    $defaults = current_default_excludes();
    $excludeDirs = validate_rule_list($body['excludeDirs'] ?? $defaults['dirs'], '제외 폴더');
    $excludeExts = validate_rule_list($body['excludeExts'] ?? $defaults['exts'], '제외 확장자');
    $excludeFiles = validate_rule_list($body['excludeFiles'] ?? $defaults['files'], '제외 파일');

    if (!is_dir($dir)) {
        throw new ApiError('측정할 업로드를 찾을 수 없습니다. 이미 측정했거나 시간이 지나 정리되었습니다. 다시 올려 주세요.', 404);
    }
    $state = json_decode((string)@file_get_contents($dir . DS . 'state.json'), true);
    $format = is_array($state) ? ($state['archive'] ?? 'zip') : 'zip';
    $format = isset(ARCHIVE_FORMATS[$format]) ? $format : 'zip';
    $archive = $dir . DS . 'upload.' . $format;
    if (!is_array($state) || empty($state['complete']) || !is_file($archive)) {
        throw new ApiError('업로드가 아직 끝나지 않았습니다. 모든 조각을 보낸 뒤 측정해 주세요.', 409);
    }
    $lock = @fopen($dir . DS . 'measure.lock', 'x');
    if ($lock === false) {
        throw new ApiError('이 작업은 이미 측정 중입니다.', 409);
    }

    $started = microtime(true);
    try {
        write_progress($dir, ['phase' => 'extracting', 'done' => 0, 'total' => 0, 'current' => null]);
        $srcDir = $dir . DS . 'src';
        $ex = extract_archive_safely($archive, $format, $srcDir, $limits['maxBytes'] ?? EXTRACT_MAX_BYTES, $limits['maxFiles'] ?? EXTRACT_MAX_FILES);
        @unlink($archive);   // 풀고 나면 압축 파일은 필요 없다

        $source = ['type' => $state['sourceType'] ?? 'zip', 'name' => $state['sourceName'] ?? ''];
        $resolved = resolve_components($dir, $srcDir, $mode, $source, $excludeDirs, $ex['names']);
        if ($resolved['components'] === []) {
            throw new ApiError('측정할 폴더나 파일이 없습니다. 선택한 폴더가 비어 있거나 모두 제외 대상입니다.', 422);
        }
        $components = $resolved['components'];
        if (isset($limits['componentHook'])) {
            $components = ($limits['componentHook'])($components);
        }

        $engine = cloc_detect_engine();
        $results = measure_components($components, $excludeDirs, $excludeExts, $engine, $dir, $ex['paths'], $excludeFiles);

        $skipped = array_merge($resolved['skipped'], $ex['skipped']);
        $response = [
            'engine'     => $engine,
            'parentName' => $resolved['parent'],   // 컴포넌트들을 담은 상위 폴더 이름 (측정 묶음 표시용)
            'batchId'    => (string)$body['jobId'],   // 이번 측정 묶음 ID: 이 측정에서 나온 컴포넌트들을 history 에서 한 묶음으로 보여준다
            'mode'       => $mode,
            'source'     => $source,
            'excludes'   => ['dirs' => $excludeDirs, 'exts' => $excludeExts, 'files' => $excludeFiles],
            'extracted'  => ['files' => $ex['files'], 'bytes' => $ex['bytes']],
            'components' => $results,
            'elapsedMs'  => (int)round((microtime(true) - $started) * 1000),
        ];
        if ($skipped !== []) {
            $response['skipped'] = ['count' => count($skipped), 'items' => array_slice($skipped, 0, 50)];
        }
        return $response;
    } finally {
        fclose($lock);
        remove_dir($dir);
    }
}
