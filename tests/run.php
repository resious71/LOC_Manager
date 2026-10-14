<?php
declare(strict_types=1);

/*
 * 2단계 테스트 (외부 프레임워크 없음)
 *   C:\xampp\php\php.exe tests\run.php
 *   (php.ini 에서 zip 확장이 꺼져 있으면: php.exe -d extension=zip tests\run.php)
 *
 * 샘플 프로젝트를 임시 폴더에 만들고 zip → 조각 업로드 → 측정 흐름을 함수로 호출해,
 * 같은 샘플에 ctool 의 cloc exe(가장 높은 버전) --json 을 직접 실행한 값과 비교한다.
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI 전용입니다.');
}
require_once dirname(__DIR__) . '/api/jobs.php';

// ---------------------------------------------------------------- 도우미

$GLOBALS['pass'] = 0;
$GLOBALS['fail'] = 0;
$GLOBALS['skip'] = [];

function ok(bool $cond, string $label, string $detail = ''): void
{
    $GLOBALS[$cond ? 'pass' : 'fail']++;
    echo ($cond ? '  PASS ' : '  FAIL ') . $label . ($detail !== '' && !$cond ? "\n       → " . $detail : '') . "\n";
}

function skip(string $label): void
{
    $GLOBALS['skip'][] = $label;
    echo "  SKIP $label\n";
}

function section(string $title): void
{
    echo "\n== $title\n";
}

/** ApiError 가 나는지 확인하고 상태 코드를 돌려준다. 안 나면 null. */
function expect_error(callable $fn): ?ApiError
{
    try {
        $fn();
    } catch (ApiError $e) {
        return $e;
    }
    return null;
}

/** work/ 아래 작업 폴더(jobId 형식) 목록 */
function job_dirs(): array
{
    return array_values(array_filter(scandir(WORK_DIR) ?: [], fn($e) => preg_match(JOB_ID_PATTERN, $e) === 1));
}

function write_file(string $path, string $content): void
{
    ensure_dir(dirname($path));
    file_put_contents($path, $content);
}

/** 샘플 프로젝트 파일 목록 (상대 경로 => 내용). 내용이 모두 달라 cloc 중복 제거의 영향을 받지 않는다. */
function sample_files(): array
{
    return [
        'compA/app.js'                     => "// entry\n/* block\n comment */\nfunction a() {\n  return 1;\n}\n\nconsole.log(a());\n",
        'compA/style.css'                  => "/* theme */\nbody { color: red; }\n\n.a { margin: 0; }\n",
        'compA/lib.min.js'                 => "var x=1;var y=2;\n",
        'compA/app.js.map'                 => "{\"version\":3}\n",
        'compA/sub/util.ts'                => "// util\nexport const u = (n: number): number => n * 2;\n\nexport const v = 3;\n",
        'compA/node_modules/dep/index.js'  => "module.exports = 42;\nmodule.exports.b = 1;\n",
        'compB/main.py'                    => "# main\nimport os\n\n\ndef f():\n    \"\"\"doc\"\"\"\n    return os.name\n",
        'compB/index.php'                  => "<?php\n// php\necho 'hi';\n\n\$a = 1;\n",
        'compB/build/out.js'               => "var built = true;\n",
        'compB/notes.zzz'                  => "unknown format\n",
        '한글컴포넌트/Main.java'             => "// java\npublic class Main {\n    public static void main(String[] a) {\n        System.out.println(1);\n    }\n}\n",
        '한글컴포넌트/page.html'             => "<!-- page -->\n<html>\n<body>\n<p>x</p>\n</body>\n</html>\n",
        '한글컴포넌트/하위폴더/한글파일.py'     => "# 한글 주석\nprint('안녕')\nx = 1\n",
        'node_modules/top/index.js'        => "var top = 1;\n",
        'README.md'                        => "# Sample\n\ntext\n",
        'setup.sh'                         => "#!/bin/sh\n# setup\necho ok\n",
    ];
}

/** 한글 이름을 ASCII 로 바꾼 같은 내용의 복사본 경로 (cloc 명령행은 한글 경로를 읽지 못하므로 비교용). */
function ascii_rel(string $rel): string
{
    return strtr($rel, ['한글컴포넌트' => 'hangul_comp', '하위폴더' => 'hangul_sub', '한글파일' => 'hangul_file']);
}

function build_sample(string $root, bool $ascii): void
{
    foreach (sample_files() as $rel => $content) {
        write_file($root . '/' . ($ascii ? ascii_rel($rel) : $rel), $content);
    }
}

/** 폴더를 zip 으로 묶는다. $prefix 는 zip 안의 최상위 폴더 이름. */
function zip_dir(string $dir, string $zipPath, string $prefix): void
{
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1));
        $zip->addFile($file->getPathname(), $prefix . '/' . $rel);
    }
    $zip->close();
}

/** tar 항목 하나 (ustar 헤더 + 내용 + 512바이트 맞춤). $type: '0' 파일, '5' 폴더, '2' 심볼릭 링크, 'L' GNU 긴 이름, 'x' pax */
function tar_entry(string $name, string $content = '', string $type = '0'): string
{
    $h = str_pad(substr($name, 0, 100), 100, "\0") . "0000644\0" . "0000000\0" . "0000000\0"
        . sprintf('%011o', strlen($content)) . "\0" . sprintf('%011o', 1700000000) . "\0" . '        ' . $type
        . str_repeat("\0", 100) . "ustar\0" . '00' . str_repeat("\0", 32 + 32 + 8 + 8 + 155 + 12);
    $sum = 0;
    for ($k = 0; $k < 512; $k++) {
        $sum += ord($h[$k]);
    }
    $h = substr_replace($h, sprintf('%06o', $sum) . "\0 ", 148, 8);
    return $h . $content . str_repeat("\0", (512 - strlen($content) % 512) % 512);
}

