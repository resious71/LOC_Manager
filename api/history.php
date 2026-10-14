<?php
declare(strict_types=1);

/*
 * history API
 *   POST                     컴포넌트 결과 1건 저장 → { file, component, run, date }
 *   GET                      이력 목록 (최신순)
 *   GET ?file=이름.json      이력 1건
 *   PUT ?file=이름.json      { componentVersion } 컴포넌트 버전 수정 (컴포넌트 이름은 바꿀 수 없음)
 *   DELETE ?file=이름.json   삭제 (history/_deleted/ 로 옮긴다. 되살리려면 파일을 history/ 로 다시 옮기면 된다)
 * 수정·삭제는 관리 탭 인증(api/auth.php)을 마친 세션만 할 수 있다 (아니면 401). 저장·조회는 누구나.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'common.php';

const LANGUAGES_MAX = 1000;       // 한 결과에 담을 수 있는 언어 항목 수
const EXCLUDES_MAX = 200;         // 제외 규칙 항목 수
const LANGUAGE_NAME_MAX_CHARS = 100;
const HISTORY_DELETED_DIR = HISTORY_DIR . DIRECTORY_SEPARATOR . '_deleted';   // 삭제한 이력을 옮겨 두는 곳
const COMPONENT_VERSION_MAX_CHARS = 50;   // 컴포넌트 버전 최대 길이 (글자)

run_api(function (): void {
    $method = require_method('GET', 'POST', 'PUT', 'DELETE');
    ensure_dir(HISTORY_DIR);

    if ($method === 'POST') {
        json_response(save_history(read_json_body()), 201);
    }
    if ($method === 'PUT') {
        require_admin();   // 수정·삭제는 관리 탭 인증 뒤에만 (저장·조회는 누구나)
        json_response(update_history($_GET['file'] ?? null, read_json_body()));
    }
    if ($method === 'DELETE') {
        require_admin();
        json_response(['deleted' => delete_history($_GET['file'] ?? null)]);
    }
    if (isset($_GET['file'])) {
        json_response(read_history_file($_GET['file']));
    }
    json_response(['items' => list_history()]);
});

// ---------------------------------------------------------------- 저장

/** 요청 본문을 prd.md 4.1 형식으로 정리해 새 파일로 저장한다. */
function save_history(array $body): array
{
    $component = sanitize_component_name($body['component'] ?? '');
    $languages = normalize_languages($body['languages'] ?? null);
    $now = new DateTimeImmutable('now', new DateTimeZone(APP_TIMEZONE));
    $ymd = $now->format('ymd');

    $record = [
        'component'  => $component,
        'componentVersion' => clean_label($body['componentVersion'] ?? '', COMPONENT_VERSION_MAX_CHARS),   // 사용자가 적은 컴포넌트 버전 (없으면 '')
        'run'        => 0, // 파일명이 정해지면 채운다
        'date'       => $now->format('Y-m-d'),
        'measuredAt' => $now->format(DATE_ATOM),
        'timeZone'   => APP_TIMEZONE,
        'engine'     => normalize_engine($body['engine'] ?? null),
        'source'     => normalize_source($body['source'] ?? null),
        'excludes'   => [
            'dirs' => validate_rule_list($body['excludes']['dirs'] ?? [], '제외 폴더', EXCLUDES_MAX),
            'exts' => validate_rule_list($body['excludes']['exts'] ?? [], '제외 확장자', EXCLUDES_MAX),
            'files' => validate_rule_list($body['excludes']['files'] ?? [], '제외 파일', EXCLUDES_MAX),
        ],
        'totals'     => sum_totals($languages),
        'languages'  => $languages,
    ];

    // 한 번의 측정(하위 폴더별이면 여러 컴포넌트)에서 나온 결과라는 표시: { id, size, mode }
    $batch = normalize_batch($body['batch'] ?? null);
    if ($batch !== null) {
        $record['batch'] = $batch;
    }

    // 하위 폴더별(컴포넌트 안 한 단계 폴더 이름)·파일 타입(확장자)별 합계. 파일 이름과 경로는 저장하지 않는다.
    // 보내지 않은 경우(예전 화면 등)에는 키를 만들지 않아, '정보 없음'과 '0건'을 구분할 수 있게 한다.
    foreach (['folders', 'exts'] as $key) {
        if (($body[$key] ?? null) !== null) {
            $record[$key] = normalize_breakdown($body[$key], $key, 'name', false);
        }
    }

    for ($attempt = 0; $attempt < HISTORY_SAVE_MAX_RETRY; $attempt++) {
        $run = next_run_number($component, $ymd);
        $file = $component . '_' . $ymd . $run . '.json';
        $path = HISTORY_DIR . DIRECTORY_SEPARATOR . $file;

        // 'x': 파일이 이미 있으면 실패한다. 동시에 같은 번호를 잡은 요청은 여기서 걸러져 번호를 다시 계산한다.
        $fh = @fopen($path, 'x');
        if ($fh === false) {
            if (!is_writable(HISTORY_DIR)) {
                throw new ApiError('history 폴더에 쓸 수 없습니다. 폴더 쓰기 권한을 확인해 주세요.', 500);
            }
            usleep(random_int(1_000, 20_000));
            continue;
        }
        $record['run'] = $run;
        $json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        try {
            flock($fh, LOCK_EX);
            $written = fwrite($fh, $json . "\n");
            fflush($fh);
            flock($fh, LOCK_UN);
        } finally {
            fclose($fh);
        }
        if ($written !== strlen($json) + 1) {
            @unlink($path);
            throw new ApiError('history 파일을 끝까지 쓰지 못했습니다. 디스크 공간을 확인해 주세요.', 500);
        }
        return ['file' => $file, 'component' => $component, 'run' => $run, 'date' => $record['date']];
    }
    throw new ApiError('동시에 저장하는 요청이 많아 파일명을 정하지 못했습니다. 잠시 후 다시 저장해 주세요.', 503);
}

