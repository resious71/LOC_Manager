<?php
declare(strict_types=1);

/*
 * cloc 실행 함수 모음. measure 흐름(api/jobs.php)이 사용하며, 직접 호출하지 않는다 (api/.htaccess 로 차단).
 * - 실행은 run_process() (proc_open 인자 배열, 셸 없음)로만 한다.
 * - ctool 안의 cloc-<버전>.exe 중 버전이 가장 높은(그리고 실행되는) 것을 쓰고,
 *   exe 가 모두 안 되면 Perl + 가장 높은 버전의 cloc-<버전>.pl 로 대체한다.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'common.php';

/** cloc exe 가 압축을 풀 TEMP 를 work/_tmp 로 지정한다. */
function cloc_env(): array
{
    return ['TEMP' => WORK_TMP_DIR, 'TMP' => WORK_TMP_DIR];
}

/**
 * ctool 폴더의 cloc 실행 파일 목록 (버전 높은 순).
 * @param 'exe'|'pl' $kind
 * @return list<array{path: string, file: string, version: string}>
 */
function cloc_candidates(string $kind, string $dir = CTOOL_DIR): array
{
    $list = [];
    foreach (scandir($dir) ?: [] as $f) {
        if (preg_match(CLOC_FILE_PATTERN, $f, $m) && strtolower($m[2]) === $kind && is_file($dir . DIRECTORY_SEPARATOR . $f)) {
            $list[] = ['path' => $dir . DIRECTORY_SEPARATOR . $f, 'file' => $f, 'version' => $m[1]];
        }
    }
    usort($list, fn($a, $b) => version_compare($b['version'], $a['version']) ?: strcmp($a['file'], $b['file']));
    return $list;
}

/** --version 출력에서 버전을 읽는다. 실행되지 않으면 null. */
function cloc_run_version(array $cmd): ?string
{
    $r = run_process([...$cmd, '--version'], ENV_CHECK_TIMEOUT_SEC, cloc_env());
    if ($r['exitCode'] !== 0 || !preg_match('/^\s*(\d+\.\d+(?:\.\d+)?)\s*$/m', $r['stdout'], $m)) {
        return null;
    }
    return $m[1];
}

/**
 * 쓸 cloc 실행 파일을 고른다: 버전이 가장 높은 것부터 --version 을 실행해 보고 처음으로 되는 것.
 * 요청마다 한 번만 확인한다 ($dir 을 바꾸면 다시 확인 — 테스트용).
 * @param 'exe'|'pl' $kind
 * @return array{path: string, file: string, version: string, tried: list<string>}|null  tried: 실행되지 않아 건너뛴 파일
 */
function cloc_select(string $kind, string $dir = CTOOL_DIR): ?array
{
    static $cache = [];
    $key = $kind . '|' . $dir;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $tried = [];
    $found = null;
    if ($kind === 'exe' && PHP_OS_FAMILY !== 'Windows') {
        return $cache[$key] = null;
    }
    foreach (cloc_candidates($kind, $dir) as $c) {
        $v = cloc_run_version($kind === 'exe' ? [$c['path']] : [PERL_BIN, $c['path']]);
        if ($v !== null) {
            $found = ['path' => $c['path'], 'file' => $c['file'], 'version' => $v, 'tried' => $tried];
            break;
        }
        $tried[] = $c['file'];
        if ($kind === 'pl') {
            break;   // Perl 이 없으면 다른 버전도 안 되므로 더 시도하지 않는다
        }
    }
    return $cache[$key] = $found;
}

/** 선택된 cloc exe 경로 (없으면 null). */
function cloc_exe_path(): ?string
{
    return cloc_select('exe')['path'] ?? null;
}

/** @param 'exe'|'pl' $runner */
function cloc_command(string $runner, array $args): array
{
    $sel = cloc_select($runner);
    if ($sel === null) {
        throw new ApiError('cloc 실행 파일을 찾을 수 없습니다. ctool 폴더에 cloc-<버전>.exe 를 넣어 주세요.', 500);
    }
    return $runner === 'exe' ? [$sel['path'], ...$args] : [PERL_BIN, $sel['path'], ...$args];
}

