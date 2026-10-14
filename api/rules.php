<?php
declare(strict_types=1);

/*
 * 제외 규칙 관리 API (관리 탭)
 *   GET                         → { rules: [...], defaults: { dirs, exts, files }, types: { dir, ext, file } }
 *   POST   { type, pattern, note?, enabled? }   → 201 { rule, defaults }        규칙 추가
 *   POST   { action: "reset" }                  → { rules, defaults }           config.php 기본값으로 초기화
 *   PUT    ?id=...  { type?, pattern?, note?, enabled? } → { rule, defaults }   규칙 수정
 *   DELETE ?id=...                              → { deleted, defaults }         규칙 삭제
 * 관리 탭 인증(api/auth.php, 관리자 비밀번호)을 마친 세션만 쓸 수 있다 (아니면 401). 화면은 삭제·초기화 전에 확인도 받는다.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'rules_store.php';

run_api(function (): void {
    $method = require_method('GET', 'POST', 'PUT', 'DELETE');
    require_admin();   // 제외 규칙 관리는 관리 탭 인증(api/auth.php) 뒤에만

    if ($method === 'GET') {
        $rules = rules_load();
        json_response(['rules' => $rules, 'defaults' => rules_defaults($rules), 'types' => RULE_TYPES]);
    }
    if ($method === 'DELETE') {
        $deleted = rule_delete($_GET['id'] ?? null);
        json_response(['deleted' => $deleted, 'defaults' => rules_defaults(rules_load())]);
    }

    $body = read_json_body();
    if ($method === 'PUT') {
        $rule = rule_update($_GET['id'] ?? null, $body);
        json_response(['rule' => $rule, 'defaults' => rules_defaults(rules_load())]);
    }
    if (($body['action'] ?? null) === 'reset') {
        $rules = rules_reset();
        json_response(['rules' => $rules, 'defaults' => rules_defaults($rules)]);
    }
    $rule = rule_create($body);
    json_response(['rule' => $rule, 'defaults' => rules_defaults(rules_load())], 201);
});