/** history 폴더에서 같은 컴포넌트·같은 날짜 파일의 최대 회차 + 1 을 구한다. */
function next_run_number(string $component, string $ymd): int
{
    // Windows 파일 시스템은 대소문자를 구분하지 않으므로 비교도 구분하지 않는다.
    $pattern = '/^' . preg_quote($component, '/') . '_' . $ymd . '([1-9][0-9]*)\.json$/iu';
    $max = 0;
    foreach (scandir(HISTORY_DIR) ?: [] as $name) {
        if (preg_match($pattern, $name, $m)) {
            $max = max($max, (int)$m[1]);
        }
    }
    return $max + 1;
}

/** 0 이상 정수로 정리한다. 숫자가 아니면 0. 합계가 넘치지 않도록 상한을 둔다. */
function non_negative_int(mixed $v): int
{
    $cap = 1_000_000_000_000;
    if (is_int($v)) {
        return min(max(0, $v), $cap);
    }
    if (is_float($v) && is_finite($v)) {
        return (int)floor(min(max(0.0, $v), $cap));
    }
    if (is_string($v) && is_numeric(trim($v))) {
        return non_negative_int(trim($v) + 0);
    }
    return 0;
}

/** 표시용 짧은 문자열로 정리한다 (제어 문자 제거, 길이 제한). */
function clean_label(mixed $v, int $maxChars): string
{
    if (!is_string($v) || !preg_match('//u', $v)) {
        return '';
    }
    return trim(utf8_truncate(preg_replace('/[\x00-\x1F\x7F]/u', '', $v), $maxChars));
}

/** languages 배열을 { language, files, code, comment, blank } 로 정리한다. 같은 언어는 합친다. */
function normalize_languages(mixed $languages): array
{
    return normalize_breakdown($languages, 'languages', 'language', true);
}

/**
 * 집계 목록 { <$field>: 이름, files, code, comment, blank }[] 을 정리한다.
 * languages 외에 folders(하위 폴더별), exts(파일 타입별)에도 쓴다. 같은 이름은 합친다.
 */
function normalize_breakdown(mixed $list, string $label, string $field, bool $required): array
{
    if ($list === null && !$required) {
        return [];
    }
    if (!is_array($list) || !array_is_list($list)) {
        throw new ApiError($label . ' 는 배열이어야 합니다.');
    }
    if (count($list) > LANGUAGES_MAX) {
        throw new ApiError($label . ' 항목이 너무 많습니다. 최대 ' . LANGUAGES_MAX . '개입니다.');
    }
    $merged = [];
    foreach ($list as $i => $row) {
        if (!is_array($row)) {
            throw new ApiError($label . '[' . $i . '] 항목이 객체가 아닙니다.');
        }
        $name = clean_label($row[$field] ?? '', LANGUAGE_NAME_MAX_CHARS);
        if ($name === '') {
            throw new ApiError($label . '[' . $i . '] 항목에 이름이 없습니다.');
        }
        $item = $merged[$name] ?? [$field => $name, 'files' => 0, 'code' => 0, 'comment' => 0, 'blank' => 0];
        foreach (['files', 'code', 'comment', 'blank'] as $k) {
            $item[$k] += non_negative_int($row[$k] ?? 0);
        }
        $merged[$name] = $item;
    }
    $out = array_values($merged);
    usort($out, fn($a, $b) => [$b['code'], $a[$field]] <=> [$a['code'], $b[$field]]);
    return $out;
}
/** 측정 묶음 { id: 측정 작업 ID(소문자 16진수 24자), size: 그 측정의 컴포넌트 수, mode: byFolder|single, parent: 상위 폴더 이름 }. 형식이 틀리면 null. */
function normalize_batch(mixed $b): ?array
{
    if (!is_array($b) || !is_string($b['id'] ?? null) || !preg_match('/^[a-f0-9]{24}$/', $b['id'])) {
        return null;
    }
    return [
        'id'   => $b['id'],
        'size' => max(1, min(100000, non_negative_int($b['size'] ?? 1))),
        'mode' => in_array($b['mode'] ?? null, ['byFolder', 'single'], true) ? $b['mode'] : 'single',
        'parent' => clean_label($b['parent'] ?? '', 120),   // 컴포넌트들을 담은 상위 폴더 이름 (표시용)
    ];
}

