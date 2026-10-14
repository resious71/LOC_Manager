<?php
declare(strict_types=1);

/*
 * 제외 규칙 저장소 (관리 탭의 '제외 규칙 관리').
 * settings/exclude_rules.json 에 규칙 목록을 저장한다. 파일이 없으면 config.php 의 DEFAULT_EXCLUDE_* 로 시작한다.
 * 사용 중(enabled)인 규칙이 LOC 측정의 기본 제외 규칙이 된다 (env.php 가 화면에 알려주고, measure 가 기본값으로 쓴다).
 * api/rules.php, api/env.php, api/jobs.php, tests/run.php 가 사용하며 직접 호출하지 않는다 (api/.htaccess 로 차단).
 *
 * 규칙: { id, type: dir|ext|file, pattern, note, enabled, createdAt, updatedAt }
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'common.php';

const RULE_TYPES = ['dir' => '제외 폴더', 'ext' => '제외 확장자', 'file' => '제외 파일'];
const RULES_MAX = 500;                // 규칙 개수 상한
const RULE_NOTE_MAX_CHARS = 200;      // 설명 최대 길이 (글자)
const RULE_ID_PATTERN = '/^[a-f0-9]{12}$/';

function rules_now(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone(APP_TIMEZONE)))->format(DATE_ATOM);
}

/** config.php 기본값으로 만든 처음 규칙 목록 */
function rules_seed(): array
{
    $now = rules_now();
    $rules = [];
    foreach (['dir' => DEFAULT_EXCLUDE_DIRS, 'ext' => DEFAULT_EXCLUDE_EXTS, 'file' => DEFAULT_EXCLUDE_FILES] as $type => $list) {
        foreach ($list as $pattern) {
            $rules[] = ['id' => bin2hex(random_bytes(6)), 'type' => $type, 'pattern' => $pattern, 'note' => 'config.php 기본값',
                'enabled' => true, 'createdAt' => $now, 'updatedAt' => $now];
        }
    }
    return $rules;
}

/** 저장된 규칙 목록. 파일이 없으면 기본값. 손상되었으면 ApiError. */
function rules_load(string $file = RULES_FILE): array
{
    if (!is_file($file)) {
        return rules_seed();
    }
    $raw = @file_get_contents($file);
    $data = $raw === false ? null : json_decode((string)preg_replace('/^\xEF\xBB\xBF/', '', $raw), true);
    if (!is_array($data) || !is_array($data['rules'] ?? null)) {
        throw new ApiError('제외 규칙 파일(settings/exclude_rules.json)이 손상되어 읽을 수 없습니다. 관리 탭에서 기본값으로 초기화하거나 파일을 고쳐 주세요.', 500);
    }
    return array_values(array_filter($data['rules'], 'is_array'));
}

/**
 * 잠금을 잡고 규칙 목록을 읽어 $fn 으로 바꾼 뒤 저장한다 (동시 요청에도 내용이 섞이지 않음).
 * $fn(array $rules): array{0: array $newRules, 1: mixed $result}
 */
