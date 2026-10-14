<?php
declare(strict_types=1);

/*
 * LOC_Mgmt 설정. 경로, 제한값, 시간대는 모두 이 파일에서만 관리한다.
 * 경로는 이 파일 위치(__DIR__) 기준 절대 경로다.
 */

// ---- 경로 ----
const APP_ROOT       = __DIR__;                                         // 프로젝트 루트
const CTOOL_DIR      = APP_ROOT . DIRECTORY_SEPARATOR . 'ctool';        // cloc 실행 파일 폴더 (웹 접근 차단)
const HISTORY_DIR    = APP_ROOT . DIRECTORY_SEPARATOR . 'history';      // 측정 결과 JSON 저장소 (웹 접근 차단)
const WORK_DIR       = APP_ROOT . DIRECTORY_SEPARATOR . 'work';         // 업로드/압축 해제 임시 폴더 (웹 접근 차단)
const WORK_TMP_DIR   = WORK_DIR . DIRECTORY_SEPARATOR . '_tmp';         // 외부 프로세스용 TEMP (cloc exe가 내부 파일을 여기 푼다)
const SETTINGS_DIR   = APP_ROOT . DIRECTORY_SEPARATOR . 'settings';     // 관리 탭에서 바꾸는 설정 (웹 접근 차단)
const RULES_FILE     = SETTINGS_DIR . DIRECTORY_SEPARATOR . 'exclude_rules.json'; // 제외 규칙 목록 (없으면 아래 DEFAULT_EXCLUDE_* 로 시작)
const ADMIN_PASSWORD_FILE = SETTINGS_DIR . DIRECTORY_SEPARATOR . 'admin_password.json'; // 관리자 비밀번호 해시 (없으면 아래 초기 비밀번호)

// ---- 관리자 비밀번호 ----
// 관리 탭 → '비밀번호 변경'에서 바꾸면 settings/admin_password.json 에 해시로 저장된다.
// 비밀번호를 잊으면 그 파일을 지우면 아래 초기 비밀번호로 돌아간다.
const ADMIN_DEFAULT_PASSWORD   = 'admin';
const ADMIN_PASSWORD_MIN_CHARS = 4;    // 새 비밀번호 최소 길이 (글자)
const ADMIN_PASSWORD_MAX_BYTES = 72;   // 최대 길이 (바이트, bcrypt 가 72바이트까지만 쓰므로)
// cloc 실행 파일: ctool 안의 cloc-<버전>.exe (기본) / cloc-<버전>.pl (Perl 대체) 중 버전이 가장 높은 것을 쓴다.
// 새 버전을 ctool 에 넣기만 하면 된다. 가장 높은 exe 가 실행되지 않으면 그다음 버전으로 대체한다.
const CLOC_FILE_PATTERN = '/^cloc-(\d+(?:\.\d+)*)\.(exe|pl)$/i';
const PERL_BIN       = 'perl';  // Perl 실행 파일. PATH에 없으면 절대 경로로 바꾼다 (예: 'C:\\Strawberry\\perl\\bin\\perl.exe')

// ---- 시간 ----
const APP_TIMEZONE   = 'Asia/Seoul';  // history 파일명의 날짜와 measuredAt 기준 시간대

// ---- cloc 실행 ----
const CLOC_TIMEOUT_SEC      = 120;  // 컴포넌트 하나당 cloc 실행 제한 시간 (초)
const ENV_CHECK_TIMEOUT_SEC = 60;   // 환경 점검에서 --version / perl -v 제한 시간 (초). exe 첫 실행은 압축 해제로 느리다

// ---- 업로드 / 압축 해제 ----
const UPLOAD_CHUNK_BYTES = 8 * 1024 * 1024;         // 브라우저가 나눠 보내는 조각 크기 (바이트, 8MB)
const UPLOAD_MAX_BYTES   = 2 * 1024 * 1024 * 1024;  // 업로드 zip 하나의 최대 크기 (바이트, 2GB)
const EXTRACT_MAX_BYTES  = 2 * 1024 * 1024 * 1024;  // 압축 해제 총 크기 상한 (바이트, 2GB)
const EXTRACT_MAX_FILES  = 200000;                  // 압축 해제 파일 수 상한 (개)
const WORK_RETENTION_SEC = 24 * 60 * 60;            // 정리되지 못한 작업 폴더 보관 시간 (초, 24시간)

// ---- 요청 / history ----
const REQUEST_BODY_MAX_BYTES   = 5 * 1024 * 1024;  // JSON 요청 본문 크기 상한 (바이트, 5MB)
const COMPONENT_NAME_MAX_CHARS = 80;               // 컴포넌트명 최대 길이 (글자)
const HISTORY_SAVE_MAX_RETRY   = 20;               // 파일명 충돌 시 회차 재계산 최대 횟수 (회)

// ---- 기본 제외 규칙 (prd.md FR-3) ----
// 관리 탭 '제외 규칙 관리'에서 규칙을 바꾸면 settings/exclude_rules.json 이 우선한다. 아래 값은 처음 값과 '초기화' 값이다.
const DEFAULT_EXCLUDE_DIRS = ['node_modules', '.git', '.svn', 'bin', 'obj', 'dist', 'build', 'target', 'vendor', '__pycache__', '.idea', '.vs'];
const DEFAULT_EXCLUDE_EXTS = ['min.js', 'min.css', 'map'];
const DEFAULT_EXCLUDE_FILES = [];   // 제외 파일 이름 (예: 'package-lock.json', '*.generated.cs'). 기본은 없음

date_default_timezone_set(APP_TIMEZONE);