/** totals 는 요청 값을 쓰지 않고 languages 합계로 다시 계산한다. */
function sum_totals(array $languages): array
{
    $t = ['files' => 0, 'code' => 0, 'comment' => 0, 'blank' => 0];
    foreach ($languages as $row) {
        foreach ($t as $k => $_) {
            $t[$k] += $row[$k];
        }
    }
    return $t;
}

function normalize_engine(mixed $engine): array
{
    $engine = is_array($engine) ? $engine : [];
    $version = is_string($engine['version'] ?? null) && preg_match('/^[0-9][0-9.]{0,19}$/', $engine['version'])
        ? $engine['version'] : '2.10';
    $runner = in_array($engine['runner'] ?? null, ['exe', 'pl'], true) ? $engine['runner'] : 'unknown';
    return ['tool' => 'cloc', 'version' => $version, 'runner' => $runner];
}

/** source 는 종류와 이름만 남긴다. 이름에 경로가 들어 있으면 마지막 부분만 쓴다. */
function normalize_source(mixed $source): array
{
    $source = is_array($source) ? $source : [];
    $type = in_array($source['type'] ?? null, ['folder', 'zip'], true) ? $source['type'] : 'unknown';
    $name = is_string($source['name'] ?? null) ? clean_label(last_segment($source['name']), 120) : '';
    return ['type' => $type, 'name' => $name];
}

// ---------------------------------------------------------------- 조회

/** 이력 목록을 최신순으로 돌려준다. 읽을 수 없거나 손상된 파일은 건너뛴다. */
function list_history(): array
{
    $items = [];
    foreach (scandir(HISTORY_DIR) ?: [] as $name) {
        if (!preg_match('/^(.+)_(\d{6})([1-9]\d*)\.json$/u', $name)) {
            continue;
        }
        $data = load_json_file(HISTORY_DIR . DIRECTORY_SEPARATOR . $name);
        if ($data === null || !is_string($data['component'] ?? null) || !is_array($data['totals'] ?? null)) {
            continue;
        }
        $items[] = [
            'file'       => $name,
            'component'  => $data['component'],
            'componentVersion' => is_string($data['componentVersion'] ?? null) ? $data['componentVersion'] : '',
            'batchId'   => is_string($data['batch']['id'] ?? null) ? $data['batch']['id'] : '',
            'batchSize' => non_negative_int($data['batch']['size'] ?? 0),
            'batchMode' => is_string($data['batch']['mode'] ?? null) ? $data['batch']['mode'] : '',
            'batchParent' => is_string($data['batch']['parent'] ?? null) ? $data['batch']['parent'] : '',
            'sourceName'  => is_string($data['source']['name'] ?? null) ? $data['source']['name'] : '',
            'clocVersion' => is_string($data['engine']['version'] ?? null) ? $data['engine']['version'] : '',   // 측정에 쓴 cloc 버전
            'clocRunner'  => is_string($data['engine']['runner'] ?? null) ? $data['engine']['runner'] : '',
            'run'        => non_negative_int($data['run'] ?? 0),
            'date'       => is_string($data['date'] ?? null) ? $data['date'] : '',
            'measuredAt' => is_string($data['measuredAt'] ?? null) ? $data['measuredAt'] : '',
            'totals'     => [
                'files'   => non_negative_int($data['totals']['files'] ?? 0),
                'code'    => non_negative_int($data['totals']['code'] ?? 0),
                'comment' => non_negative_int($data['totals']['comment'] ?? 0),
                'blank'   => non_negative_int($data['totals']['blank'] ?? 0),
            ],
        ];
    }
    $ts = static fn(array $i): int => ($t = strtotime($i['measuredAt'])) === false ? 0 : $t;
    usort($items, fn($a, $b) => [$ts($b), $b['date'], $b['run'], $a['component']] <=> [$ts($a), $a['date'], $a['run'], $b['component']]);
    return $items;
}

