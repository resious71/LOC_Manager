# 기여 안내 (Contributing)

LOC_Mgmt 에 관심을 가져 주셔서 감사합니다. 버그 제보, 기능 제안, 문서 수정, 코드 기여 모두 환영합니다.

> English: Issues and pull requests in English are welcome too. Please read the rules below (summarized in English at the end).

## 이슈 올리기

- 먼저 [기존 이슈](../../issues)에 같은 내용이 있는지 확인해 주세요.
- 버그는 **버그 제보** 템플릿을 써 주세요. 화면 위 **환경 점검** 결과(PHP 버전, cloc 실행 방식 등)를 함께 적어 주시면 원인을 찾기 쉽습니다.
- 보안 취약점은 공개 이슈로 올리지 말고 [SECURITY.md](SECURITY.md) 의 방법으로 알려 주세요.
- 측정한 **소스 코드나 사내 정보**는 이슈에 올리지 마세요. 재현에 필요하면 작은 샘플을 새로 만들어 주세요.

## 개발 환경

- XAMPP for Windows (Apache 2.4 + **PHP 8.1 이상**). 설치 방법은 [설치·사용 가이드](docs/GUIDE.md) 1~3장을 보세요.
- 순수 HTML + JavaScript + PHP 입니다. **Composer, 프레임워크, 데이터베이스, 빌드 도구, 외부 CDN을 쓰지 않습니다.** 새 의존성을 넣지 말아 주세요.
- 요구사항의 기준 문서는 [prd.md](prd.md) 입니다. 동작을 바꾸는 변경은 prd.md 와 어긋나지 않는지 확인해 주세요.
- 코드 구조와 여러 파일에 걸친 규칙은 [CLAUDE.md](CLAUDE.md) 에 정리되어 있습니다.

## 변경 전 확인

```bat
:: 모든 PHP 파일 문법 검사
for /r %f in (*.php) do C:\xampp\php\php.exe -l "%f"

:: 테스트 (종료 코드 0 = 모두 통과)
C:\xampp\php\php.exe tests\run.php
```

Linux/macOS 에서도 `php tests/run.php` 로 실행할 수 있습니다 (cloc exe 가 필요한 항목은 건너뜁니다). Pull Request 를 올리면 GitHub Actions 가 PHP 8.1~8.4 에서 같은 검사를 실행합니다.

## 코드 규칙

- 외부 프로그램(cloc, perl)은 반드시 `run_process()` 로 **인자 배열**을 넘겨 실행합니다. 셸 문자열을 만들지 마세요.
- 경로, 제한값, 시간대 같은 설정은 `config.php` 에만 둡니다.
- API 응답은 `json_response()` / `ApiError` 를 사용하고, 오류는 `{ "error": "사용자가 읽을 수 있는 문장" }` 형식입니다.
- history 에는 파일 경로나 소스 내용을 절대 저장하지 않습니다.
- 한글 이름은 `basename()` 대신 `last_segment()` 를 씁니다.
- `assets/*` 를 바꾸면 `index.html` 의 `?v=N` 을 올려 주세요 (브라우저 캐시).
- 설치 방법이나 동작이 바뀌면 [docs/GUIDE.md](docs/GUIDE.md) 와 관리 → **가이드** 탭(`index.html`)을 함께 고쳐 주세요. 사용자에게 보이는 변경은 [CHANGELOG.md](CHANGELOG.md) 의 `[Unreleased]` 에 적어 주세요.
- `ctool/` 의 cloc 파일과 `lib/` 의 라이브러리는 수정하지 않습니다.
- 주변 코드처럼 한국어 주석을 씁니다.

## Pull Request

1. 저장소를 fork 하고 `main` 에서 브랜치를 만듭니다.
2. 변경은 작게, 한 PR 에 한 가지 목적만 담아 주세요.
3. 위 문법 검사와 테스트를 통과시키고, 새 동작에는 `tests/run.php` 에 테스트를 추가해 주세요.
4. PR 템플릿의 항목을 채워 주세요. Apache 에서만 확인할 수 있는 항목(`.htaccess` 403 등)은 확인했는지 여부를 따로 적어 주세요.

기여하신 코드는 이 프로젝트의 [MIT License](LICENSE) 로 배포되는 데 동의한 것으로 봅니다.

## 행동 강령

이 프로젝트에 참여하는 모든 분은 [행동 강령](CODE_OF_CONDUCT.md)을 지켜 주세요.

---

### Summary in English

- Search existing issues first; use the issue templates. Report security issues privately (see `SECURITY.md`).
- Stack: plain HTML/JS/PHP 8.1+ on Apache (XAMPP). No Composer, frameworks, databases, build steps or CDNs.
- Before a PR: `php -l` every PHP file and run `php tests/run.php` (exit code 0). CI runs the same checks.
- Run external programs only through `run_process()` with an argument array; keep settings in `config.php`; never store paths or source code in history.
- Contributions are licensed under the MIT License.
