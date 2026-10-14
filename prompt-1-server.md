# 1단계 프롬프트: XAMPP 프로젝트 골격, 설정, history API, 환경 점검

먼저 같은 폴더의 `prd.md`(v2)를 끝까지 읽고 그 내용을 기준으로 작업하세요. 이번 단계에서는 PHP 백엔드의 기반만 만듭니다. 업로드와 cloc 실행(2단계), 화면(3단계)은 만들지 마세요.

## 전제
- 서버는 XAMPP for Windows(Apache + PHP 8.x)이고, 프로젝트 폴더는 `htdocs` 아래에 둡니다(예: `C:\xampp\htdocs\LOC_Mgmt`).
- 구현 언어는 HTML, JavaScript, PHP뿐입니다. Composer, 프레임워크, 데이터베이스는 쓰지 않습니다.
- `ctool/cloc-2.10.exe`, `ctool/cloc-2.10.pl` 은 이미 있습니다. 수정하거나 재생성하지 마세요.

## 만들 것
1. 폴더 구조 (prd.md 8장): `api/`, `assets/`, `lib/`, `history/`, `work/`
2. `config.php`
   - 경로: 프로젝트 루트, `ctool`, `history`, `work` (모두 `__DIR__` 기준 절대 경로)
   - 시간대 `Asia/Seoul`, 컴포넌트당 cloc 제한 시간(120초), 업로드 조각 크기(8MB), 압축 해제 상한(2GB, 20만 파일), 작업 폴더 보관 시간(24시간)
   - 값은 배열이나 상수로 한 곳에서 관리하고, 이름과 단위를 주석으로 적습니다.
3. `api/common.php`
   - JSON 응답 함수(상태 코드, `Content-Type: application/json; charset=utf-8`, `Cache-Control: no-store`)
   - 오류 응답 형식 `{ "error": "사용자가 읽을 수 있는 문장" }`
   - 컴포넌트명 정리 함수: `\ / : * ? " < > |` 와 제어 문자를 `_` 로 치환, 앞의 `.` 제거, 최대 80자, 빈 이름은 오류
   - 요청 본문(JSON) 읽기, 크기 제한 5MB
4. `api/history.php`
   - `POST`: 컴포넌트 결과 1건 저장 (prd.md 4.1 형식). 본문의 `languages` 로 `totals` 를 서버가 다시 계산하고, 숫자는 0 이상 정수로 정리합니다. 경로와 소스 내용은 받지도 저장하지도 않습니다.
   - 파일명 `컴포넌트_YYMMDDN.json`: N은 같은 컴포넌트의 해당 날짜 수행 횟수이며 0을 채우지 않습니다(1, 2, ... 10). 날짜는 `config.php` 의 시간대 기준입니다.
   - N 계산: `history` 폴더의 기존 파일을 정규식으로 찾아 최댓값+1. 저장은 `fopen($path, 'x')` 로 "이미 있으면 실패"하게 하고, 실패하면 번호를 다시 계산해 재시도합니다(최대 20회). 동시 요청에도 덮어쓰지 않아야 합니다.
   - `GET`(파라미터 없음): 이력 목록(파일명, 컴포넌트, 회차, 날짜, 측정 시각, 합계)을 최신순으로 반환. 손상된 JSON 파일은 목록에서 건너뜁니다.
   - `GET ?file=이름.json`: 이력 1건 반환. `basename` 비교와 확장자 검사로 `history` 밖 접근을 막습니다.
   - JSON은 `JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT` 로 저장하고, 파일은 UTF-8입니다. 한글 파일명이 Windows에서 깨지지 않게 확인합니다.
5. `api/env.php`: 환경 점검(prd.md FR-8)
   - `ctool/cloc-2.10.exe` 존재 여부와 `--version` 실행 결과, `cloc-2.10.pl` 존재 여부, Perl 실행 가능 여부(`perl -v`)
   - PHP 버전, `zip` 확장 로드 여부, `exec`/`proc_open` 사용 가능 여부(`disable_functions`)
   - `history/`, `work/` 쓰기 가능 여부, 서버 시간대
   - `upload_max_filesize`, `post_max_size`, `max_execution_time`, `memory_limit` 현재 값
   - 항목마다 `ok` 와 `message`(문제일 때 해결 방법 포함)를 돌려줍니다. 실행은 인자 배열 방식(`proc_open` 배열 형식)으로 하고 셸 문자열을 조립하지 마세요.
6. `.htaccess`
   - `history/`, `work/`, `ctool/` 각 폴더에 `Require all denied` (Apache 2.4)
   - `api/` 에는 PHP 파일만 실행되게 두고 다른 파일은 접근을 막습니다.
7. `README.md`: XAMPP 설치 위치, 프로젝트 배치, Apache 시작, `php.ini` 에서 확인할 항목(`extension=zip`, `upload_max_filesize`, `post_max_size`, `max_execution_time`), 폴더 쓰기 권한, 인증이 없다는 점, `lib/` 에 넣을 파일(Chart.js, JSZip)과 받는 방법

## 하지 말 것
- 사용자 입력을 셸 명령 문자열에 이어 붙이지 마세요.
- 외부 CDN 주소나 외부 통신 코드를 넣지 마세요.

## 완료 조건 (직접 실행해서 확인)
- `php -l` 로 모든 PHP 파일의 문법을 검사합니다. 가능하면 `php -S` 로 띄워 확인하고, XAMPP에서 확인할 수 없으면 확인하지 못했다고 보고합니다.
- 같은 컴포넌트를 두 번 저장하면 `..._YYMMDD1.json`, `..._YYMMDD2.json` 이 생기고, 동시 요청 20개에서도 번호가 겹치지 않습니다.
- `../` 를 넣은 파일명, 잘못된 확장자, 5MB 초과 본문이 거부됩니다.
- 한글 컴포넌트명과 특수문자가 든 이름이 올바르게 저장됩니다.
- `api/env.php` 가 cloc 실행 여부와 각 항목 상태를 정확히 보고합니다.
- 브라우저에서 `history/`, `work/`, `ctool/` 주소를 직접 열면 403입니다.
- 확인한 내용과 확인하지 못한 내용을 마지막에 구분해서 보고하세요.
