<?php
declare(strict_types=1);

/*
 * 관리자 비밀번호 저장소.
 * settings/admin_password.json = { hash: password_hash(...), version: 바꾼 횟수, changedAt }.
 * 파일이 없으면 config.php 의 ADMIN_DEFAULT_PASSWORD(초기 비밀번호, version 0)를 쓴다.
 * version 은 로그인 토큰에 함께 기록되어, 비밀번호를 바꾸면 그 전에 받은 토큰(다른 PC·탭)이 모두 무효가 된다.
 * api/auth.php, api/common.php(is_admin), tests/run.php 가 사용하며 직접 호출하지 않는다 (api/.htaccess 로 차단).
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'common.php';

/** 저장된 비밀번호 정보. 파일이 없으면 초기 비밀번호. 손상되었으면 ApiError (초기 비밀번호로 몰래 돌아가지 않는다). */
function admin_password_load(string $file = ADMIN_PASSWORD_FILE): array
{
    if (!is_file($file)) {
        return ['hash' => null, 'version' => 0, 'changedAt' => null];
    }
    $raw = @file_get_contents($file);
    $data = $raw === false ? null : json_decode((string)$raw, true);
    if (!is_array($data) || !is_string($data['hash'] ?? null) || $data['hash'] === '' || !is_int($data['version'] ?? null)) {
        throw new ApiError('관리자 비밀번호 파일(settings/admin_password.json)이 손상되었습니다. 그 파일을 지우면 초기 비밀번호로 돌아갑니다.', 500);
    }
    return ['hash' => $data['hash'], 'version' => $data['version'], 'changedAt' => $data['changedAt'] ?? null];
}

/** 아직 초기 비밀번호를 쓰고 있는지 */
function admin_password_is_default(string $file = ADMIN_PASSWORD_FILE): bool
{
    return admin_password_load($file)['hash'] === null;
}

/** 지금 비밀번호 버전 (토큰 확인용). 파일이 손상되었으면 -1 (어떤 토큰과도 맞지 않음). */
function admin_password_version(string $file = ADMIN_PASSWORD_FILE): int
{
    try {
        return admin_password_load($file)['version'];
    } catch (Throwable) {
        return -1;
    }
}

function admin_password_verify(string $password, string $file = ADMIN_PASSWORD_FILE): bool
{
    $p = admin_password_load($file);
    return $p['hash'] === null ? hash_equals(ADMIN_DEFAULT_PASSWORD, $password) : password_verify($password, $p['hash']);
}

/** 새 비밀번호 규칙을 확인한다. 틀리면 ApiError(400). */
function admin_password_validate(mixed $password): string
{
    if (!is_string($password) || $password === '' || !preg_match('//u', $password)) {
        throw new ApiError('새 비밀번호를 입력해 주세요.');
    }
    if (preg_match('/[\x00-\x1F\x7F]/', $password)) {
        throw new ApiError('비밀번호에 제어 문자는 쓸 수 없습니다.');
    }
    if (trim($password) !== $password) {
        throw new ApiError('비밀번호의 앞뒤에는 공백을 둘 수 없습니다.');
    }
    if (mb_strlen($password, 'UTF-8') < ADMIN_PASSWORD_MIN_CHARS) {
        throw new ApiError('비밀번호는 ' . ADMIN_PASSWORD_MIN_CHARS . '자 이상이어야 합니다.');
    }
    if (strlen($password) > ADMIN_PASSWORD_MAX_BYTES) {
        throw new ApiError('비밀번호가 너무 깁니다. 영문 기준 ' . ADMIN_PASSWORD_MAX_BYTES . '자(한글은 약 24자)까지 쓸 수 있습니다.');
    }
    return $password;
}

/** 비밀번호를 바꾼다 (해시로 저장, version + 1). 새 version 을 돌려준다. */
function admin_password_set(string $password, string $file = ADMIN_PASSWORD_FILE): int
{
    admin_password_validate($password);
    ensure_dir(dirname($file));
    $fh = @fopen($file, 'c+');
    if ($fh === false) {
        throw new ApiError('비밀번호를 저장할 수 없습니다. settings 폴더 쓰기 권한을 확인해 주세요.', 500);
    }
    try {
        flock($fh, LOCK_EX);
        $old = json_decode((string)stream_get_contents($fh), true);
        $version = (is_array($old) && is_int($old['version'] ?? null) ? $old['version'] : 0) + 1;
        $json = json_encode([
            'hash' => password_hash($password, PASSWORD_DEFAULT),
            'version' => $version,
            'changedAt' => (new DateTimeImmutable('now', new DateTimeZone(APP_TIMEZONE)))->format(DATE_ATOM),
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, $json . "\n");
        fflush($fh);
        return $version;
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}