/** Perl 대체 실행이 가능하면 그 cloc 버전. */
function cloc_pl_version(): ?string
{
    return cloc_select('pl')['version'] ?? null;
}

/**
 * 사용할 실행기를 정한다: exe 가 되면 exe, 아니면 pl.
 * @return array{tool: string, version: string, runner: string, file: string}
 */
function cloc_detect_engine(): array
{
    $exe = cloc_select('exe');
    if ($exe !== null) {
        return ['tool' => 'cloc', 'version' => $exe['version'], 'runner' => 'exe', 'file' => $exe['file']];
    }
    $pl = cloc_select('pl');
    if ($pl !== null) {
        return ['tool' => 'cloc', 'version' => $pl['version'], 'runner' => 'pl', 'file' => $pl['file']];
    }
    throw new ApiError('cloc을 실행할 수 없습니다. ctool 폴더의 cloc exe 가 동작하지 않고 Perl 대체 실행도 불가능합니다. api/env.php 의 점검 결과를 확인해 주세요.', 500);
}

/**
 * 폴더 하나에 cloc 을 파일별(--by-file)로 실행해 언어별 수치와 파일 목록으로 정리한다.
 * 파일별 값을 언어별로 합친 결과는 cloc 의 기본(언어별) 출력과 같다 (tests/run.php 에서 비교).
 * 파일 경로가 상대 경로로 나오도록 작업 폴더를 $dir 로 두고 '.' 을 넘긴다.
 *
 * @param list<string> $excludeDirs 검증된 제외 폴더
 * @param list<string> $excludeExts 검증된 제외 확장자
 * @return array{ok: bool, error?: string, timedOut?: bool, languages: list<array>, files: list<array>, totals: array, warnings: list<string>, ignored: array}
 *         files: [{ path: 'sub/a.js'($dir 기준, / 구분), language, code, comment, blank }] — 응답이나 history 로는 내보내지 않는다
 */
function cloc_run(string $dir, array $excludeDirs, array $excludeExts, string $runner, int $timeoutSec, string $ignoredFile, array $excludeFiles = []): array
{
    // 제외 규칙은 호출하는 쪽에서 검증하지만, 셸을 거치지 않더라도 한 번 더 확인한다.
    $excludeDirs = validate_rule_list($excludeDirs, '제외 폴더');
    $excludeExts = validate_rule_list($excludeExts, '제외 확장자');
    $excludeFiles = validate_rule_list($excludeFiles, '제외 파일');

    $args = ['--json', '--quiet', '--by-file'];
    if ($excludeDirs !== []) {
        $args[] = '--exclude-dir=' . implode(',', $excludeDirs);
    }
    if ($excludeExts !== []) {
        $args[] = '--exclude-ext=' . implode(',', $excludeExts);
    }
    if ($excludeFiles !== []) {
        $args[] = '--not-match-f=' . cloc_file_regex($excludeFiles);
    }
    $args[] = '--ignored=' . $ignoredFile;
    $args[] = '.';

    $empty = ['languages' => [], 'files' => [], 'totals' => cloc_sum([]), 'warnings' => [], 'ignored' => ['count' => 0, 'reasons' => []]];
    $r = run_process(cloc_command($runner, $args), $timeoutSec, cloc_env(), $dir);
    $warnings = cloc_summarize_stderr($r['stderr'], $dir);

    if (!$r['started']) {
        return ['ok' => false, 'error' => 'cloc을 시작하지 못했습니다.'] + $empty;
    }
    if ($r['timedOut']) {
        return ['ok' => false, 'timedOut' => true, 'error' => '제한 시간(' . $timeoutSec . '초)을 넘겨 측정을 중단했습니다. 컴포넌트를 나누거나 config.php 의 CLOC_TIMEOUT_SEC 를 늘려 주세요.', 'warnings' => $warnings] + $empty;
    }

    $out = trim($r['stdout']);
    $ignored = cloc_summarize_ignored($ignoredFile);
    if ($out === '' || $out === '{}') {
        // 코드 파일이 없다: 실패가 아니라 0 이다.
        if ($r['exitCode'] !== 0) {
            return ['ok' => false, 'error' => 'cloc이 오류로 끝났습니다 (종료 코드 ' . $r['exitCode'] . ').' . ($warnings ? ' ' . $warnings[0] : ''), 'warnings' => $warnings] + $empty;
        }
        return ['ok' => true, 'languages' => [], 'files' => [], 'totals' => cloc_sum([]), 'warnings' => $warnings, 'ignored' => $ignored];
    }

    $json = json_decode($out, true);
    if (!is_array($json)) {
        return ['ok' => false, 'error' => 'cloc 출력(JSON)을 해석하지 못했습니다 (종료 코드 ' . var_export($r['exitCode'], true) . ').', 'warnings' => $warnings] + $empty;
    }
    $files = cloc_files_from_json($json);
    $languages = cloc_group($files, fn(array $f): string => $f['language'], 'language');
    return ['ok' => true, 'languages' => $languages, 'files' => $files, 'totals' => cloc_sum($languages), 'warnings' => $warnings, 'ignored' => $ignored];
}

