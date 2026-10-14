<?php
declare(strict_types=1);

/*
 * zip 조각 업로드 API
 *   POST multipart/form-data: jobId(첫 조각은 비움), index, total, chunk(파일), sourceType(folder|zip), sourceName
 *   → { jobId, received, total, complete }
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'jobs.php';

run_api(function (): void {
    require_method('POST');
    cleanup_stale_work();

    // post_max_size 를 넘으면 PHP가 $_POST, $_FILES 를 모두 비운다.
    $length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    $postMax = ini_bytes(ini_get('post_max_size'));
    if ($postMax > 0 && $length > $postMax) {
        throw new ApiError('조각이 서버 한도(post_max_size = ' . ini_get('post_max_size') . ')보다 큽니다. php.ini 의 post_max_size 를 늘려 주세요.', 413);
    }

    $file = $_FILES['chunk'] ?? null;
    if (!is_array($file) || is_array($file['error'] ?? null)) {
        throw new ApiError('업로드 조각(chunk)이 없습니다.');
    }
    $err = (int)$file['error'];
    if ($err !== UPLOAD_ERR_OK) {
        throw new ApiError(match ($err) {
            UPLOAD_ERR_INI_SIZE => '조각이 서버 한도(upload_max_filesize = ' . ini_get('upload_max_filesize') . ')보다 큽니다. php.ini 의 upload_max_filesize 를 늘려 주세요.',
            UPLOAD_ERR_FORM_SIZE => '조각이 허용 크기보다 큽니다.',
            UPLOAD_ERR_PARTIAL => '조각이 끝까지 전송되지 않았습니다. 다시 시도해 주세요.',
            UPLOAD_ERR_NO_FILE => '업로드 조각(chunk)이 없습니다.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => '서버 임시 폴더에 조각을 저장하지 못했습니다. php.ini 의 upload_tmp_dir 과 디스크 공간을 확인해 주세요.',
            default => '조각 업로드 중 오류가 발생했습니다 (코드 ' . $err . ').',
        }, $err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE ? 413 : ($err === UPLOAD_ERR_PARTIAL || $err === UPLOAD_ERR_NO_FILE ? 400 : 500));
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        throw new ApiError('업로드 조각이 올바르지 않습니다.');
    }

    json_response(upload_chunk($_POST, $file['tmp_name']));
});