function tar_pax(string $path): string
{
    $body = ' path=' . $path . "\n";
    $n = strlen($body) + 1;
    while (strlen($n . $body) !== $n) {
        $n = strlen($n . $body);
    }
    return tar_entry('PaxHeader/x', $n . $body, 'x');
}

function tar_build(array $parts): string
{
    return implode('', $parts) . str_repeat("\0", 1024);
}

function make_zip(string $zipPath, array $entries): void
{
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($entries as $name => $content) {
        $zip->addFromString($name, $content);
    }
    $zip->close();
}

/** zip 을 $chunkSize 조각으로 나눠 upload_chunk 로 올리고 jobId 를 돌려준다. */
function upload_file(string $zipPath, int $chunkSize, string $type, string $name): string
{
    $data = file_get_contents($zipPath);
    $chunks = str_split($data, $chunkSize);
    $jobId = '';
    foreach ($chunks as $i => $c) {
        $tmp = tempnam(sys_get_temp_dir(), 'chk');
        file_put_contents($tmp, $c);
        $r = upload_chunk(['jobId' => $jobId, 'index' => (string)$i, 'total' => (string)count($chunks), 'sourceType' => $type, 'sourceName' => $name], $tmp);
        unlink($tmp);
        $jobId = $r['jobId'];
    }
    return $jobId;
}

/** cloc 명령행 직접 실행 결과 (API와 같은 제외 규칙) */
function cli_cloc(string $dir): array
{
    $args = ['--json', '--quiet', '--exclude-dir=' . implode(',', DEFAULT_EXCLUDE_DIRS), '--exclude-ext=' . implode(',', DEFAULT_EXCLUDE_EXTS), $dir];
    $r = run_process([cloc_exe_path(), ...$args], 120, cloc_env());
    $json = json_decode(trim($r['stdout']), true);
    return is_array($json) ? cloc_languages_from_json($json) : [];
}

function langs_str(array $langs): string
{
    return implode(', ', array_map(fn($l) => "{$l['language']} f{$l['files']} c{$l['code']} m{$l['comment']} b{$l['blank']}", $langs));
}

function component(array $result, string $name): ?array
{
    foreach ($result['components'] as $c) {
        if ($c['name'] === $name) {
            return $c;
        }
    }
    return null;
}

// ---------------------------------------------------------------- 준비

echo "LOC_Mgmt 2단계 테스트  (PHP " . PHP_VERSION . ', ' . PHP_OS_FAMILY . ")\n";
if (!class_exists('ZipArchive')) {
    echo "\nzip 확장이 없어 테스트를 진행할 수 없습니다. php.ini 에서 extension=zip 을 켜거나 php -d extension=zip tests/run.php 로 실행하세요.\n";
    exit(2);
}
$exeSel = cloc_select('exe');
$exeVersion = $exeSel['version'] ?? null;
$canRunCloc = $exeVersion !== null;
echo $canRunCloc ? "cloc exe: {$exeSel['file']} (버전 $exeVersion)\n" : "cloc exe 를 실행할 수 없습니다 (Windows 아님 또는 실행 실패). 압축 해제·경로 검사·정리만 검증합니다.\n";

$tmpRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'loc_mgmt_test_' . bin2hex(random_bytes(4));
$sampleDir = $tmpRoot . '/sample/MyProject';
$asciiDir = $tmpRoot . '/ascii/MyProject';
build_sample($sampleDir, false);
build_sample($asciiDir, true);
$sampleZip = $tmpRoot . '/MyProject.zip';
zip_dir($sampleDir, $sampleZip, 'MyProject');
$jobsBefore = job_dirs();