/**
 * 제외 파일 이름 패턴(검증된 [A-Za-z0-9._*-] 값)을 cloc --not-match-f 용 Perl 정규식으로 바꾼다.
 * 파일 이름 전체와 비교하며 대소문자를 구분한다. '*' 는 아무 글자나 여러 개.
 */
function cloc_file_regex(array $patterns): string
{
    $parts = array_map(fn(string $p): string => str_replace(['\*'], ['.*'], preg_quote($p, '/')), $patterns);
    return '^(' . implode('|', $parts) . ')$';
}

/** cloc --json --by-file 출력을 파일 목록으로 바꾼다. 경로는 './' 를 뗀 / 구분 상대 경로다. */
function cloc_files_from_json(array $json): array
{
    $files = [];
    foreach ($json as $path => $row) {
        if ($path === 'header' || $path === 'SUM' || !is_array($row) || !isset($row['language'])) {
            continue;
        }
        $p = str_replace('\\', '/', (string)$path);
        $p = preg_replace('#^(\./)+#', '', $p);
        $files[] = [
            'path'     => $p,
            'language' => (string)$row['language'],
            'code'     => max(0, (int)($row['code'] ?? 0)),
            'comment'  => max(0, (int)($row['comment'] ?? 0)),
            'blank'    => max(0, (int)($row['blank'] ?? 0)),
        ];
    }
    return $files;
}

/**
 * 파일 목록을 $keyOf 가 돌려주는 이름별로 합친다 → [{ $field: 이름, files, code, comment, blank }] (코드 줄 수 내림차순).
 * $limit 을 넘는 항목은 마지막 한 줄 '(그 외 N개)' 로 합친다.
 */
function cloc_group(array $files, callable $keyOf, string $field = 'name', int $limit = 0): array
{
    $groups = [];
    foreach ($files as $f) {
        $k = (string)$keyOf($f);
        $g = $groups[$k] ?? [$field => $k, 'files' => 0, 'code' => 0, 'comment' => 0, 'blank' => 0];
        $g['files']++;
        $g['code'] += $f['code'];
        $g['comment'] += $f['comment'];
        $g['blank'] += $f['blank'];
        $groups[$k] = $g;
    }
    $list = array_values($groups);
    usort($list, fn($a, $b) => [$b['code'], $a[$field]] <=> [$a['code'], $b[$field]]);   // 코드 줄 수 내림차순, 같으면 이름순 (cloc_languages_from_json 과 같은 순서)
    if ($limit > 0 && count($list) > $limit) {
        $rest = array_splice($list, $limit - 1);
        $other = [$field => '(그 외 ' . count($rest) . '개)', 'files' => 0, 'code' => 0, 'comment' => 0, 'blank' => 0];
        foreach ($rest as $r) {
            foreach (['files', 'code', 'comment', 'blank'] as $m) {
                $other[$m] += $r[$m];
            }
        }
        $list[] = $other;
    }
    return $list;
}

