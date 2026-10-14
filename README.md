# LOC_Mgmt — 컴포넌트 CLOC 측정 웹

[![CI](https://github.com/resious71/LOC_Manager/actions/workflows/ci.yml/badge.svg)](https://github.com/resious71/LOC_Manager/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-777bb4.svg)
![XAMPP](https://img.shields.io/badge/server-XAMPP%20(Apache%20%2B%20PHP)-orange.svg)

> **English summary** — LOC_Mgmt is a self-hosted intranet web app that measures lines of code per component with [cloc](https://github.com/AlDanial/cloc).
> Pick a local folder or zip in the browser; the server (XAMPP: Apache + PHP 8.1+, no database, no Composer, no CDN) runs cloc on each component,
> shows the results as a two-ring pie chart and table, exports to Excel, and keeps history as JSON files with trend charts.
> The UI and docs are in Korean. See [Contributing](CONTRIBUTING.md) · [Security](SECURITY.md) · [License](LICENSE).

부서 컴포넌트별 코드 규모(CLOC)를 cloc 2.10으로 측정하고 결과를 `history/` 에 JSON으로 남기는 사내용 웹입니다.
요구사항은 `prd.md`, 구현 단계는 `prompt-1~4.md` 를 참고하세요.

> 1~4단계(설정·history API·환경 점검, 업로드·측정 API, 웹 화면, LOC 조회 탭)가 모두 구현되어 있습니다.

**라이선스**: 이 프로젝트의 코드는 [MIT License](LICENSE) 입니다. 함께 들어 있는 cloc(`ctool/`, GPL v2 이상), Chart.js·JSZip(`lib/`, MIT)은 각자의 라이선스를 따릅니다 — [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md), [ctool/NOTICE.md](ctool/NOTICE.md).

## 1. 설치와 배치

1. **XAMPP for Windows** (PHP 8.1 이상 포함)를 설치합니다. 기본 위치는 `C:\xampp` 입니다.
2. 이 프로젝트 폴더를 `C:\xampp\htdocs\LOC_Mgmt` 로 복사합니다.
3. **XAMPP Control Panel** 에서 Apache의 **Start** 를 누릅니다.
   (`php.ini` 를 고친 뒤에는 Stop → Start 로 다시 시작해야 반영됩니다.)
4. 브라우저에서 `http://<서버주소>/LOC_Mgmt/` 를 엽니다. 화면 맨 위에 **서버 환경 정상** 이 보이면 준비가 끝난 것입니다. 문제가 있으면 원인과 해결 방법이 그 자리에 표시됩니다 (자세한 내용: `api/env.php`).

## 2. php.ini 에서 확인할 항목

XAMPP Control Panel → Apache 줄의 **Config** → **PHP (php.ini)** 로 엽니다 (보통 `C:\xampp\php\php.ini`).

| 항목 | 권장 값 | 설명 |
|---|---|---|
| `extension=zip` | 앞의 `;` 제거 | 업로드한 zip을 풀 때 필요합니다. **XAMPP 기본값은 꺼져 있습니다(`;extension=zip`).** |
| `upload_max_filesize` | `16M` 이상 | 브라우저는 8MB 조각으로 나눠 보냅니다. |
| `post_max_size` | `16M` 이상 | `upload_max_filesize` 이상이어야 합니다. |
| `max_execution_time` | `600` 또는 그대로 | 측정 API는 실행 중 `set_time_limit` 으로 늘립니다. `set_time_limit` 이 막혀 있으면 600 이상으로 올립니다. |
| `memory_limit` | `256M` 이상 | |
| `disable_functions` | `proc_open` 이 없어야 함 | cloc 실행에 `proc_open` 을 씁니다. |

시간대는 `php.ini` 의 `date.timezone` 과 관계없이 `config.php` 의 `APP_TIMEZONE`(기본 `Asia/Seoul`)을 씁니다.

### cloc 버전 올리기

`ctool/` 에 새 버전의 `cloc-<버전>.exe` (또는 `cloc-<버전>.pl`)를 넣기만 하면 됩니다. 서버는 `cloc-<버전>.exe` 중 **버전이 가장 높은 것**(예: `cloc-2.12.exe` > `cloc-2.10.exe`, 숫자 비교)을 쓰고, 그 파일이 실행되지 않으면 그다음 버전으로 대체합니다. 어떤 파일을 쓰는지는 화면 위 환경 점검(`api/env.php` 의 `cloc exe` 항목)과 측정 결과의 “실행 방식”에 나옵니다. 이름 규칙(`cloc-숫자.숫자….exe`)에 맞지 않는 파일은 쓰지 않습니다. 예전 버전 파일은 지우지 않아도 됩니다.

## 3. 폴더와 권한

| 폴더 | 용도 | 권한 |
|---|---|---|
| `ctool/` | `cloc-<버전>.exe`, `cloc-<버전>.pl` (가장 높은 버전을 씀) | Apache 실행 계정이 읽고 실행 |
| `settings/` | 관리 탭의 제외 규칙(`exclude_rules.json`), 관리자 비밀번호 해시(`admin_password.json`), 로그인 실패 기록(`auth_attempts.json`) | Apache 실행 계정이 **쓰기** |
| `history/` | 측정 결과 JSON | Apache 실행 계정이 **쓰기** |
| `work/` | 업로드·압축 해제 임시 폴더, `work/_tmp` 는 cloc exe 임시 폴더 | Apache 실행 계정이 **쓰기** |
| `api/` | PHP API (`jobs.php`, `cloc_runner.php`, `common.php` 는 내부 함수 파일) | |
| `assets/`, `lib/` | 화면 파일 (3단계) | |
| `tests/` | 명령행 테스트 (웹 접근 차단) | |

- XAMPP Apache를 콘솔(Control Panel)로 실행하면 로그인한 사용자 계정으로 돌아가므로 보통 추가 설정이 필요 없습니다.
- Apache를 **Windows 서비스**로 설치했다면 서비스 계정(기본 `Local System` 등)에 `history`, `work` 폴더의 수정 권한을 주세요 (폴더 속성 → 보안).
- `history/`, `work/`, `ctool/` 는 각 폴더의 `.htaccess`(`Require all denied`)로 웹에서 직접 열 수 없습니다(403). `api/` 는 공개 API 파일(`env.php`, `upload.php`, `measure.php`, `history.php`)만 열립니다. 루트의 `config.php`, `*.md`, 실행 파일(`.exe`, `.pl` — XAMPP 기본 설정은 `.pl` 을 CGI로 실행하려 함)도 막혀 있습니다. 폴더별 `.htaccess` 가 빠져도 열리지 않도록 루트 `.htaccess` 가 `ctool/ history/ work/ tests/` 를 한 번 더 막습니다 (대소문자 무관).
  `.htaccess` 가 동작하려면 해당 경로에 `AllowOverride All` 이 필요합니다 (XAMPP 기본 `htdocs` 설정은 이미 `All` 입니다).

## 4. 인증과 접근 제한

관리 탭만 관리자 비밀번호(초기값 `admin`, 관리 탭에서 변경)로 보호되며(6장 참고), 그 밖에는 로그인이나 권한 관리가 **없습니다**. 사내망에서 누구나 측정하고 이력을 보고 저장할 수 있습니다.
접근을 제한해야 하면 Apache 설정에서 IP 제한이나 기본 인증을 추가하세요. 예 (`C:\xampp\apache\conf\extra\httpd-xampp.conf` 끝):

```apache
<Directory "C:/xampp/htdocs/LOC_Mgmt">
    Require ip 10.0.0.0/8 192.168.0.0/16
</Directory>
```

## 5. lib/ 에 넣을 파일

외부 CDN을 쓰지 않으므로 아래 두 파일을 `lib/` 에 둡니다. 현재 Chart.js 4.5.1, JSZip 3.10.2 가 들어 있습니다 (npm 레지스트리의 sha512 무결성 값 확인). `chart.umd.js.map` 은 개발자 도구용이며 없어도 됩니다.

- `chart.umd.js` 가 없으면 차트 자리에 안내 문구가 나오고 표는 그대로 동작합니다.
- `jszip.min.js` 가 없으면 폴더 선택 측정을 할 수 없고, 압축 파일 선택은 동작합니다.

| 파일 | 받는 곳 |
|---|---|
| `lib/chart.umd.js` | Chart.js 4.x — npm 패키지 `chart.js` 의 `dist/chart.umd.js` (예: `https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.js` 를 인터넷이 되는 PC에서 받아 복사) 또는 GitHub 릴리스 |
| `lib/jszip.min.js` | JSZip 3.x — npm 패키지 `jszip` 의 `dist/jszip.min.js` (예: `https://cdn.jsdelivr.net/npm/jszip@3/dist/jszip.min.js`) 또는 GitHub `Stuk/jszip` 릴리스 |

npm이 있다면: `npm pack chart.js jszip` 으로 받은 tgz 안의 `package/dist/` 에서 위 파일을 꺼내면 됩니다.

## 6. 화면 사용 방법

탭은 `LOC 조회 | LOC 측정 | 측정 이력 | 관리` 순서이고, 페이지를 열면 **LOC 조회** 탭이 보입니다 (← → Home End 키로도 옮겨 다닙니다).

**LOC 조회 탭 (읽기 전용, 기본 탭)** — 저장된 이력을 골라 봅니다. 저장·수정·삭제는 없습니다. 탭을 열 때마다 이력 목록을 서버에서 새로 읽으며, 오른쪽 위 `새로고침` 으로 다시 읽을 수도 있습니다. 결과(요약, 차트, 범례, 표, 보기·기준, Excel 내보내기, JSON)는 측정 이력 탭과 같습니다.
- **컴포넌트** 하위 탭(기본): `컴포넌트` → `컴포넌트 버전` → `회차` 를 차례로 고릅니다. 컴포넌트는 가나다순(처음에는 가장 최근에 측정한 컴포넌트), 버전은 **높은 버전부터(내림차순, 숫자 크기로 비교: 2.10 > 2.9) 보이며 가장 높은 버전이 기본**(빈 버전 `(버전 없음)` 은 맨 뒤), 회차는 `2026-10-07 2회차 · 14:05` 형식이고 가장 최근 회차가 기본입니다. 위 상자를 바꾸면 아래 상자와 결과가 다시 채워집니다. 결과 위에 그 컴포넌트의 **측정 추이**(측정 이력 탭과 같은 막대·꺾은선·회차 표)가 함께 나오며, 추이에서 회차를 누르면 그 회차(버전이 다르면 버전도)를 고릅니다.
- **컴포넌트 그룹** 하위 탭: `컴포넌트 그룹` → `버전` → `측정` 을 고르면 그 한 번의 측정(`batch.id` 가 같은 이력)에서 저장된 컴포넌트를 한 화면에 봅니다. 그룹은 `컴포넌트 그룹`(`byFolder`)으로 측정한 이력의 상위 폴더 이름(`batch.parent`, 예전 이력처럼 비어 있으면 측정 이력 탭의 묶음 배지와 같이 소스 이름)이고, **버전은 높은 버전부터(내림차순, 숫자 크기로 비교: 2.10 > 2.9) 보이며 가장 높은 버전이 기본**입니다. `측정` 은 `2026-10-07 14:05 · 컴포넌트 12개` 형식에 최근 측정이 기본이며, 같은 그룹·버전이라도 측정이 다르면 합치지 않습니다. 이력 파일은 동시에 6개씩 읽으며 진행 상황을 보여주고, 읽지 못한 파일은 어느 컴포넌트인지 알린 뒤 나머지를 보여줍니다. 저장된 이력이 측정 때 컴포넌트 수보다 적으면 안내가 나옵니다.
  - 표의 컴포넌트 이름, 컴포넌트 범례, 차트의 컴포넌트 링 조각(밑줄·손가락 모양)을 누르면 **컴포넌트 하위 탭으로 이동**해 그 컴포넌트·버전·그 측정의 회차를 보여줍니다. 그룹 하위 탭으로 돌아오면 이전 선택이 그대로입니다. `기타 (N개)` 는 이동하지 않습니다.
  - 보기 `하위 폴더별` 일 때만 차트가 **링 3개**입니다: 가운데 원 = 그룹 전체(그룹 이름), 가운데 링 = 컴포넌트, 바깥 링 = 컴포넌트의 1단계 하위 폴더(같은 이름은 같은 색). 범례도 그룹 → 컴포넌트 → 하위 폴더 순서로 나옵니다. `언어별`·`파일 타입별` 과 컴포넌트 하위 탭은 기존과 같은 링 2개입니다.
- 이력이 없거나 목록을 읽지 못하면 안내 문장이 나오고 선택 상자는 비활성입니다.

1. **소스 선택** — `폴더 선택` 또는 `압축 파일 선택` 을 누르거나, 폴더/압축 파일 하나를 점선 영역에 끌어다 놓습니다. 압축 파일은 **zip, tar, tar.gz(.tgz)** 를 받습니다.
   - 폴더를 고르면 브라우저가 제외 규칙에 걸리는 폴더·확장자를 뺀 파일만 zip(압축 없음)으로 묶어 올립니다. 끌어다 놓을 때는 제외 폴더를 아예 읽지 않아 더 빠릅니다.
   - 압축 파일은 그대로 올리며, 제외 규칙은 서버에서 적용합니다. 한국어 Windows 압축 프로그램(CP949)으로 만든 zip/tar 의 한글 이름도 복원됩니다. tar 의 GNU 긴 이름·pax 경로를 읽고, 링크 항목은 건너뜁니다.
2. **컴포넌트 구분 / 컴포넌트 버전** — 버전(선택, 50자까지)은 history 저장 기록의 `componentVersion` 으로 남고, 결과·측정 이력 목록·측정 추이·Excel 에 표시됩니다. 컴포넌트 그룹으로 측정하면 모든 컴포넌트에 같은 버전이 붙습니다.
   컴포넌트 구분 — `단일 컴포넌트`(화면 기본값, API `mode: single`): 폴더 이름(zip 은 파일 이름)으로 컴포넌트 하나. `컴포넌트 그룹`(API `mode: byFolder`): 선택한 폴더 바로 아래 폴더 하나가 컴포넌트 하나, 바로 아래 파일은 `_root`. (API 에서 `mode` 를 생략하면 `byFolder` 입니다.)
3. **제외 폴더 / 제외 확장자 / 제외 파일** — 한 줄에 하나씩. 제외 파일은 파일 이름 전체와 비교하며(**대소문자 구분**, 제외 폴더·확장자는 구분하지 않음) `*` 를 쓸 수 있습니다 (예: `package-lock.json`, `*.generated.cs`). 폴더를 고른 경우 브라우저가 업로드에서 빼고, 서버에서는 cloc `--not-match-f` 로 적용합니다(zip 에도 적용). 영문, 숫자, `. _ - *` 만 쓸 수 있습니다 (`.map`, `*.map` 은 `map` 으로 바뀝니다). `제외 규칙 기본값` 은 토글입니다: 켜면(✓, 기본) 두 칸이 기본값으로 채워지고, 끄면 두 칸을 함께 비워 제외 없이 모든 파일을 측정합니다. 칸을 직접 고치면 꺼진 모양이 되며, 누르면 기본값으로 돌아갑니다.
4. **측정 시작** — zip 만들기 → 업로드(8MB 조각, 실패한 조각은 한 번 자동 재시도) → 서버 측정(컴포넌트 n/전체) 순서로 진행률이 표시됩니다. 측정 전까지는 `취소` 할 수 있습니다.
5. **결과**
   - 바깥 링 조각 위에는 언어/하위 폴더/파일 타입 이름이 표시되고(좁은 조각은 줄이거나 생략), 옆의 범례에는 컴포넌트 범례 아래에 바깥 링 범례(항목별 색·이름·줄 수·비율)가 나옵니다.
   - 차트: 안쪽 링은 컴포넌트, 바깥 링은 컴포넌트 안의 언어/하위 폴더/파일 타입(항목마다 다른 파스텔 색, 같은 이름은 어느 컴포넌트에서나 같은 색). 컴포넌트가 16개를 넘으면 작은 것부터 `기타` 로 묶습니다(표에는 모두 표시). 조각에 마우스를 올리면 이름, 줄 수, 비율이 나옵니다.
   - **보기** 드롭다운(`언어별`(기본) / `하위 폴더별` / `파일 타입별`)으로 바깥 링과 표의 상세 행을 바꿉니다. 하위 폴더는 컴포넌트 바로 아래 폴더 한 단계이고(바로 아래 파일은 `(바로 아래 파일)`), 파일 타입은 확장자입니다(없으면 `(확장자 없음)`). 항목이 300개를 넘으면 나머지는 `(그 외 N개)` 로 합칩니다.
   - **기준** `코드 / 주석 / 빈 줄 / 전체` 로 차트와 비율의 기준을 바꿉니다.
   - 요약 칸과 표의 코드/주석/빈 줄 옆에는 그 행 전체 줄 수 대비 비율 `(xx.x%)` 이 붙습니다.
   - 표: 컴포넌트 소계 행과 언어별 행. 실패한 컴포넌트는 원인과 함께 표시되고 나머지는 정상 표시됩니다. 좁은 화면에서는 선택한 기준 값과 비율만 보입니다.
   - 실행 방식(exe/Perl), cloc 이 인식하지 못한 파일, 경고, 측정에서 빠진 항목이 요약됩니다.
   - 표 위 오른쪽 **Export to Excel** 로 지금 보이는 표를 `.xlsx` 로 내려받습니다. `상세` 시트는 화면 표 그대로(현재 보기·비율 기준, 코드/주석/빈 줄 비율, 소계·실패·합계 행, 숫자·% 서식, 머리글 고정)이고, `정보` 시트에는 소스·실행 방식·제외 규칙·내보낸 시각이 들어갑니다. 외부 라이브러리 없이 `lib/jszip.min.js` 로 만듭니다 (`assets/xlsx-export.js`). history 결과도 같은 방식으로 내보낼 수 있습니다.
   - `저장될 JSON 미리보기` 를 펼쳐 저장 내용을 확인할 수 있습니다.
6. **history에 저장** — 성공한 컴포넌트마다 파일 하나씩 저장되고 파일명이 표시됩니다. 저장이 끝나면 버튼이 `저장됨` 으로 바뀌어 중복 저장을 막습니다. 일부가 실패하면 `실패한 N개 다시 저장` 으로 실패한 것만 다시 저장합니다.
7. **측정 이력 탭** — 저장된 이력(컴포넌트, 컴포넌트 버전, CLOC 버전, 날짜, 회차, 코드 합계)을 최신순으로 보여줍니다. 항목을 누르면 같은 차트와 표로 봅니다.
   - **Export to Excel**(표 오른쪽 위): 저장된 이력 **전체**를 `.xlsx` 로 내려받습니다(페이지·검색과 관계없음). `측정 이력` 시트(한 건 한 줄: 컴포넌트·버전·CLOC 버전·측정 묶음·날짜·회차·줄 수), `언어별 상세` 시트(이력마다 언어별 수치), `정보` 시트로 되어 있습니다.
   - **페이지 나누기**: 표 아래에서 행 수(10 / 15 / 30 / ALL, 기본 15)를 고르고 페이지를 넘깁니다. 검색하거나 행 수를 바꾸면 1페이지로 돌아갑니다. 머리글의 전체 선택은 지금 보이는 페이지만 고릅니다.
   - **측정 묶음**: 한 번의 측정에서 나온 이력(컴포넌트 그룹이면 여러 컴포넌트)은 같은 색 `상위폴더이름_버전: N개` 배지(버전이 없으면 `상위폴더이름: N개`)와 왼쪽 띠로 표시됩니다. 배지를 누르면 그 묶음이 모두 선택됩니다. 저장 기록의 `batch`(`id` = 측정 작업 ID, `size`, `mode`, `parent` = 상위 폴더 이름)로 구분하며, 이 기능 전에 저장한 이력은 `—` 입니다.
   - **함께 보기**: 이력을 체크하고 `선택한 N개 함께 보기` 를 누르면 이력 하나를 컴포넌트 하나로 놓고 같은 차트·표·보기 기준·Excel 내보내기로 봅니다. 같은 컴포넌트 이름은 `compA (26-09-30 #2)` 처럼 날짜·회차를 붙여 구분합니다 (한 번에 100개까지).
   - **측정 추이**: 같은 컴포넌트를 여러 번 저장했으면 회차별 코드·주석·빈 줄 누적 세로 막대와 직전 회차 대비 전체 줄 수 변화량 꺾은선(두 그래프 모두 최근 10회), 그리고 모든 회차의 표(이전 회차 대비 변화)를 보여줍니다. 막대·점이나 표의 회차를 누르면 그 회차 결과를 봅니다. 추이는 컴포넌트 이름이 같은 이력끼리 묶습니다.
   - 이 기능이 생기기 전에 저장한 이력에는 하위 폴더별·파일 타입별 정보가 없어 언어별로만 볼 수 있습니다.
   - **관리 탭 인증**: 관리 탭을 열려면 비밀번호를 입력해야 합니다. **초기 비밀번호는 `admin`** 이며, 설치 후 바로 관리 탭 → **비밀번호 변경** 에서 바꾸세요(초기 비밀번호를 쓰는 동안 관리 탭 위에 안내가 나옵니다). 새 비밀번호는 4자 이상, 앞뒤 공백 없이 72바이트까지이고, `settings/admin_password.json` 에 bcrypt 해시로만 저장됩니다(웹 접근 차단, git 제외). 비밀번호를 바꾸면 다른 PC·탭에서 열려 있던 관리 화면은 다시 로그인해야 하고, 바꾼 화면은 그대로 유지됩니다. **비밀번호를 잊으면** 서버에서 `settings/admin_password.json` 을 지우면 초기 비밀번호로 돌아갑니다(초기값은 `config.php` 의 `ADMIN_DEFAULT_PASSWORD`). 서버(`api/auth.php`, `api/admin_password.php`)가 확인하고 이 페이지 전용 토큰을 주며, 화면은 토큰을 메모리에만 들고 관리 API에 `X-Admin-Token` 헤더로 보냅니다. 그래서 같은 페이지 안에서는 탭을 자유롭게 오가지만, **새로고침·새 탭·브라우저를 다시 열면** 다시 입력해야 합니다. `관리 잠그기` 로 바로 잠글 수 있습니다. 10분 안에 5번 틀리면(비밀번호 변경 때 현재 비밀번호를 틀린 것도 포함) 5분간 막힙니다. 제외 규칙 API 전체와 이력 수정·삭제 API는 인증된 세션에서만 동작합니다(아니면 401). 비밀번호 확인은 서버에서 합니다.
   - **관리 탭**: 맨 오른쪽(측정 이력 탭 다음)에 있으며, 하위 탭 `제외 규칙 관리` / `저장 이력 관리` / `비밀번호 변경` / `가이드` 가 있습니다.
     - **가이드**: 설치·php.ini·폴더 권한·cloc/lib 파일·화면 사용법·제외 규칙·이력 파일·config.php 설정값·관리 인증과 접근 제한·문제 해결을 화면에서 볼 수 있게 정리한 문서입니다 (목차를 누르면 해당 절로 이동). 이 README 와 내용이 겹치므로 설치·설정 방법이 바뀌면 `index.html` 의 `#admin-guide` 도 함께 고칩니다.
     - **저장 이력 관리**: 저장된 이력의 컴포넌트 버전을 고치거나(컴포넌트 이름은 바꿀 수 없음), 한 건 또는 여러 건을 삭제합니다. 삭제한 파일은 `history/_deleted/<삭제시각>_<파일명>` 으로 옮겨지며, 되살리려면 `history/` 로 다시 옮기면 됩니다. API: `PUT api/history.php?file=…` `{ componentVersion }` (이름을 바꾸려 하면 400), `DELETE api/history.php?file=…`.
     - **제외 규칙 관리**: 제외 폴더·확장자·파일 규칙을 추가/수정/삭제하고, 규칙마다 `사용` 을 켜고 끕니다. 종류·검색으로 거를 수 있고 `config.php 기본값으로 초기화` 도 있습니다. **사용 중인 규칙이 LOC 측정 탭의 “제외 규칙 기본값”과 ZIP 측정의 기본 제외 규칙이 됩니다.** 규칙은 `settings/exclude_rules.json` 에 저장되며(웹 접근 차단), 파일이 없으면 `config.php` 의 `DEFAULT_EXCLUDE_*` 로 시작합니다. 같은 종류에 같은 패턴은 한 번만 둘 수 있습니다(제외 파일은 대소문자를 구분해 비교, 폴더·확장자는 구분하지 않음). 관리 탭 비밀번호를 아는 사람은 누구나 바꿀 수 있고, 삭제·초기화 전에만 확인을 받습니다.
8. **포털로 이동 / 테마** — 제목 오른쪽의 집 아이콘을 누르면 포털 첫 화면(`http://<서버주소>/`)으로 갑니다. 밝은/어두운 테마는 PC(시스템) 설정을 따릅니다.

### history 파일 형식

`history/컴포넌트_YYMMDDN.json` (UTF-8). 예: `compA_2609301.json`

```json
{
    "component": "compA",
    "componentVersion": "1.4.2",
    "run": 1,
    "date": "2026-09-30",
    "measuredAt": "2026-09-30T18:05:12+09:00",
    "timeZone": "Asia/Seoul",
    "engine": { "tool": "cloc", "version": "2.10", "runner": "exe" },
    "source": { "type": "folder", "name": "MyProject" },
    "excludes": { "dirs": ["node_modules", ".git"], "exts": ["min.js", "min.css", "map"] },
    "totals": { "files": 3, "code": 8, "comment": 5, "blank": 3 },
    "languages": [
        { "language": "JavaScript", "files": 1, "code": 4, "comment": 3, "blank": 1 },
        { "language": "CSS", "files": 1, "code": 2, "comment": 1, "blank": 1 }
    ],
    "folders": [
        { "name": "(바로 아래 파일)", "files": 2, "code": 6, "comment": 4, "blank": 2 },
        { "name": "sub", "files": 1, "code": 2, "comment": 1, "blank": 1 }
    ],
    "exts": [
        { "name": ".js", "files": 1, "code": 4, "comment": 3, "blank": 1 }
    ]
}
```

- `run`: 같은 컴포넌트의 그날 저장 횟수 (파일명의 N 과 같음). `date`, `measuredAt` 은 서버 시간대(`Asia/Seoul`) 기준입니다.
- `totals` 는 `languages` 합계를 서버가 다시 계산한 값입니다.
- `folders` 는 컴포넌트 바로 아래 폴더 **이름**별 합계, `exts` 는 확장자별 합계입니다. 파일 이름·파일 경로와 소스 내용은 저장하지 않습니다.

## 7. API

모든 응답은 `application/json; charset=utf-8`, `Cache-Control: no-store` 입니다. 오류는 `{ "error": "사람이 읽을 수 있는 문장" }` 형식입니다.

### `GET api/env.php` — 환경 점검

```json
{ "ok": false, "checkedAt": "2026-09-30T17:24:36+09:00",
  "checks": [ { "id": "zip", "label": "zip 확장", "ok": false, "level": "error",
                "value": "로드되지 않음", "message": "... \";extension=zip\" 줄의 앞 \";\"를 지우고 Apache를 다시 시작해 주세요." } ] }
```

`level` 은 `ok` / `warn`(측정은 되지만 확인 필요) / `error`(측정 불가)입니다. 점검 항목: PHP 버전, `proc_open`/`exec`, zip 확장, `file_uploads`, `upload_max_filesize`, `post_max_size`, `max_execution_time`, `memory_limit`, 시간대, `history`/`work` 쓰기, cloc exe 존재·`--version` 실행, `cloc-2.10.pl` 존재, Perl(`perl -v`), 사용할 실행 방식.
Perl이 PATH에 없으면 `config.php` 의 `PERL_BIN` 을 절대 경로로 바꾸세요.

### `POST api/history.php` — 컴포넌트 결과 1건 저장

요청 (JSON, 최대 5MB):

```json
{ "component": "ComponentA",
  "engine": { "tool": "cloc", "version": "2.10", "runner": "exe" },
  "source": { "type": "folder", "name": "MyProject" },
  "excludes": { "dirs": ["node_modules", ".git"], "exts": ["min.js", "map"] },
  "languages": [ { "language": "JavaScript", "files": 80, "code": 11000, "comment": 1500, "blank": 1200 } ] }
```

응답 `201`: `{ "file": "ComponentA_2609301.json", "component": "ComponentA", "run": 1, "date": "2026-09-30" }`

- 파일명은 `컴포넌트_YYMMDDN.json` 입니다. N은 같은 컴포넌트의 그날 저장 횟수이며 0을 채우지 않습니다 (1, 2, … 10).
- 동시에 저장해도 기존 파일을 덮어쓰지 않습니다 (`fopen 'x'` + 번호 재계산, 최대 20회).
- 컴포넌트명의 `\ / : * ? " < > |` 와 제어 문자는 `_` 로 바뀌고, 앞의 `.` 은 지워지며, 80자까지입니다.
- `totals` 는 보낸 값을 무시하고 `languages` 합계로 서버가 다시 계산합니다. 숫자는 0 이상 정수로 정리됩니다.
- `date`, `measuredAt`, `timeZone`, `run` 은 서버가 정합니다. 위에 없는 필드(경로, 소스 내용 등)는 저장하지 않습니다. `source.name` 에 경로가 오면 마지막 이름만 남깁니다.
- 제외 규칙 값은 영문/숫자/`.`/`_`/`-`/`*` 만 허용합니다.

### `GET api/history.php` — 이력 목록 (최신순)

`{ "items": [ { "file", "component", "componentVersion", "clocVersion", "clocRunner", "run", "date", "measuredAt", "totals": { "files", "code", "comment", "blank" } } ] }`
손상된 JSON 파일은 목록에서 빠집니다.

### `GET api/history.php?file=ComponentA_2609301.json` — 이력 1건

저장된 JSON 그대로 돌려줍니다. 경로가 들어간 이름(`../`, `\`, 드라이브 문자), `.json` 이 아닌 파일은 `400`, 없는 파일은 `404`, 손상된 파일은 `422` 입니다.

### 측정 흐름

```
1. POST api/upload.php   (조각 0 … n-1, 순서대로)  → jobId
2. POST api/measure.php  { jobId, mode, excludeDirs, excludeExts }  → 컴포넌트별 결과
   (측정 중 GET api/measure.php?jobId=… 로 진행 상태 확인)
3. POST api/history.php  (저장할 컴포넌트마다 1건)
```

### `POST api/upload.php` — zip 조각 업로드

`multipart/form-data`:

| 필드 | 설명 |
|---|---|
| `jobId` | 첫 조각에서는 비워 둡니다. 서버가 발급한 값(소문자 16진수 24자)을 이후 조각에 그대로 보냅니다. 다른 형식은 거부합니다. |
| `index` | 조각 번호 (0부터) |
| `total` | 전체 조각 수 |
| `chunk` | 조각 파일 (최대 8MB) |
| `sourceType` | `folder` 또는 `zip` (첫 조각의 값만 씁니다) |
| `sourceName` | 폴더 이름 또는 zip 파일 이름. 경로가 오면 마지막 이름만 남깁니다. |

응답: `{ "jobId": "5ba11dcb5bf0630e6867e684", "received": 2, "total": 3, "complete": false }`

- 조각은 `work/<jobId>/upload.part` 에 순서대로 이어 붙고, 마지막 조각이 오면 `upload.zip` 으로 확정됩니다.
- 순서를 건너뛰면 `409` 입니다. 방금 보낸 조각을 **다시** 보내는 것(응답을 못 받은 재시도)은 허용하며 덮어씁니다.
- 마지막 조각까지 받은 파일이 zip 이 아니면 `422` 와 함께 작업 폴더를 지웁니다.
- 조각이 `upload_max_filesize`/`post_max_size` 를 넘으면 원인과 php.ini 항목을 알려줍니다 (`413`).

### `POST api/measure.php` — 측정

요청: `{ "jobId": "…", "mode": "byFolder", "excludeDirs": ["node_modules", ".git"], "excludeExts": ["min.js", "map"], "excludeFiles": ["package-lock.json"] }`
(`mode` 기본값 `byFolder`, 제외 규칙을 생략하면 `config.php` 의 기본값을 씁니다.)

응답:

```json
{ "engine": { "tool": "cloc", "version": "2.10", "runner": "exe" },
  "mode": "byFolder",
  "source": { "type": "folder", "name": "MyProject" },
  "excludes": { "dirs": ["node_modules"], "exts": ["map"] },
  "extracted": { "files": 304, "bytes": 900031 },
  "components": [
    { "name": "compA", "ok": true, "runner": "exe",
      "totals": { "files": 1, "code": 1, "comment": 1, "blank": 0 },
      "languages": [ { "language": "JavaScript", "files": 1, "code": 1, "comment": 1, "blank": 0 } ],
      "folders": [ { "name": "(바로 아래 파일)", "files": 1, "code": 1, "comment": 1, "blank": 0 } ],
      "exts": [ { "name": ".js", "files": 1, "code": 1, "comment": 1, "blank": 0 } ],
      "warnings": [], "ignored": { "count": 0, "reasons": [] } },
    { "name": "broken", "ok": false, "error": "제한 시간(120초)을 넘겨 측정을 중단했습니다. …", "…": "…" } ],
  "skipped": { "count": 1, "items": [ { "path": "MyProject/node_modules", "reason": "제외 폴더" } ] },
  "elapsedMs": 1017 }
```

- **컴포넌트 구분** (`byFolder`): 압축을 푼 최상위 바로 아래 폴더 하나가 컴포넌트 하나입니다. 최상위가 폴더 하나뿐이면(폴더를 선택해 만든 zip이 보통 그렇습니다) 그 안쪽을 기준으로 합니다. 바로 아래 파일은 `_root` 컴포넌트로 묶습니다. 제외 폴더와 이름이 같은 최상위 폴더는 컴포넌트가 되지 않고 `skipped` 에 나옵니다.
- **`single`**: 전체를 컴포넌트 하나로 측정하며 이름은 `sourceName`(zip이면 `.zip` 제외)입니다.
- **집계**: cloc 을 파일별(`--by-file`)로 한 번 실행해 언어별(`languages`), 하위 폴더별(`folders`), 파일 타입별(`exts`)로 합칩니다. 언어별 값은 cloc 기본(언어별) 출력과 같습니다. 파일 목록 자체는 응답에 넣지 않습니다.
- **실패 격리**: 컴포넌트 하나가 실패(제한 시간 초과 등)해도 나머지 결과는 돌려주고, 실패한 항목에 `ok:false` 와 `error` 문장을 넣습니다.
- **`ignored`**: cloc 이 인식하지 못한 파일(`language unknown`, `binary file`, `duplicate file` 등) 개수를 이유별로 요약합니다. 제외 규칙으로 뺀 파일은 세지 않으며 파일 경로는 돌려주지 않습니다. `warnings` 는 cloc 표준 오류의 첫 5줄입니다.
- **정리**: 응답 전에 `work/<jobId>` 를 지웁니다 (성공, 실패, 예외 모두). 같은 작업은 한 번만 측정할 수 있습니다.
- **실행 방식**: `cloc-2.10.exe` 가 기본이며, exe 가 실패하고 Perl이 있으면 해당 컴포넌트만 `cloc-2.10.pl` 로 다시 실행합니다 (`runner: "pl"`). cloc 은 셸을 거치지 않고 인자 배열로 실행합니다.

### `GET api/measure.php?jobId=…` — 진행 상태

`{ "phase": "extracting" | "measuring" | "done", "done": 1, "total": 4, "current": "compB" }`. 측정이 끝나 작업 폴더가 지워지면 `404` 입니다.

### 압축 해제 안전 장치

- zip, tar, tar.gz(.tgz) 모두 같은 규칙을 적용합니다. 형식은 이름이 아니라 파일 앞부분으로 정합니다. tar.gz 는 PharData 로 한꺼번에 풀지 않고 스트림으로 풀며 한 블록씩 읽어, 압축 폭탄도 상한에서 멈춥니다 (`api/archive.php`).

- `../`, `..\`, `/`로 시작하는 절대 경로, `C:` 같은 드라이브 문자가 든 항목이 **하나라도** 있으면 zip 전체를 거부합니다 (`400`, zip slip).
- 파일 수 20만 개, 압축 해제 총 2GB 를 넘으면 중단합니다 (`413`, zip bomb). 크기는 zip 에 적힌 값과 실제로 풀린 양을 모두 확인합니다.
- 항목 이름은 UTF-8 이 아니면 CP949(한국어 Windows 압축 프로그램)로 읽어 한글 이름을 복원합니다.
- **cloc(exe)은 한글 등 ASCII 가 아닌 경로의 파일을 읽지 못하고 조용히 건너뜁니다.** 그래서 서버는 그런 이름을 `_u1.py` 처럼 확장자를 유지한 ASCII 이름으로 바꿔 풀고, 컴포넌트명은 원래 이름으로 돌려줍니다. 수치는 같은 내용을 ASCII 경로에 두고 명령행 cloc 을 실행한 값과 같습니다.

## 8. 설정과 제한값 (`config.php`)

| 상수 | 기본값 | 설명 |
|---|---|---|
| `APP_TIMEZONE` | `Asia/Seoul` | history 파일명 날짜, `measuredAt` 기준 |
| `CLOC_TIMEOUT_SEC` | 120 | 컴포넌트 하나당 cloc 실행 제한 시간(초). 넘으면 그 컴포넌트만 실패 |
| `ENV_CHECK_TIMEOUT_SEC` | 60 | `--version`, `perl -v` 확인 제한 시간(초) |
| `UPLOAD_CHUNK_BYTES` | 8MB | 조각 하나의 최대 크기 |
| `UPLOAD_MAX_BYTES` | 2GB | 업로드 zip 하나의 최대 크기 |
| `EXTRACT_MAX_BYTES` | 2GB | 압축 해제 총 크기 상한 |
| `EXTRACT_MAX_FILES` | 200,000 | 압축 해제 파일 수 상한 |
| `WORK_RETENTION_SEC` | 24시간 | 정리되지 못한 `work/<jobId>` 와 `work/_tmp` 잔여물 보관 시간. 업로드·측정 요청마다 지난 것을 지웁니다 |
| `REQUEST_BODY_MAX_BYTES` | 5MB | JSON 요청 본문 상한 |
| `COMPONENT_NAME_MAX_CHARS` | 80 | 컴포넌트명 최대 길이 |
| `HISTORY_SAVE_MAX_RETRY` | 20 | history 파일명 충돌 시 재시도 횟수 |
| `DEFAULT_EXCLUDE_DIRS`, `DEFAULT_EXCLUDE_EXTS`, `DEFAULT_EXCLUDE_FILES` | prd.md FR-3 (제외 파일은 기본 없음) | 기본 제외 규칙 |
| `PERL_BIN` | `perl` | Perl 실행 파일 (PATH 에 없으면 절대 경로) |
| `ADMIN_DEFAULT_PASSWORD` | `admin` | 관리자 초기 비밀번호 (`settings/admin_password.json` 이 없을 때) |
| `ADMIN_PASSWORD_MIN_CHARS` / `ADMIN_PASSWORD_MAX_BYTES` | 4 / 72 | 새 비밀번호 길이 (bcrypt 는 72바이트까지 씀) |

## 9. 개발용 확인

```bat
C:\xampp\php\php.exe -l api\jobs.php
C:\xampp\php\php.exe tests\run.php
```

- `tests\run.php` 는 샘플 프로젝트(하위 폴더 3개, 여러 언어, 한글 폴더·파일명, `node_modules`)를 임시 폴더에 만들고 zip → 조각 업로드 → 측정 흐름을 함수로 호출해, 명령행 `cloc-2.10.exe --json` 결과와 비교합니다. 악성 zip, zip bomb 상한, 실패 격리, 24시간 정리, `work/` 잔여물도 확인합니다.
- `php.ini` 에서 zip 확장이 꺼져 있으면 (현재 서버는 켜져 있음) `C:\xampp\php\php.exe -d extension=zip tests\run.php` 로 실행합니다.
- exe 를 실행할 수 없는 환경(Windows 아님 등)에서는 cloc 이 필요한 항목을 건너뛰고 압축 해제·경로 검사·정리만 검증합니다.
- `php -S` 내장 서버는 `.htaccess` 를 읽지 않으므로 403 차단은 Apache에서만 확인할 수 있습니다.

## 10. 기여와 보안

- 버그 제보·기능 제안·Pull Request 는 [CONTRIBUTING.md](CONTRIBUTING.md) 를 먼저 읽어 주세요. 모든 참여자는 [행동 강령](CODE_OF_CONDUCT.md)을 따릅니다.
- 보안 취약점은 공개 이슈 대신 [SECURITY.md](SECURITY.md) 의 방법으로 알려 주세요.
- **설치 직후 관리자 비밀번호(초기값 `admin`)를 꼭 바꾸고, 인터넷에 직접 공개하지 마세요.**