function rules_update(callable $fn, string $file = RULES_FILE): mixed
{
    ensure_dir(dirname($file));
    $fh = @fopen($file, 'c+');
    if ($fh === false) {
        throw new ApiError('제외 규칙을 저장할 수 없습니다. settings 폴더 쓰기 권한을 확인해 주세요.', 500);
    }
    try {
        flock($fh, LOCK_EX);
        $raw = (string)stream_get_contents($fh);
        if (trim($raw) === '') {
            $rules = rules_seed();
        } else {
            $data = json_decode((string)preg_replace('/^\xEF\xBB\xBF/', '', $raw), true);
            if (!is_array($data) || !is_array($data['rules'] ?? null)) {
                throw new ApiError('제외 규칙 파일(settings/exclude_rules.json)이 손상되어 고칠 수 없습니다. 기본값으로 초기화해 주세요.', 500);
            }
            $rules = array_values(array_filter($data['rules'], 'is_array'));
        }
        [$rules, $result] = $fn($rules);
        $json = json_encode(['rules' => array_values($rules)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, $json . "\n");
        fflush($fh);
        return $result;
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

/** 사용 중인 규칙 → { dirs, exts, files } (LOC 측정의 기본 제외 규칙) */
function rules_defaults(array $rules): array
{
    $out = ['dirs' => [], 'exts' => [], 'files' => []];
    $key = ['dir' => 'dirs', 'ext' => 'exts', 'file' => 'files'];
    foreach ($rules as $r) {
        if (!empty($r['enabled']) && isset($key[$r['type'] ?? '']) && is_string($r['pattern'] ?? null)
            && !in_array($r['pattern'], $out[$key[$r['type']]], true)) {
            $out[$key[$r['type']]][] = $r['pattern'];
        }
    }
    return $out;
}

/** 현재 기본 제외 규칙. 규칙 파일을 읽을 수 없으면 config.php 기본값. */
function current_default_excludes(): array
{
    try {
        return rules_defaults(rules_load());
    } catch (Throwable) {
        return ['dirs' => DEFAULT_EXCLUDE_DIRS, 'exts' => DEFAULT_EXCLUDE_EXTS, 'files' => DEFAULT_EXCLUDE_FILES];
    }
}

/** 요청 값 → 검증된 규칙 필드. $partial 이면 보낸 필드만 돌려준다 (수정용). */
function rule_fields(array $in, bool $partial): array
{
    $out = [];
    if (!$partial || array_key_exists('type', $in)) {
        if (!is_string($in['type'] ?? null) || !isset(RULE_TYPES[$in['type']])) {
            throw new ApiError('규칙 종류는 dir(제외 폴더), ext(제외 확장자), file(제외 파일) 중 하나여야 합니다.');
        }
        $out['type'] = $in['type'];
    }
    if (!$partial || array_key_exists('pattern', $in)) {
        $p = is_string($in['pattern'] ?? null) ? trim($in['pattern']) : '';
        if ($p === '') {
            throw new ApiError('패턴을 입력해 주세요.');
        }
        $out['pattern'] = $p;
    }
    if (array_key_exists('note', $in) || !$partial) {
        $note = is_string($in['note'] ?? null) ? $in['note'] : '';
        $out['note'] = trim(utf8_truncate((string)preg_replace('/[\x00-\x1F\x7F]/u', ' ', preg_match('//u', $note) ? $note : ''), RULE_NOTE_MAX_CHARS));
    }
    if (array_key_exists('enabled', $in) || !$partial) {
        $out['enabled'] = array_key_exists('enabled', $in) ? filter_var($in['enabled'], FILTER_VALIDATE_BOOL) : true;
    }
    return $out;
}

/** 종류에 맞게 패턴을 정리하고 검증한다 (확장자는 앞의 '.', '*.' 를 뗌). */
function rule_normalize_pattern(string $type, string $pattern): string
{
    if ($type === 'ext') {
        $pattern = (string)preg_replace('/^\*?\./', '', $pattern);
    }
    validate_rule_list([$pattern], RULE_TYPES[$type]);
    return $pattern;
}

function rule_find_duplicate(array $rules, string $type, string $pattern, ?string $exceptId = null): ?array
{
    foreach ($rules as $r) {
        // 제외 파일은 대소문자를 구분해 비교하고, 폴더·확장자는 구분하지 않는다 (측정할 때의 규칙과 같다)
        $same = $type === 'file' ? (string)($r['pattern'] ?? '') === $pattern : strcasecmp((string)($r['pattern'] ?? ''), $pattern) === 0;
        if (($r['id'] ?? null) !== $exceptId && ($r['type'] ?? null) === $type && $same) {
            return $r;
        }
    }
    return null;
}

function rule_check_id(mixed $id): string
{
    if (!is_string($id) || !preg_match(RULE_ID_PATTERN, $id)) {
        throw new ApiError('규칙 ID 형식이 올바르지 않습니다.');
    }
    return $id;
}

function rule_create(array $in, string $file = RULES_FILE): array
{
    $f = rule_fields($in, false);
    $f['pattern'] = rule_normalize_pattern($f['type'], $f['pattern']);
    return rules_update(function (array $rules) use ($f): array {
        if (count($rules) >= RULES_MAX) {
            throw new ApiError('규칙이 너무 많습니다. 최대 ' . RULES_MAX . '개까지 저장할 수 있습니다.', 413);
        }
        if (rule_find_duplicate($rules, $f['type'], $f['pattern'])) {
            throw new ApiError('이미 같은 ' . RULE_TYPES[$f['type']] . ' 규칙(' . $f['pattern'] . ')이 있습니다.', 409);
        }
        $now = rules_now();
        $rule = ['id' => bin2hex(random_bytes(6))] + $f + ['createdAt' => $now, 'updatedAt' => $now];
        $rules[] = $rule;
        return [$rules, $rule];
    }, $file);
}

function rule_update(mixed $id, array $in, string $file = RULES_FILE): array
{
    $id = rule_check_id($id);
    $f = rule_fields($in, true);
    if ($f === []) {
        throw new ApiError('바꿀 내용이 없습니다.');
    }
    return rules_update(function (array $rules) use ($id, $f): array {
        foreach ($rules as $i => $r) {
            if (($r['id'] ?? null) !== $id) {
                continue;
            }
            $next = array_merge($r, $f);
            $next['pattern'] = rule_normalize_pattern($next['type'], (string)$next['pattern']);
            if (rule_find_duplicate($rules, $next['type'], $next['pattern'], $id)) {
                throw new ApiError('이미 같은 ' . RULE_TYPES[$next['type']] . ' 규칙(' . $next['pattern'] . ')이 있습니다.', 409);
            }
            $next['updatedAt'] = rules_now();
            $rules[$i] = $next;
            return [$rules, $next];
        }
        throw new ApiError('해당 규칙을 찾을 수 없습니다. 다른 사람이 지웠을 수 있으니 목록을 새로고침해 주세요.', 404);
    }, $file);
}

function rule_delete(mixed $id, string $file = RULES_FILE): string
{
    $id = rule_check_id($id);
    return rules_update(function (array $rules) use ($id): array {
        $left = array_values(array_filter($rules, fn($r) => ($r['id'] ?? null) !== $id));
        if (count($left) === count($rules)) {
            throw new ApiError('해당 규칙을 찾을 수 없습니다. 다른 사람이 지웠을 수 있으니 목록을 새로고침해 주세요.', 404);
        }
        return [$left, $id];
    }, $file);
}

/** config.php 기본값으로 되돌린다. */
function rules_reset(string $file = RULES_FILE): array
{
    return rules_update(fn(array $rules): array => [$seed = rules_seed(), $seed], $file);
}