/** cloc --json 출력에서 header, SUM 을 뺀 항목을 { language, files, code, comment, blank } 로 바꾼다. */
function cloc_languages_from_json(array $json): array
{
    $list = [];
    foreach ($json as $lang => $row) {
        if ($lang === 'header' || $lang === 'SUM' || !is_array($row)) {
            continue;
        }
        $list[] = [
            'language' => (string)$lang,
            'files'    => max(0, (int)($row['nFiles'] ?? 0)),
            'code'     => max(0, (int)($row['code'] ?? 0)),
            'comment'  => max(0, (int)($row['comment'] ?? 0)),
            'blank'    => max(0, (int)($row['blank'] ?? 0)),
        ];
    }
    usort($list, fn($a, $b) => [$b['code'], $a['language']] <=> [$a['code'], $b['language']]);
    return $list;
}

function cloc_sum(array $languages): array
{
    $t = ['files' => 0, 'code' => 0, 'comment' => 0, 'blank' => 0];
    foreach ($languages as $row) {
        foreach ($t as $k => $_) {
            $t[$k] += $row[$k];
        }
    }
    return $t;
}

/** 서버 경로가 응답에 나가지 않도록 작업 폴더 경로를 지운다. */
function cloc_strip_paths(string $text, string $dir): string
{
    $variants = [];
    foreach ([$dir, WORK_DIR] as $p) {
        $variants[] = $p;
        $variants[] = str_replace('\\', '/', $p);
        $variants[] = str_replace('/', '\\', $p);
    }
    usort($variants, fn($a, $b) => strlen($b) <=> strlen($a));
    return str_ireplace($variants, '…', $text);
}

/** 표준 오류의 첫 몇 줄을 요약한다. */
function cloc_summarize_stderr(string $stderr, string $dir, int $maxLines = 5): array
{
    $lines = [];
    foreach (preg_split('/\R/', $stderr) ?: [] as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if (!preg_match('//u', $line)) {
            $line = function_exists('mb_convert_encoding') ? mb_convert_encoding($line, 'UTF-8', 'CP949') : $line;
        }
        $lines[] = utf8_truncate(cloc_strip_paths($line, $dir), 200);
        if (count($lines) >= $maxLines) {
            break;
        }
    }
    return $lines;
}

/**
 * --ignored 파일을 이유별 개수로 요약한다 (파일 경로는 돌려주지 않음).
 * --json 과 함께 쓰면 cloc 은 이 파일도 JSON 배열([{file, reason}])로 쓴다. 텍스트("경로:  이유")도 읽는다.
 * 사용자가 제외 규칙으로 뺀 항목은 '인식 불가' 가 아니므로 세지 않는다.
 */
function cloc_summarize_ignored(string $file): array
{
    $raw = @file_get_contents($file);
    if ($raw === false || trim($raw) === '') {
        return ['count' => 0, 'reasons' => []];
    }
    $rawReasons = [];
    $json = json_decode($raw, true);
    if (is_array($json)) {
        foreach ($json as $row) {
            if (is_array($row) && is_string($row['reason'] ?? null)) {
                $rawReasons[] = $row['reason'];
            }
        }
    } else {
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $pos = strpos($line, ':  ');
            if ($pos !== false) {
                $rawReasons[] = substr($line, $pos + 3);
            }
        }
    }

    $reasons = [];
    $count = 0;
    foreach ($rawReasons as $reason) {
        $reason = preg_replace('/\s*\(#\d+\)$/', '', trim($reason));
        if (stripos($reason, 'duplicate') !== false) {
            $reason = 'duplicate file';
        } elseif (stripos($reason, '--exclude') !== false || stripos($reason, 'excluded') !== false
            || str_contains($reason, 'Not_Code_') || stripos($reason, 'match-f') !== false) {
            // --exclude-ext 값과 cloc 이 원래 코드가 아니라고 아는 확장자(이미지 등)는 '인식 불가' 가 아니다.
            continue;
        }
        $reason = utf8_truncate(trim(preg_replace('#\s+\S*[/\\\\]\S*#', '', $reason)), 80);
        $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
        $count++;
    }
    arsort($reasons);
    $out = [];
    foreach (array_slice($reasons, 0, 10, true) as $reason => $n) {
        $out[] = ['reason' => (string)$reason, 'count' => $n];
    }
    return ['count' => $count, 'reasons' => $out];
}
