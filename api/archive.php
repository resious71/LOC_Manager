<?php
declare(strict_types=1);

/*
 * 압축 파일 읽기: zip / tar / tar.gz(.tgz).
 * api/jobs.php 의 extract_archive_safely() 가 사용한다 (직접 호출하지 않음, api/.htaccess 로 차단).
 *
 * 두 형식 모두 walk($fn) 하나로 항목을 차례로 넘겨준다:
 *   $fn(int $i, array{raw: string, isDir: bool, size: int, skip: ?string} $meta, callable $read)
 *   $read(int $n): string|false  — 파일 내용을 최대 $n 바이트씩 읽는다. 끝이면 '', 읽을 수 없는 항목이면 false.
 * tar 는 PharData 를 쓰지 않고 직접 읽는다. PharData 는 tar.gz 를 열 때 전체를 먼저 풀어 버려
 * 압축 폭탄(작은 gz 가 수 GB 로 풀림)을 막을 수 없기 때문이다. 여기서는 gz 를 스트림으로 풀며 한 블록씩 읽는다.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'common.php';

const ARCHIVE_FORMATS = ['zip' => 'zip', 'tar' => 'tar', 'tgz' => 'tar.gz'];

/** 파일 앞부분으로 형식을 알아낸다: zip | tgz | tar | null */
function detect_archive_format(string $path): ?string
{
    $head = (string)@file_get_contents($path, false, null, 0, 512);
    if (str_starts_with($head, "PK\x03\x04") || str_starts_with($head, "PK\x05\x06")) {
        return 'zip';
    }
    if (str_starts_with($head, "\x1f\x8b")) {
        return 'tgz';   // gzip. 안에 tar 가 있는지는 풀면서 확인한다
    }
    if (strlen($head) === 512 && tar_header_valid($head)) {
        return 'tar';
    }
    return null;
}

/** tar 헤더 블록(512바이트)의 체크섬이 맞는지 */
function tar_header_valid(string $hdr): bool
{
    $field = trim(substr($hdr, 148, 8), " \0");
    if ($field === '' || !preg_match('/^[0-7]+$/', $field)) {
        return false;
    }
    $sum = 0;
    for ($k = 0; $k < 512; $k++) {
        $sum += ($k >= 148 && $k < 156) ? 32 : ord($hdr[$k]);
    }
    return $sum === octdec($field);
}

/** pax 확장 헤더("길이 키=값\n" 반복)를 읽는다. */
function tar_parse_pax(string $data): array
{
    $out = [];
    $pos = 0;
    $len = strlen($data);
    while ($pos < $len) {
        $sp = strpos($data, ' ', $pos);
        if ($sp === false) {
            break;
        }
        $recLen = (int)substr($data, $pos, $sp - $pos);
        if ($recLen <= 0 || $pos + $recLen > $len) {
            break;
        }
        $rec = substr($data, $sp + 1, $recLen - ($sp - $pos) - 2);   // 끝의 "\n" 제외
        $eq = strpos($rec, '=');
        if ($eq !== false) {
            $out[substr($rec, 0, $eq)] = substr($rec, $eq + 1);
        }
        $pos += $recLen;
    }
    return $out;
}

final class ZipSource
{
    private ZipArchive $zip;

    public function __construct(string $path)
    {
        if (!class_exists('ZipArchive')) {
            throw new ApiError('서버에 PHP zip 확장이 없어 압축을 풀 수 없습니다. php.ini 에서 extension=zip 을 켜고 Apache를 다시 시작해 주세요.', 500);
        }
        $this->zip = new ZipArchive();
        if ($this->zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new ApiError('zip 파일을 열 수 없습니다. 파일이 손상되었거나 zip 형식이 아닙니다.', 422);
        }
    }

    public function walk(callable $fn): void
    {
        for ($i = 0; $i < $this->zip->numFiles; $i++) {
            $st = $this->zip->statIndex($i, ZipArchive::FL_ENC_RAW);
            if ($st === false) {
                throw new ApiError('zip 항목을 읽을 수 없습니다. 파일이 손상되었습니다.', 422);
            }
            $isDir = str_ends_with(str_replace('\\', '/', $st['name']), '/');
            $stream = null;
            $read = function (int $n) use (&$stream, $i) {
                if ($stream === null) {
                    $stream = $this->zip->getStreamIndex($i);   // 처음 읽을 때 연다 (암호화 등은 false)
                }
                if ($stream === false) {
                    return false;
                }
                if (feof($stream)) {
                    return '';
                }
                $buf = fread($stream, $n);
                return $buf === false ? false : $buf;
            };
            try {
                $fn($i, ['raw' => $st['name'], 'isDir' => $isDir, 'size' => (int)$st['size'], 'skip' => null], $read);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }
    }

    public function close(): void
    {
        $this->zip->close();
    }
}

final class TarSource
{
    public function __construct(private string $path, private bool $gz)
    {
    }

