# 보안 정책 (Security Policy)

## 지원 버전

보안 수정은 `main` 브랜치의 최신 버전에만 적용합니다.

## 취약점 신고

보안 취약점은 **공개 이슈로 올리지 마세요.** 다음 방법으로 비공개로 알려 주세요.

- GitHub 저장소의 **Security → Report a vulnerability** (Private vulnerability reporting)

신고에는 다음 내용을 포함해 주세요.

- 영향받는 파일이나 API (예: `api/upload.php`)
- 재현 방법 (요청 예시, 샘플 zip 등. 실제 사내 소스는 보내지 마세요)
- 예상되는 영향 (파일 읽기·쓰기, 명령 실행, 인증 우회 등)

확인 후 가능한 한 빨리 답변드리고, 수정이 배포되면 신고자와 협의해 공개합니다.

## 운영 시 주의 사항

LOC_Mgmt 는 **사내망(intranet) 전용**으로 설계되었습니다. 인터넷에 직접 공개하지 마세요.

- **관리자 비밀번호 초기값은 `admin` 입니다.** 설치 직후 관리 → **비밀번호 변경**에서 꼭 바꿔 주세요. (초기 비밀번호를 쓰는 동안 화면에 경고가 표시됩니다.)
- 관리 탭을 뺀 측정·조회 기능에는 로그인이 없습니다. 접근을 제한하려면 Apache 에서 IP 제한이나 기본 인증을 추가하세요 ([설치·사용 가이드](docs/GUIDE.md#4-인증과-접근-제한) 4장).
- `.htaccess` 가 동작하도록 `AllowOverride All` 을 유지하세요. `ctool/`, `history/`, `work/`, `settings/`, `tests/` 는 웹에서 열리면 안 됩니다 (403).
- 업로드한 소스는 측정이 끝나면 `work/` 에서 지워지고, `history/` 에는 경로나 소스 내용 없이 줄 수만 저장됩니다.
- 함께 들어 있는 cloc, Chart.js, JSZip 의 보안 업데이트도 확인해 주세요 ([THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md)).
