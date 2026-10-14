# Changelog

이 프로젝트의 주요 변경 사항을 기록합니다.
형식은 [Keep a Changelog](https://keepachangelog.com/ko/1.1.0/)를 따르고, 버전은 [Semantic Versioning](https://semver.org/lang/ko/)을 따릅니다.

## [Unreleased]

## [1.0.0] - 2026-10-14

첫 공개 버전입니다.

### 추가
- **LOC 측정**: 폴더 또는 zip / tar / tar.gz 를 골라 cloc 2.10 으로 측정. 단일 컴포넌트와 컴포넌트 그룹(바로 아래 폴더 = 컴포넌트) 방식, 컴포넌트 버전 기록.
- 두 겹 원형 차트(컴포넌트 / 언어·하위 폴더·파일 타입)와 표, 기준(코드·주석·빈 줄·전체) 전환.
- **측정 이력**: 컴포넌트마다 `history/<컴포넌트>_YYMMDDN.json` 으로 저장, 측정 묶음 표시, 여러 이력 함께 보기, 회차별 측정 추이 차트.
- **LOC 조회** 탭: 컴포넌트별·컴포넌트 그룹별 조회, 그룹 → 컴포넌트 → 하위 폴더 세 겹 차트.
- **Excel 내보내기**(.xlsx): 화면 표와 이력 전체.
- **관리** 탭: 제외 규칙 관리, 저장 이력 관리(버전 수정·삭제), 관리자 비밀번호 변경(bcrypt), 가이드.
- 서버 환경 점검(PHP 버전·확장·`php.ini` 한도·폴더 권한·cloc 실행 여부와 해결 방법 안내).
- 안전 장치: zip slip·zip bomb 방어, 측정 후 업로드 소스 즉시 삭제, 내부 폴더 웹 접근 차단(`.htaccess`), 비밀번호 잠금.
- 한글 폴더·파일 이름 지원(CP949 zip 포함), 밝은/어두운 테마.
- 오픈소스 문서(README 한국어·영어, 설치·사용 가이드, CONTRIBUTING, SECURITY, 행동 강령), 이슈·PR 템플릿, GitHub Actions CI(PHP 8.1~8.4).

### 수정
- 최소 PHP 버전을 8.1 로 바로잡음. 환경 점검이 8.0 을 통과시키고 있었지만 코드는 8.1 기능을 사용함.

[Unreleased]: https://github.com/resious71/LOC_Manager/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/resious71/LOC_Manager/releases/tag/v1.0.0
