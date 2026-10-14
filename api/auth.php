<?php
declare(strict_types=1);

/*
 * 관리 탭 인증 API
 *   GET              → { authenticated, defaultPassword }   (X-Admin-Token 헤더의 토큰이 유효한지, 초기 비밀번호를 쓰는 중인지)
 *   POST {password}  → { authenticated: true, token, defaultPassword } | 401 틀림 | 429 여러 번 틀려 잠김
 *   PUT  {currentPassword, newPassword} → { changed: true, token, defaultPassword: false }   (관리자 인증 필요)
 *   DELETE           → { authenticated: false }     (관리 잠그기: 그 토큰을 버린다)
 * 비밀번호는 초기값 'admin'(config.php ADMIN_DEFAULT_PASSWORD)이고, 관리 탭 '비밀번호 변경'에서 바꾸면
 * settings/admin_password.json 에 해시로 저장된다 (api/admin_password.php). 확인은 모두 서버에서 한다.
 * 토큰은 페이지(화면) 하나에만 쓰인다. 화면은 토큰을 메모리에만 들고 있으므로 새로고침·새 탭·브라우저를 다시 열면
 * 다시 비밀번호를 입력해야 한다. 비밀번호를 바꾸면 그 전에 받은 토큰(다른 PC·탭)은 모두 무효가 된다.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'common.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'admin_password.php';

const AUTH_MAX_FAILS = 5;                 // 이 횟수만큼 틀리면
const AUTH_FAIL_WINDOW_SEC = 10 * 60;     // (10분 안에)
const AUTH_LOCK_SEC = 5 * 60;             // 5분 동안 막는다
const AUTH_ATTEMPTS_FILE = SETTINGS_DIR . DIRECTORY_SEPARATOR . 'auth_attempts.json';

run_api(function (): void {
    $method = require_method('GET', 'POST', 'PUT', 'DELETE');
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');

    if ($method === 'GET') {
        json_response(['authenticated' => is_admin(), 'defaultPassword' => admin_password_is_default()]);
    }
    if ($method === 'DELETE') {
        $token = (string)($_SERVER['HTTP_X_ADMIN_TOKEN'] ?? '');
        app_session_start();
        unset($_SESSION['locmgmt_admin_tokens'][$token]);
        session_write_close();
        json_response(['authenticated' => false]);
    }

    $body = read_json_body();

    if ($method === 'PUT') {
        // 비밀번호 변경: 이미 인증된 페이지에서, 현재 비밀번호를 한 번 더 확인한다 (틀린 횟수는 로그인과 함께 센다)
        require_admin();
        auth_check_lock($ip);
        $current = is_string($body['currentPassword'] ?? null) ? $body['currentPassword'] : '';
        if (!admin_password_verify($current)) {
            auth_fail($ip, '현재 비밀번호가 올바르지 않습니다.', 400);
        }
        auth_clear($ip);
        $new = admin_password_validate($body['newPassword'] ?? null);
        if (admin_password_verify($new)) {
            throw new ApiError('새 비밀번호가 지금 비밀번호와 같습니다. 다른 비밀번호를 입력해 주세요.');
        }
        admin_password_set($new);
        // 버전이 올라가 모든 토큰이 무효가 되었으므로, 바꾼 이 페이지에는 새 토큰을 준다
        json_response(['changed' => true, 'token' => issue_admin_token(), 'defaultPassword' => false]);
    }

    // POST: 로그인
    $password = is_string($body['password'] ?? null) ? trim($body['password']) : '';
    auth_check_lock($ip);
    if (admin_password_verify($password)) {
        auth_clear($ip);
        app_session_start();
        session_regenerate_id(true);   // 세션 고정 공격 방지
        session_write_close();
        json_response(['authenticated' => true, 'token' => issue_admin_token(), 'defaultPassword' => admin_password_is_default()]);
    }
    auth_fail($ip, '비밀번호가 올바르지 않습니다.', 401);
});

/** 여러 번 틀려 막혀 있으면 429 */
function auth_check_lock(string $ip): void
{
    $now = time();
    $locked = auth_attempts(function (array $a) use ($ip, $now): array {
        $e = $a[$ip] ?? null;
        return [$a, $e !== null && ($e['lockedUntil'] ?? 0) > $now ? (int)$e['lockedUntil'] - $now : 0];
    });
    if ($locked > 0) {
        throw new ApiError('비밀번호를 여러 번 틀려 잠시 막혔습니다. ' . max(1, (int)ceil($locked / 60)) . '분 뒤에 다시 시도해 주세요.', 429);
    }
}

function auth_clear(string $ip): void
{
    auth_attempts(function (array $a) use ($ip): array {
        unset($a[$ip]);
        return [$a, null];
    });
}

/** 틀린 횟수를 하나 올리고 오류를 던진다 (AUTH_MAX_FAILS 번째면 잠그고 429). */
function auth_fail(string $ip, string $message, int $status): never
{
    $now = time();
    $left = auth_attempts(function (array $a) use ($ip, $now): array {
        $e = $a[$ip] ?? ['fails' => 0, 'first' => $now];
        if ($now - ($e['first'] ?? $now) > AUTH_FAIL_WINDOW_SEC) {
            $e = ['fails' => 0, 'first' => $now];
        }
        $e['fails']++;
        if ($e['fails'] >= AUTH_MAX_FAILS) {
            $a[$ip] = ['fails' => 0, 'first' => $now, 'lockedUntil' => $now + AUTH_LOCK_SEC];
            return [$a, 0];
        }
        $a[$ip] = $e;
        return [$a, AUTH_MAX_FAILS - $e['fails']];
    });
    if ($left === 0) {
        throw new ApiError('비밀번호를 ' . AUTH_MAX_FAILS . '번 틀려 ' . (AUTH_LOCK_SEC / 60) . '분 동안 막혔습니다.', 429);
    }
    throw new ApiError($message . ' (' . $left . '번 더 틀리면 잠시 막힙니다)', $status);
}

/** 잠금을 잡고 틀린 기록(IP별)을 읽어 $fn 으로 바꾼 뒤 저장한다. 하루 지난 기록은 지운다. */
function auth_attempts(callable $fn): mixed
{
    ensure_dir(SETTINGS_DIR);
    $fh = @fopen(AUTH_ATTEMPTS_FILE, 'c+');
    if ($fh === false) {
        throw new ApiError('인증 기록을 저장할 수 없습니다. settings 폴더 쓰기 권한을 확인해 주세요.', 500);
    }
    try {
        flock($fh, LOCK_EX);
        $a = json_decode((string)stream_get_contents($fh), true);
        $a = is_array($a) ? $a : [];
        $now = time();
        foreach ($a as $k => $e) {
            if (!is_array($e) || max((int)($e['first'] ?? 0), (int)($e['lockedUntil'] ?? 0)) < $now - 86400) {
                unset($a[$k]);
            }
        }
        [$a, $result] = $fn($a);
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($a));
        fflush($fh);
        return $result;
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}