    private function corrupt(): ApiError
    {
        return new ApiError(($this->gz ? 'tar.gz' : 'tar') . ' 파일이 손상되었거나 ' . ($this->gz ? 'tar.gz' : 'tar') . ' 형식이 아닙니다.', 422);
    }

    /** 정확히 $n 바이트를 읽는다 (파일 끝이면 짧게). gz 스트림은 한 번에 덜 줄 수 있으므로 반복한다. */
    private function readExact($h, int $n): string
    {
        $buf = '';
        while (strlen($buf) < $n) {
            $chunk = $this->gz ? gzread($h, $n - strlen($buf)) : fread($h, $n - strlen($buf));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $buf .= $chunk;
        }
        return $buf;
    }

    private function skip($h, int $n): void
    {
        while ($n > 0) {
            $chunk = $this->readExact($h, min($n, 1024 * 1024));
            if ($chunk === '') {
                throw $this->corrupt();
            }
            $n -= strlen($chunk);
        }
    }

    public function walk(callable $fn): void
    {
        $h = $this->gz ? @gzopen($this->path, 'rb') : @fopen($this->path, 'rb');
        if ($h === false) {
            throw $this->corrupt();
        }
        try {
            $i = 0;
            $longName = null;   // GNU 긴 이름('L')
            $paxPath = null;    // pax 확장 헤더의 path=
            $first = true;
            while (true) {
                $hdr = $this->readExact($h, 512);
                if ($hdr === '') {
                    break;   // 끝 표시(빈 블록 두 개) 없이 끝난 tar 도 받아 준다
                }
                if (strlen($hdr) < 512) {
                    throw $this->corrupt();
                }
                if ($hdr === str_repeat("\0", 512)) {
                    break;   // 끝 표시
                }
                if (!tar_header_valid($hdr)) {
                    throw $this->corrupt();
                }
                $first = false;
                $type = $hdr[156];
                $sizeField = substr($hdr, 124, 12);
                if (ord($sizeField[0]) & 0x80) {
                    throw new ApiError('tar 안에 너무 큰 파일(8GB 이상)이 있습니다.', 413);
                }
                $size = (int)octdec(trim($sizeField, " \0"));
                $padded = (int)(ceil($size / 512) * 512);
                $name = rtrim(substr($hdr, 0, 100), "\0");
                if (substr($hdr, 257, 5) === 'ustar') {
                    $prefix = rtrim(substr($hdr, 345, 155), "\0");
                    if ($prefix !== '') {
                        $name = $prefix . '/' . $name;
                    }
                }

                if ($type === 'L') {                       // GNU 긴 이름: 다음 항목의 이름
                    $longName = rtrim($this->readExact($h, $size), "\0");
                    $this->skip($h, $padded - $size);
                    continue;
                }
                if ($type === 'x') {                       // pax 확장 헤더: 다음 항목의 path
                    $pax = tar_parse_pax($this->readExact($h, $size));
                    $this->skip($h, $padded - $size);
                    $paxPath = $pax['path'] ?? null;
                    continue;
                }
                if ($type === 'g' || $type === 'K') {      // pax 전역 헤더, GNU 긴 링크 대상: 쓰지 않음
                    $this->skip($h, $padded);
                    continue;
                }
                if ($paxPath !== null) {
                    $name = $paxPath;
                } elseif ($longName !== null) {
                    $name = $longName;
                }
                $paxPath = $longName = null;

                $isFile = $type === '0' || $type === "\0" || $type === '7';
                $isDir = !$isFile && ($type === '5' || str_ends_with($name, '/'));
                $skip = ($isFile || $isDir) ? null : match ($type) {
                    '1' => '하드 링크 (건너뜀)',
                    '2' => '심볼릭 링크 (건너뜀)',
                    default => '지원하지 않는 tar 항목 (건너뜀)',
                };
                $remaining = $isFile ? $size : 0;
                $read = function (int $n) use (&$remaining, $h) {
                    if ($remaining <= 0) {
                        return '';
                    }
                    $chunk = $this->readExact($h, min($n, $remaining));
                    if ($chunk === '') {
                        throw $this->corrupt();
                    }
                    $remaining -= strlen($chunk);
                    return $chunk;
                };
                $fn($i++, ['raw' => $name, 'isDir' => $isDir, 'size' => $isFile ? $size : 0, 'skip' => $skip], $read);
                // 읽지 않은 내용과 512바이트 맞춤을 건너뛴다
                $consumed = $isFile ? $size - $remaining : 0;
                $this->skip($h, $padded - $consumed);
            }
            if ($first) {
                throw $this->corrupt();   // 항목이 하나도 없는(빈 블록뿐인) 파일
            }
        } finally {
            $this->gz ? gzclose($h) : fclose($h);
        }
    }

    public function close(): void
    {
    }
}

/** @param 'zip'|'tar'|'tgz' $format */
function open_archive(string $path, string $format): ZipSource|TarSource
{
    return match ($format) {
        'zip' => new ZipSource($path),
        'tar' => new TarSource($path, false),
        'tgz' => new TarSource($path, true),
        default => throw new ApiError('지원하지 않는 압축 형식입니다.', 422),
    };
}