/** 이력 1건. 파일명은 history 폴더 바로 아래의 .json 만 허용한다. */
function read_history_file(mixed $file): array
{
    $data = load_json_file(history_file_path($file));
    if ($data === null) {
        throw new ApiError('이력 파일이 손상되어 읽을 수 없습니다.', 422);
    }
    return $data;
}

/** 파일명을 검사하고 history 폴더 바로 아래의 실제 경로를 돌려준다 (경로 조작·다른 확장자 거부). */
function history_file_path(mixed $file): string
{
    if (!is_string($file) || $file === '' || strlen($file) > 255 || !preg_match('//u', $file)) {
        throw new ApiError('파일명이 올바르지 않습니다.');
    }
    if (basename($file) !== $file || strpbrk($file, "/\\:\0") !== false || str_starts_with($file, '.')
        || preg_match('/[\x00-\x1F\x7F]/', $file)) {
        throw new ApiError('파일명에 경로를 넣을 수 없습니다.');
    }
    if (!preg_match('/^[^.].*\.json$/', $file)) {
        throw new ApiError('.json 파일만 조회할 수 있습니다.');
    }

    $path = HISTORY_DIR . DIRECTORY_SEPARATOR . $file;
    $real = realpath($path);
    $base = realpath(HISTORY_DIR);
    if ($real === false || !is_file($real)) {
        throw new ApiError('해당 이력 파일이 없습니다.', 404);
    }
    if ($base === false || dirname($real) !== $base) {
        throw new ApiError('파일명이 올바르지 않습니다.');
    }
    return $real;
}

/** 이력 1건 삭제: history/_deleted/<삭제시각>_<파일명> 으로 옮긴다. */
function delete_history(mixed $file): string
{
    $path = history_file_path($file);
    ensure_dir(HISTORY_DELETED_DIR);
    $stamp = (new DateTimeImmutable('now', new DateTimeZone(APP_TIMEZONE)))->format('Ymd_His');
    $dest = HISTORY_DELETED_DIR . DIRECTORY_SEPARATOR . $stamp . '_' . basename($path);
    for ($k = 2; file_exists($dest); $k++) {
        $dest = HISTORY_DELETED_DIR . DIRECTORY_SEPARATOR . $stamp . '_' . $k . '_' . basename($path);
    }
    if (!@rename($path, $dest)) {
        throw new ApiError('이력 파일을 삭제하지 못했습니다. history 폴더 쓰기 권한을 확인해 주세요.', 500);
    }
    return (string)$file;
}

/**
 * 이력 1건 수정: 컴포넌트 버전만 고칠 수 있다.
 * 컴포넌트 이름은 바꿀 수 없다 (파일 이름·추이 묶음의 기준이므로). 측정 수치·시각·그룹 정보도 그대로.
 * @return array{file: string, component: string, componentVersion: string, run: int, date: string}
 */
function update_history(mixed $file, array $body): array
{
    $path = history_file_path($file);
    $data = load_json_file($path);
    if ($data === null) {
        throw new ApiError('이력 파일이 손상되어 수정할 수 없습니다.', 422);
    }
    if (array_key_exists('component', $body) && $body['component'] !== ($data['component'] ?? null)) {
        throw new ApiError('컴포넌트 이름은 바꿀 수 없습니다. 컴포넌트 버전만 고칠 수 있습니다.');
    }
    if (!array_key_exists('componentVersion', $body)) {
        throw new ApiError('바꿀 내용이 없습니다.');
    }
    $data['componentVersion'] = clean_label($body['componentVersion'] ?? '', COMPONENT_VERSION_MAX_CHARS);

    $fh = fopen($path, 'c+');
    if ($fh === false) {
        throw new ApiError('이력 파일을 고칠 수 없습니다. history 폴더 쓰기 권한을 확인해 주세요.', 500);
    }
    try {
        flock($fh, LOCK_EX);
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
        fflush($fh);
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
    return ['file' => basename($path), 'component' => (string)($data['component'] ?? ''),
        'componentVersion' => $data['componentVersion'], 'run' => non_negative_int($data['run'] ?? 0), 'date' => (string)($data['date'] ?? '')];
}

function load_json_file(string $path): ?array
{
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return null;
    }
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw); // UTF-8 BOM 허용
    $data = json_decode($raw, true);
    return is_array($data) && !array_is_list($data) ? $data : null;
}
