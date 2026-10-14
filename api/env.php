<?php
declare(strict_types=1);

/*
 * 환경 점검 API (prd.md FR-8)
 *   GET → { ok, checkedAt, checks: [{ id, label, ok, level, value, message }], config: { 화면이 쓰는 설정값 } }
 *   level: ok | warn(측정은 가능하지만 확인 필요) | error(측정 불가)
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'rules_store.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'cloc_runner.php';

run_api(function (): void {
    require_method('GET');
    $checks = collect_checks();
    $excludes = current_default_excludes();   // 관리 탭에서 사용 중으로 둔 제외 규칙
    $ok = !in_array('error', array_column($checks, 'level'), true);
    json_response([
        'ok'        => $ok,
        'checkedAt' => (new DateTimeImmutable('now', new DateTimeZone(APP_TIMEZONE)))->format(DATE_ATOM),
        'checks'    => $checks,
        // 화면(assets/app.js)이 config.php 와 같은 값을 쓰도록 알려준다.
        'config'    => [
            'chunkBytes'     => UPLOAD_CHUNK_BYTES,
            'uploadMaxBytes' => UPLOAD_MAX_BYTES,
            'clocTimeoutSec' => CLOC_TIMEOUT_SEC,
            'excludeDirs'    => $excludes['dirs'],
            'excludeExts'    => $excludes['exts'],
            'excludeFiles'   => $excludes['files'],
        ],
    ]);
});

function check(string $id, string $label, string $level, string $value, string $message): array
{
    return ['id' => $id, 'label' => $label, 'ok' => $level !== 'error', 'level' => $level, 'value' => $value, 'message' => $message];
}

function collect_checks(): array
{
    $c = [];
    $procOpen = function_usable('proc_open');

    // ---- PHP ----
    $phpOk = version_compare(PHP_VERSION, '8.0.0', '>=');
    $c[] = check('php_version', 'PHP 버전', $phpOk ? 'ok' : 'error', PHP_VERSION,
        $phpOk ? 'PHP 8 이상입니다.' : 'PHP 8.0 이상이 필요합니다. 최신 XAMPP로 업데이트해 주세요.');

    $c[] = check('proc_open', 'proc_open 사용 가능', $procOpen ? 'ok' : 'error', $procOpen ? '사용 가능' : '사용 불가',
        $procOpen ? 'cloc 실행에 사용합니다.'
            : 'cloc 실행에 proc_open이 필요합니다. php.ini의 disable_functions 에서 proc_open 을 빼고 Apache를 다시 시작해 주세요.');

    $exec = function_usable('exec');
    $c[] = check('exec', 'exec 사용 가능', $exec ? 'ok' : 'warn', $exec ? '사용 가능' : '사용 불가',
        $exec ? '이 프로그램은 exec을 쓰지 않고 proc_open만 사용합니다.' : 'exec은 쓰지 않으므로 측정에는 영향이 없습니다.');

    $zip = extension_loaded('zip') && class_exists('ZipArchive');
    $c[] = check('zip', 'zip 확장', $zip ? 'ok' : 'error', $zip ? '로드됨' : '로드되지 않음',
        $zip ? '업로드한 zip 압축 해제에 사용합니다.'
            : 'zip 확장이 꺼져 있어 업로드한 소스를 풀 수 없습니다. php.ini(' . (php_ini_loaded_file() ?: 'php.ini') . ')에서 ";extension=zip" 줄의 앞 ";"를 지우고 Apache를 다시 시작해 주세요.');

    // ---- 업로드/실행 한도 ----
    $chunkMb = intdiv(UPLOAD_CHUNK_BYTES, 1024 * 1024);
    $need = UPLOAD_CHUNK_BYTES + 1024 * 1024; // 조각 + multipart 여유 1MB
    $uploadMax = (string)ini_get('upload_max_filesize');
    $postMax = (string)ini_get('post_max_size');
    $uploadsOn = filter_var(ini_get('file_uploads'), FILTER_VALIDATE_BOOL);

    $c[] = check('file_uploads', '파일 업로드 (file_uploads)', $uploadsOn ? 'ok' : 'error', $uploadsOn ? 'On' : 'Off',
        $uploadsOn ? '파일 업로드가 켜져 있습니다.' : 'php.ini에서 file_uploads = On 으로 바꾸고 Apache를 다시 시작해 주세요.');

    $upOk = ini_bytes($uploadMax) >= $need;
    $c[] = check('upload_max_filesize', 'upload_max_filesize', $upOk ? 'ok' : 'error', $uploadMax,
        $upOk ? "업로드 조각({$chunkMb}MB)을 받을 수 있습니다."
            : "업로드 조각({$chunkMb}MB)보다 작습니다. php.ini에서 upload_max_filesize 를 16M 이상으로 올리고 Apache를 다시 시작해 주세요.");

    $postBytes = ini_bytes($postMax);
    $postOk = $postBytes === 0 || $postBytes >= $need;
    $c[] = check('post_max_size', 'post_max_size', $postOk ? 'ok' : 'error', $postMax,
        $postOk ? "업로드 조각({$chunkMb}MB)을 받을 수 있습니다."
            : "업로드 조각({$chunkMb}MB)보다 작습니다. php.ini에서 post_max_size 를 16M 이상으로 올리고 Apache를 다시 시작해 주세요.");

    $maxExec = (int)ini_get('max_execution_time');
    $setLimit = function_usable('set_time_limit');
    $execOk = $maxExec === 0 || $setLimit || $maxExec >= 600;
    $c[] = check('max_execution_time', 'max_execution_time', $execOk ? 'ok' : 'warn', $maxExec === 0 ? '0 (무제한)' : $maxExec . '초',
        $execOk ? ($maxExec === 0 ? '실행 시간 제한이 없습니다.' : '측정 API는 실행 중 set_time_limit 으로 제한을 늘립니다.')
            : '컴포넌트가 많으면 측정 도중 끊길 수 있습니다. php.ini에서 max_execution_time 을 600 이상으로 올리거나 set_time_limit 을 허용해 주세요.');

    $mem = (string)ini_get('memory_limit');
    $memBytes = ini_bytes($mem);
    $memOk = $memBytes < 0 || $memBytes >= 128 * 1024 * 1024;
    $c[] = check('memory_limit', 'memory_limit', $memOk ? 'ok' : 'warn', $mem,
        $memOk ? '충분합니다.' : '128M 미만입니다. 파일이 많은 zip을 처리하다 멈출 수 있으니 php.ini에서 memory_limit 을 256M 이상으로 올려 주세요.');

    // ---- 시간대 ----
    $tz = date_default_timezone_get();
    $tzOk = $tz === APP_TIMEZONE;
    $c[] = check('timezone', '서버 시간대', $tzOk ? 'ok' : 'warn', $tz . ' (' . date('Y-m-d H:i:s P') . ')',
        $tzOk ? 'history 파일명의 날짜는 이 시간대 기준입니다.' : 'config.php 의 APP_TIMEZONE(' . APP_TIMEZONE . ')이 적용되지 않았습니다.');

    // ---- 폴더 쓰기 ----
    foreach (['history' => HISTORY_DIR, 'work' => WORK_DIR] as $name => $dir) {
        [$ok, $msg] = probe_writable($dir);
        $c[] = check('dir_' . $name, $name . '/ 쓰기 권한', $ok ? 'ok' : 'error', $ok ? '쓰기 가능' : '쓰기 불가',
            $ok ? $name . ' 폴더에 파일을 만들 수 있습니다.'
                : $name . ' 폴더에 쓸 수 없습니다(' . $msg . '). Apache 실행 계정이 프로젝트의 ' . $name . ' 폴더를 수정할 수 있도록 폴더 속성 > 보안에서 권한을 주세요.');
    }

    // ---- cloc exe: ctool 의 cloc-<버전>.exe 중 가장 높은 버전 (안 되면 그다음 버전) ----
    $exes = cloc_candidates('exe');
    $exeList = implode(', ', array_column($exes, 'file'));
    $exeVersion = null;
    if ($exes === []) {
        $c[] = check('cloc_exe', 'cloc exe', 'error', '없음', 'ctool 폴더에 cloc-<버전>.exe 파일이 없습니다. cloc 릴리스에서 받은 exe 를 ctool 에 넣어 주세요 (예: cloc-2.10.exe).');
    } elseif (PHP_OS_FAMILY !== 'Windows') {
        $c[] = check('cloc_exe', 'cloc exe', 'warn', '있음 (Windows 아님)', 'Windows 서버가 아니라 exe를 실행할 수 없습니다. Perl로 cloc-<버전>.pl 을 사용합니다.');
    } elseif (!$procOpen) {
        $c[] = check('cloc_exe', 'cloc exe', 'error', '있음 (실행 확인 불가)', 'proc_open 이 비활성화되어 실행해 볼 수 없습니다.');
    } else {
        $sel = cloc_select('exe');
        if ($sel !== null) {
            $exeVersion = $sel['version'];
            $skipped = $sel['tried'];
            $c[] = check('cloc_exe', 'cloc exe', $skipped ? 'warn' : 'ok', $sel['file'] . ' 실행 가능, 버전 ' . $sel['version'],
                ($skipped
                    ? '더 높은 버전(' . implode(', ', $skipped) . ')을 실행하지 못해 ' . $sel['file'] . ' 로 측정합니다. 백신이 막았거나 파일이 손상되지 않았는지 확인해 주세요.'
                    : 'ctool 에서 가장 높은 버전으로 측정합니다.')
                . ' (ctool 의 exe: ' . $exeList . ')');
        } else {
            $r = run_process([$exes[0]['path'], '--version'], ENV_CHECK_TIMEOUT_SEC, cloc_env());
            $c[] = check('cloc_exe', 'cloc exe', 'error', '실행 실패', describe_run_failure($r,
                'ctool 의 cloc exe(' . $exeList . ')를 하나도 실행하지 못했습니다. 백신이 파일을 막았는지, Apache 실행 계정이 ctool 폴더를 실행하고 work 폴더에 쓸 수 있는지 확인해 주세요.'));
        }
    }

    // ---- cloc pl / Perl ----
    $pls = cloc_candidates('pl');
    $plExists = $pls !== [];
    $plFile = $plExists ? $pls[0]['file'] : 'cloc-<버전>.pl';
    $c[] = check('cloc_pl', 'cloc pl', $plExists ? 'ok' : 'warn', $plExists ? $plFile . ' 있음' : '없음',
        $plExists ? 'Perl이 있으면 exe 대신 쓸 수 있습니다 (가장 높은 버전).' : 'ctool 에 cloc-<버전>.pl 이 없어 Perl 대체 실행을 할 수 없습니다. exe만 사용합니다.');

    $perlVersion = null;
    if ($procOpen) {
        $r = run_process([PERL_BIN, '-v'], ENV_CHECK_TIMEOUT_SEC);
        if ($r['exitCode'] === 0 && preg_match('/\(v?(\d+\.\d+\.\d+)\)|v(\d+\.\d+\.\d+)/', $r['stdout'], $m)) {
            $perlVersion = $m[1] !== '' ? $m[1] : $m[2];
        }
    }
    $exeWorks = $exeVersion !== null;
    if ($perlVersion !== null) {
        $c[] = check('perl', 'Perl', 'ok', 'v' . $perlVersion, $exeWorks ? "exe가 동작하지 않을 때 {$plFile} 로 대체합니다." : "{$plFile} 로 측정합니다.");
    } else {
        $c[] = check('perl', 'Perl', $exeWorks ? 'ok' : 'warn', '없음',
            $exeWorks ? 'Perl이 없지만 exe가 동작하므로 측정에는 문제가 없습니다.'
                : 'Perl도 없어 대체 실행을 할 수 없습니다. exe 문제를 해결하거나 Perl(예: Strawberry Perl)을 설치하고 config.php 의 PERL_BIN 을 설정해 주세요.');
    }

    // exe/pl 중 하나라도 실행되면 측정 가능
    $canMeasure = $exeWorks || ($perlVersion !== null && $plExists);
    $c[] = check('cloc_runner', 'cloc 실행 방식', $canMeasure ? 'ok' : 'error',
        $exeWorks ? 'exe' : ($canMeasure ? 'pl' : '없음'),
        $canMeasure ? '측정에 사용할 실행 방식입니다.' : '측정에 쓸 수 있는 cloc 실행 방식이 없습니다. 위 cloc 항목의 해결 방법을 따라 주세요.');

    return $c;
}

/** 실제로 파일을 만들고 지워 쓰기 가능 여부를 확인한다 (Windows에서 is_writable 은 부정확할 수 있음). */
function probe_writable(string $dir): array
{
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        return [false, '폴더가 없고 만들 수도 없음'];
    }
    $probe = $dir . DIRECTORY_SEPARATOR . '.probe_' . bin2hex(random_bytes(6));
    if (@file_put_contents($probe, 'x') !== 1) {
        return [false, '파일 생성 실패'];
    }
    if (!@unlink($probe)) {
        return [false, '파일 삭제 실패'];
    }
    return [true, ''];
}

function parse_version(string $out): ?string
{
    return preg_match('/^\s*(\d+\.\d+(?:\.\d+)?)\s*$/m', $out, $m) ? $m[1] : null;
}

function describe_run_failure(array $r, string $hint): string
{
    if ($r['error'] !== null) {
        $why = $r['error'];
    } elseif ($r['timedOut']) {
        $why = ENV_CHECK_TIMEOUT_SEC . '초 안에 끝나지 않았습니다.';
    } else {
        $why = '종료 코드 ' . var_export($r['exitCode'], true) . '.';
        $err = trim(utf8_truncate(preg_replace('/\s+/', ' ', (string)$r['stderr']), 200));
        if ($err !== '') {
            $why .= ' 오류 출력: ' . $err;
        }
    }
    return $why . ' ' . $hint;
}