try {
    // ------------------------------------------------------------ 업로드
    section('업로드 (조각 전송)');
    $zipSize = filesize($sampleZip);
    $chunk = max(512, intdiv($zipSize, 3) + 1);   // 3조각
    $data = file_get_contents($sampleZip);
    $parts = str_split($data, $chunk);
    $tmp = [];
    foreach ($parts as $i => $p) {
        $tmp[$i] = tempnam(sys_get_temp_dir(), 'chk');
        file_put_contents($tmp[$i], $p);
    }
    $f = fn($jobId, $i) => ['jobId' => $jobId, 'index' => (string)$i, 'total' => (string)count($parts), 'sourceType' => 'folder', 'sourceName' => 'C:\\Users\\me\\MyProject'];

    $r0 = upload_chunk($f('', 0), $tmp[0]);
    ok((bool)preg_match(JOB_ID_PATTERN, $r0['jobId']) && $r0['received'] === 1 && !$r0['complete'], '첫 조각: 서버가 jobId 발급', json_encode($r0));
    $jobId = $r0['jobId'];
    $e = expect_error(fn() => upload_chunk($f($jobId, 2), $tmp[2]));
    ok($e !== null && $e->status === 409, '조각 순서 건너뛰기 거부 (409)', $e?->getMessage() ?? '오류 없음');
    $r0b = upload_chunk($f($jobId, 0), $tmp[0]);
    ok($r0b['received'] === 1, '직전 조각 재전송(재시도)은 덮어쓰기로 허용');
    upload_chunk($f($jobId, 1), $tmp[1]);
    $r2 = upload_chunk($f($jobId, 2), $tmp[2]);
    ok($r2['complete'] === true && $r2['received'] === 3, '마지막 조각 → complete');
    ok(filesize(WORK_DIR . "/$jobId/upload.zip") === $zipSize && md5_file(WORK_DIR . "/$jobId/upload.zip") === md5_file($sampleZip), '조립된 zip 이 원본과 같음');
    $e = expect_error(fn() => upload_chunk($f($jobId, 2), $tmp[2]));
    ok($e !== null && $e->status === 409, '완료된 작업에 조각 추가 거부');
    foreach ($tmp as $t) {
        unlink($t);
    }

    foreach (['../evil', '..\\x', 'ABCDEF', str_repeat('g', 24), '../../../../windows'] as $bad) {
        $t = tempnam(sys_get_temp_dir(), 'chk');
        file_put_contents($t, 'x');
        $e = expect_error(fn() => upload_chunk(['jobId' => $bad, 'index' => '1', 'total' => '2'], $t));
        unlink($t);
        ok($e !== null && $e->status === 400, "잘못된 jobId 거부: $bad", $e?->getMessage() ?? '오류 없음');
    }
    $e = expect_error(fn() => measure_job(['jobId' => '../' . $jobId]));
    ok($e !== null && $e->status === 400, 'measure: 경로가 든 jobId 거부');

    $t = tempnam(sys_get_temp_dir(), 'chk');
    file_put_contents($t, 'this is not a zip');
    $e = expect_error(fn() => upload_chunk(['jobId' => '', 'index' => '0', 'total' => '1', 'sourceType' => 'zip', 'sourceName' => 'x.zip'], $t));
    unlink($t);
    ok($e !== null && $e->status === 422, 'zip 이 아닌 파일 거부 (422)', $e?->getMessage() ?? '');
    ok(count(job_dirs()) === count($jobsBefore) + 1, 'zip 이 아닌 업로드의 작업 폴더는 바로 삭제');

    // ------------------------------------------------------------ 측정 (byFolder)
    section('측정: 하위 폴더별 (byFolder)');
    if (!$canRunCloc) {
        skip('cloc 실행 필요: byFolder 측정과 명령행 비교');
        remove_dir(WORK_DIR . "/$jobId");
    } else {
        $res = measure_job(['jobId' => $jobId, 'mode' => 'byFolder', 'excludeDirs' => DEFAULT_EXCLUDE_DIRS, 'excludeExts' => DEFAULT_EXCLUDE_EXTS]);
        $names = array_column($res['components'], 'name');
        ok($names === ['compA', 'compB', '한글컴포넌트', '_root'], '컴포넌트: compA, compB, 한글컴포넌트, _root (최상위 MyProject 안쪽 기준)', json_encode($names, JSON_UNESCAPED_UNICODE));
        ok($res['engine']['runner'] === 'exe' && $res['engine']['version'] === $exeVersion && $res['engine']['file'] === $exeSel['file'], "engine: {$exeSel['file']} (가장 높은 버전)", json_encode($res['engine']));
        ok(($res['skipped']['items'][0]['path'] ?? '') === 'MyProject/node_modules', '최상위 node_modules 는 컴포넌트에서 제외', json_encode($res['skipped'] ?? null, JSON_UNESCAPED_UNICODE));
        ok($res['source'] === ['type' => 'folder', 'name' => 'MyProject'], 'source 이름에서 경로 제거', json_encode($res['source'], JSON_UNESCAPED_UNICODE));        ok(!is_dir(WORK_DIR . "/$jobId"), '측정 후 작업 폴더 삭제');
        $json = json_encode($res, JSON_UNESCAPED_UNICODE);
        ok(!str_contains($json, str_replace('\\', '/', WORK_DIR)) && !str_contains($json, WORK_DIR) && !str_contains($json, $tmpRoot), '응답에 서버 경로가 없음');

        foreach (['compA', 'compB', '_root'] as $name) {
            $c = component($res, $name);
            $cliDir = $name === '_root' ? $tmpRoot . '/rootfiles' : "$sampleDir/$name";
            if ($name === '_root') {
                write_file("$cliDir/README.md", sample_files()['README.md']);
                write_file("$cliDir/setup.sh", sample_files()['setup.sh']);
            }
            $cli = cli_cloc($cliDir);
            ok($c !== null && $c['ok'] && $c['languages'] === $cli && $cli !== [], "$name = 명령행 cloc", 'API: ' . langs_str($c['languages'] ?? []) . ' / CLI: ' . langs_str($cli));
        }
        $a = component($res, 'compA');
        ok(!in_array('JSON', array_column($a['languages'], 'language'), true)
            && array_sum(array_column($a['languages'], 'files')) === 3, 'compA: node_modules, min.js, map 제외 (파일 3개: js, css, ts)', langs_str($a['languages']));
        $b = component($res, 'compB');
        ok(!in_array('JavaScript', array_column($b['languages'], 'language'), true), 'compB: build/ 제외', langs_str($b['languages']));
        ok($a['totals'] === cloc_sum($a['languages']), 'totals = languages 합계');

        // 하위 폴더별 / 파일 타입별 집계
        $byName = fn(array $list): array => array_column($list, null, 'name');
        $sumOk = true;
        foreach ($res['components'] as $c) {
            $sumOk = $sumOk && cloc_sum($c['folders']) === $c['totals'] && cloc_sum($c['exts']) === $c['totals'];
        }
        ok($sumOk, '모든 컴포넌트: 하위 폴더별 합계 = 파일 타입별 합계 = totals');
        $af = $byName($a['folders']);
        ok(array_keys($af) === [ROOT_FILES_LABEL, 'sub'] && $af[ROOT_FILES_LABEL]['files'] === 2 && $af['sub']['files'] === 1,
            'compA 하위 폴더별: (바로 아래 파일) 2개, sub 1개', json_encode($a['folders'], JSON_UNESCAPED_UNICODE));
        $ae = $byName($a['exts']);
        ok(isset($ae['.js'], $ae['.css'], $ae['.ts']) && count($ae) === 3, 'compA 파일 타입별: .js .css .ts', json_encode(array_keys($ae)));
        $kf = array_column(component($res, '한글컴포넌트')['folders'], 'name');
        ok(in_array('하위폴더', $kf, true), '한글 하위 폴더 이름 복원', json_encode($kf, JSON_UNESCAPED_UNICODE));
        $rf = array_column(component($res, '_root')['folders'], 'name');
        ok($rf === [ROOT_FILES_LABEL], '_root: 폴더는 (바로 아래 파일) 하나', json_encode($rf, JSON_UNESCAPED_UNICODE));
        ok(!str_contains(json_encode($res, JSON_UNESCAPED_UNICODE), '한글파일.py') && !str_contains(json_encode($res), 'util.ts'), '응답에 파일 이름이 없음');
        ok(file_type_label('Makefile') === NO_EXT_LABEL && file_type_label('a.JS') === '.js' && file_type_label('.bashrc') === NO_EXT_LABEL, '파일 타입 이름 규칙');
        $r = component($res, '_root');
        ok(($r['ignored']['count'] ?? 0) === 0, '_root: 인식 불가 파일 없음');
        $ig = $b['ignored'];
        ok($ig['count'] === 1 && ($ig['reasons'][0]['reason'] ?? '') === 'language unknown' && !str_contains(json_encode($ig), '/'),
            'compB: 인식 불가 파일(notes.zzz) 1개를 경로 없이 요약', json_encode($ig));
        ok($a['ignored']['count'] === 0, 'compA: 제외 규칙으로 뺀 min.js, map 은 인식 불가로 세지 않음', json_encode($a['ignored']));

        $k = component($res, '한글컴포넌트');
        $cliKorean = cli_cloc("$sampleDir/한글컴포넌트");
        $cliAscii = cli_cloc($asciiDir . '/hangul_comp');
        ok($k !== null && $k['ok'] && $k['languages'] === $cliAscii && in_array('Python', array_column($k['languages'], 'language'), true),
            '한글컴포넌트 = 명령행 cloc (같은 내용의 ASCII 경로 복사본, 한글 파일명 포함)', 'API: ' . langs_str($k['languages'] ?? []) . ' / CLI: ' . langs_str($cliAscii));
        echo '       참고: 명령행 cloc 에 한글 경로를 그대로 주면 → ' . ($cliKorean === [] ? '결과 없음 (cloc exe 가 한글 경로를 읽지 못함)' : langs_str($cliKorean)) . "\n";

        // ------------------------------------------------------------ 측정 (single)
        section('측정: 전체를 하나로 (single)');
        $jobId = upload_file($sampleZip, 4096, 'zip', 'MyProject.zip');
        $res = measure_job(['jobId' => $jobId, 'mode' => 'single']);
        $cliAll = cli_cloc($asciiDir);
        ok(count($res['components']) === 1 && $res['components'][0]['name'] === 'MyProject', 'single: 컴포넌트명 = zip 이름(확장자 제외)', json_encode(array_column($res['components'], 'name')));
        ok($res['components'][0]['languages'] === $cliAll, 'single = 명령행 cloc (전체)', 'API: ' . langs_str($res['components'][0]['languages']) . ' / CLI: ' . langs_str($cliAll));
        ok($res['excludes']['dirs'] === DEFAULT_EXCLUDE_DIRS, '제외 규칙 생략 시 기본값 사용');
        $sf = array_column($res['components'][0]['folders'], 'name');
        sort($sf);
        $expectF = [ROOT_FILES_LABEL, 'compA', 'compB', '한글컴포넌트'];
        sort($expectF);
        ok($sf === $expectF, 'single 하위 폴더별: 최상위 폴더 안쪽 기준 (node_modules 는 제외)', json_encode($sf, JSON_UNESCAPED_UNICODE));

        // ------------------------------------------------------------ 실패 격리
        section('실패 격리: 잘못된 폴더 / 제한 시간 초과');
        $jobId = upload_file($sampleZip, 1 << 20, 'folder', 'MyProject');
        $res = measure_job(['jobId' => $jobId, 'mode' => 'byFolder'], ['componentHook' => function (array $cs): array {
            $cs[] = ['name' => 'broken', 'dir' => WORK_DIR . '/no/such/dir'];
            $cs[] = ['name' => 'slow', 'dir' => $cs[0]['dir'], 'timeout' => 0];
            return $cs;
        }]);
        $broken = component($res, 'broken');
        $slow = component($res, 'slow');
        ok($broken !== null && $broken['ok'] === false && $broken['error'] !== '', '잘못된 폴더 → ok:false + 원인', json_encode($broken, JSON_UNESCAPED_UNICODE));
        ok($slow !== null && $slow['ok'] === false && str_contains($slow['error'], '제한 시간'), '제한 시간 초과 → ok:false + 원인', json_encode($slow, JSON_UNESCAPED_UNICODE));
        $okNames = array_column(array_filter($res['components'], fn($c) => $c['ok']), 'name');
        ok($okNames === ['compA', 'compB', '한글컴포넌트', '_root'], '나머지 컴포넌트는 정상 결과', json_encode($okNames, JSON_UNESCAPED_UNICODE));
        ok(!is_dir(WORK_DIR . "/$jobId"), '실패가 섞여도 작업 폴더 삭제');

        $jobId = upload_file($sampleZip, 1 << 20, 'folder', 'MyProject');
        $e = expect_error(fn() => measure_job(['jobId' => $jobId, 'excludeDirs' => ['node_modules', 'a,b']]));
        ok($e !== null && $e->status === 400, '잘못된 제외 규칙 값 거부 (쉼표)', $e?->getMessage() ?? '');
        $e = expect_error(fn() => measure_job(['jobId' => $jobId, 'excludeExts' => ['js;rm -rf /']]));
        ok($e !== null && $e->status === 400, '잘못된 제외 규칙 값 거부 (셸 문자)');
        $e = expect_error(fn() => measure_job(['jobId' => $jobId, 'mode' => 'weird']));
        ok($e !== null && $e->status === 400, '잘못된 mode 거부');
        $e = expect_error(fn() => measure_job(['jobId' => $jobId, 'excludeFiles' => ['a b.txt']]));
        ok($e !== null && $e->status === 400 && str_contains($e->getMessage(), '제외 파일'), '잘못된 제외 파일 값 거부 (공백)', $e?->getMessage() ?? '');

        // 제외 파일: 이름 전체 비교, 대소문자 구분, * 패턴 (APP.JS 는 app.js 와 다르므로 빠지지 않는다)
        $res = measure_job(['jobId' => $jobId, 'mode' => 'byFolder', 'excludeFiles' => ['app.js', '*.md', 'setup.sh', 'STYLE.CSS']]);
        $a = component($res, 'compA');
        $r = component($res, '_root');
        $argsF = ['--json', '--quiet', '--exclude-dir=' . implode(',', DEFAULT_EXCLUDE_DIRS), '--exclude-ext=' . implode(',', DEFAULT_EXCLUDE_EXTS),
            '--not-match-f=' . cloc_file_regex(['app.js', '*.md', 'setup.sh', 'STYLE.CSS']), "$sampleDir/compA"];
        $cliF = cloc_languages_from_json(json_decode(trim(run_process([cloc_exe_path(), ...$argsF], 120, cloc_env())['stdout']), true) ?: []);
        ok($a['ok'] && !in_array('JavaScript', array_column($a['languages'], 'language'), true) && in_array('CSS', array_column($a['languages'], 'language'), true) && $a['languages'] === $cliF,
            '제외 파일(app.js 빠짐, STYLE.CSS 는 style.css 와 달라 남음 — 대소문자 구분) = 명령행 cloc --not-match-f', langs_str($a['languages']) . ' / CLI: ' . langs_str($cliF));
        ok($r !== null && $r['ok'] && $r['totals']['files'] === 0 && $r['ignored']['count'] === 0, '_root: README.md(*.md)·setup.sh 제외, 인식 불가로 세지 않음', json_encode($r, JSON_UNESCAPED_UNICODE));
        ok($res['excludes']['files'] === ['app.js', '*.md', 'setup.sh', 'STYLE.CSS'], '응답 excludes.files');
        $res2 = measure_job(['jobId' => upload_file($sampleZip, 1 << 20, 'folder', 'MyProject'), 'mode' => 'byFolder', 'excludeFiles' => ['APP.JS', '*.MD']]);
        ok(in_array('JavaScript', array_column(component($res2, 'compA')['languages'], 'language'), true) && component($res2, '_root')['totals']['files'] === 2,
            '대소문자가 다른 패턴(APP.JS, *.MD)은 app.js·README.md 를 빼지 않음', json_encode(component($res2, '_root')['totals']));
        ok(cloc_file_regex(['package-lock.json', '*.generated.cs']) === '^(package\-lock\.json|.*\.generated\.cs)$', '제외 파일 → 정규식 변환');
        $jobId = upload_file($sampleZip, 1 << 20, 'folder', 'MyProject');
        remove_dir(WORK_DIR . "/$jobId");

        // ------------------------------------------------------------ CP949
        section('CP949 이름 (한국어 Windows 압축 프로그램)');
        $cpZip = $tmpRoot . '/cp949.zip';
        make_zip($cpZip, [
            mb_convert_encoding('한글CP/테스트.js', 'CP949', 'UTF-8') => "var a = 1;\n// c\n",
            mb_convert_encoding('한글CP/설명.md', 'CP949', 'UTF-8')   => "# t\n",
            'ascii/a.py' => "x = 1\n",
        ]);
        $raw = (new ZipArchive());
        $raw->open($cpZip);
        $rawName = $raw->statIndex(0, ZipArchive::FL_ENC_RAW)['name'];
        $raw->close();
        ok(!preg_match('//u', $rawName), 'zip 항목 이름이 실제로 CP949 바이트로 저장됨');
        $jobId = upload_file($cpZip, 1 << 20, 'zip', 'cp949.zip');
        $res = measure_job(['jobId' => $jobId]);
        $cp = component($res, '한글CP');
        ok($cp !== null && $cp['ok'] && ($cp['languages'][0]['language'] ?? '') === 'JavaScript' && $cp['totals']['files'] === 2,
            'CP949 한글 폴더명 복원 + 한글 파일도 측정', json_encode($res['components'], JSON_UNESCAPED_UNICODE));

        // ------------------------------------------------------------ tar / tar.gz
        section('tar / tar.gz');
        foreach (['tgz' => ['-czf', 'MyProject.tar.gz'], 'tar' => ['-cf', 'MyProject.tar']] as $fmt => [$flag, $fname]) {
            $out = "$tmpRoot/$fname";
            $r = run_process(['tar', $flag, $out, '-C', "$tmpRoot/sample", 'MyProject'], 120);
            if ($r['exitCode'] !== 0 || !is_file($out)) {
                skip("tar 명령으로 $fname 을 만들 수 없음: " . trim($r['stderr']));
                continue;
            }
            ok(detect_archive_format($out) === $fmt, "$fname 형식 판별 = $fmt");
            $jobId = upload_file($out, 1 << 20, 'zip', $fname);
            $st = json_decode(file_get_contents(WORK_DIR . "/$jobId/state.json"), true);
            ok(($st['archive'] ?? null) === $fmt && is_file(WORK_DIR . "/$jobId/upload.$fmt"), "업로드: upload.$fmt 로 확정");
            $res = measure_job(['jobId' => $jobId, 'mode' => 'byFolder']);
            $same = component($res, 'compA')['languages'] === cli_cloc("$sampleDir/compA")
                && component($res, 'compB')['languages'] === cli_cloc("$sampleDir/compB")
                && component($res, '한글컴포넌트')['languages'] === $cliAscii
                && component($res, '_root')['languages'] === cli_cloc($tmpRoot . '/rootfiles');
            ok($same && array_column($res['components'], 'name') === ['compA', 'compB', '한글컴포넌트', '_root'],
                "$fname (Windows tar 로 만든 것) = 명령행 cloc, 한글 이름 복원", json_encode(array_column($res['components'], 'name'), JSON_UNESCAPED_UNICODE));
            ok(!is_dir(WORK_DIR . "/$jobId"), "  → $fname 작업 폴더 삭제");
        }
        if (is_file("$tmpRoot/MyProject.tar.gz")) {
            $jobId = upload_file("$tmpRoot/MyProject.tar.gz", 4096, 'zip', 'MyProject.tar.gz');
            $res = measure_job(['jobId' => $jobId, 'mode' => 'single']);
            ok($res['components'][0]['name'] === 'MyProject' && $res['components'][0]['languages'] === cli_cloc($asciiDir),
                'tar.gz 전체를 하나로: 이름에서 .tar.gz 제거, 수치 = 명령행 cloc', $res['components'][0]['name']);
        }

        // 직접 만든 tar: GNU 긴 이름, pax 경로(한글), 심볼릭 링크, CP949 이름
        $longDir = str_repeat('d', 60) . '/' . str_repeat('e', 60);
        $tarBytes = tar_build([
            tar_entry('././@LongLink', "lt/$longDir/long.py\0", 'L'), tar_entry('lt/long.py', "x = 1\ny = 2\n"),
            tar_pax('lt/pax_dir/한글pax.js'), tar_entry('lt/pax_dir/ignored.js', "var a = 1;\n"),
            tar_entry('lt/link.js', '', '2'),
            tar_entry('lt/' . mb_convert_encoding('씨피.go', 'CP949', 'UTF-8'), "package main\n"),
            tar_entry('lt/dir_only/', '', '5'),
        ]);
        foreach (['crafted.tar' => $tarBytes, 'crafted.tar.gz' => gzencode($tarBytes)] as $fname => $bytes) {
            file_put_contents("$tmpRoot/$fname", $bytes);
            $jobId = upload_file("$tmpRoot/$fname", 1 << 20, 'zip', $fname);
            $res = measure_job(['jobId' => $jobId, 'mode' => 'byFolder']);
            $names = array_column($res['components'], 'name');
            $pax = component($res, 'pax_dir');
            $skippedReasons = array_column($res['skipped']['items'] ?? [], 'reason');
            $root = component($res, '_root');
            ok(in_array(str_repeat('d', 60), $names, true) && $pax && ($pax['languages'][0]['language'] ?? '') === 'JavaScript' && $pax['totals']['files'] === 1
                && in_array('심볼릭 링크 (건너뜀)', $skippedReasons, true) && $root && ($root['languages'][0]['language'] ?? '') === 'Go',
                "$fname: GNU 긴 이름·pax 경로(한글)·CP949 이름 복원, 링크는 건너뜀", json_encode(['names' => $names, 'skipped' => $skippedReasons], JSON_UNESCAPED_UNICODE));
        }
    }

    // ------------------------------------------------------------ 악성 tar / 손상 / 압축 폭탄
    section('악성·손상 tar');
    foreach ([
        'tar ../evil.txt'     => ['evil.tar', tar_build([tar_entry('ok/a.js', 'x'), tar_entry('../evil.txt', 'evil')])],
        'tar /abs.txt'        => ['abs.tar', tar_build([tar_entry('/abs.txt', 'evil')])],
        'tar.gz a/../../e.txt' => ['evil.tar.gz', gzencode(tar_build([tar_entry('a/../../e.txt', 'evil')]))],
        'pax path=../x'       => ['paxevil.tar', tar_build([tar_pax('../x.txt'), tar_entry('fine.txt', 'evil')])],
    ] as $label => [$fname, $bytes]) {
        file_put_contents("$tmpRoot/$fname", $bytes);
        $jobId = upload_file("$tmpRoot/$fname", 1 << 20, 'zip', $fname);
        $e = expect_error(fn() => measure_job(['jobId' => $jobId]));
        ok($e !== null && $e->status === 400 && str_contains($e->getMessage(), '안전하지 않은 경로'), "거부: $label", $e?->getMessage() ?? '거부되지 않음');
        ok(!is_dir(WORK_DIR . "/$jobId") && !file_exists(WORK_DIR . '/evil.txt') && !file_exists(WORK_DIR . '/x.txt'), '  → 파일이 생기지 않고 작업 폴더 삭제');
    }
    $t = tempnam(sys_get_temp_dir(), 'chk');
    file_put_contents($t, str_repeat('not a tar ', 100));
    $e = expect_error(fn() => upload_chunk(['jobId' => '', 'index' => '0', 'total' => '1', 'sourceType' => 'zip', 'sourceName' => 'x.tar'], $t));
    unlink($t);
    ok($e !== null && $e->status === 422 && str_contains($e->getMessage(), 'tar'), 'zip·tar·tar.gz 가 아닌 파일 거부 (422)', $e?->getMessage() ?? '');
    file_put_contents("$tmpRoot/garbage.tar.gz", gzencode(str_repeat('garbage!', 200)));
    $jobId = upload_file("$tmpRoot/garbage.tar.gz", 1 << 20, 'zip', 'garbage.tar.gz');
    $e = expect_error(fn() => measure_job(['jobId' => $jobId]));
    ok($e !== null && $e->status === 422 && str_contains($e->getMessage(), '손상'), 'gz 안이 tar 가 아니면 거부 (422)', $e?->getMessage() ?? '');
    ok(!is_dir(WORK_DIR . "/$jobId"), '  → 작업 폴더 삭제');
    file_put_contents("$tmpRoot/bomb.tar.gz", gzencode(tar_build([tar_entry('p/big.txt', str_repeat('A', 1 << 20))])));
    ok(filesize("$tmpRoot/bomb.tar.gz") < 10000, 'tar.gz 압축 폭탄 준비 (1MB → ' . filesize("$tmpRoot/bomb.tar.gz") . 'B)');
    $jobId = upload_file("$tmpRoot/bomb.tar.gz", 1 << 20, 'zip', 'bomb.tar.gz');
    $e = expect_error(fn() => measure_job(['jobId' => $jobId], ['maxBytes' => 100_000]));
    ok($e !== null && $e->status === 413, 'tar.gz 압축 해제 크기 상한 초과 → 413', $e?->getMessage() ?? '');
    $many = tar_build(array_map(fn($k) => tar_entry("p/f$k.js", 'x'), range(1, 5)));
    file_put_contents("$tmpRoot/many.tar", $many);
    $jobId = upload_file("$tmpRoot/many.tar", 1 << 20, 'zip', 'many.tar');
    $e = expect_error(fn() => measure_job(['jobId' => $jobId], ['maxFiles' => 3]));
    ok($e !== null && $e->status === 413, 'tar 파일 수 상한 초과 → 413', $e?->getMessage() ?? '');
    ok(!is_dir(WORK_DIR . "/$jobId"), '  → 작업 폴더 삭제');

    // ------------------------------------------------------------ 악성 zip
    section('악성 zip (zip slip)');
    foreach ([
        '../evil.txt'           => ['../evil.txt' => 'evil'],
        'a/../../evil.txt'      => ['ok/a.js' => 'x', 'a/../../evil.txt' => 'evil'],
        '..\\evil.txt'          => ['..\\evil.txt' => 'evil'],
        '/abs.txt'              => ['/abs.txt' => 'evil'],
        'C:/evil.txt'           => ['C:/evil.txt' => 'evil'],
    ] as $label => $entries) {
        $z = $tmpRoot . '/evil.zip';
        make_zip($z, $entries);
        $za = new ZipArchive();
        $za->open($z);
        $stored = $za->getNameIndex($za->numFiles - 1, ZipArchive::FL_ENC_RAW);
        $za->close();
        $jobId = upload_file($z, 1 << 20, 'zip', 'evil.zip');
        $e = expect_error(fn() => measure_job(['jobId' => $jobId]));
        ok($e !== null && $e->status === 400 && str_contains($e->getMessage(), '안전하지 않은 경로'), "거부: $label (zip 안 이름: $stored)", $e?->getMessage() ?? '거부되지 않음');
        ok(!is_dir(WORK_DIR . "/$jobId") && !file_exists(WORK_DIR . '/evil.txt') && !file_exists(WORK_DIR . "/$jobId/evil.txt") && !file_exists(APP_ROOT . '/evil.txt'),
            '  → evil.txt 가 생기지 않고 작업 폴더 삭제');
    }

    // ------------------------------------------------------------ zip bomb
    section('zip bomb 상한');
    $z = $tmpRoot . '/many.zip';
    make_zip($z, ['p/a.js' => 'a', 'p/b.js' => 'b', 'p/c.js' => 'c', 'p/d.js' => 'd']);
    $jobId = upload_file($z, 1 << 20, 'zip', 'many.zip');
    $e = expect_error(fn() => measure_job(['jobId' => $jobId], ['maxFiles' => 3]));
    ok($e !== null && $e->status === 413, '파일 수 상한 초과 → 413', $e?->getMessage() ?? '');
    ok(!is_dir(WORK_DIR . "/$jobId"), '  → 작업 폴더 삭제');
    $z = $tmpRoot . '/big.zip';
    make_zip($z, ['p/big.txt' => str_repeat('A', 1 << 20)]);   // 1MB → 압축하면 수 KB
    $jobId = upload_file($z, 1 << 20, 'zip', 'big.zip');
    $e = expect_error(fn() => measure_job(['jobId' => $jobId], ['maxBytes' => 100_000]));
    ok($e !== null && $e->status === 413, '압축 해제 크기 상한 초과 → 413', $e?->getMessage() ?? '');
    ok(!is_dir(WORK_DIR . "/$jobId"), '  → 작업 폴더 삭제');

    // ------------------------------------------------------------ cloc 버전 선택
    section('cloc 버전 선택 (ctool 의 가장 높은 버전)');
    $vd = $tmpRoot . '/ctool_versions';
    ensure_dir($vd);
    foreach (['cloc-2.9.exe', 'cloc-2.10.pl', 'cloc-2.9.pl', 'readme.txt', 'cloc.exe', 'cloc-2.10.1.exe'] as $f) {
        write_file("$vd/$f", 'x');
    }
    $cands = array_column(cloc_candidates('exe', $vd), 'file');
    ok($cands === ['cloc-2.10.1.exe', 'cloc-2.9.exe'], '버전 순 정렬 (2.10.1 > 2.9, 이름 규칙 밖 파일 제외)', json_encode($cands));
    ok(array_column(cloc_candidates('pl', $vd), 'file') === ['cloc-2.10.pl', 'cloc-2.9.pl'], 'pl 도 버전 순');
    if ($canRunCloc) {
        // 가장 높은 버전(가짜, 실행 안 됨)을 건너뛰고 실제 exe 를 고르는지
        $real = cloc_exe_path();
        $fake = '99.0';
        write_file("$vd/cloc-$fake.exe", 'not an executable');
        copy($real, "$vd/cloc-3.0.exe");
        $sel = cloc_select('exe', $vd);
        ok($sel !== null && $sel['file'] === 'cloc-3.0.exe' && $sel['tried'] === ["cloc-$fake.exe"] && $sel['version'] === $exeVersion,
            '실행 안 되는 최신 파일(cloc-99.0.exe)은 건너뛰고 다음 버전 사용', json_encode($sel));
    } else {
        skip('cloc 실행 필요: 실행 안 되는 최신 파일 건너뛰기');
    }

    // ------------------------------------------------------------ 이름 변환 단위 검사
    section('이름 변환');
    ok(is_cloc_safe_segment('app.js') && !is_cloc_safe_segment('한글.js') && !is_cloc_safe_segment('a:b') && !is_cloc_safe_segment('CON') && !is_cloc_safe_segment('x.'),
        'cloc 에 그대로 쓸 수 있는 이름 판정');
    ok(substitute_segment('한글.min.js', 1) === '_u1.min.js' && substitute_segment('폴더', 2) === '_u2', '대체 이름은 확장자 유지');
    ok(last_segment('C:\\a\\한글폴더') === '한글폴더' && last_segment('a/b/') === 'b', 'last_segment (한글 안전)');

    // ------------------------------------------------------------ 관리자 비밀번호 (임시 파일로, 실제 settings/ 는 건드리지 않음)
    section('관리자 비밀번호');
    require_once dirname(__DIR__) . '/api/admin_password.php';
    $pwFile = $tmpRoot . '/admin_password.json';
    ok(admin_password_is_default($pwFile) && admin_password_version($pwFile) === 0, '파일이 없으면 초기 비밀번호 (version 0)');
    ok(admin_password_verify(ADMIN_DEFAULT_PASSWORD, $pwFile) && ADMIN_DEFAULT_PASSWORD === 'admin' && !admin_password_verify('Admin', $pwFile) && !admin_password_verify('', $pwFile),
        '초기 비밀번호 admin 만 통과 (대소문자 구분)');
    $bad = [];
    foreach (['', 'abc', ' abcd', 'abcd ', "ab\ncd", str_repeat('a', 73), str_repeat('가', 25), 123] as $p) {
        try { admin_password_validate($p); $bad[] = var_export($p, true); } catch (ApiError $e) { /* 기대한 거부 */ }
    }
    ok($bad === [], '규칙에 맞지 않는 새 비밀번호 거부 (짧음·앞뒤 공백·제어 문자·72바이트 초과·문자열 아님)', implode(', ', $bad));
    ok(admin_password_validate('비번1234') === '비번1234' && admin_password_validate(str_repeat('a', 72)) === str_repeat('a', 72), '한글·72바이트까지 허용');
    $v1 = admin_password_set('NewPass#1', $pwFile);
    $saved = json_decode((string)file_get_contents($pwFile), true);
    ok($v1 === 1 && !admin_password_is_default($pwFile) && admin_password_verify('NewPass#1', $pwFile) && !admin_password_verify('admin', $pwFile),
        '바꾸면 새 비밀번호만 통과, 초기 비밀번호는 거부 (version 1)');
    ok(str_starts_with((string)$saved['hash'], '$2y$') && !str_contains((string)file_get_contents($pwFile), 'NewPass'), '파일에는 bcrypt 해시만 저장 (원문 없음)');
    ok(admin_password_set('Second#2', $pwFile) === 2 && admin_password_version($pwFile) === 2, '다시 바꾸면 version 증가 (이전 토큰 무효용)');
    file_put_contents($pwFile, '{broken');
    $threw = false;
    try { admin_password_verify('admin', $pwFile); } catch (ApiError $e) { $threw = str_contains($e->getMessage(), '지우면'); }
    ok($threw && admin_password_version($pwFile) === -1, '손상된 파일: 초기 비밀번호로 몰래 돌아가지 않고 오류 (토큰도 모두 무효)');
    unlink($pwFile);
    ok(admin_password_is_default($pwFile), '파일을 지우면 초기 비밀번호로 돌아감');

    // ------------------------------------------------------------ 정리
    section('정리 (24시간 지난 작업)');
    $old = WORK_DIR . '/' . new_job_id();
    $fresh = WORK_DIR . '/' . new_job_id();
    $oldTmp = WORK_TMP_DIR . '/old_leftover.tmp';
    ensure_dir($old . '/src');
    ensure_dir($fresh);
    write_file($old . '/state.json', '{}');
    write_file($oldTmp, 'x');
    $past = time() - WORK_RETENTION_SEC - 3600;
    touch($old . '/state.json', $past);
    touch($old . '/src', $past);
    touch($old, $past);
    touch($oldTmp, $past);
    cleanup_stale_work();
    ok(!is_dir($old), '24시간 지난 작업 폴더 삭제');
    ok(is_dir($fresh), '진행 중(최근) 작업 폴더는 유지');
    ok(!file_exists($oldTmp), 'work/_tmp 의 오래된 잔여물 삭제');
    remove_dir($fresh);
} catch (Throwable $e) {
    $GLOBALS['fail']++;
    echo "\n  ERROR " . get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
} finally {
    remove_dir($tmpRoot);
    section('잔여물');
    $left = array_diff(job_dirs(), $jobsBefore);
    ok($left === [], 'work/ 에 작업 폴더가 남지 않음', implode(', ', $left));
    $tmpLeft = array_filter(scandir(WORK_TMP_DIR) ?: [], fn($e) => preg_match('/^(out|err)/', $e));
    ok($tmpLeft === [], 'work/_tmp 에 프로세스 출력 임시 파일이 남지 않음', implode(', ', $tmpLeft));
}

echo "\n결과: 통과 {$GLOBALS['pass']}, 실패 {$GLOBALS['fail']}" . ($GLOBALS['skip'] ? ', 건너뜀 ' . count($GLOBALS['skip']) : '') . "\n";
foreach ($GLOBALS['skip'] as $s) {
    echo "  건너뜀: $s\n";
}
exit($GLOBALS['fail'] > 0 ? 1 : 0);
