<?php
declare(strict_types=1);

/*
 * API 공통 함수: JSON 응답, 요청 본문 읽기, 이름 정리, 외부 프로세스 실행.
 * 이 파일은 다른 API 파일이 require 해서 쓰며, 직접 호출하지 않는다 (api/.htaccess 로 차단).
 */

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config.php';

/** 사용자에게 보여줄 수 있는 문장과 HTTP 상태 코드를 가진 오류. */
final class ApiError extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 400)
    {
        parent::__construct($message);
    }
}

/** JSON 응답을 보내고 종료한다. */
function json_response(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/** 오류 응답 `{ "error": "..." }` 을 보내고 종료한다. */
function json_error(string $message, int $status = 400): never
{
    json_response(['error' => $message], $status);
}

/**
 * 요청 처리 함수를 실행하고, ApiError 는 그 문장으로, 나머지 예외는 일반 문장으로 응답한다.
 * 내부 예외 메시지(경로 등)는 사용자에게 보내지 않는다.
 */
function run_api(callable $handler): never
{
    try {
        $handler();
        json_error('응답이 만들어지지 않았습니다.', 500);
    } catch (ApiError $e) {
        json_error($e->getMessage(), $e->status);
    } catch (Throwable $e) {
        error_log('[LOC_Mgmt] ' . $e);
        json_error('서버 내부 오류가 발생했습니다. 서버 로그를 확인해 주세요.', 500);
    }
}

// ---------------------------------------------------------------- 관리자 세션 (관리 탭 인증, api/auth.php)

const ADMIN_SESSION_NAME = 'LOCMGMTSESSID';   // 같은 서버의 다른 앱(포털 등) 세션과 섞이지 않도록 이름을 따로 둔다

/** 세션을 연다. 쿠키는 브라우저를 닫으면 사라지고(lifetime 0), 이 앱 경로에서만, 스크립트에서 읽을 수 없게 둔다. */
function app_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    // /loc_mgmt/api/x.php → /loc_mgmt/
    $path = rtrim(str_replace('\\', '/', dirname(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/api/x.php')))), '/') . '/';
    session_name(ADMIN_SESSION_NAME);
    session_set_cookie_params(['lifetime' => 0, 'path' => $path, 'httponly' => true, 'samesite' => 'Strict']);
    session_start();
}

const ADMIN_TOKEN_MAX = 50;   // 한 세션에 남겨 두는 페이지 토큰 수 (오래된 것부터 버림)

/**
 * 이 요청이 관리 탭 인증을 마친 페이지에서 왔는지.
 * 인증에 성공하면 페이지마다 토큰을 하나 주고(api/auth.php), 화면은 메모리에만 들고 X-Admin-Token 헤더로 보낸다.
 * 그래서 새로고침·새 탭·브라우저를 다시 열면 토큰이 없어져 다시 비밀번호를 입력해야 한다.
 * 토큰에는 받을 때의 비밀번호 버전이 기록되어, 비밀번호를 바꾸면 그 전에 받은 토큰은 모두 무효가 된다.
 */
function is_admin(): bool
{
    $token = (string)($_SERVER['HTTP_X_ADMIN_TOKEN'] ?? '');
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
        return false;
    }
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'admin_password.php';
    app_session_start();
    $entry = $_SESSION['locmgmt_admin_tokens'][$token] ?? null;
    session_write_close();
    return is_array($entry) && ($entry['pv'] ?? null) === admin_password_version();
}

/** 지금 세션에 관리자 토큰을 하나 만들어 돌려준다 (현재 비밀번호 버전을 함께 기록). */
function issue_admin_token(): string
{
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'admin_password.php';
    app_session_start();
    $token = bin2hex(random_bytes(16));
    $tokens = is_array($_SESSION['locmgmt_admin_tokens'] ?? null) ? $_SESSION['locmgmt_admin_tokens'] : [];
    $tokens[$token] = ['pv' => admin_password_version(), 'at' => time()];
    $_SESSION['locmgmt_admin_tokens'] = array_slice($tokens, -ADMIN_TOKEN_MAX, null, true);
    session_write_close();
    return $token;
}

/** 관리 기능 API 앞에서 호출한다. 인증 전이면 401. */
function require_admin(): void
{
    if (!is_admin()) {
        throw new ApiError('관리자 인증이 필요합니다. 관리 탭에서 비밀번호를 입력해 주세요.', 401);
    }
}

/** 허용된 HTTP 메서드인지 확인하고 메서드를 돌려준다. */
function require_method(string ...$allowed): string
{
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if (!in_array($method, $allowed, true)) {
        header('Allow: ' . implode(', ', $allowed));
        throw new ApiError('허용되지 않는 요청 방식입니다: ' . $method, 405);
    }
    return $method;
}

/** JSON 요청 본문을 읽어 배열로 돌려준다. 5MB(REQUEST_BODY_MAX_BYTES)를 넘으면 거부한다. */
function read_json_body(): array
{
    $limit = REQUEST_BODY_MAX_BYTES;
    $tooLarge = '요청 본문이 너무 큽니다. 최대 ' . intdiv($limit, 1024 * 1024) . 'MB까지 보낼 수 있습니다.';

    $declared = $_SERVER['CONTENT_LENGTH'] ?? $_SERVER['HTTP_CONTENT_LENGTH'] ?? null;
    if ($declared !== null && $declared !== '' && (int)$declared > $limit) {
        throw new ApiError($tooLarge, 413);
    }

    $in = fopen('php://input', 'rb');
    $raw = $in === false ? false : stream_get_contents($in, $limit + 1);
    if ($in !== false) {
        fclose($in);
    }
    if ($raw === false) {
        throw new ApiError('요청 본문을 읽을 수 없습니다.', 400);
    }
    if (strlen($raw) > $limit) {
        throw new ApiError($tooLarge, 413);
    }
    if (trim($raw) === '') {
        throw new ApiError('요청 본문이 비어 있습니다. JSON을 보내 주세요.', 400);
    }

    try {
        $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new ApiError('요청 본문이 올바른 JSON이 아닙니다.', 400);
    }
    if (!is_array($data) || array_is_list($data) && $data !== []) {
        throw new ApiError('요청 본문은 JSON 객체여야 합니다.', 400);
    }
    return $data;
}

/** 문자열 길이(글자 수)를 기준으로 자른다. mbstring 이 없어도 동작한다. */
function utf8_truncate(string $s, int $maxChars): string
{
    if (function_exists('mb_substr')) {
        return mb_substr($s, 0, $maxChars, 'UTF-8');
    }
    preg_match_all('/./us', $s, $m);
    return implode('', array_slice($m[0], 0, $maxChars));
}

/**
 * 컴포넌트명을 파일명에 쓸 수 있게 정리한다.
 * - `\ / : * ? " < > |` 와 제어 문자는 `_` 로 치환
 * - 앞의 `.` 제거, 최대 80자
 * - Windows에서 만들 수 없는 끝의 공백/`.` 과 예약어(CON, NUL, COM1 등)도 처리
 * 정리 후 빈 이름이면 ApiError.
 */
function sanitize_component_name(mixed $name): string
{
    if (!is_string($name)) {
        throw new ApiError('컴포넌트명은 문자열이어야 합니다.');
    }
    if (!preg_match('//u', $name)) {
        throw new ApiError('컴포넌트명이 올바른 UTF-8 문자열이 아닙니다.');
    }
    $s = preg_replace('/[\\\\\/:*?"<>|\x00-\x1F\x7F]/u', '_', $name);
    $s = trim($s);
    $s = ltrim($s, '.');
    $s = utf8_truncate($s, COMPONENT_NAME_MAX_CHARS);
    $s = rtrim($s, ' .');
    if ($s === '') {
        throw new ApiError('컴포넌트명이 비어 있습니다.');
    }
    if (preg_match('/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])$/i', $s)) {
        $s = '_' . $s;
    }
    return $s;
}

/**
 * 제외 규칙 목록(폴더명/확장자)을 검증한다. 영문/숫자/`.`/`_`/`-`/`*` 만 허용하고 나머지는 ApiError.
 * cloc 인자에 그대로 들어가므로 쉼표와 공백도 허용하지 않는다.
 */
function validate_rule_list(mixed $list, string $label, int $max = 200): array
{
    if (!is_array($list) || !array_is_list($list)) {
        throw new ApiError($label . ' 목록은 배열이어야 합니다.');
    }
    if (count($list) > $max) {
        throw new ApiError($label . ' 항목이 너무 많습니다. 최대 ' . $max . '개입니다.');
    }
    $out = [];
    foreach ($list as $v) {
        if (!is_string($v) || !preg_match('/^[A-Za-z0-9._*-]{1,100}$/', $v)) {
            $shown = is_string($v) ? ' ("' . utf8_truncate(preg_replace('/[\x00-\x1F\x7F]/', '', $v), 30) . '")' : '';
            throw new ApiError($label . '에 쓸 수 없는 값이 있습니다' . $shown . '. 영문, 숫자, . _ - * 만 사용할 수 있습니다.');
        }
        $out[$v] = true;
    }
    return array_map('strval', array_keys($out));
}

/** 경로의 마지막 이름. basename() 은 로캘에 따라 한글을 깨뜨릴 수 있어 직접 자른다. */
function last_segment(string $path): string
{
    $path = rtrim(str_replace('\\', '/', $path), '/');
    $pos = strrpos($path, '/');
    return $pos === false ? $path : substr($path, $pos + 1);
}

/** 폴더가 없으면 만든다. */
function ensure_dir(string $dir): void
{
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('폴더를 만들 수 없습니다: ' . $dir);
    }
}

/** php.ini 크기 값(예: "40M")을 바이트로 바꾼다. -1 은 무제한이다. */
function ini_bytes(string|false $value): int
{
    $v = trim((string)$value);
    if ($v === '') {
        return 0;
    }
    $num = (int)$v;
    return match (strtolower(substr($v, -1))) {
        'g' => $num * 1024 * 1024 * 1024,
        'm' => $num * 1024 * 1024,
        'k' => $num * 1024,
        default => $num,
    };
}

/** disable_functions 설정을 고려해 함수 사용 가능 여부를 돌려준다. */
function function_usable(string $name): bool
{
    if (!function_exists($name)) {
        return false;
    }
    $disabled = array_map('trim', explode(',', strtolower((string)ini_get('disable_functions'))));
    return !in_array(strtolower($name), $disabled, true);
}

/**
 * 외부 프로그램을 인자 배열로 실행한다 (셸을 거치지 않음).
 * 표준 출력/오류는 임시 파일로 받아 교착을 피한다 (Windows 파이프는 비차단 읽기가 되지 않음).
 *
 * @param list<string>          $cmd        [실행 파일, 인자...]
 * @param array<string, string> $extraEnv   추가/덮어쓸 환경 변수 (예: TEMP)
 * @param string|null           $cwd        작업 폴더 (null 이면 현재 폴더)
 * @return array{started: bool, exitCode: ?int, stdout: string, stderr: string, timedOut: bool, error: ?string}
 */
function run_process(array $cmd, int $timeoutSec, array $extraEnv = [], ?string $cwd = null): array
{
    $result = ['started' => false, 'exitCode' => null, 'stdout' => '', 'stderr' => '', 'timedOut' => false, 'error' => null];
    if (!function_usable('proc_open')) {
        $result['error'] = 'proc_open 함수가 비활성화되어 있습니다.';
        return $result;
    }

    ensure_dir(WORK_TMP_DIR);
    $outFile = tempnam(WORK_TMP_DIR, 'out');
    $errFile = tempnam(WORK_TMP_DIR, 'err');
    $env = null;
    if ($extraEnv !== []) {
        $env = array_merge(getenv(), $extraEnv);
    }

    try {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['file', $outFile, 'w'], 2 => ['file', $errFile, 'w']];
        $proc = @proc_open(array_values($cmd), $descriptors, $pipes, $cwd, $env, ['bypass_shell' => true]);
        if (!is_resource($proc)) {
            $result['error'] = '프로그램을 실행할 수 없습니다.';
            return $result;
        }
        $result['started'] = true;
        fclose($pipes[0]);

        $deadline = microtime(true) + $timeoutSec;
        while (true) {
            $status = proc_get_status($proc);
            if (!$status['running']) {
                $result['exitCode'] = $status['exitcode'];
                break;
            }
            if (microtime(true) >= $deadline) {
                $result['timedOut'] = true;
                kill_process_tree($proc, (int)$status['pid']);
                break;
            }
            usleep(50_000);
        }
        proc_close($proc);

        $result['stdout'] = (string)@file_get_contents($outFile);
        $result['stderr'] = (string)@file_get_contents($errFile);
        // Unix에서 exec 실패는 127로 끝난다.
        if (PHP_OS_FAMILY !== 'Windows' && $result['exitCode'] === 127) {
            $result['error'] = '프로그램을 찾을 수 없습니다.';
        }
        return $result;
    } finally {
        @unlink($outFile);
        @unlink($errFile);
    }
}

/** 프로세스와 그 자식 프로세스를 강제 종료한다. */
function kill_process_tree($proc, int $pid): void
{
    if (PHP_OS_FAMILY === 'Windows' && $pid > 0) {
        $k = @proc_open(['taskkill', '/F', '/T', '/PID', (string)$pid], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $kp, null, null, ['bypass_shell' => true]);
        if (is_resource($k)) {
            stream_get_contents($kp[1]);
            stream_get_contents($kp[2]);
            proc_close($k);
        }
    }
    @proc_terminate($proc, 9);
}
