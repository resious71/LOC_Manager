/*
 * LOC_Mgmt 화면
 *   소스 선택(폴더/zip/끌어다 놓기) → 폴더면 제외 규칙을 적용해 zip(STORE) 생성 → 8MB 조각 업로드
 *   → 측정(api/measure.php) → 차트·표 → history 저장 / history 탭에서 다시 보기
 * 외부 주소로는 요청하지 않는다. 라이브러리는 lib/ 에서 읽는다 (index.html).
 */
(() => {
  'use strict';

  // ================================================================ 기본값과 상태

  const DEFAULTS = {
    chunkBytes: 8 * 1024 * 1024,
    uploadMaxBytes: 2 * 1024 * 1024 * 1024,
    clocTimeoutSec: 120,
    excludeDirs: ['node_modules', '.git', '.svn', 'bin', 'obj', 'dist', 'build', 'target', 'vendor', '__pycache__', '.idea', '.vs'],
    excludeExts: ['min.js', 'min.css', 'map'],
    excludeFiles: [],
  };
  const RULE_RE = /^[A-Za-z0-9._*-]{1,100}$/;
  const MAX_CHART_GROUPS = 16;      // 컴포넌트가 이보다 많으면 작은 것부터 '기타'로 묶는다 (파스텔 16색)
  const METRIC_LABEL = { code: '코드', comment: '주석', blank: '빈 줄', total: '전체' };
  // 결과 보기 기준: 바깥 링과 표의 상세 행을 무엇으로 나눌지
  const DIMS = {
    language: { key: 'languages', field: 'language', label: '언어', col: '컴포넌트 / 언어' },
    folder: { key: 'folders', field: 'name', label: '하위 폴더', col: '컴포넌트 / 하위 폴더' },
    ext: { key: 'exts', field: 'name', label: '파일 타입', col: '컴포넌트 / 파일 타입' },
  };

  const state = {
    config: { ...DEFAULTS },
    source: null,          // { kind: 'folder', name, entries: [{path, file}], pruned } | { kind: 'zip', name, file }
    running: false,
    cancelled: false,
    xhr: null,
    tab: 'query',           // 페이지를 열면 LOC 조회 탭
    measureModel: null,
    historyModel: null,
    metric: 'code',
    dim: 'language',        // 보기 기준 (페이지를 열면 항상 언어별)
    trendChart: null,
    trendDeltaChart: null,
    trendComponent: null,
    chart: null,
    histItems: [],
    histLoaded: false,
    histSelected: null,
    adminAuthed: false,       // 관리 탭 인증 (이 페이지에서 비밀번호를 넣었는지)
    adminToken: null,         // 인증 토큰: 메모리에만 두므로 새로고침·새 탭에서는 다시 인증해야 한다
    adminDefaultPw: false,    // 서버가 아직 초기 비밀번호를 쓰는 중인지 (관리 탭에 바꾸라는 안내)
    histChecked: new Set(),   // 측정 이력에서 '함께 보기'로 고른 파일들
    histPage: 1,              // 측정 이력 표의 현재 페이지 (1부터)
    histPageSize: ['10', '15', '30', 'all'].includes(storageGet('locmgmt.histPageSize')) ? storageGet('locmgmt.histPageSize') : '15',
  };

  /**
   * LOC 조회 탭 (읽기 전용). 이력 목록은 측정 이력 탭(state.histItems)과 따로 둔다 — 두 탭이 서로의 동작을 바꾸지 않게.
   * 선택은 하위 탭마다 따로 유지한다 (그룹 → 컴포넌트로 이동했다가 돌아와도 그룹 선택이 그대로).
   */
  const query = {
    sub: 'component',          // 하위 탭: 'component' | 'group'
    items: [],                 // GET api/history.php 목록 (탭을 열 때마다 새로 읽음)
    loaded: false,
    error: null,
    listSeq: 0,                // 늦게 도착한 응답을 버리기 위한 번호
    recs: new Map(),           // 이력 파일 → 내용 (목록을 새로 읽으면 비운다)
    comp: { component: null, version: null, file: null },
    group: { parent: null, version: null, batchId: null },
    compModel: null,
    groupModel: null,
    compSeq: 0,
    groupSeq: 0,
    trendChart: null,          // 조회 탭의 측정 추이 차트 (측정 이력 탭의 state.trendChart 와 따로)
    trendDeltaChart: null,
  };
  const NO_VERSION = '(버전 없음)';

  const $ = (id) => document.getElementById(id);
  const nf = new Intl.NumberFormat('ko-KR');
  const fmt = (n) => nf.format(n);
  const pct = (v, total) => (total > 0 ? ((v / total) * 100).toFixed(1) : '0.0') + '%';
  const tick = () => new Promise((r) => setTimeout(r, 0));
  const reducedMotion = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  function formatBytes(n) {
    if (n >= 1024 ** 3) return (n / 1024 ** 3).toFixed(2) + 'GB';
    if (n >= 1024 ** 2) return (n / 1024 ** 2).toFixed(1) + 'MB';
    if (n >= 1024) return (n / 1024).toFixed(0) + 'KB';
    return n + 'B';
  }

  /** DOM 요소를 만든다. 문자열 자식은 textContent 로 넣어 HTML로 해석되지 않는다. */
  function el(tag, attrs, ...children) {
    const node = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs || {})) {
      if (v === null || v === undefined || v === false) continue;
      if (k === 'class') node.className = v;
      else if (k === 'style') node.style.cssText = v;
      else if (k.startsWith('on')) node.addEventListener(k.slice(2), v);
      else node.setAttribute(k, v === true ? '' : v);
    }
    for (const c of children.flat()) {
      if (c === null || c === undefined || c === false) continue;
      node.append(c instanceof Node ? c : document.createTextNode(String(c)));
    }
    return node;
  }

  /** 사용자에게 보여줄 문장과 해결 방법을 가진 오류 */
  class UserError extends Error {
    constructor(message, hint) {
      super(message);
      this.hint = hint || '';
    }
  }

  function storageGet(key) {
    try { return window.localStorage.getItem(key); } catch { return null; }
  }
  function storageSet(key, value) {
    try { window.localStorage.setItem(key, value); } catch { /* 저장소를 쓸 수 없어도 동작한다 */ }
  }

  // ================================================================ 테마

  // 테마는 시스템 설정(밝게/어둡게)을 따른다. 시스템 설정이 바뀌면 차트 색을 다시 그린다.
  function applyTheme() {
    document.documentElement.removeAttribute('data-theme');
    redrawChart();
  }
  window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', redrawChart);

  function cssVar(name) {
    return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
  }

  // ================================================================ API 호출

  async function readJson(res) {
    const text = await res.text();
    try { return JSON.parse(text); } catch { return null; }
  }

  function httpError(status, body) {
    if (body && typeof body.error === 'string') return new UserError(body.error);
    if (status === 413) return new UserError('서버가 받을 수 있는 크기를 넘었습니다.', 'php.ini 의 upload_max_filesize, post_max_size 를 확인해 주세요.');
    if (status === 404) return new UserError('서버에서 API를 찾을 수 없습니다 (404).', '프로젝트가 XAMPP htdocs 아래에 제대로 배치되었는지 확인해 주세요.');
    if (status === 403) return new UserError('서버가 요청을 거부했습니다 (403).', 'Apache 접근 제한 설정을 확인해 주세요.');
    if (status >= 500) return new UserError('서버 오류가 발생했습니다 (' + status + ').', 'Apache 오류 로그(xampp/apache/logs/error.log)를 확인해 주세요.');
    return new UserError('요청이 실패했습니다 (' + status + ').');
  }

  async function api(path, options = {}) {
    let res;
    try {
      // 관리 탭 인증 토큰은 이 페이지 메모리에만 있다 (새로고침하면 사라져 다시 인증)
      const headers = { ...(options.headers || {}), ...(state.adminToken ? { 'X-Admin-Token': state.adminToken } : {}) };
      res = await fetch('api/' + path, { cache: 'no-store', ...options, headers });
    } catch {
      throw new UserError('서버에 연결할 수 없습니다.', 'Apache가 실행 중인지, 네트워크가 연결되어 있는지 확인해 주세요.');
    }
    const body = await readJson(res);
    if (res.status === 401 && !path.startsWith('auth.php')) { state.adminAuthed = false; state.adminToken = null; }   // 관리자 인증이 끝남
    if (!res.ok) {
      const err = httpError(res.status, body);
      err.status = res.status;
      throw err;
    }
    if (body === null) throw new UserError('서버 응답을 해석할 수 없습니다.', 'PHP 오류가 출력되고 있는지 Apache 오류 로그를 확인해 주세요.');
    return body;
  }

  // ================================================================ 환경 점검

  async function loadEnv() {
    const box = $('env');
    try {
      const env = await api('env.php');
      if (env.config) state.config = { ...DEFAULTS, ...env.config };
      const issues = env.checks.filter((c) => c.level !== 'ok');
      const errors = issues.filter((c) => c.level === 'error');
      const runner = env.checks.find((c) => c.id === 'cloc_runner');
      const exe = env.checks.find((c) => c.id === 'cloc_exe');
      const version = exe && /버전 ([\d.]+)/.exec(exe.value);
      box.dataset.level = errors.length ? 'error' : issues.length ? 'warn' : 'ok';
      $('env-text').textContent = errors.length
        ? `서버 환경에 문제가 ${errors.length}건 있습니다. 해결하기 전에는 측정이 실패할 수 있습니다.`
        : issues.length
          ? `서버 환경: 확인이 필요한 항목 ${issues.length}건 (측정은 가능)`
          : `서버 환경 정상 · cloc ${version ? version[1] : ''} (${runner ? runner.value : '?'})`;
      const list = $('env-issues');
      list.replaceChildren(...issues.map((c) => el('li', null,
        el('span', { class: 'lvl lvl-' + c.level }, c.level === 'error' ? '오류' : '확인'),
        `${c.label}: ${c.value} — ${c.message}`)));
      $('env-toggle').hidden = issues.length === 0;
      list.hidden = errors.length === 0;
      $('env-toggle').textContent = list.hidden ? '자세히' : '접기';
    } catch (e) {
      box.dataset.level = 'error';
      $('env-text').textContent = '서버 환경을 점검하지 못했습니다: ' + e.message + (e.hint ? ' ' + e.hint : '');
    }
    initRuleInputs();
  }
  $('env-toggle').addEventListener('click', () => {
    const list = $('env-issues');
    list.hidden = !list.hidden;
    $('env-toggle').textContent = list.hidden ? '자세히' : '접기';
  });

  // ================================================================ 제외 규칙

  // 제외 규칙은 시작하자마자 기본값으로 채운다 (환경 점검 응답을 기다리는 동안 빈 규칙으로 측정되지 않도록).
  // 서버 기본값(config.php)이 도착하면, 사용자가 아직 고치지 않은 경우에만 그 값으로 바꾼다.
  let rulesEdited = false;
  function fillRuleInputs() {
    $('ex-dirs').value = state.config.excludeDirs.join('\n');
    $('ex-exts').value = state.config.excludeExts.join('\n');
    $('ex-files').value = (state.config.excludeFiles || []).join('\n');
  }
  function initRuleInputs() {
    if (!rulesEdited) fillRuleInputs();
    refreshSelection();
  }
  // "제외 규칙 기본값" 토글: 켜면 세 칸(폴더·확장자·파일)을 기본값으로 채우고, 끄면 함께 비운다 (제외 없이 모두 측정).
  // 칸을 직접 고쳐 기본값과 달라지면 꺼진 모양이 되고, 누르면 기본값으로 돌아간다.
  $('reset-defaults').addEventListener('click', () => {
    rulesEdited = true;          // 환경 점검 응답이 늦게 와도 사용자가 고른 상태를 덮어쓰지 않는다
    if (rulesAreDefault()) {
      $('ex-dirs').value = '';
      $('ex-exts').value = '';
      $('ex-files').value = '';
    } else {
      fillRuleInputs();
    }
    refreshSelection();
  });

  const sameList = (a, b) => a.length === b.length && a.every((v, i) => v === b[i]);
  function rulesAreDefault() {
    return sameList(parseRules($('ex-dirs').value, false).list, state.config.excludeDirs)
      && sameList(parseRules($('ex-exts').value, true).list, state.config.excludeExts)
      && sameList(parseRules($('ex-files').value, false).list, state.config.excludeFiles || []);
  }
  function syncRuleToggle() {
    const on = rulesAreDefault();
    const btn = $('reset-defaults');
    btn.setAttribute('aria-pressed', String(on));
    btn.textContent = (on ? '✓ ' : '') + '제외 규칙 기본값';
    const empty = !$('ex-dirs').value.trim() && !$('ex-exts').value.trim() && !$('ex-files').value.trim();
    $('rule-note').hidden = !empty;
  }

  function parseRules(text, isExt) {
    const list = [];
    const bad = [];
    for (let line of text.split(/\r?\n/)) {
      line = line.trim();
      if (!line || line.startsWith('#')) continue;
      if (isExt) line = line.replace(/^\*?\./, '');   // ".map", "*.map" → "map"
      if (!RULE_RE.test(line)) bad.push(line);
      else if (!list.includes(line)) list.push(line);
    }
    return { list, bad };
  }

  function readRules() {
    const dirs = parseRules($('ex-dirs').value, false);
    const exts = parseRules($('ex-exts').value, true);
    const files = parseRules($('ex-files').value, false);
    const bad = [...dirs.bad, ...exts.bad, ...files.bad];
    const errBox = $('rule-error');
    errBox.hidden = bad.length === 0;
    errBox.textContent = bad.length ? `제외 규칙에 쓸 수 없는 값이 있습니다: ${bad.slice(0, 5).join(', ')} — 영문, 숫자, . _ - * 만 사용할 수 있습니다.` : '';
    return { dirs: dirs.list, exts: exts.list, files: files.list, ok: bad.length === 0 };
  }

  const globCache = new Map();
  /** caseSensitive 가 아니면 대소문자를 무시한다 (제외 파일만 대소문자를 구분) */
  function globToRegExp(glob, caseSensitive = false) {
    const key = (caseSensitive ? 'C:' : 'I:') + glob;
    if (!globCache.has(key)) {
      const src = glob.replace(/[.+^${}()|[\]\\?]/g, '\\$&').replace(/\*/g, '.*');
      globCache.set(key, new RegExp('^' + src + '$', caseSensitive ? '' : 'i'));
    }
    return globCache.get(key);
  }

  function isExcludedDir(name, dirs) {
    const low = name.toLowerCase();
    return dirs.some((d) => (d.includes('*') ? globToRegExp(d).test(name) : d.toLowerCase() === low));
  }
  function isExcludedFile(name, exts) {
    const low = name.toLowerCase();
    return exts.some((e) => (e.includes('*') ? globToRegExp('*.' + e).test(name) : low.endsWith('.' + e.toLowerCase())));
  }
  /** path 는 "선택한폴더/하위/파일". 선택한 폴더 이름 자체는 제외 검사 대상이 아니다. */
  function isIncluded(path, rules) {
    const segs = path.split('/');
    const file = segs[segs.length - 1];
    for (let i = 1; i < segs.length - 1; i++) {
      if (isExcludedDir(segs[i], rules.dirs)) return false;
    }
    // 제외 파일: 이름 전체와 비교 (대소문자 구분, * 는 아무 글자). 서버의 cloc --not-match-f 와 같은 규칙
    if (rules.files.some((f) => globToRegExp(f, true).test(file))) return false;
    return !isExcludedFile(file, rules.exts);
  }

  let ruleTimer = 0;
  for (const id of ['ex-dirs', 'ex-exts', 'ex-files']) {
    $(id).addEventListener('input', () => {
      rulesEdited = true;
      clearTimeout(ruleTimer);
      ruleTimer = setTimeout(refreshSelection, 250);
    });
  }

  // ================================================================ 소스 선택

  function setSource(src) {
    state.source = src;
    clearError();
    refreshSelection();
  }

  function refreshSelection() {
    const rules = readRules();
    syncRuleToggle();
    const box = $('selected');
    const src = state.source;
    // 지금 고른 소스 종류의 버튼을 두꺼운 테두리로 표시한다 (끌어다 놓은 경우도 같음)
    $('btn-folder').classList.toggle('is-selected', !!src && src.kind === 'folder');
    $('btn-zip').classList.toggle('is-selected', !!src && src.kind === 'zip');
    if (!src) {
      box.hidden = true;
      $('run').disabled = true;
      return;
    }
    box.hidden = false;
    if (src.kind === 'zip') {
      const label = archiveLabel(src.name);
      box.replaceChildren(`${label} “${src.name}” (${formatBytes(src.file.size)})`,
        el('span', { class: 'hint' }, label === 'zip' ? 'zip은 그대로 올리며, 제외 규칙은 서버에서 적용합니다.' : `${label} 파일은 그대로 올리며, 제외 규칙은 서버에서 적용합니다.`));
    } else {
      const inc = src.entries.filter((e) => isIncluded(e.path, rules));
      const bytes = inc.reduce((s, e) => s + e.file.size, 0);
      box.replaceChildren(`폴더 “${src.name}” · 파일 ${fmt(src.entries.length)}개 중 ${fmt(inc.length)}개 측정 대상 (${formatBytes(bytes)})`,
        el('span', { class: 'hint' }, src.pruned
          ? `끌어다 놓을 때 제외 폴더 ${fmt(src.pruned)}개는 읽지 않았습니다.`
          : '제외 규칙에 걸리는 폴더와 확장자는 업로드하지 않습니다.'));
    }
    $('run').disabled = state.running || !rules.ok;
  }

  $('pick-folder').addEventListener('change', (ev) => {
    const files = Array.from(ev.target.files || []);
    ev.target.value = '';
    if (!files.length) {
      showError(new UserError('선택한 폴더가 비어 있습니다.', '파일이 들어 있는 폴더를 선택해 주세요.'));
      return;
    }
    const entries = files.map((f) => ({ path: f.webkitRelativePath || f.name, file: f }));
    const name = entries[0].path.split('/')[0] || 'folder';
    setSource({ kind: 'folder', name, entries, pruned: 0 });
  });

  $('pick-zip').addEventListener('change', (ev) => {
    const file = ev.target.files && ev.target.files[0];
    ev.target.value = '';
    if (file) chooseZip(file);
  });

  // 압축 파일: zip, tar, tar.gz(.tgz). 형식은 서버가 파일 앞부분으로 다시 확인한다.
  const ARCHIVE_RE = /\.(zip|tar|tar\.gz|tgz)$/i;
  const archiveLabel = (name) => {
    const m = ARCHIVE_RE.exec(name);
    return m ? (m[1].toLowerCase() === 'tgz' ? 'tar.gz' : m[1].toLowerCase()) : 'zip';
  };

  function chooseZip(file) {
    if (!ARCHIVE_RE.test(file.name)) {
      showError(new UserError(`“${file.name}” 은(는) 지원하는 압축 파일(zip, tar, tar.gz)이 아닙니다.`, '폴더를 선택하거나 .zip, .tar, .tar.gz(.tgz) 파일을 선택해 주세요.'));
      return;
    }
    setSource({ kind: 'zip', name: file.name, file });
  }

  // ---- 끌어다 놓기
  const drop = $('drop');
  ['dragenter', 'dragover'].forEach((t) => drop.addEventListener(t, (ev) => {
    ev.preventDefault();
    drop.classList.add('is-over');
  }));
  ['dragleave', 'drop'].forEach((t) => drop.addEventListener(t, () => drop.classList.remove('is-over')));
  // 영역 밖에 놓아도 브라우저가 파일을 열지 않게 한다
  window.addEventListener('dragover', (ev) => ev.preventDefault());
  window.addEventListener('drop', (ev) => ev.preventDefault());

  drop.addEventListener('drop', async (ev) => {
    ev.preventDefault();
    if (state.running) return;
    const items = Array.from(ev.dataTransfer.items || []).filter((i) => i.kind === 'file');
    const entries = items.map((i) => (i.webkitGetAsEntry ? i.webkitGetAsEntry() : null)).filter(Boolean);
    if (entries.length !== 1) {
      showError(new UserError('폴더 하나 또는 zip 파일 하나만 끌어다 놓아 주세요.'));
      return;
    }
    const entry = entries[0];
    if (entry.isFile) {
      entry.file((f) => chooseZip(f), () => showError(new UserError('끌어다 놓은 파일을 읽을 수 없습니다.')));
      return;
    }
    try {
      setProgress('폴더 목록 읽는 중…', null);
      const rules = readRules();
      const collected = { files: [], pruned: 0 };
      await walkEntry(entry, entry.name, rules, collected);
      hideProgress();
      if (!collected.files.length) throw new UserError('끌어다 놓은 폴더에 파일이 없습니다.');
      setSource({ kind: 'folder', name: entry.name, entries: collected.files, pruned: collected.pruned });
    } catch (e) {
      hideProgress();
      showError(e);
    }
  });

  function readAllEntries(reader) {
    return new Promise((resolve, reject) => {
      const all = [];
      const next = () => reader.readEntries((batch) => {
        if (!batch.length) resolve(all);
        else { all.push(...batch); next(); }
      }, reject);
      next();
    });
  }
  function entryFile(entry) {
    return new Promise((resolve, reject) => entry.file(resolve, reject));
  }
  /** 폴더를 따라 내려가며 파일을 모은다. 제외 폴더는 아예 읽지 않는다. */
  async function walkEntry(dirEntry, path, rules, out) {
    const children = await readAllEntries(dirEntry.createReader());
    for (const child of children) {
      const childPath = path + '/' + child.name;
      if (child.isDirectory) {
        if (isExcludedDir(child.name, rules.dirs)) { out.pruned++; continue; }
        await walkEntry(child, childPath, rules, out);
      } else {
        out.files.push({ path: childPath, file: await entryFile(child) });
        if (out.files.length % 500 === 0) {
          setProgress(`폴더 목록 읽는 중… 파일 ${fmt(out.files.length)}개`, null);
          await tick();
        }
      }
    }
  }

  // ================================================================ 진행 표시·오류

  function setProgress(text, percent) {
    $('progress').hidden = false;
    $('progress-text').textContent = text;
    const bar = $('progress').querySelector('.bar');
    if (percent === null || percent === undefined) {
      bar.classList.add('is-indeterminate');
      bar.removeAttribute('aria-valuenow');
      $('progress-pct').textContent = '';
      $('progress-bar').style.width = '';
    } else {
      const p = Math.max(0, Math.min(100, percent));
      bar.classList.remove('is-indeterminate');
      bar.setAttribute('aria-valuenow', String(Math.round(p)));
      $('progress-bar').style.width = p.toFixed(1) + '%';
      $('progress-pct').textContent = Math.floor(p) + '%';
    }
  }
  function hideProgress() {
    $('progress').hidden = true;
  }

  function showError(e) {
    const box = $('run-error');
    const msg = e instanceof UserError ? e.message : '예상하지 못한 오류가 발생했습니다: ' + (e && e.message ? e.message : e);
    const hint = e instanceof UserError ? e.hint : '브라우저 개발자 도구의 콘솔을 확인해 주세요.';
    box.replaceChildren(el('p', null, el('strong', null, '측정하지 못했습니다. '), msg), hint ? el('p', null, hint) : null);
    box.hidden = false;
    if (!(e instanceof UserError)) console.error(e);
  }
  function clearError() {
    $('run-error').hidden = true;
  }

  // ================================================================ 실행

  function setRunning(on) {
    state.running = on;
    $('run').disabled = on;
    $('cancel').hidden = !on;
    $('cancel').disabled = false;
    for (const id of ['pick-folder', 'pick-zip', 'ex-dirs', 'ex-exts', 'ex-files', 'reset-defaults', 'comp-version']) $(id).disabled = on;
    document.querySelectorAll('input[name="mode"]').forEach((r) => { r.disabled = on; });
    if (!on) refreshSelection();
  }

  $('cancel').addEventListener('click', () => {
    state.cancelled = true;
    if (state.xhr) state.xhr.abort();
    $('cancel').disabled = true;
  });

  function checkCancelled() {
    if (state.cancelled) throw new UserError('측정을 취소했습니다.');
  }

  $('run').addEventListener('click', run);

  /** 컴포넌트 버전 입력값 (선택). 제어 문자는 뺀다. 너무 길면 오류를 보이고 null. */
  function readComponentVersion() {
    const v = $('comp-version').value.replace(/[\u0000-\u001F\u007F]/g, '').trim();
    if (v.length > 50) {
      showError(new UserError('컴포넌트 버전은 50자까지 입력할 수 있습니다.'));
      return null;
    }
    return v;
  }

  async function run() {
    const src = state.source;
    const rules = readRules();
    if (!src || !rules.ok || state.running) return;
    const mode = document.querySelector('input[name="mode"]:checked').value;
    const componentVersion = readComponentVersion();
    if (componentVersion === null) return;
    clearError();
    state.cancelled = false;
    setRunning(true);
    try {
      let blob;
      if (src.kind === 'folder') {
        const entries = src.entries.filter((e) => isIncluded(e.path, rules));
        if (!entries.length) {
          throw new UserError('제외 규칙을 적용하고 나니 측정할 파일이 없습니다.', '다른 폴더를 선택하거나 제외 규칙을 줄여 주세요.');
        }
        blob = await buildZip(entries);
      } else {
        blob = src.file;
        const head = new Uint8Array(await blob.slice(0, 512).arrayBuffer());
        const isZip = head[0] === 0x50 && head[1] === 0x4b && (head[2] === 3 || head[2] === 5);
        const isGz = head[0] === 0x1f && head[1] === 0x8b;
        const isTar = head.length === 512 && String.fromCharCode(...head.slice(257, 262)) === 'ustar';
        const label = archiveLabel(src.name);
        const ok = label === 'zip' ? isZip : label === 'tar.gz' ? isGz : (isTar || head.length === 512);   // 옛 tar 는 서버가 체크섬으로 확인
        if (!ok) {
          throw new UserError(`“${src.name}” 은(는) 읽을 수 있는 ${label} 파일이 아닙니다.`, '파일이 손상되지 않았는지 확인하거나, 압축을 푼 폴더를 선택해 주세요.');
        }
      }
      if (blob.size > state.config.uploadMaxBytes) {
        throw new UserError(`업로드할 크기(${formatBytes(blob.size)})가 최대 ${formatBytes(state.config.uploadMaxBytes)} 를 넘습니다.`, '제외 폴더를 늘리거나 컴포넌트를 나눠서 측정해 주세요.');
      }
      checkCancelled();
      const jobId = await uploadBlob(blob, src.kind, src.name);
      $('cancel').hidden = true;     // 측정은 서버에서 진행되므로 중간에 취소할 수 없다
      const result = await measure(jobId, mode, rules);
      state.measureModel = modelFromMeasure(result, componentVersion);
      hideProgress();
      if (state.tab === 'measure') renderModel(state.measureModel);
      $('result').scrollIntoView({ behavior: reducedMotion() ? 'auto' : 'smooth', block: 'start' });
    } catch (e) {
      hideProgress();
      showError(e);
    } finally {
      state.xhr = null;
      setRunning(false);
    }
  }

  /** 폴더 파일을 zip(STORE, 압축 없음)으로 묶는다. 일정 개수마다 제어권을 돌려줘 화면이 멈추지 않게 한다. */
  async function buildZip(entries) {
    if (typeof JSZip === 'undefined') {
      throw new UserError('lib 폴더에 JSZip 파일(lib/jszip.min.js)이 없어 폴더를 묶을 수 없습니다.', 'README 의 안내대로 jszip.min.js 를 lib 폴더에 넣거나, zip 파일을 직접 선택해 주세요.');
    }
    const zip = new JSZip();
    const n = entries.length;
    for (let i = 0; i < n; i++) {
      zip.file(entries[i].path, entries[i].file, { binary: true, createFolders: false, date: new Date(entries[i].file.lastModified || Date.now()) });
      if (i % 400 === 0) {
        setProgress(`zip 만드는 중… 파일 목록 ${fmt(i)} / ${fmt(n)}`, (i / n) * 10);
        await tick();
        checkCancelled();
      }
    }
    let lastPaint = 0;
    const blob = await zip.generateAsync({ type: 'blob', compression: 'STORE', streamFiles: true }, (meta) => {
      if (state.cancelled) throw new Error('cancelled');
      const now = performance.now();
      if (now - lastPaint > 100 || meta.percent >= 100) {
        lastPaint = now;
        setProgress(`zip 만드는 중… ${fmt(n)}개 파일`, 10 + meta.percent * 0.9);
      }
    }).catch((e) => {
      if (state.cancelled) throw new UserError('측정을 취소했습니다.');
      throw new UserError('zip 을 만드는 중 파일을 읽지 못했습니다: ' + (e && e.message ? e.message : e), '파일이 다른 프로그램에서 잠겨 있지 않은지, 선택한 뒤 파일이 바뀌지 않았는지 확인해 주세요.');
    });
    return blob;
  }

  /** zip 을 조각으로 나눠 순서대로 올린다. 실패한 조각은 한 번 다시 보낸다. */
  async function uploadBlob(blob, sourceType, sourceName) {
    const chunk = state.config.chunkBytes;
    const total = Math.max(1, Math.ceil(blob.size / chunk));
    let jobId = '';
    for (let i = 0; i < total; i++) {
      const part = blob.slice(i * chunk, Math.min(blob.size, (i + 1) * chunk));
      const fields = { jobId, index: String(i), total: String(total), sourceType, sourceName };
      let res;
      try {
        res = await sendChunk(fields, part, i * chunk, blob.size);
      } catch (e) {
        checkCancelled();
        if (!e.retryable) throw e;
        setProgress(`업로드 재시도 중… (${i + 1}/${total} 조각)`, (i * chunk / blob.size) * 100);
        res = await sendChunk(fields, part, i * chunk, blob.size);
      }
      jobId = res.jobId;
      checkCancelled();
    }
    return jobId;
  }

  function sendChunk(fields, part, offset, size) {
    return new Promise((resolve, reject) => {
      const fd = new FormData();
      for (const [k, v] of Object.entries(fields)) fd.append(k, v);
      fd.append('chunk', part, 'chunk.bin');
      const xhr = new XMLHttpRequest();
      state.xhr = xhr;
      xhr.open('POST', 'api/upload.php');
      xhr.responseType = 'text';
      xhr.upload.onprogress = (ev) => {
        const sent = offset + (ev.lengthComputable ? Math.min(ev.loaded, part.size) : 0);
        setProgress(`업로드 중… ${formatBytes(sent)} / ${formatBytes(size)}`, (sent / size) * 100);
      };
      xhr.onload = () => {
        let body = null;
        try { body = JSON.parse(xhr.responseText); } catch { /* 아래에서 처리 */ }
        if (xhr.status >= 200 && xhr.status < 300 && body) {
          setProgress(`업로드 중… ${formatBytes(offset + part.size)} / ${formatBytes(size)}`, ((offset + part.size) / size) * 100);
          resolve(body);
          return;
        }
        const err = httpError(xhr.status, body);
        err.retryable = xhr.status >= 500 || (xhr.status === 0);
        if (xhr.status === 413 && !(body && body.error)) {
          err.message = '업로드 조각이 서버 한도를 넘었습니다 (413).';
        }
        reject(err);
      };
      xhr.onerror = () => {
        const err = new UserError('업로드 중 서버와 연결이 끊어졌습니다.', 'Apache가 실행 중인지, 네트워크가 연결되어 있는지 확인해 주세요.');
        err.retryable = true;
        reject(err);
      };
      xhr.onabort = () => reject(new UserError('측정을 취소했습니다.'));
      xhr.send(fd);
    });
  }

  /** 측정을 요청하고, 끝날 때까지 진행 상태를 물어 표시한다. */
  async function measure(jobId, mode, rules) {
    setProgress('서버에서 압축을 푸는 중…', null);
    let stop = false;
    const poll = async () => {
      while (!stop) {
        await new Promise((r) => setTimeout(r, 700));
        if (stop) break;
        try {
          const res = await fetch('api/measure.php?jobId=' + encodeURIComponent(jobId), { cache: 'no-store' });
          if (!res.ok || stop) continue;
          const p = await res.json();
          if (stop) break;
          if (p.phase === 'extracting') setProgress('서버에서 압축을 푸는 중…', null);
          else if (p.phase === 'measuring' && p.total > 0) {
            setProgress(`측정 중… ${p.done + 1} / ${p.total} 컴포넌트 · ${p.current}`, (p.done / p.total) * 100);
          }
        } catch { /* 진행 표시만 못 할 뿐 측정은 계속된다 */ }
      }
    };
    const poller = poll();
    try {
      return await api('measure.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ jobId, mode, excludeDirs: rules.dirs, excludeExts: rules.exts, excludeFiles: rules.files }),
      });
    } finally {
      stop = true;
      await poller;
    }
  }

  // ================================================================ 결과 모델

  function modelFromMeasure(r, componentVersion = '') {
    const okCount = r.components.filter((c) => c.ok).length;
    const sub = [
      `${r.source.type === 'zip' ? archiveLabel(r.source.name) : '폴더'} “${r.source.name}”`,
      componentVersion ? `버전 ${componentVersion}` : null,
      r.mode === 'single' ? '단일 컴포넌트' : '컴포넌트 그룹',
      `컴포넌트 ${r.components.length}개` + (okCount < r.components.length ? ` (실패 ${r.components.length - okCount})` : ''),
      `cloc ${r.engine.version} (${r.engine.runner})`,
      `${(r.elapsedMs / 1000).toFixed(1)}초`,
    ].filter(Boolean).join(' · ');
    return {
      kind: 'measure',
      title: '측정 결과',
      sub,
      componentVersion,
      batchId: r.batchId || '',
      parentName: r.parentName || '',
      mode: r.mode,
      engine: r.engine,
      source: r.source,
      excludes: r.excludes,
      components: r.components,
      skipped: r.skipped || null,
      saves: {},             // 컴포넌트명 → { ok, file?, error? }
      saving: false,
    };
  }

  /** 측정 이력 여러 개 → 한 화면 모델. 이력 하나가 컴포넌트 하나가 된다 (같은 이름은 날짜·회차를 붙여 구분). */
  function modelFromHistoryMulti(recs, files) {
    const count = new Map();
    for (const r of recs) count.set(r.component, (count.get(r.component) || 0) + 1);
    const batches = new Set(recs.map((r) => (r.batch && r.batch.id) || '')).size;
    const dates = [...new Set(recs.map((r) => r.date))].sort();
    const versions = [...new Set(recs.map((r) => (r.engine && r.engine.version) || '?'))];
    const first = recs[0];
    return {
      kind: 'history',
      multi: true,
      title: `history · 선택한 ${recs.length}개 함께 보기`,
      sub: [`이력 ${recs.length}개`, `측정 ${batches}번`, dates.length > 1 ? `${dates[0]} ~ ${dates[dates.length - 1]}` : dates[0],
        `cloc ${versions.join(', ')}`].join(' · '),
      engine: first.engine || { tool: 'cloc', version: '?', runner: 'unknown' },
      source: first.source,
      excludes: first.excludes,
      components: recs.map((rec) => ({
        name: count.get(rec.component) > 1 ? `${rec.component} (${rec.date.slice(2)} #${rec.run})` : rec.component,
        ok: true, runner: rec.engine ? rec.engine.runner : '', totals: rec.totals, languages: rec.languages || [],
        folders: Array.isArray(rec.folders) ? rec.folders : null, exts: Array.isArray(rec.exts) ? rec.exts : null, warnings: [], ignored: null,
      })),
      records: recs,
      files,
    };
  }

  function modelFromHistory(rec, file) {
    return {
      kind: 'history',
      title: 'history · ' + rec.component,
      sub: [`${file}`, rec.componentVersion ? `버전 ${rec.componentVersion}` : null, `${rec.date} ${rec.run}회차`, `측정 ${formatTime(rec.measuredAt)}`,
        rec.source && rec.source.name ? `소스 “${rec.source.name}”` : null,
        rec.engine ? `cloc ${rec.engine.version} (${rec.engine.runner})` : null].filter(Boolean).join(' · '),
      engine: rec.engine || { tool: 'cloc', version: '?', runner: 'unknown' },
      source: rec.source,
      excludes: rec.excludes,
      components: [{ name: rec.component, ok: true, runner: rec.engine ? rec.engine.runner : '', totals: rec.totals, languages: rec.languages || [],
        folders: Array.isArray(rec.folders) ? rec.folders : null, exts: Array.isArray(rec.exts) ? rec.exts : null, warnings: [], ignored: null }],
      record: rec,
      file,
    };
  }

  /**
   * LOC 조회 → 컴포넌트 그룹: 한 번의 측정(batch)에서 저장된 이력들 → 한 화면 모델 (읽기 전용, kind 'history').
   * 같은 batch 안의 컴포넌트 이름은 서로 다르므로 modelFromHistoryMulti 의 날짜·회차 접미어는 붙지 않는다
   * (손으로 고친 파일 등으로 이름이 겹칠 때만 붙어 구분된다).
   * group: { name, version, batchId, size(측정 때 컴포넌트 수) }, onComponent(name): 컴포넌트 탭으로 이동
   */
  function modelFromGroup(recs, files, group, onComponent) {
    const m = modelFromHistoryMulti(recs, files);
    const times = recs.map((r) => r.measuredAt).filter(Boolean).sort();
    const versions = [...new Set(recs.map((r) => (r.engine && r.engine.version) || '?'))];
    m.title = `컴포넌트 그룹 · ${group.name} · ${group.version ? '버전 ' + group.version : NO_VERSION}`;
    m.sub = [group.version ? `버전 ${group.version}` : NO_VERSION, times.length ? `측정 ${formatTime(times[0])}` : null,
      `컴포넌트 ${recs.length}개` + (group.size > recs.length ? ` (측정 때 ${group.size}개)` : ''),
      m.source && m.source.name ? `소스 “${m.source.name}”` : null, `cloc ${versions.join(', ')}`].filter(Boolean).join(' · ');
    m.group = group;
    m.componentVersion = group.version;
    m.compFiles = new Map(m.components.map((c, k) => [c.name, files[k]]));   // 화면의 컴포넌트 이름 → 이력 파일
    m.onComponent = (name) => onComponent(m.compFiles.get(name), name);
    return m;
  }

  function formatTime(iso) {
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return iso || '';
    const p = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`;
  }

  const metricOf = (t, metric) => (metric === 'total' ? t.code + t.comment + t.blank : t[metric]);
  /** 컴포넌트의 상세 항목 (현재 보기 기준). 예전 이력처럼 정보가 없으면 null. */
  const itemsOf = (c) => (Array.isArray(c[DIMS[state.dim].key]) ? c[DIMS[state.dim].key] : null);
  const itemName = (it) => it[DIMS[state.dim].field];
  const withTotal = (t) => ({ ...t, total: t.code + t.comment + t.blank });

  // ================================================================ 색

  function hexToHsl(hex) {
    const m = /^#?([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(hex);
    if (!m) return [0, 0, 50];
    const [r, g, b] = [m[1], m[2], m[3]].map((x) => parseInt(x, 16) / 255);
    const max = Math.max(r, g, b);
    const min = Math.min(r, g, b);
    const l = (max + min) / 2;
    let h = 0;
    let s = 0;
    if (max !== min) {
      const d = max - min;
      s = l > 0.5 ? d / (2 - max - min) : d / (max + min);
      if (max === r) h = (g - b) / d + (g < b ? 6 : 0);
      else if (max === g) h = (b - r) / d + 2;
      else h = (r - g) / d + 4;
      h *= 60;
    }
    return [h, s * 100, l * 100];
  }
  const hsl = (h, s, l) => `hsl(${h.toFixed(0)} ${s.toFixed(0)}% ${l.toFixed(0)}%)`;

  /**
   * 컴포넌트 색 계열 안에서 언어 n개를 구분할 색. 첫 번째(가장 큰 언어)가 기준 색에 가장 가깝다.
   * 파스텔 톤을 유지하면서 명암과 색상(±12°)을 함께 바꿔, 같은 계열 안에서도 잘 구분되게 한다.
   */
  function shades(base, n) {
    const [h, s, l] = hexToHsl(base);
    if (n <= 1) return [hsl(h, s, l)];
    const dark = document.documentElement.getAttribute('data-theme') === 'dark'
      || (document.documentElement.getAttribute('data-theme') !== 'light' && window.matchMedia('(prefers-color-scheme: dark)').matches);
    // 명암은 파스텔 범위 안에서만 움직인다 (너무 진하면 원색처럼 보인다)
    const from = Math.max(l - 6, dark ? 60 : 68);      // 기준 색보다 살짝 진한 파스텔
    const to = Math.min(l + 15, dark ? 86 : 93);       // 아주 연한 파스텔
    const out = [];
    for (let i = 0; i < n; i++) {
      const t = i / (n - 1);
      out.push(hsl((h + (t - 0.5) * 24 + 360) % 360, Math.min(s, dark ? 55 : 62) - t * 8, from + (to - from) * t));
    }
    // 이웃 슬라이스가 비슷해 보이지 않도록 진하고 연한 단계를 번갈아 쓴다
    const alt = [];
    for (let i = 0, j = n - 1; i <= j; i++, j--) {
      alt.push(out[i]);
      if (i !== j) alt.push(out[j]);
    }
    return alt;
  }
  /**
   * 색과 '기타' 묶음은 기준 값과 상관없이 전체 줄 수 순서로 정해, 기준을 바꿔도 색이 유지되게 한다.
   * @return {{order: Array, groups: Array, otherNames: string[], palette: string[]}}
   */
  function layout(model) {
    const palette = Array.from({ length: MAX_CHART_GROUPS }, (_, i) => cssVar('--c' + (i + 1)));
    const other = cssVar('--c-other');
    const ok = model.components.filter((c) => c.ok)
      .map((c) => ({ ...c, t: withTotal(c.totals) }))
      .sort((a, b) => b.t.total - a.t.total || a.name.localeCompare(b.name, 'ko'));
    const failed = model.components.filter((c) => !c.ok);
    const grouped = ok.length > MAX_CHART_GROUPS;
    const main = grouped ? ok.slice(0, MAX_CHART_GROUPS - 1) : ok;
    const rest = grouped ? ok.slice(MAX_CHART_GROUPS - 1) : [];
    // 안쪽 링(컴포넌트)은 조금 진한 파스텔, 바깥 링(항목)은 연한 파스텔로 명도 층을 나눠 두 링의 색이 겹쳐 보이지 않게 한다
    main.forEach((c, i) => { c.color = deepen(palette[i % palette.length]); });
    rest.forEach((c) => { c.color = other; });

    // 바깥 링 항목(언어/하위 폴더/파일 타입)은 항목 이름마다 다른 색을 쓴다. 같은 이름은 어느 컴포넌트에서나 같은 색.
    // 색 순서는 전체 줄 수가 많은 항목부터 정해, 기준 값을 바꿔도 색이 유지된다.
    const itemTotals = new Map();
    for (const c of ok) {
      for (const it of itemsOf(c) || []) {
        const t = withTotal(it);
        itemTotals.set(itemName(it), (itemTotals.get(itemName(it)) || 0) + t.total);
      }
    }
    const itemOrder = [...itemTotals.entries()].sort((a, b) => b[1] - a[1] || a[0].localeCompare(b[0], 'ko')).map(([n]) => n);
    const itemColor = new Map(itemOrder.map((n, i) => [n, distinctColor(i)]));
    for (const c of ok) c.itemColors = (itemsOf(c) || []).map((it) => itemColor.get(itemName(it)));
    return { main, rest, failed, other, rows: [...ok, ...failed], itemOrder, itemColor };
  }

  /**
   * i 번째 항목의 파스텔 색. 색상환을 황금각(약 137.5°)씩 돌려 이웃한 색끼리 멀어지게 하고,
   * 밝기를 세 단계로 번갈아 써서 항목이 많아도 구분되게 한다.
   */
  function distinctColor(i) {
    const dark = isDarkTheme();
    const h = (205 + i * 137.508) % 360;
    const light = dark ? [70, 79, 64][i % 3] : [78, 87, 71][i % 3];
    const sat = dark ? [55, 48, 52][i % 3] : [68, 62, 58][i % 3];
    return hsl(h, sat, light);
  }

  function deepen(hex) {
    const [h, s, l] = hexToHsl(hex);
    return hsl(h, Math.min(s, 58), l - (isDarkTheme() ? 12 : 16));
  }

  function isDarkTheme() {
    const t = document.documentElement.getAttribute('data-theme');
    return t === 'dark' || (t !== 'light' && window.matchMedia('(prefers-color-scheme: dark)').matches);
  }

  // ================================================================ 결과 그리기

  function currentModel() {
    if (state.tab === 'query') return query.sub === 'group' ? query.groupModel : query.compModel;
    if (state.tab === 'measure') return state.measureModel;
    return state.tab === 'history' ? state.historyModel : null;   // 관리 탭에는 결과를 보이지 않는다
  }

  function renderModel(model) {
    const card = $('result');
    if (!model) {
      card.hidden = true;
      destroyChart();
      $('empty-measure').hidden = state.tab !== 'measure' ? true : false;
      return;
    }
    $('empty-measure').hidden = true;
    card.hidden = false;
    $('result-title').textContent = model.title;
    $('result-sub').textContent = model.sub;
    document.querySelectorAll('#metric button').forEach((b) => b.setAttribute('aria-checked', String(b.dataset.metric === state.metric)));
    $('dim').value = state.dim;
    $('col-name').textContent = DIMS[state.dim].col;
    $('outer-note').textContent = `바깥 링은 각 컴포넌트 안의 ${DIMS[state.dim].label}이며, ${DIMS[state.dim].label}마다 다른 색으로 표시합니다 (같은 이름은 같은 색).`;

    const lay = layout(model);
    renderKpis(model, lay);
    renderChart(model, lay);
    renderTable(model, lay);
    renderMessages(model, lay);
    renderSave(model);
    renderJson(model);
  }

  function renderKpis(model, lay) {
    const sum = { files: 0, code: 0, comment: 0, blank: 0 };
    for (const c of lay.main.concat(lay.rest)) for (const k in sum) sum[k] += c.totals[k];
    const kpi = (label, value, sub) => el('div', { class: 'kpi' }, el('div', { class: 'kpi-label' }, label),
      el('div', { class: 'kpi-value' }, value, sub ? el('span', { class: 'kpi-sub' }, sub) : null));
    const lines = sum.code + sum.comment + sum.blank;
    const ratio = (v) => `(${pct(v, lines)})`;
    $('kpis').replaceChildren(
      kpi('컴포넌트', fmt(lay.main.length + lay.rest.length) + (lay.failed.length ? ` (+실패 ${lay.failed.length})` : '')),
      kpi('파일', fmt(sum.files)),
      kpi('코드', fmt(sum.code), ratio(sum.code)),
      kpi('주석', fmt(sum.comment), ratio(sum.comment)),
      kpi('빈 줄', fmt(sum.blank), ratio(sum.blank)),
    );
  }

  function destroyChart() {
    if (state.chart) {
      state.chart.destroy();
      state.chart = null;
    }
  }

  function redrawChart() {
    const m = currentModel();
    if (m && !$('result').hidden) renderModel(m);
    if (state.tab === 'history' && state.trendComponent) renderTrend();
    if (state.tab === 'query' && query.sub === 'component' && query.compModel) renderTrend(queryTrendView());
  }

  /** LOC 조회 → 컴포넌트 그룹 결과에서 이 이름을 누르면 컴포넌트 탭으로 갈 수 있는지 ('기타'·실패 행은 아님) */
  const canOpenComponent = (model, name) => !!(model.onComponent && model.compFiles && model.compFiles.has(name));

  /**
   * 컴포넌트 이름 표시. 이동할 수 있으면 링크 모양 버튼(밑줄, 키보드 포커스), 아니면 기존과 같은 모양.
   * cls 가 있으면 span(cls, title) 로, 없으면 글자만 돌려준다 (표의 셀은 글자만 넣어 왔다).
   */
  function compNameNode(model, name, cls) {
    if (canOpenComponent(model, name)) {
      return el('button', { type: 'button', class: (cls ? cls + ' ' : '') + 'comp-link', title: `${name} — 컴포넌트 탭에서 이 컴포넌트의 LOC 정보 보기`,
        'aria-label': `${name} 컴포넌트 탭에서 보기`, onclick: () => model.onComponent(name) }, name);
    }
    return cls ? el('span', { class: cls, title: name }, name) : name;
  }

  /** 3레벨 차트(그룹 → 컴포넌트 → 1단계 폴더): LOC 조회의 컴포넌트 그룹 결과이고 보기가 '하위 폴더별'일 때만 */
  const isThreeRing = (model) => !!model.group && state.dim === 'folder';
  const groupRingColor = () => (isDarkTheme() ? 'hsl(215 16% 44%)' : 'hsl(215 22% 74%)');
  const CHART_LABEL_2 = '컴포넌트와 언어별 줄 수 차트';
  const CHART_LABEL_3 = '그룹, 컴포넌트, 하위 폴더 3단계 줄 수 차트';

  function renderChart(model, lay) {
    destroyChart();
    const metric = state.metric;
    const all = lay.main.concat(lay.rest);
    const grand = all.reduce((s, c) => s + metricOf(c.t, metric), 0);
    const threeRing = isThreeRing(model);

    // 범례 (색 + 이름 + 값 + 비율)
    const legendItems = lay.main.map((c) => ({ name: c.name, color: c.color, value: metricOf(c.t, metric) }));
    if (lay.rest.length) {
      legendItems.push({ name: `기타 (${lay.rest.length}개)`, color: lay.other, value: lay.rest.reduce((s, c) => s + metricOf(c.t, metric), 0) });
    }
    $('legend').replaceChildren(...legendItems.map((g) => el('li', null,
      el('span', { class: 'swatch', style: `background:${g.color}` }),
      compNameNode(model, g.name, 'name'),
      el('span', { class: 'num' }, fmt(g.value)),
      el('span', { class: 'num pct' }, pct(g.value, grand)))));
    $('legend-note').textContent = lay.rest.length
      ? `컴포넌트가 ${MAX_CHART_GROUPS}개를 넘어 작은 ${lay.rest.length}개는 차트에서 '기타'로 묶었습니다. 표에는 모두 나옵니다.`
      : '';
    // 범례는 링 순서대로: 그룹(안쪽) → 컴포넌트(가운데) → 하위 폴더(바깥). 2레벨이면 기존 그대로.
    $('group-legend-block').hidden = !threeRing;
    $('legend-ring').textContent = threeRing ? '(가운데 링)' : '(안쪽 링)';
    if (threeRing) {
      $('group-legend').replaceChildren(el('li', null,
        el('span', { class: 'swatch', style: `background:${groupRingColor()}` }),
        el('span', { class: 'name', title: model.group.name }, model.group.name),
        el('span', { class: 'num' }, fmt(grand)),
        el('span', { class: 'num pct' }, pct(grand, grand))));
    }
    $('chart').setAttribute('aria-label', threeRing ? CHART_LABEL_3 : CHART_LABEL_2);
    renderOuterLegend(lay, metric, grand, model);

    const emptyMsg = $('chart-empty');
    const canvas = $('chart');
    if (typeof Chart === 'undefined') {
      canvas.hidden = true;
      emptyMsg.hidden = false;
      emptyMsg.textContent = 'lib 폴더에 Chart.js 파일(lib/chart.umd.js)을 넣어 주세요. 표는 그대로 볼 수 있습니다.';
      return;
    }
    if (grand === 0) {
      canvas.hidden = true;
      emptyMsg.hidden = false;
      emptyMsg.textContent = `${METRIC_LABEL[metric]} 줄 수가 모두 0이라 차트를 그릴 수 없습니다.`;
      return;
    }
    canvas.hidden = false;
    emptyMsg.hidden = true;

    // 바깥 링: 컴포넌트 안의 언어/하위 폴더/파일 타입 / 안쪽 링: 컴포넌트 합계
    const outer = { data: [], backgroundColor: [], labels: [], comps: [] };
    const inner = { data: [], backgroundColor: [], labels: [] };
    for (const c of lay.main) {
      inner.data.push(metricOf(c.t, metric));
      inner.backgroundColor.push(c.color);
      inner.labels.push(c.name);
      const items = itemsOf(c);
      if (!items) {
        // 예전 이력에는 이 기준의 정보가 없다: 컴포넌트 전체를 한 조각으로
        outer.data.push(metricOf(c.t, metric));
        outer.backgroundColor.push(shades(c.color, 2)[1]);
        outer.labels.push('(정보 없음)');
        outer.comps.push(c.name);
        continue;
      }
      items.forEach((it, i) => {
        outer.data.push(metricOf(withTotal(it), metric));
        outer.backgroundColor.push(c.itemColors[i]);
        outer.labels.push(itemName(it));
        outer.comps.push(c.name);
      });
    }
    if (lay.rest.length) {
      const restVal = lay.rest.reduce((s, c) => s + metricOf(c.t, metric), 0);
      inner.data.push(restVal);
      inner.backgroundColor.push(lay.other);
      inner.labels.push(`기타 (${lay.rest.length}개)`);
      outer.data.push(restVal);
      outer.backgroundColor.push(shades(lay.other, 2)[1]);
      outer.labels.push('여러 항목');
      outer.comps.push(`기타 (${lay.rest.length}개)`);
    }

    const surface = cssVar('--surface');
    const text = cssVar('--text');
    const compTotal = new Map(inner.labels.map((n, i) => [n, inner.data[i]]));
    const unit = METRIC_LABEL[metric] + ' ';
    // dataset 0 = 바깥 링(항목), 1 = 컴포넌트 링, (3레벨이면) 2 = 그룹 링(가운데 원, 조각 1개)
    const datasets = [
      { label: DIMS[state.dim].label, data: outer.data, backgroundColor: outer.backgroundColor, borderColor: surface, borderWidth: 1.5, weight: 1, hoverOffset: 4 },
      { label: '컴포넌트', data: inner.data, backgroundColor: inner.backgroundColor, borderColor: surface, borderWidth: 2, weight: threeRing ? 1.15 : 1.6, hoverOffset: 4 },
    ];
    if (threeRing) {
      datasets.push({ label: '그룹', data: [grand], backgroundColor: [groupRingColor()], borderColor: surface, borderWidth: 2, weight: 1.05, hoverOffset: 0 });
    }
    // 그룹 결과: 컴포넌트 링 조각을 누르면 컴포넌트 탭으로 ('기타' 조각은 제외)
    const compAt = (els) => {
      const hit = (els || []).find((e) => e.datasetIndex === 1);
      const name = hit ? inner.labels[hit.index] : null;
      return name && canOpenComponent(model, name) ? name : null;
    };
    const navOptions = model.onComponent ? {
      onClick: (evt, els) => { const name = compAt(els); if (name) model.onComponent(name); },
      onHover: (evt, els) => { evt.native.target.style.cursor = compAt(els) ? 'pointer' : ''; },
    } : {};
    state.chart = new Chart(canvas, {
      type: 'pie',
      plugins: [outerLabelsPlugin],
      data: { datasets },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        animation: reducedMotion() ? false : { duration: 300 },
        color: text,
        ...navOptions,
        plugins: {
          legend: { display: false },
          tooltip: {
            backgroundColor: cssVar('--surface-2'),
            titleColor: text,
            bodyColor: text,
            borderColor: cssVar('--border'),
            borderWidth: 1,
            padding: 10,
            callbacks: {
              title: () => '',
              label: (ctx) => {
                const v = ctx.parsed;
                if (ctx.datasetIndex === 2) return [model.group.name, `${unit}${fmt(v)}줄 · ${pct(v, grand)}`];
                if (ctx.datasetIndex === 1) {
                  const name = inner.labels[ctx.dataIndex];
                  if (threeRing) return [name, `${unit}${fmt(v)}줄 · 그룹 대비 ${pct(v, grand)}`];
                  return [`${name}`, `${unit}${fmt(v)}줄 · ${pct(v, grand)}`];
                }
                const comp = outer.comps[ctx.dataIndex];
                return [`${comp} · ${outer.labels[ctx.dataIndex]}`,
                  `${unit}${fmt(v)}줄 · 전체의 ${pct(v, grand)} · 컴포넌트의 ${pct(v, compTotal.get(comp) || 0)}`];
              },
            },
          },
        },
      },
    });
    state.chart.$outerLabelTexts = outer.labels.map((l, k) => (outer.comps[k].startsWith('기타 (') ? '' : l));
    state.chart.$centerLabel = threeRing ? model.group.name : '';
    state.chart.draw();
  }

  const OUTER_LEGEND_MAX = 40;   // 범례에 보일 항목 수 (나머지는 '외 N개', 표에는 모두 나옴)

  /** 바깥 링 범례: 항목마다 한 줄 (색 · 이름 · 줄 수 · 비율). 여러 컴포넌트에 있는 항목은 합쳐서 보여준다. */
  function renderOuterLegend(lay, metric, grand, model) {
    const dim = DIMS[state.dim];
    $('outer-legend-title').textContent = dim.label;
    const sums = new Map();
    let missing = false;
    let missingAll = lay.main.length + lay.rest.length > 0;
    for (const c of lay.main.concat(lay.rest)) {
      const items = itemsOf(c);
      if (!items) { missing = true; continue; }
      missingAll = false;
      for (const it of items) sums.set(itemName(it), (sums.get(itemName(it)) || 0) + metricOf(withTotal(it), metric));
    }
    if (model && isThreeRing(model) && missingAll) {
      // 그룹의 모든 이력이 폴더 정보가 없는 예전 형식: 바깥 링은 컴포넌트마다 '(정보 없음)' 한 조각
      $('outer-legend').replaceChildren(el('p', { class: 'hint' },
        '이 측정의 이력에는 하위 폴더 정보가 없습니다 (이 기능이 생기기 전에 저장됨). 바깥 링은 컴포넌트마다 ‘(정보 없음)’ 한 조각으로 표시합니다.'));
      return;
    }
    const rows = lay.itemOrder.filter((n) => sums.has(n)).slice(0, OUTER_LEGEND_MAX).map((n) => el('li', null,
      el('span', { class: 'swatch', style: `background:${lay.itemColor.get(n)}` }),
      el('span', { class: 'name', title: n }, n),
      el('span', { class: 'num' }, fmt(sums.get(n))),
      el('span', { class: 'num pct' }, pct(sums.get(n), grand))));
    const extra = [];
    if (lay.itemOrder.length > OUTER_LEGEND_MAX) extra.push(el('p', { class: 'hint' }, `외 ${lay.itemOrder.length - OUTER_LEGEND_MAX}개 항목은 표에서 확인하세요.`));
    if (missing) extra.push(el('p', { class: 'hint' }, `이 이력에는 ${dim.label}별 정보가 없습니다.`));
    if (lay.rest.length) extra.push(el('p', { class: 'hint' }, `'기타'로 묶인 컴포넌트의 항목도 합계에 포함했습니다.`));
    $('outer-legend').replaceChildren(el('ul', { class: 'legend' }, rows), ...extra);
  }

  /**
   * 바깥 링 조각 위에 항목 이름(언어/하위 폴더/파일 타입)을 쓴다.
   * 조각 안에 들어가지 않으면 글자를 줄이고(…), 그래도 안 되면 쓰지 않는다 (툴팁·범례로 확인).
   * 그린 이름은 chart.$outerLabels 에 남긴다 (테스트용).
   */
  const outerLabelsPlugin = {
    id: 'outerLabels',
    afterDatasetsDraw(chart) {
      const meta = chart.getDatasetMeta(0);
      const labels = chart.$outerLabelTexts || [];
      const ctx = chart.ctx;
      const drawn = [];
      ctx.save();
      ctx.font = `600 11px ${cssVar('--font') || 'sans-serif'}`;
      ctx.textAlign = 'center';
      ctx.textBaseline = 'middle';
      meta.data.forEach((arc, i) => {
        const text = labels[i];
        if (!text || meta.data[i].hidden) return;
        const { x, y, startAngle, endAngle, innerRadius, outerRadius } = arc.getProps(['x', 'y', 'startAngle', 'endAngle', 'innerRadius', 'outerRadius'], true);
        const angle = endAngle - startAngle;
        const thick = outerRadius - innerRadius;
        if (angle < 0.12 || thick < 14) return;
        const r = (innerRadius + outerRadius) / 2;
        const mid = startAngle + angle / 2;
        // 가로 글자가 들어갈 수 있는 폭: 조각의 호 길이와 링 두께 중 작은 쪽을 기준으로 (대략)
        const room = Math.min(angle * r, thick * 1.9) - 6;
        let t = text;
        while (t.length > 1 && ctx.measureText(t).width > room) t = t.slice(0, -2) + '…';
        if (ctx.measureText(t).width > room || t.length < 2) return;
        const px = x + Math.cos(mid) * r;
        const py = y + Math.sin(mid) * r;
        ctx.lineWidth = 3;
        ctx.strokeStyle = 'rgba(255,255,255,.75)';   // 파스텔 위에서도 읽히도록 흰 테두리 + 진한 글자
        ctx.strokeText(t, px, py);
        ctx.fillStyle = '#1f2937';
        ctx.fillText(t, px, py);
        drawn.push(t);
      });
      // 3레벨 차트: 가운데 원(그룹 링)에 그룹 이름. 원 안에 들어가지 않으면 줄이거나 쓰지 않는다.
      chart.$centerDrawn = '';
      const center = chart.$centerLabel;
      const cmeta = center ? chart.getDatasetMeta(2) : null;
      if (cmeta && cmeta.data[0]) {
        const { x, y, outerRadius } = cmeta.data[0].getProps(['x', 'y', 'outerRadius'], true);
        const room = outerRadius * 2 - 12;
        ctx.font = `700 12px ${cssVar('--font') || 'sans-serif'}`;
        let t = center;
        while (t.length > 1 && ctx.measureText(t).width > room) t = t.slice(0, -2) + '…';
        if (ctx.measureText(t).width <= room && outerRadius >= 18) {
          ctx.lineWidth = 3;
          ctx.strokeStyle = 'rgba(255,255,255,.75)';
          ctx.strokeText(t, x, y);
          ctx.fillStyle = '#1f2937';
          ctx.fillText(t, x, y);
          chart.$centerDrawn = t;
        }
      }
      ctx.restore();
      chart.$outerLabels = drawn;
    },
  };

  function renderTable(model, lay) {
    const metric = state.metric;
    const all = lay.main.concat(lay.rest);
    const grand = all.reduce((s, c) => s + metricOf(c.t, metric), 0);
    const cols = ['files', 'code', 'comment', 'blank', 'total'];
    const RATIO_COLS = ['code', 'comment', 'blank'];   // 이 칸에는 그 행 전체 줄 수 대비 (xx.x%) 를 붙인다
    const numCell = (col, v, t) => el('td', { class: 'num' + (col === metric ? ' is-metric' : ''), 'data-col': col },
      el('span', { class: 'n' }, fmt(v)),
      t && RATIO_COLS.includes(col) ? el('span', { class: 'ratio' }, `(${pct(v, t.total)})`) : null);
    document.querySelectorAll('.result-table thead th[data-col]').forEach((th) => th.classList.toggle('is-metric', th.dataset.col === metric));

    const rows = [];
    for (const c of lay.rows) {
      if (!c.ok) {
        rows.push(el('tr', { class: 'comp failed' },
          el('td', null, el('div', { class: 'cell-name' }, el('span', { class: 'badge badge-error' }, '실패'), c.name),
            el('span', { class: 'err-text' }, c.error || '원인을 알 수 없습니다.')),
          ...cols.map((col) => el('td', { class: 'num' + (col === metric ? ' is-metric' : ''), 'data-col': col }, '—')),
          el('td', { class: 'num share' }, '—')));
        continue;
      }
      const t = c.t;
      rows.push(el('tr', { class: 'comp' },
        el('th', { scope: 'row' },
          el('div', { class: 'cell-name' }, el('span', { class: 'swatch', style: `background:${c.color}` }), compNameNode(model, c.name, null))),
        ...cols.map((col) => numCell(col, t[col], t)),
        el('td', { class: 'num share' }, pct(metricOf(t, metric), grand))));
      const items = itemsOf(c);
      if (!items) {
        rows.push(el('tr', { class: 'lang' }, el('td', { colspan: '7', class: 'hint' },
          `이 이력에는 ${DIMS[state.dim].label}별 정보가 없습니다 (이 기능이 생기기 전에 저장됨). 언어별 보기는 가능합니다.`)));
        continue;
      }
      if (!items.length) {
        rows.push(el('tr', { class: 'lang' }, el('td', { colspan: '7', class: 'hint' }, '코드로 인식된 파일이 없습니다.')));
      }
      items.forEach((it, i) => {
        const lt = withTotal(it);
        rows.push(el('tr', { class: 'lang' },
          el('td', null, el('div', { class: 'cell-name' }, el('span', { class: 'swatch sm', style: `background:${c.itemColors[i]}` }), itemName(it))),
          ...cols.map((col) => numCell(col, lt[col], lt)),
          el('td', { class: 'num share' }, pct(metricOf(lt, metric), grand))));
      });
    }
    $('result-body').replaceChildren(...rows);

    const sum = { files: 0, code: 0, comment: 0, blank: 0, total: 0 };
    for (const c of all) for (const k in sum) sum[k] += c.t[k];
    $('result-foot').replaceChildren(el('tr', null,
      el('td', null, '합계'), ...cols.map((col) => numCell(col, sum[col], sum)), el('td', { class: 'num share' }, all.length ? '100.0%' : '—')));
  }

  function renderMessages(model, lay) {
    const box = $('messages');
    const out = [];
    const ok = lay.main.concat(lay.rest);
    if (lay.failed.length) {
      out.push(el('div', { class: 'alert alert-error' },
        el('p', null, el('strong', null, `컴포넌트 ${lay.failed.length}개를 측정하지 못했습니다. `), '표에서 원인을 확인해 주세요. 나머지 결과는 정상입니다.'),
        el('p', null, `제한 시간 초과라면 컴포넌트를 나누거나 config.php 의 CLOC_TIMEOUT_SEC(현재 ${state.config.clocTimeoutSec}초)를 늘려 주세요.`)));
    }
    if (model.kind === 'measure' && ok.length && ok.every((c) => c.totals.code === 0)) {
      out.push(el('div', { class: 'alert alert-warn' },
        el('p', null, el('strong', null, '코드로 인식된 파일이 없습니다. '), '선택한 폴더가 맞는지, 제외 규칙이 너무 넓지 않은지 확인해 주세요.')));
    }
    if (model.kind === 'measure') {
      const pl = ok.filter((c) => c.runner === 'pl').map((c) => c.name);
      const file = model.engine.file || (model.engine.runner === 'exe' ? 'cloc exe' : 'cloc pl');
      const info = [`실행 방식: cloc ${model.engine.version} (${model.engine.runner === 'exe' ? file : 'Perl + ' + file})`];
      if (pl.length && model.engine.runner === 'exe') info.push(`exe 실패로 Perl 로 대체한 컴포넌트: ${pl.join(', ')}`);
      const warns = [];
      for (const c of model.components) for (const w of c.warnings || []) warns.push(`${c.name}: ${w}`);
      const ign = model.components.filter((c) => c.ignored && c.ignored.count > 0)
        .map((c) => `${c.name} ${fmt(c.ignored.count)}개 (${c.ignored.reasons.map((r) => `${r.reason} ${fmt(r.count)}`).join(', ')})`);
      const alertBox = el('div', { class: 'alert ' + (warns.length ? 'alert-warn' : 'alert-info') },
        el('p', null, info.join(' · ')));
      if (ign.length) {
        alertBox.append(el('p', null, 'cloc 이 인식하지 못해 세지 않은 파일:'), el('ul', null, ign.map((t) => el('li', null, t))));
      }
      if (warns.length) {
        alertBox.append(el('p', null, 'cloc 경고:'), el('ul', null, warns.slice(0, 10).map((t) => el('li', null, t))));
      }
      if (model.skipped && model.skipped.count) {
        alertBox.append(el('p', null, `측정에서 빠진 항목 ${fmt(model.skipped.count)}개:`),
          el('ul', null, model.skipped.items.slice(0, 10).map((s) => el('li', null, `${s.path} — ${s.reason}`))));
      }
      out.push(alertBox);
    }
    box.replaceChildren(...out);
  }

  // ================================================================ Excel 내보내기

  /** 지금 화면의 표(보기 기준·차트 기준 포함)를 Excel 시트 행으로 만든다. */
  function exportSheets(model) {
    const lay = layout(model);
    const metric = state.metric;
    const dim = DIMS[state.dim];
    const all = lay.main.concat(lay.rest);
    const grand = all.reduce((s, c) => s + metricOf(c.t, metric), 0);
    const share = (v) => (grand > 0 ? v / grand : null);
    const nums = (t, bold) => ['files', 'code', 'comment', 'blank', 'total'].map((k) => ({ v: t[k], s: bold ? 'boldNum' : 'num' }));
    // 행 전체 줄 수 대비 코드/주석/빈 줄 비율 (화면 표의 (xx.x%))
    const ratios = (t, bold) => ['code', 'comment', 'blank'].map((k) => ({ v: t.total > 0 ? t[k] / t.total : null, s: bold ? 'boldPct' : 'pct' }));
    const header = ['컴포넌트', '구분', dim.label, '파일', '코드', '주석', '빈 줄', '전체', `비율(${METRIC_LABEL[metric]})`, '코드 %', '주석 %', '빈 줄 %', '비고']
      .map((v) => ({ v, s: 'header' }));
    const rows = [header];
    for (const c of lay.rows) {
      if (!c.ok) {
        rows.push([{ v: c.name, s: 'bold' }, '실패', '', null, null, null, null, null, null, null, null, null, c.error || '원인을 알 수 없습니다.']);
        continue;
      }
      rows.push([{ v: c.name, s: 'bold' }, { v: '소계', s: 'bold' }, '', ...nums(c.t, true), { v: share(metricOf(c.t, metric)), s: 'boldPct' }, ...ratios(c.t, true), '']);
      const items = itemsOf(c);
      if (!items) {
        rows.push([c.name, dim.label, '', null, null, null, null, null, null, null, null, null, `이 이력에는 ${dim.label}별 정보가 없습니다.`]);
        continue;
      }
      for (const it of items) {
        const t = withTotal(it);
        rows.push([c.name, dim.label, itemName(it), ...nums(t, false), { v: share(metricOf(t, metric)), s: 'pct' }, ...ratios(t, false), '']);
      }
    }
    const sum = { files: 0, code: 0, comment: 0, blank: 0, total: 0 };
    for (const c of all) for (const k in sum) sum[k] += c.t[k];
    rows.push([{ v: '합계', s: 'bold' }, '', '', ...nums(sum, true), { v: grand > 0 ? 1 : null, s: 'boldPct' }, ...ratios(sum, true), '']);

    const ex = model.excludes || {};
    const info = [
      [{ v: '항목', s: 'header' }, { v: '값', s: 'header' }],
      ['제목', model.title],
      ['내용', model.sub],
      ['보기 기준', dim.label + '별'],
      ['비율 기준', METRIC_LABEL[metric]],
      ['컴포넌트 버전', (model.kind === 'history' ? (model.record ? model.record.componentVersion : model.componentVersion) : model.componentVersion) || ''],
      ['소스', model.source ? `${model.source.type} “${model.source.name}”` : ''],
      ['실행 방식', model.engine ? `cloc ${model.engine.version} (${model.engine.runner})` : ''],
      ['제외 폴더', (ex.dirs || []).join(', ')],
      ['제외 확장자', (ex.exts || []).join(', ')],
      ['제외 파일', (ex.files || []).join(', ')],
    ];
    if (model.kind === 'history' && model.record) {
      info.push(['history 파일', model.file || state.histSelected || ''], ['측정 시각', model.record.measuredAt || '']);
    }
    if (model.group) {
      info.push(['컴포넌트 그룹', model.group.name], ['측정 ID', model.group.batchId], ['측정 때 컴포넌트 수', { v: model.group.size, s: 'num' }]);
    }
    if (model.multi) {
      info.push(['history 파일', model.files.join(', ')]);
    }
    info.push(['내보낸 시각', formatTime(new Date().toISOString())]);
    return [
      { name: '상세', rows, widths: [22, 10, 28, 9, 11, 11, 11, 11, 11, 9, 9, 9, 40], freezeHeader: true },
      { name: '정보', rows: info, widths: [16, 90] },
    ];
  }

  function exportFileName(model) {
    const d = new Date();
    const p = (n) => String(n).padStart(2, '0');
    const stamp = `${String(d.getFullYear()).slice(2)}${p(d.getMonth() + 1)}${p(d.getDate())}_${p(d.getHours())}${p(d.getMinutes())}`;
    const base = model.group ? `${model.group.name}_${model.group.version || '버전없음'}`
      : model.multi ? `history_${model.records.length}개`
      : model.kind === 'history' && model.record
      ? `${model.record.component}_${model.record.date}_${model.record.run}회차`
      : (model.source && model.source.name ? model.source.name.replace(ARCHIVE_RE, '') : 'result');
    return `CLOC_${base}_${DIMS[state.dim].label}별_${stamp}.xlsx`.replace(/[\\/:*?"<>|\u0000-\u001F]/g, '_').replace(/\s+/g, '_');
  }

  $('export-xlsx').addEventListener('click', async () => {
    const model = currentModel();
    const btn = $('export-xlsx');
    if (!model || btn.disabled) return;
    btn.disabled = true;
    const label = btn.textContent;
    btn.textContent = '만드는 중…';
    try {
      if (typeof XlsxExport === 'undefined' || typeof JSZip === 'undefined') {
        throw new Error('lib/jszip.min.js 또는 assets/xlsx-export.js 가 없어 Excel 파일을 만들 수 없습니다.');
      }
      const blob = await XlsxExport.build(exportSheets(model));
      XlsxExport.download(blob, exportFileName(model));
    } catch (e) {
      window.alert('Excel 파일을 만들지 못했습니다: ' + (e && e.message ? e.message : e));
    } finally {
      btn.disabled = false;
      btn.textContent = label;
    }
  });

  // ================================================================ history 저장

  function recordPayload(model, c) {
    return {
      component: c.name,
      componentVersion: model.componentVersion || '',
      batch: model.batchId ? { id: model.batchId, size: model.components.filter((x) => x.ok).length, mode: model.mode, parent: model.parentName || '' } : null,
      engine: { tool: 'cloc', version: model.engine.version, runner: c.runner || model.engine.runner },
      source: model.source,
      excludes: model.excludes,
      languages: c.languages,
      folders: c.folders || [],
      exts: c.exts || [],
    };
  }

  function renderSave(model) {
    const box = $('save-box');
    if (model.kind !== 'measure') {
      box.hidden = true;
      return;
    }
    box.hidden = false;
    const btn = $('save');
    const targets = model.components.filter((c) => c.ok);
    const saved = targets.filter((c) => model.saves[c.name] && model.saves[c.name].ok);
    const failed = targets.filter((c) => model.saves[c.name] && !model.saves[c.name].ok);
    if (!targets.length) {
      btn.disabled = true;
      btn.textContent = '저장할 결과가 없습니다';
    } else if (model.saving) {
      btn.disabled = true;
      btn.textContent = '저장 중…';
    } else if (saved.length === targets.length) {
      btn.disabled = true;
      btn.textContent = `저장됨 (${saved.length}개)`;
    } else if (failed.length) {
      btn.disabled = false;
      btn.textContent = `실패한 ${failed.length}개 다시 저장`;
    } else {
      btn.disabled = false;
      btn.textContent = `history에 저장 (${targets.length}개)`;
    }
    $('save-list').replaceChildren(...targets.filter((c) => model.saves[c.name]).map((c) => {
      const s = model.saves[c.name];
      return s.ok
        ? el('li', null, el('span', { class: 'ok' }, '저장 ✓ '), `${c.name} → `, el('code', null, s.file))
        : el('li', null, el('span', { class: 'fail' }, '실패 ✕ '), `${c.name}: ${s.error}`);
    }));
  }

  $('save').addEventListener('click', async () => {
    const model = state.measureModel;
    if (!model || model.saving) return;
    const targets = model.components.filter((c) => c.ok && !(model.saves[c.name] && model.saves[c.name].ok));
    if (!targets.length) return;
    model.saving = true;
    renderSave(model);
    for (const c of targets) {
      try {
        const r = await api('history.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(recordPayload(model, c)),
        });
        model.saves[c.name] = { ok: true, file: r.file };
      } catch (e) {
        model.saves[c.name] = { ok: false, error: e.message + (e.hint ? ' ' + e.hint : '') };
      }
      renderSave(model);
    }
    model.saving = false;
    state.histLoaded = false;   // history 탭을 열 때 다시 불러온다
    renderSave(model);
    renderJson(model);
  });

  function renderJson(model) {
    let data;
    if (model.kind === 'history') {
      $('json-summary').textContent = model.multi ? `저장된 JSON 보기 (${model.records.length}개)` : '저장된 JSON 보기';
      data = model.multi ? model.records : model.record;
    } else {
      $('json-summary').textContent = '저장될 JSON 미리보기 (run, date, measuredAt, totals 는 서버가 정합니다)';
      data = model.components.filter((c) => c.ok).map((c) => ({
        component: c.name,
        run: model.saves[c.name] && model.saves[c.name].ok ? `(저장됨: ${model.saves[c.name].file})` : '(서버가 정함)',
        date: '(서버가 정함)',
        measuredAt: '(서버가 정함)',
        timeZone: 'Asia/Seoul',
        ...recordPayload(model, c),
        totals: c.totals,
      })).map(({ component, componentVersion, run, date, measuredAt, timeZone, engine, source, excludes, totals, languages, folders, exts }) =>
        ({ component, componentVersion, run, date, measuredAt, timeZone, engine, source, excludes, totals, languages, folders, exts }));
    }
    $('json-pre').textContent = JSON.stringify(data, null, 2);
  }

  // ================================================================ 기준 값 전환

  $('metric').addEventListener('click', (ev) => {
    const b = ev.target.closest('button[data-metric]');
    if (!b) return;
    state.metric = b.dataset.metric;
    const m = currentModel();
    if (m) renderModel(m);
  });
  $('metric').addEventListener('keydown', (ev) => {
    if (!['ArrowLeft', 'ArrowRight'].includes(ev.key)) return;
    const keys = Object.keys(METRIC_LABEL);
    const i = (keys.indexOf(state.metric) + (ev.key === 'ArrowRight' ? 1 : keys.length - 1)) % keys.length;
    state.metric = keys[i];
    const m = currentModel();
    if (m) renderModel(m);
    $('metric').querySelector(`[data-metric="${state.metric}"]`).focus();
  });

  $('dim').addEventListener('change', (ev) => {
    if (!DIMS[ev.target.value]) return;
    state.dim = ev.target.value;
    const m = currentModel();
    if (m) renderModel(m);
  });

  // ================================================================ 탭

  const TABS = ['query', 'measure', 'history', 'admin'];

  function showTab(tab) {
    state.tab = tab;
    for (const t of TABS) {
      const on = t === tab;
      $('tab-' + t).setAttribute('aria-selected', String(on));
      $('tab-' + t).tabIndex = on ? 0 : -1;
      $('view-' + t).hidden = !on;
    }
    if (tab === 'query') {       // 다른 사람이 저장했을 수 있으므로 열 때마다 목록을 새로 읽는다 (그동안은 이전 결과를 보인다)
      showQuerySub(query.sub, { load: false });
      loadQueryList();
    }
    if (tab === 'history' && !state.histLoaded) loadHistory();
    if (tab === 'history' && state.trendComponent && state.histLoaded) renderTrend();
    if (tab === 'admin') {   // 다른 사람이 바꿨을 수 있으므로 열 때마다 새로 읽는다
      loadRules();
      if (!$('admin-history').hidden) loadHadmin();
      if (!$('admin-password').hidden) mountPwForm();
      syncDefaultPwNotice();
      refreshAdminAuth();
    } else {
      unmountPwForm();       // 비밀번호 칸은 관리 탭의 비밀번호 변경을 볼 때만 둔다
    }
    renderModel(currentModel());
    if (tab === 'measure') $('empty-measure').hidden = !!state.measureModel;
  }
  $('tab-query').addEventListener('click', () => showTab('query'));
  $('tab-measure').addEventListener('click', () => showTab('measure'));
  $('tab-history').addEventListener('click', () => showTab('history'));
  $('tab-admin').addEventListener('click', () => requestAdmin(() => showTab('admin')));

  // ================================================================ 관리 탭 인증 (초기 비밀번호 admin, 관리 탭에서 변경, 서버가 확인)

  let adminPending = null;
  let adminReturnFocus = null;

  /**
   * 비밀번호 칸은 창을 열 때 새로 만들고 닫을 때 없앤다.
   * 페이지에 비밀번호 칸이 늘 있으면 브라우저가 로그인 화면으로 보고 다른 입력칸에 저장된 사용자 이름을 채우기 때문이다.
   */
  function mountPasswordInput() {
    const input = el('input', { type: 'password', id: 'password-input', name: 'locmgmt-admin-date', autocomplete: 'new-password',
      'aria-label': '비밀번호', 'data-lpignore': 'true', 'data-1p-ignore': 'true', inputmode: 'numeric' });
    input.addEventListener('keydown', (ev) => {
      if (ev.key === 'Enter') { ev.preventDefault(); submitPassword(); }
    });
    $('password-slot').replaceChildren(input);
    return input;
  }

  /** 인증된 페이지면 바로, 아니면 비밀번호 창을 띄우고 성공하면 onOk 를 부른다. */
  function requestAdmin(onOk) {
    if (state.adminAuthed) {
      onOk();
      return;
    }
    adminPending = onOk;
    adminReturnFocus = document.activeElement;
    $('password-error').hidden = true;
    const input = mountPasswordInput();
    $('password-modal').hidden = false;
    input.focus();
  }

  function closePasswordModal() {
    $('password-modal').hidden = true;
    $('password-slot').replaceChildren();
    adminPending = null;
    if (adminReturnFocus && adminReturnFocus.focus) adminReturnFocus.focus();
  }

  async function submitPassword() {
    const input = $('password-input');
    const btn = $('password-submit-btn');
    if (btn.disabled) return;
    btn.disabled = true;
    try {
      const r = await api('auth.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ password: input.value }) });
      state.adminAuthed = !!r.authenticated;
      state.adminToken = r.token || null;
      state.adminDefaultPw = !!r.defaultPassword;
      syncDefaultPwNotice();
      const cb = adminPending;
      adminPending = null;
      $('password-modal').hidden = true;
      $('password-slot').replaceChildren();
      if (cb) cb();
    } catch (e) {
      $('password-error').textContent = e.message;
      $('password-error').hidden = false;
      input.value = '';
      input.focus();
    } finally {
      btn.disabled = false;
    }
  }

  $('password-submit-btn').addEventListener('click', submitPassword);
  $('password-cancel-btn').addEventListener('click', closePasswordModal);
  $('password-modal').addEventListener('keydown', (ev) => { if (ev.key === 'Escape') closePasswordModal(); });
  $('password-modal').addEventListener('click', (ev) => { if (ev.target === $('password-modal')) closePasswordModal(); });

  $('admin-logout').addEventListener('click', async () => {
    try { await api('auth.php', { method: 'DELETE' }); } catch { /* 이미 끝난 세션이어도 화면은 잠근다 */ }
    state.adminAuthed = false;
    state.adminToken = null;
    syncDefaultPwNotice();
    showTab('measure');
  });

  // 관리 탭의 하위 탭 (제외 규칙 관리 / 저장 이력 관리 / 비밀번호 변경 / 가이드)
  const ADMIN_TABS = ['rules', 'history', 'password', 'guide'];
  function showAdminTab(name) {
    for (const t of ADMIN_TABS) {
      const on = t === name;
      const btn = $('admin-tab-' + t);
      btn.classList.toggle('active', on);
      btn.setAttribute('aria-selected', String(on));
      btn.tabIndex = on ? 0 : -1;
      $('admin-' + t).hidden = !on;
    }
    // 비밀번호 칸은 이 하위 탭을 볼 때만 둔다
    if (name === 'password') mountPwForm();
    else unmountPwForm();
  }

  // ================================================================ 관리: 비밀번호 변경

  function syncDefaultPwNotice() {
    $('admin-default-pw').hidden = !(state.adminAuthed && state.adminDefaultPw);
  }
  $('admin-default-pw-go').addEventListener('click', () => {
    showAdminTab('password');
    $('admin-tab-password').focus();
  });

  /** 관리 탭을 열 때: 다른 PC에서 비밀번호를 바꿨으면 이 페이지의 토큰은 무효이므로 다시 인증, 초기 비밀번호 안내도 갱신 */
  async function refreshAdminAuth() {
    try {
      const r = await api('auth.php');
      if (!r.authenticated && state.adminAuthed) {
        state.adminAuthed = false;
        state.adminToken = null;
        if (state.tab === 'admin') reauthAdmin();
        return;
      }
      state.adminDefaultPw = !!r.defaultPassword;
      syncDefaultPwNotice();
    } catch { /* 확인하지 못해도 관리 API 가 401 이면 그때 다시 인증한다 */ }
  }

  function mountPwForm() {
    if ($('pw-form')) return;
    const field = (id, label, autocomplete) => el('label', { class: 'pw-field' },
      el('span', { class: 'field-label' }, label),
      el('input', { type: 'password', id, class: 'text-input', autocomplete, maxlength: '72', spellcheck: 'false',
        'data-lpignore': 'true', 'data-1p-ignore': 'true' }));
    const form = el('form', { class: 'pw-form', id: 'pw-form', autocomplete: 'off', novalidate: true, onsubmit: (ev) => { ev.preventDefault(); changePassword(); } },
      field('pw-current', '현재 비밀번호', 'off'),
      field('pw-new', '새 비밀번호', 'new-password'),
      field('pw-confirm', '새 비밀번호 확인', 'new-password'),
      el('div', { class: 'pw-actions' }, el('button', { type: 'submit', class: 'btn btn-primary', id: 'pw-submit' }, '비밀번호 변경')));
    $('pw-form-slot').replaceChildren(form);
    $('pw-error').hidden = true;
  }
  function unmountPwForm() {
    $('pw-form-slot').replaceChildren();
    $('pw-error').hidden = true;
    $('pw-done').hidden = true;
  }

  async function changePassword() {
    const btn = $('pw-submit');
    if (!btn || btn.disabled) return;
    const cur = $('pw-current').value;
    const next = $('pw-new').value;
    const confirm = $('pw-confirm').value;
    const fail = (message, focusId) => {
      $('pw-done').hidden = true;
      $('pw-error').textContent = message;
      $('pw-error').hidden = false;
      if (focusId) $(focusId).focus();
    };
    if (!cur) return fail('현재 비밀번호를 입력해 주세요.', 'pw-current');
    if (!next) return fail('새 비밀번호를 입력해 주세요.', 'pw-new');
    if (next !== confirm) return fail('새 비밀번호와 확인이 서로 다릅니다. 다시 입력해 주세요.', 'pw-confirm');
    btn.disabled = true;
    try {
      const r = await api('auth.php', { method: 'PUT', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ currentPassword: cur, newPassword: next }) });
      state.adminToken = r.token || null;    // 비밀번호 버전이 바뀌어 새 토큰을 받는다 (다른 PC·탭의 토큰은 무효)
      state.adminDefaultPw = !!r.defaultPassword;
      syncDefaultPwNotice();
      unmountPwForm();
      mountPwForm();
      $('pw-done').textContent = '비밀번호를 바꿨습니다. 다음 로그인부터 새 비밀번호를 쓰세요. 다른 PC나 탭에서 열려 있던 관리 화면은 다시 로그인해야 합니다.';
      $('pw-done').hidden = false;
    } catch (e) {
      if (e.status === 401) {          // 이 페이지의 인증이 끝남 (다른 곳에서 비밀번호를 바꾼 경우 등)
        state.adminAuthed = false;
        state.adminToken = null;
        reauthAdmin();
        return;
      }
      $('pw-current').value = '';
      fail(e.message + (e.hint ? ' ' + e.hint : ''), e.status === 400 && /현재 비밀번호/.test(e.message) ? 'pw-current' : 'pw-new');
    } finally {
      if ($('pw-submit')) $('pw-submit').disabled = false;
    }
  }
  for (const t of ADMIN_TABS) $('admin-tab-' + t).addEventListener('click', () => { showAdminTab(t); if (t === 'history') loadHadmin(); });

  // ================================================================ 관리: 저장 이력 관리 (수정·삭제)

  const hadmin = { items: [], page: 1, size: '15', checked: new Set(), editing: null, busy: false };

  function showHadminError(e) {
    if (e && !state.adminAuthed && state.tab === 'admin') reauthAdmin();
    const box = $('hadmin-error');
    box.hidden = !e;
    box.textContent = e ? e.message + (e.hint ? ' ' + e.hint : '') : '';
  }

  async function loadHadmin() {
    try {
      hadmin.items = (await api('history.php')).items;
      const exist = new Set(hadmin.items.map((i) => i.file));
      for (const f of [...hadmin.checked]) if (!exist.has(f)) hadmin.checked.delete(f);
      if (hadmin.editing && !exist.has(hadmin.editing)) hadmin.editing = null;
      renderHadmin();
    } catch (e) {
      $('hadmin-summary').textContent = '저장 이력을 불러오지 못했습니다.';
      showHadminError(e);
    }
  }

  /** 이력이 바뀌었으니 측정 이력 탭도 다음에 열 때 새로 읽는다 */
  function historyChanged() {
    state.histLoaded = false;
  }

  function hadminFiltered() {
    const q = $('hadmin-filter').value.trim().toLowerCase();
    return hadmin.items.filter((i) => !q || [i.component, i.componentVersion, i.file].some((v) => (v || '').toLowerCase().includes(q)));
  }

  function renderHadmin() {
    const items = hadminFiltered();
    const groups = new Set(hadmin.items.filter((i) => i.batchId && i.batchSize > 1).map((i) => i.batchId)).size;
    $('hadmin-summary').replaceChildren(`저장된 이력 ${fmt(hadmin.items.length)}건`, groups ? ` · 컴포넌트 그룹 ${fmt(groups)}개` : '',
      items.length !== hadmin.items.length ? ` · 검색 결과 ${fmt(items.length)}건` : '');
    const size = hadmin.size === 'all' ? Math.max(items.length, 1) : Number(hadmin.size);
    const pages = Math.max(1, Math.ceil(items.length / size));
    hadmin.page = Math.min(Math.max(1, hadmin.page), pages);
    const offset = (hadmin.page - 1) * size;
    const pageItems = items.slice(offset, offset + size);
    hadmin.pageItems = pageItems;
    if (!pageItems.length) {
      $('hadmin-body').replaceChildren(el('tr', null, el('td', { colspan: '10', class: 'hint' }, hadmin.items.length ? '검색 결과가 없습니다.' : '저장된 이력이 없습니다.')));
    } else {
      $('hadmin-body').replaceChildren(...pageItems.map((i) => (hadmin.editing === i.file ? hadminEditRow(i) : hadminRow(i))));
    }
    // 페이지 단추
    $('hadmin-pager').hidden = items.length === 0;
    $('hadmin-page-size').value = hadmin.size;
    $('hadmin-range').textContent = items.length ? `${fmt(offset + 1)}–${fmt(offset + pageItems.length)} / ${fmt(items.length)}건` : '';
    const go = (n) => { hadmin.page = n; renderHadmin(); };
    const cur = hadmin.page;
    const b = (label, n, o = {}) => el('button', { type: 'button', class: 'pager-btn' + (o.current ? ' current' : ''), disabled: o.disabled || o.current,
      'aria-current': o.current ? 'page' : null, 'aria-label': o.aria || null, onclick: () => go(n) }, label);
    const list = [...new Set([1, pages, cur - 2, cur - 1, cur, cur + 1, cur + 2].filter((n) => n >= 1 && n <= pages))].sort((x, y) => x - y);
    const parts = [b('«', 1, { disabled: cur === 1, aria: '첫 페이지' }), b('‹', cur - 1, { disabled: cur === 1, aria: '이전 페이지' })];
    list.forEach((n, k) => {
      if (k > 0 && n - list[k - 1] > 1) parts.push(el('span', { class: 'pager-gap' }, '…'));
      parts.push(b(String(n), n, { current: n === cur, aria: `${n}페이지` }));
    });
    parts.push(b('›', cur + 1, { disabled: cur === pages, aria: '다음 페이지' }), b('»', pages, { disabled: cur === pages, aria: '마지막 페이지' }));
    $('hadmin-pages').replaceChildren(...parts);
    $('hadmin-pages').hidden = pages <= 1;
    // 선택 상태
    const all = pageItems.length > 0 && pageItems.every((i) => hadmin.checked.has(i.file));
    $('hadmin-check-all').checked = all;
    $('hadmin-check-all').indeterminate = !all && pageItems.some((i) => hadmin.checked.has(i.file));
    $('hadmin-delete').disabled = hadmin.checked.size === 0 || hadmin.busy;
    $('hadmin-delete').textContent = `선택한 ${fmt(hadmin.checked.size)}개 삭제`;
  }

  function hadminCells(i) {
    const grouped = i.batchId && i.batchSize > 1;
    return [
      el('td', { class: 'opt' }, i.clocVersion || ''),
      el('td', { class: 'opt' }, grouped ? el('span', { class: 'batch-badge', style: `--batch:${batchColor(i.batchId)}`, title: i.batchId }, batchLabel(i)) : el('span', { class: 'hint' }, '—')),
      el('td', null, i.date),
      el('td', { class: 'num' }, fmt(i.run)),
      el('td', { class: 'num' }, fmt(i.totals.code)),
      el('td', { class: 'opt' }, el('code', { class: 'file-name', title: i.file }, i.file)),
    ];
  }

  function hadminRow(i) {
    const check = el('input', { type: 'checkbox', 'aria-label': `${i.file} 선택`,
      onchange: (ev) => { ev.target.checked ? hadmin.checked.add(i.file) : hadmin.checked.delete(i.file); renderHadmin(); } });
    check.checked = hadmin.checked.has(i.file);
    return el('tr', { 'data-file': i.file },
      el('td', { class: 'chk' }, check),
      el('td', null, i.component),
      el('td', null, i.componentVersion || ''),
      ...hadminCells(i),
      el('td', { class: 'num actions' },
        el('button', { type: 'button', class: 'btn btn-sm', onclick: () => { hadmin.editing = i.file; showHadminError(null); renderHadmin(); } }, '수정'),
        el('button', { type: 'button', class: 'btn btn-sm btn-danger', onclick: () => {
          if (!window.confirm(`“${i.component}” ${i.date} ${i.run}회차 이력(${i.file})을 삭제할까요?\n파일은 history/_deleted 로 옮겨집니다.`)) return;
          hadminDelete([i.file]);
        } }, '삭제')));
  }

  function hadminEditRow(i) {
    const ver = el('input', { type: 'text', class: 'rule-input', value: i.componentVersion || '', maxlength: '50', 'aria-label': '컴포넌트 버전', placeholder: '예: 1.4.2' });
    const save = async () => {
      if (hadmin.busy) return;
      // 컴포넌트 이름은 바꿀 수 없다: 버전만 보낸다
      if (ver.value.trim() === (i.componentVersion || '')) { hadmin.editing = null; renderHadmin(); return; }
      const body = { componentVersion: ver.value };
      hadmin.busy = true;
      showHadminError(null);
      try {
        const r = await api('history.php?file=' + encodeURIComponent(i.file), { method: 'PUT', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
        hadmin.editing = null;
        historyChanged();
        await loadHadmin();
      } catch (e) {
        showHadminError(e);
      } finally {
        hadmin.busy = false;
        renderHadmin();
      }
    };
    const cancel = () => { hadmin.editing = null; showHadminError(null); renderHadmin(); };
    const keys = (ev) => { if (ev.key === 'Enter') { ev.preventDefault(); save(); } if (ev.key === 'Escape') cancel(); };
    ver.addEventListener('keydown', keys);
    setTimeout(() => ver.focus(), 0);
    return el('tr', { class: 'rule-editing', 'data-file': i.file },
      el('td', { class: 'chk' }), el('td', { title: '컴포넌트 이름은 바꿀 수 없습니다' }, i.component), el('td', null, ver), ...hadminCells(i),
      el('td', { class: 'num actions' },
        el('button', { type: 'button', class: 'btn btn-sm btn-primary', onclick: save }, '저장'),
        el('button', { type: 'button', class: 'btn btn-sm', onclick: cancel }, '취소')));
  }

  async function hadminDelete(files) {
    if (hadmin.busy || !files.length) return;
    hadmin.busy = true;
    showHadminError(null);
    const failed = [];
    for (const f of files) {
      try {
        await api('history.php?file=' + encodeURIComponent(f), { method: 'DELETE' });
        hadmin.checked.delete(f);
        state.histChecked.delete(f);
      } catch (e) {
        failed.push(`${f}: ${e.message}`);
      }
    }
    hadmin.busy = false;
    historyChanged();
    if (failed.length) showHadminError(new UserError(`${failed.length}개를 삭제하지 못했습니다. ` + failed.slice(0, 3).join(' / ')));
    await loadHadmin();
  }

  $('hadmin-delete').addEventListener('click', () => {
    const files = [...hadmin.checked];
    if (!files.length) return;
    if (!window.confirm(`선택한 이력 ${files.length}개를 삭제할까요?\n파일은 history/_deleted 로 옮겨집니다.`)) return;
    hadminDelete(files);
  });
  $('hadmin-check-all').addEventListener('change', (ev) => {
    for (const i of hadmin.pageItems || []) ev.target.checked ? hadmin.checked.add(i.file) : hadmin.checked.delete(i.file);
    renderHadmin();
  });
  $('hadmin-filter').addEventListener('input', () => { hadmin.page = 1; renderHadmin(); });
  $('hadmin-reload').addEventListener('click', loadHadmin);
  $('hadmin-page-size').addEventListener('change', (ev) => { hadmin.size = ev.target.value; hadmin.page = 1; renderHadmin(); });

  // ================================================================ 관리: 제외 규칙 관리 (CRUD)

  /** 관리 화면을 쓰는 중에 세션이 끝났으면 측정 탭으로 돌리고 다시 인증을 받는다. */
  function reauthAdmin() {
    showTab('measure');
    requestAdmin(() => showTab('admin'));
  }

  const RULE_TYPE_LABEL = { dir: '제외 폴더', ext: '제외 확장자', file: '제외 파일' };
  const RULE_PLACEHOLDER = { dir: '패턴 (예: node_modules)', ext: '패턴 (예: min.js)', file: '패턴 (예: package-lock.json)' };
  const rulesState = { list: [], loaded: false, editing: null, busy: false };

  function showRulesError(e) {
    if (e && !state.adminAuthed && state.tab === 'admin') reauthAdmin();
    const box = $('rules-error');
    if (!e) { box.hidden = true; return; }
    box.textContent = e.message + (e.hint ? ' ' + e.hint : '');
    box.hidden = false;
  }

  /** 사용 중인 규칙이 바뀌면 LOC 측정 탭의 기본값도 바꾼다. 측정 탭이 기본값 그대로였다면 새 기본값으로 채운다. */
  function applyRuleDefaults(defaults) {
    if (!defaults) return;
    const wasDefault = rulesAreDefault();
    state.config.excludeDirs = defaults.dirs;
    state.config.excludeExts = defaults.exts;
    state.config.excludeFiles = defaults.files;
    if (wasDefault || !rulesEdited) fillRuleInputs();
    refreshSelection();
  }

  async function loadRules() {
    try {
      const r = await api('rules.php');
      rulesState.list = r.rules;
      rulesState.loaded = true;
      rulesState.editing = null;
      showRulesError(null);
      applyRuleDefaults(r.defaults);
      renderRules();
    } catch (e) {
      $('rules-summary').textContent = '제외 규칙을 불러오지 못했습니다.';
      showRulesError(e);
    }
  }

  async function rulesCall(path, options, onOk) {
    if (rulesState.busy) return;
    rulesState.busy = true;
    showRulesError(null);
    try {
      const r = await api(path, options);
      await onOk(r);
      return r;
    } catch (e) {
      showRulesError(e);
      if (e.message && /찾을 수 없습니다/.test(e.message)) await loadRules();
      return null;
    } finally {
      rulesState.busy = false;
    }
  }
  const jsonOpts = (method, body) => ({ method, headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });

  function renderRules() {
    const all = rulesState.list;
    const on = (t) => all.filter((r) => r.type === t && r.enabled).length;
    $('rules-summary').replaceChildren(
      `전체 ${fmt(all.length)}개 · 사용 중 `,
      el('strong', null, `폴더 ${on('dir')} · 확장자 ${on('ext')} · 파일 ${on('file')}`),
      ' — 사용 중인 규칙이 LOC 측정의 기본 제외 규칙이 됩니다.');
    const type = $('rules-filter-type').value;
    const q = $('rules-filter-q').value.trim().toLowerCase();
    const order = { dir: 0, ext: 1, file: 2 };
    const rows = all
      .filter((r) => (!type || r.type === type) && (!q || r.pattern.toLowerCase().includes(q) || (r.note || '').toLowerCase().includes(q)))
      .sort((a, b) => order[a.type] - order[b.type] || a.pattern.localeCompare(b.pattern, 'ko', { sensitivity: 'base' }));
    if (!rows.length) {
      $('rules-body').replaceChildren(el('tr', null, el('td', { colspan: '6', class: 'hint' }, all.length ? '조건에 맞는 규칙이 없습니다.' : '규칙이 없습니다. 위에서 추가하세요.')));
      return;
    }
    $('rules-body').replaceChildren(...rows.map((r) => (rulesState.editing === r.id ? ruleEditRow(r) : ruleRow(r))));
  }

  function ruleRow(r) {
    const toggle = el('input', { type: 'checkbox', 'aria-label': `${r.pattern} 사용`, onchange: (ev) => {
      rulesCall('rules.php?id=' + r.id, jsonOpts('PUT', { enabled: ev.target.checked }), (res) => {
        Object.assign(r, res.rule);
        applyRuleDefaults(res.defaults);
        renderRules();
      }).then((ok) => { if (!ok) ev.target.checked = !ev.target.checked; });
    } });
    toggle.checked = !!r.enabled;
    return el('tr', { class: r.enabled ? '' : 'rule-off', 'data-id': r.id },
      el('td', null, el('span', { class: `rule-type rule-type-${r.type}` }, RULE_TYPE_LABEL[r.type])),
      el('td', null, el('code', null, r.pattern)),
      el('td', { class: 'opt' }, r.note || ''),
      el('td', null, el('label', { class: 'check' }, toggle, r.enabled ? '사용' : '안 씀')),
      el('td', { class: 'opt' }, formatTime(r.updatedAt)),
      el('td', { class: 'num actions' },
        el('button', { type: 'button', class: 'btn btn-sm', onclick: () => { rulesState.editing = r.id; showRulesError(null); renderRules(); } }, '수정'),
        el('button', { type: 'button', class: 'btn btn-sm btn-danger', onclick: () => {
          if (!window.confirm(`${RULE_TYPE_LABEL[r.type]} 규칙 “${r.pattern}” 을(를) 삭제할까요?`)) return;
          rulesCall('rules.php?id=' + r.id, { method: 'DELETE' }, (res) => {
            rulesState.list = rulesState.list.filter((x) => x.id !== r.id);
            applyRuleDefaults(res.defaults);
            renderRules();
          });
        } }, '삭제')));
  }

  function ruleEditRow(r) {
    const type = el('select', { 'aria-label': '규칙 종류' }, Object.entries(RULE_TYPE_LABEL).map(([v, t]) => el('option', { value: v }, t)));
    type.value = r.type;
    const pattern = el('input', { type: 'text', class: 'rule-input', value: r.pattern, maxlength: '100', spellcheck: 'false', 'aria-label': '패턴' });
    const note = el('input', { type: 'text', class: 'rule-input', value: r.note || '', maxlength: '200', 'aria-label': '설명' });
    const enabled = el('input', { type: 'checkbox', 'aria-label': '사용' });
    enabled.checked = !!r.enabled;
    const save = () => rulesCall('rules.php?id=' + r.id, jsonOpts('PUT', { type: type.value, pattern: pattern.value, note: note.value, enabled: enabled.checked }), (res) => {
      Object.assign(r, res.rule);
      rulesState.editing = null;
      applyRuleDefaults(res.defaults);
      renderRules();
    });
    const cancel = () => { rulesState.editing = null; showRulesError(null); renderRules(); };
    const keys = (ev) => {
      if (ev.key === 'Enter') { ev.preventDefault(); save(); }
      if (ev.key === 'Escape') cancel();
    };
    pattern.addEventListener('keydown', keys);
    note.addEventListener('keydown', keys);
    setTimeout(() => pattern.focus(), 0);
    return el('tr', { class: 'rule-editing', 'data-id': r.id },
      el('td', null, type), el('td', null, pattern), el('td', { class: 'opt' }, note),
      el('td', null, el('label', { class: 'check' }, enabled, '사용')), el('td', { class: 'opt' }, formatTime(r.updatedAt)),
      el('td', { class: 'num actions' },
        el('button', { type: 'button', class: 'btn btn-sm btn-primary', onclick: save }, '저장'),
        el('button', { type: 'button', class: 'btn btn-sm', onclick: cancel }, '취소')));
  }

  $('rule-add-type').addEventListener('change', () => { $('rule-add-pattern').placeholder = RULE_PLACEHOLDER[$('rule-add-type').value]; });
  $('rule-add').addEventListener('submit', (ev) => {
    ev.preventDefault();
    const body = { type: $('rule-add-type').value, pattern: $('rule-add-pattern').value, note: $('rule-add-note').value, enabled: $('rule-add-enabled').checked };
    if (!body.pattern.trim()) {
      showRulesError(new UserError('패턴을 입력해 주세요.'));
      $('rule-add-pattern').focus();
      return;
    }
    rulesCall('rules.php', jsonOpts('POST', body), (res) => {
      rulesState.list.push(res.rule);
      $('rule-add-pattern').value = '';
      $('rule-add-note').value = '';
      applyRuleDefaults(res.defaults);
      renderRules();
      $('rule-add-pattern').focus();
    });
  });
  $('rules-filter-type').addEventListener('change', renderRules);
  $('rules-filter-q').addEventListener('input', renderRules);
  $('rules-reload').addEventListener('click', loadRules);
  $('rules-reset').addEventListener('click', () => {
    if (!window.confirm('제외 규칙을 모두 지우고 config.php 기본값으로 되돌릴까요? 추가하거나 고친 규칙은 사라집니다.')) return;
    rulesCall('rules.php', jsonOpts('POST', { action: 'reset' }), (res) => {
      rulesState.list = res.rules;
      rulesState.editing = null;
      applyRuleDefaults(res.defaults);
      renderRules();
    });
  });
  document.querySelector('.admin-tab-bar').addEventListener('keydown', (ev) => {
    if (!['ArrowLeft', 'ArrowRight'].includes(ev.key)) return;
    const cur = ADMIN_TABS.findIndex((t) => $('admin-tab-' + t).classList.contains('active'));
    const next = ADMIN_TABS[(cur + (ev.key === 'ArrowRight' ? 1 : ADMIN_TABS.length - 1)) % ADMIN_TABS.length];
    showAdminTab(next);
    $('admin-tab-' + next).focus();
  });
  document.querySelector('.tab-bar').addEventListener('keydown', (ev) => {
    if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(ev.key)) return;
    if (ev.key === 'Home' || ev.key === 'End') ev.preventDefault();   // 페이지가 스크롤되지 않게
    const next = ev.key === 'Home' ? TABS[0] : ev.key === 'End' ? TABS[TABS.length - 1]
      : TABS[(TABS.indexOf(state.tab) + (ev.key === 'ArrowRight' ? 1 : TABS.length - 1)) % TABS.length];
    const go = () => { showTab(next); $('tab-' + next).focus(); };
    if (next === 'admin') requestAdmin(go); else go();
  });

  // ================================================================ history 탭

  async function loadHistory() {
    const status = $('hist-status');
    status.textContent = '불러오는 중…';
    try {
      const r = await api('history.php');
      state.histItems = r.items;
      state.histLoaded = true;
      renderHistory();
      if (state.tab === 'history' && state.trendComponent) renderTrend();
    } catch (e) {
      status.textContent = '이력을 불러오지 못했습니다: ' + e.message + (e.hint ? ' ' + e.hint : '');
      $('hist-table').hidden = true;
    }
  }

  function renderHistory() {
    const q = $('hist-filter').value.trim().toLowerCase();
    const items = state.histItems.filter((i) => !q || i.component.toLowerCase().includes(q));
    const status = $('hist-status');
    if (!state.histItems.length) {
      status.textContent = '저장된 이력이 없습니다. 측정 탭에서 측정한 뒤 “history에 저장”을 누르세요.';
      $('hist-table').hidden = true;
      return;
    }
    status.textContent = q ? `${fmt(items.length)}건 / 전체 ${fmt(state.histItems.length)}건 (최신순). 항목을 누르면 차트와 표로 봅니다.`
      : `${fmt(items.length)}건 (최신순). 항목을 누르면 차트와 표로 봅니다.`;
    $('hist-table').hidden = items.length === 0;
    // 페이지 나누기: 행 수 10 / 15 / 30 / ALL
    const size = state.histPageSize === 'all' ? Math.max(items.length, 1) : Number(state.histPageSize);
    const pages = Math.max(1, Math.ceil(items.length / size));
    state.histPage = Math.min(Math.max(1, state.histPage), pages);
    const pageItems = items.slice((state.histPage - 1) * size, state.histPage * size);
    renderHistPager(items.length, pages, (state.histPage - 1) * size, pageItems.length);
    // 같은 측정 묶음의 이력 (배지 설명과 '묶음 선택'에 쓴다)
    const byBatch = new Map();
    for (const i of state.histItems) {
      if (i.batchId) byBatch.set(i.batchId, [...(byBatch.get(i.batchId) || []), i]);
    }
    $('hist-body').replaceChildren(...pageItems.map((i) => {
      const members = i.batchId ? byBatch.get(i.batchId) || [] : [];
      const grouped = i.batchId && i.batchSize > 1;
      const color = grouped ? batchColor(i.batchId) : null;
      const check = el('input', { type: 'checkbox', 'aria-label': `${i.component} ${i.date} ${i.run}회차 선택`,
        onclick: (ev) => ev.stopPropagation(),
        onchange: (ev) => { ev.target.checked ? state.histChecked.add(i.file) : state.histChecked.delete(i.file); syncHistSelection(); } });
      check.checked = state.histChecked.has(i.file);
      const batchCell = grouped
        ? el('button', { type: 'button', class: 'batch-badge', style: `--batch:${color}`,
          title: `한 번에 측정한 ${i.batchSize}개 (${i.batchMode === 'byFolder' ? '컴포넌트 그룹' : '단일 컴포넌트'}): ${members.map((m) => m.component).join(', ')}`
            + (members.length < i.batchSize ? ` — 이 중 ${members.length}개만 저장됨` : '') + '\n누르면 이 묶음을 모두 선택합니다.',
          onclick: (ev) => { ev.stopPropagation(); for (const m of members) state.histChecked.add(m.file); renderHistory(); } },
          batchLabel(i))
        : el('span', { class: 'hint', title: i.batchId ? '단독 측정' : '측정 묶음 정보가 없는 이력' }, '—');
      const tr = el('tr', { 'aria-selected': String(state.histSelected === i.file), class: grouped ? 'in-batch' : null,
          style: grouped ? `--batch:${color}` : null, onclick: () => openHistory(i.file) },
        el('td', { class: 'chk' }, check),
        el('td', null, el('button', { type: 'button', class: 'linklike', title: i.file }, i.component)),
        el('td', null, i.componentVersion || ''),
        el('td', { title: i.clocRunner ? `cloc ${i.clocVersion} (${i.clocRunner === 'pl' ? 'Perl + cloc-' + i.clocVersion + '.pl' : 'cloc-' + i.clocVersion + '.exe'})` : null }, i.clocVersion || ''),
        el('td', null, batchCell),
        el('td', null, i.date),
        el('td', { class: 'num' }, fmt(i.run)),
        el('td', { class: 'num' }, fmt(i.totals.code)),
        el('td', { class: 'opt' }, formatTime(i.measuredAt)));
      return tr;
    }));
    state.histPageItems = pageItems;
    const allChecked = pageItems.length > 0 && pageItems.every((i) => state.histChecked.has(i.file));
    $('hist-check-all').checked = allChecked;
    $('hist-check-all').indeterminate = !allChecked && pageItems.some((i) => state.histChecked.has(i.file));
    syncHistSelection();
  }

  /** 페이지 이동 단추: « ‹ 1 2 … › » (페이지가 많으면 현재 페이지 주변만) 과 "1–15 / 26건" */
  function renderHistPager(total, pages, offset, shown) {
    $('hist-pager').hidden = total === 0;
    $('hist-page-size').value = state.histPageSize;
    $('hist-range').textContent = total ? `${fmt(offset + 1)}–${fmt(offset + shown)} / ${fmt(total)}건` : '';
    const cur = state.histPage;
    const go = (n) => { state.histPage = n; renderHistory(); };
    const btn = (label, n, opts = {}) => el('button', {
      type: 'button', class: 'pager-btn' + (opts.current ? ' current' : ''), disabled: opts.disabled || opts.current,
      'aria-current': opts.current ? 'page' : null, 'aria-label': opts.aria || null, onclick: () => go(n),
    }, label);
    const nums = new Set([1, pages, cur - 2, cur - 1, cur, cur + 1, cur + 2].filter((n) => n >= 1 && n <= pages));
    const list = [...nums].sort((a, b) => a - b);
    const parts = [btn('«', 1, { disabled: cur === 1, aria: '첫 페이지' }), btn('‹', cur - 1, { disabled: cur === 1, aria: '이전 페이지' })];
    list.forEach((n, k) => {
      if (k > 0 && n - list[k - 1] > 1) parts.push(el('span', { class: 'pager-gap' }, '…'));
      parts.push(btn(String(n), n, { current: n === cur, aria: `${n}페이지` }));
    });
    parts.push(btn('›', cur + 1, { disabled: cur === pages, aria: '다음 페이지' }), btn('»', pages, { disabled: cur === pages, aria: '마지막 페이지' }));
    $('hist-pages').replaceChildren(...parts);
    $('hist-pages').hidden = pages <= 1;
  }

  $('hist-page-size').addEventListener('change', (ev) => {
    state.histPageSize = ev.target.value;
    storageSet('locmgmt.histPageSize', state.histPageSize);
    state.histPage = 1;
    renderHistory();
  });

  /** 측정 묶음 표시: "상위폴더이름_버전: N개" (버전이 없으면 "상위폴더이름: N개") */
  function batchLabel(i) {
    const parent = i.batchParent || (i.sourceName || '').replace(ARCHIVE_RE, '') || '묶음';
    return `${parent}${i.componentVersion ? '_' + i.componentVersion : ''}: ${fmt(i.batchSize)}개`;
  }

  /** 측정 묶음 ID → 파스텔 색 (같은 묶음은 같은 색) */
  function batchColor(id) {
    let h = 0;
    for (const ch of id) h = (h * 31 + ch.charCodeAt(0)) % 360;
    return hsl(h, isDarkTheme() ? 45 : 60, isDarkTheme() ? 55 : 72);
  }

  function syncHistSelection() {
    const exist = new Set(state.histItems.map((i) => i.file));
    for (const f of [...state.histChecked]) if (!exist.has(f)) state.histChecked.delete(f);   // 지워진 이력은 선택에서 뺀다
    const n = state.histChecked.size;
    $('hist-select-count').textContent = `선택 ${fmt(n)}개`;
    $('hist-combine').disabled = n === 0;
    $('hist-combine').textContent = n > 1 ? `선택한 ${fmt(n)}개 함께 보기` : '선택한 결과 보기';
    $('hist-clear').disabled = n === 0;
  }

  $('hist-check-all').addEventListener('change', (ev) => {
    for (const i of state.histPageItems || []) {   // 지금 보이는 페이지의 행만
      ev.target.checked ? state.histChecked.add(i.file) : state.histChecked.delete(i.file);
    }
    renderHistory();
  });
  $('hist-clear').addEventListener('click', () => { state.histChecked.clear(); renderHistory(); });

  const COMBINE_MAX = 100;   // 한 번에 함께 볼 수 있는 이력 수
  $('hist-combine').addEventListener('click', async () => {
    // 목록 순서(최신순)대로 가져온다
    const files = state.histItems.map((i) => i.file).filter((f) => state.histChecked.has(f));
    if (!files.length) return;
    if (files.length === 1) { openHistory(files[0]); return; }
    if (files.length > COMBINE_MAX) {
      $('hist-status').textContent = `함께 보기는 한 번에 ${COMBINE_MAX}개까지 할 수 있습니다. 선택을 줄여 주세요.`;
      return;
    }
    const btn = $('hist-combine');
    btn.disabled = true;
    try {
      const recs = await Promise.all(files.map((f) => api('history.php?file=' + encodeURIComponent(f))));
      state.histSelected = null;
      state.trendComponent = null;       // 여러 컴포넌트를 함께 볼 때는 측정 추이를 숨긴다
      state.historyModel = modelFromHistoryMulti(recs, files);
      renderHistory();
      renderTrend();
      renderModel(state.historyModel);
      $('result').scrollIntoView({ behavior: reducedMotion() ? 'auto' : 'smooth', block: 'start' });
    } catch (e) {
      $('hist-status').textContent = '선택한 이력을 불러오지 못했습니다: ' + e.message + (e.hint ? ' ' + e.hint : '');
    } finally {
      syncHistSelection();
    }
  });
  $('hist-filter').addEventListener('input', () => { state.histPage = 1; renderHistory(); });

  // ---- 측정 이력 전체를 Excel 로: "측정 이력"(한 건 한 줄) + "언어별 상세"(이력마다 언어별) + "정보"
  $('hist-export').addEventListener('click', async () => {
    const btn = $('hist-export');
    if (btn.disabled) return;
    const label = btn.textContent;
    btn.disabled = true;
    try {
      if (typeof XlsxExport === 'undefined' || typeof JSZip === 'undefined') {
        throw new Error('lib/jszip.min.js 또는 assets/xlsx-export.js 가 없어 Excel 파일을 만들 수 없습니다.');
      }
      btn.textContent = '목록 읽는 중…';
      const items = (await api('history.php')).items;   // 항상 서버의 최신 목록 전체
      if (!items.length) throw new Error('저장된 측정 이력이 없습니다.');
      // 언어별 상세는 이력 파일마다 읽는다 (동시에 6개씩)
      const recs = new Array(items.length);
      let done = 0;
      for (let k = 0; k < items.length; k += 6) {
        await Promise.all(items.slice(k, k + 6).map(async (it, j) => {
          try { recs[k + j] = await api('history.php?file=' + encodeURIComponent(it.file)); } catch { recs[k + j] = null; }
          btn.textContent = `만드는 중… ${fmt(++done)} / ${fmt(items.length)}`;
        }));
      }
      const h = (v) => ({ v, s: 'header' });
      const n = (v) => ({ v, s: 'num' });
      const main = [['파일', '컴포넌트', '컴포넌트 버전', 'CLOC 버전', '실행 방식', '컴포넌트 그룹', '그룹 ID', '구분 방식', '날짜', '회차', '측정 시각',
        '파일 수', '코드', '주석', '빈 줄', '전체'].map(h)];
      for (const i of items) {
        const t = withTotal(i.totals);
        main.push([i.file, i.component, i.componentVersion || '', i.clocVersion || '', i.clocRunner || '',
          i.batchId && i.batchSize > 1 ? batchLabel(i) : '', i.batchId || '',
          i.batchMode === 'byFolder' ? '컴포넌트 그룹' : i.batchMode === 'single' ? '단일 컴포넌트' : '',
          i.date, n(i.run), formatTime(i.measuredAt), n(t.files), n(t.code), n(t.comment), n(t.blank), n(t.total)]);
      }
      const langs = [['파일', '컴포넌트', '컴포넌트 버전', '날짜', '회차', '언어', '파일 수', '코드', '주석', '빈 줄', '전체'].map(h)];
      let missing = 0;
      items.forEach((i, k) => {
        const rec = recs[k];
        if (!rec) { missing++; return; }
        for (const l of rec.languages || []) {
          const t = withTotal(l);
          langs.push([i.file, i.component, i.componentVersion || '', i.date, n(i.run), l.language, n(t.files), n(t.code), n(t.comment), n(t.blank), n(t.total)]);
        }
      });
      const info = [[h('항목'), h('값')], ['제목', 'LOC 측정 이력 전체'], ['이력 수', n(items.length)],
        ['내보낸 시각', formatTime(new Date().toISOString())]];
      if (missing) info.push(['읽지 못한 이력', `${missing}개 (언어별 상세에서 빠짐)`]);
      const blob = await XlsxExport.build([
        { name: '측정 이력', rows: main, widths: [34, 22, 12, 10, 9, 24, 26, 14, 11, 7, 17, 9, 11, 11, 11, 11], freezeHeader: true },
        { name: '언어별 상세', rows: langs, widths: [34, 22, 12, 11, 7, 20, 9, 11, 11, 11, 11], freezeHeader: true },
        { name: '정보', rows: info, widths: [16, 40] },
      ]);
      const d = new Date();
      const p = (x) => String(x).padStart(2, '0');
      XlsxExport.download(blob, `CLOC_history_all_${String(d.getFullYear()).slice(2)}${p(d.getMonth() + 1)}${p(d.getDate())}_${p(d.getHours())}${p(d.getMinutes())}.xlsx`);
    } catch (e) {
      window.alert('Excel 파일을 만들지 못했습니다: ' + (e && e.message ? e.message : e));
    } finally {
      btn.disabled = false;
      btn.textContent = label;
    }
  });
  $('hist-reload').addEventListener('click', loadHistory);

  async function openHistory(file) {
    state.histSelected = file;
    renderHistory();
    try {
      const rec = await api('history.php?file=' + encodeURIComponent(file));
      state.historyModel = modelFromHistory(rec, file);
      state.trendComponent = rec.component;
      if (state.tab === 'history') {
        renderTrend();
        renderModel(state.historyModel);
        $('result').scrollIntoView({ behavior: reducedMotion() ? 'auto' : 'smooth', block: 'start' });
      }
    } catch (e) {
      $('hist-status').textContent = `“${file}” 을(를) 열지 못했습니다: ${e.message}`;
    }
  }

  // ================================================================ 측정 추이 (history)

  /** 누적 막대: 코드 · 주석 · 빈 줄 (파스텔 팔레트 그대로) */
  const TREND_SERIES = [
    { key: 'code', label: '코드', color: '--c1' },
    { key: 'comment', label: '주석', color: '--c2' },
    { key: 'blank', label: '빈 줄', color: '--c4' },
  ];
  const TREND_MAX_RUNS = 10;   // 그래프에는 최근 10회까지만

  /** 선 색: 파스텔은 흰 배경에서 선으로 보기 어려우므로 조금 진하게 쓴다 */
  function lineColor(varName) {
    const v = cssVar(varName);
    if (varName === '--accent') return v;
    const [h, s, l] = hexToHsl(v);
    return hsl(h, Math.min(s, 60), Math.max(l - 22, 40));
  }

  /**
   * 측정 추이를 어디에 무엇으로 그릴지. 기본은 측정 이력 탭(#trend…, state.histItems, openHistory).
   * LOC 조회 탭은 같은 모양의 카드(#qtrend…)에 조회 탭의 목록·선택으로 그린다 (queryTrendView).
   *   p: 요소 id 접두어, comp: 컴포넌트명(null 이면 숨김), items: 이력 목록, selected: 선택한 파일,
   *   open(file): 회차를 눌렀을 때, charts: 차트 객체를 둘 곳 (trendChart, trendDeltaChart)
   */
  function histTrendView() {
    return { p: 'trend', comp: state.trendComponent, items: state.histItems, selected: state.histSelected, open: openHistory, charts: state };
  }

  function destroyTrend(charts = state) {
    for (const k of ['trendChart', 'trendDeltaChart']) {
      if (charts[k]) {
        charts[k].destroy();
        charts[k] = null;
      }
    }
  }

  /**
   * 같은 컴포넌트의 이력을 시간 순서로 모아 보여준다.
   * 왼쪽: 회차별 코드·주석·빈 줄 누적 세로 막대, 오른쪽: 회차별 전체 줄 수 변화량(직전 회차 대비) 꺾은선.
   * 그래프는 최근 TREND_MAX_RUNS 회만, 표는 모든 회차.
   */
  function renderTrend(v = histTrendView()) {
    const $t = (suffix) => $(v.p + suffix);
    const card = $t('');
    const comp = v.comp;
    destroyTrend(v.charts);
    if (!comp) {
      card.hidden = true;
      return;
    }
    const runs = v.items.filter((i) => i.component === comp)
      .map((i) => ({ ...i, t: withTotal(i.totals), ts: Date.parse(i.measuredAt) || 0 }))
      .sort((a, b) => a.ts - b.ts || a.date.localeCompare(b.date) || a.run - b.run);
    runs.forEach((r, i) => { r.diff = i > 0 ? r.t.total - runs[i - 1].t.total : null; });
    card.hidden = false;
    $t('-title').textContent = `${comp} 측정 추이`;
    const first = runs[0];
    const last = runs[runs.length - 1];
    /** 변화량 "+1,234줄 (+5.6%)" (unit 을 비우면 숫자만) */
    const delta = (a, b, unit = '') => {
      const d = b - a;
      return `${d > 0 ? '+' : d < 0 ? '−' : '±'}${fmt(Math.abs(d))}${unit}` + (a > 0 ? ` (${d >= 0 ? '+' : '−'}${Math.abs((d / a) * 100).toFixed(1)}%)` : '');
    };
    const shown = runs.slice(-TREND_MAX_RUNS);
    $t('-sub').textContent = runs.length >= 2
      ? `${fmt(runs.length)}회 측정 · 첫 측정(${first.date}) 대비 전체 ${delta(first.t.total, last.t.total, '줄')}, 코드 ${delta(first.t.code, last.t.code, '줄')}.`
        + (runs.length > TREND_MAX_RUNS ? ` 그래프는 최근 ${TREND_MAX_RUNS}회만 보여줍니다.` : '') + ' 막대나 점을 누르면 그 회차를 봅니다.'
      : '측정이 1회뿐이라 추이를 그릴 수 없습니다. 같은 컴포넌트를 다시 측정해 저장하면 여기에 변화가 표시됩니다.';

    // 표 (색에 의존하지 않고 수치 확인)
    $t('-body').replaceChildren(...runs.slice().reverse().map((r) => {
      const idx = runs.indexOf(r);
      const prev = idx > 0 ? runs[idx - 1] : null;
      return el('tr', { 'aria-selected': String(r.file === v.selected), onclick: () => v.open(r.file) },
        el('td', null, el('button', { type: 'button', class: 'linklike', title: r.file }, `${r.date} #${r.run}`)),
        el('td', null, r.componentVersion || ''),
        el('td', { class: 'opt' }, formatTime(r.measuredAt)),
        el('td', { class: 'num' }, fmt(r.t.total)),
        el('td', { class: 'num' }, fmt(r.t.code)),
        el('td', { class: 'num opt' }, fmt(r.t.comment)),
        el('td', { class: 'num opt' }, fmt(r.t.blank)),
        el('td', { class: 'num' }, prev ? delta(prev.t.total, r.t.total) : '—'));
    }));

    const box = $t('-box');
    const empty = $t('-empty');
    if (runs.length < 2) {
      box.hidden = true;
      return;
    }
    box.hidden = false;
    const canvases = [$t('-chart'), $t('-delta-chart')];
    if (typeof Chart === 'undefined') {
      canvases.forEach((c) => { c.closest('.trend-pane').hidden = true; });
      empty.hidden = false;
      empty.textContent = 'lib 폴더에 Chart.js 파일(lib/chart.umd.js)을 넣어 주세요. 아래 표로 변화를 볼 수 있습니다.';
      return;
    }
    canvases.forEach((c) => { c.closest('.trend-pane').hidden = false; });
    empty.hidden = true;
    const text = cssVar('--text');
    const muted = cssVar('--text-2');
    const grid = cssVar('--border');
    const selIdx = shown.findIndex((r) => r.file === v.selected);
    const labels = shown.map((r) => `${r.date.slice(2)} #${r.run}` + (r.componentVersion ? ` (${r.componentVersion})` : ''));
    const runTitle = (r) => `${r.date} ${r.run}회차` + (r.componentVersion ? ` · 버전 ${r.componentVersion}` : '') + ` · ${formatTime(r.measuredAt)}`;
    const common = {
      responsive: true,
      maintainAspectRatio: false,
      animation: reducedMotion() ? false : { duration: 300 },
      interaction: { mode: 'index', intersect: false },
      onClick: (evt, elements) => {
        if (elements.length) v.open(shown[elements[0].index].file);
      },
    };
    const tooltipBase = {
      backgroundColor: cssVar('--surface-2'), titleColor: text, bodyColor: text, footerColor: text,
      borderColor: grid, borderWidth: 1, padding: 10,
    };
    const xAxis = { ticks: { color: muted, maxRotation: 0, autoSkip: true }, grid: { color: grid } };

    // 왼쪽: 누적 세로 막대 (선택한 회차는 테두리로 표시)
    const edge = cssVar('--text');
    v.charts.trendChart = new Chart(canvases[0], {
      type: 'bar',
      data: {
        labels,
        datasets: TREND_SERIES.map((s) => ({
          label: s.label,
          data: shown.map((r) => r.t[s.key]),
          backgroundColor: cssVar(s.color),
          borderColor: shown.map((_, i) => (i === selIdx ? edge : 'transparent')),
          borderWidth: shown.map((_, i) => (i === selIdx ? 2 : 0)),
          stack: 'loc',
          maxBarThickness: 56,
        })),
      },
      options: {
        ...common,
        scales: {
          x: { ...xAxis, stacked: true, grid: { display: false } },
          y: { stacked: true, beginAtZero: true, ticks: { color: muted, callback: (v) => fmt(v) }, grid: { color: grid }, title: { display: true, text: '줄 수', color: muted } },
        },
        plugins: {
          legend: { labels: { color: text, boxWidth: 12, boxHeight: 12 } },
          tooltip: {
            ...tooltipBase,
            callbacks: {
              title: (items) => runTitle(shown[items[0].dataIndex]),
              label: (ctx) => `${ctx.dataset.label} ${fmt(ctx.raw)}줄`,
              footer: (items) => `전체 ${fmt(shown[items[0].dataIndex].t.total)}줄`,
            },
          },
        },
      },
    });

    // 오른쪽: 직전 회차 대비 전체 줄 수 변화량 (늘면 초록, 줄면 빨강 점. 첫 측정은 비교 대상이 없어 비움)
    const up = lineColor('--c3');
    const down = lineColor('--c6');
    const flat = cssVar('--text-2');
    const pointColor = shown.map((r) => (r.diff > 0 ? up : r.diff < 0 ? down : flat));
    const lineCol = isDarkTheme() ? cssVar('--c8') : lineColor('--c8');   // 어두운 배경에서는 파스텔 그대로가 잘 보인다
    v.charts.trendDeltaChart = new Chart(canvases[1], {
      type: 'line',
      data: {
        labels,
        datasets: [{
          label: '전체 LOC 변화량',
          data: shown.map((r) => r.diff),
          borderColor: lineCol,
          backgroundColor: lineCol,
          borderWidth: 2,
          tension: 0,          // 꺾은선: 점과 점을 곧게 잇는다 (곡선은 실제 값보다 튀어 보임)
          spanGaps: true,
          pointBackgroundColor: pointColor,
          pointBorderColor: pointColor,
          pointRadius: shown.map((_, i) => (i === selIdx ? 6 : 4)),
          pointHoverRadius: 7,
        }],
      },
      options: {
        ...common,
        scales: {
          x: xAxis,
          y: {
            ticks: { color: muted, callback: (v) => (v > 0 ? '+' : '') + fmt(v) },
            grid: { color: (c) => (c.tick && c.tick.value === 0 ? muted : grid), lineWidth: (c) => (c.tick && c.tick.value === 0 ? 1.5 : 1) },
            title: { display: true, text: '직전 회차 대비 줄 수', color: muted },
          },
        },
        plugins: {
          legend: {
            labels: {
              color: text, usePointStyle: true, pointStyle: 'line', boxHeight: 8,
              // 범례 색은 첫 점(증감 색)이 아니라 선 색으로
              generateLabels: (chart) => Chart.defaults.plugins.legend.labels.generateLabels(chart)
                .map((l) => ({ ...l, fillStyle: lineCol, strokeStyle: lineCol, lineWidth: 3 })),
            },
          },
          tooltip: {
            ...tooltipBase,
            callbacks: {
              title: (items) => runTitle(shown[items[0].dataIndex]),
              label: (ctx) => {
                const r = shown[ctx.dataIndex];
                const i = runs.indexOf(r);
                return i > 0 ? `변화량 ${delta(runs[i - 1].t.total, r.t.total, '줄')}` : '첫 측정 (비교 대상 없음)';
              },
              footer: (items) => `전체 ${fmt(shown[items[0].dataIndex].t.total)}줄`,
            },
          },
        },
      },
    });
  }

  // ================================================================ LOC 조회 탭 (읽기 전용)
  // 저장된 이력을 컴포넌트 / 컴포넌트 그룹 단위로 골라 본다. 기존 api/history.php (목록 GET, 파일 GET)만 쓴다.
  // 결과는 측정 이력 탭과 같은 공용 결과 카드(#result)와 renderModel 로 그린다. 저장·수정·삭제는 없다.

  const QUERY_SUBS = ['component', 'group'];
  const QUERY_FETCH_CONCURRENCY = 6;      // 그룹의 이력 파일을 동시에 읽는 수
  const itemTs = (i) => Date.parse(i.measuredAt) || 0;
  const newestFirst = (a, b) => itemTs(b) - itemTs(a) || String(b.date).localeCompare(String(a.date)) || b.run - a.run;
  const verOf = (i) => i.componentVersion || '';
  const versionText = (v) => v || NO_VERSION;
  /**
   * 그룹 이름 = batch.parent. 그 항목이 생기기 전에 저장된 이력은 parent 가 비어 있으므로
   * 측정 이력 탭의 '컴포넌트 그룹' 칸(batchLabel)과 같이 소스 이름(압축 확장자 제외)을 쓴다.
   */
  const groupNameOf = (i) => i.batchParent || (i.sourceName || '').replace(ARCHIVE_RE, '');
  /** 컴포넌트 그룹으로 측정한 이력 (batch.mode === 'byFolder' 이고 그룹 이름을 알 수 있음) */
  const isGroupItem = (i) => i.batchMode === 'byFolder' && !!i.batchId && !!groupNameOf(i);
  const uniqueSorted = (arr) => [...new Set(arr)].sort((a, b) => a.localeCompare(b, 'ko'));
  /** 회차 표시: "2026-10-07 2회차 · 14:05" */
  const runLabel = (i) => `${i.date} ${i.run}회차 · ${formatTime(i.measuredAt).slice(11)}`;

  /**
   * 버전 목록 (컴포넌트·컴포넌트 그룹 하위 탭 공통): 버전 내림차순 (높은 버전이 먼저·기본, 사용자 요청). 숫자는 크기로 비교한다 (2.10 > 2.9).
   * 빈 버전 '(버전 없음)' 은 맨 뒤.
   */
  function versionsDesc(items) {
    return [...new Set(items.map(verOf))].sort((a, b) => (!a) - (!b) || b.localeCompare(a, 'ko', { numeric: true }));
  }

  /** 같은 그룹·버전의 측정(batchId) 목록: 최근 측정이 먼저. measuredAt 은 그 측정에서 처음 저장된 시각. */
  function batchesOf(items) {
    const m = new Map();
    for (const i of items) {
      const b = m.get(i.batchId) || { id: i.batchId, size: 0, first: Infinity, last: -Infinity, measuredAt: '' };
      const t = itemTs(i);
      b.size = Math.max(b.size, i.batchSize || 0);
      if (t < b.first) { b.first = t; b.measuredAt = i.measuredAt; }
      b.last = Math.max(b.last, t);
      m.set(i.batchId, b);
    }
    return [...m.values()].sort((a, b) => b.last - a.last || a.id.localeCompare(b.id));
  }

  /** 선택 상자 채우기. 항목이 없으면 '—' 하나만 두고 비활성. */
  function fillSelect(sel, options, value) {
    if (!options.length) {
      sel.replaceChildren(el('option', { value: '' }, '—'));
      sel.disabled = true;
      return;
    }
    sel.replaceChildren(...options.map((o) => el('option', { value: o.value }, o.label)));
    sel.value = value;
    sel.disabled = false;
  }

  // 이력 파일 GET 은 조회 탭 전체에서 동시에 QUERY_FETCH_CONCURRENCY 개까지 (선택을 빨리 바꿔도 요청이 쌓이지 않게)
  let queryActive = 0;
  const queryWaiting = [];
  async function withQuerySlot(fn) {
    if (queryActive >= QUERY_FETCH_CONCURRENCY) await new Promise((r) => queryWaiting.push(r));   // 끝난 요청의 자리를 넘겨받는다
    else queryActive++;
    try {
      return await fn();
    } finally {
      const next = queryWaiting.shift();
      if (next) next(); else queryActive--;
    }
  }

  /** 이력 파일 내용 (목록을 새로 읽기 전까지는 한 번 읽은 것을 다시 쓴다) */
  async function readQueryRecord(file) {
    if (!query.recs.has(file)) {
      const rec = await withQuerySlot(() => api('history.php?file=' + encodeURIComponent(file)));
      query.recs.set(file, rec);
    }
    return query.recs.get(file);
  }

  function queryTrendView() {
    const m = query.compModel;
    return { p: 'qtrend', comp: m ? m.record.component : null, items: query.items, selected: m ? m.file : null, open: openQueryRun, charts: query };
  }

  /** 조회 탭이 보이는 중이면 지금 하위 탭의 측정 추이와 결과를 그린다 */
  function showQueryResult() {
    if (state.tab !== 'query') return;
    if (query.sub === 'component') {
      renderTrend(queryTrendView());
    } else {
      destroyTrend(query);
      $('qtrend').hidden = true;
    }
    renderModel(currentModel());
  }

  function renderQueryStatus() {
    const st = $('query-status');
    st.classList.toggle('is-error', !!query.error);
    if (query.error) {
      const e = query.error;
      st.textContent = `이력 목록을 불러오지 못했습니다: ${e.message}${e.hint ? ' ' + e.hint : ''} 문제를 해결한 뒤 오른쪽 위 ‘새로고침’ 버튼을 눌러 다시 읽어 주세요.`;
      return;
    }
    if (!query.items.length) {
      st.textContent = '저장된 이력이 없습니다. LOC 측정 탭에서 측정하고 저장하세요.';
      return;
    }
    if (query.sub === 'component') {
      const n = new Set(query.items.map((i) => i.component)).size;
      st.textContent = `저장된 이력 ${fmt(query.items.length)}건 · 컴포넌트 ${fmt(n)}개. 컴포넌트 → 버전 → 회차를 고르면 측정 추이와 결과를 봅니다 (회차는 최근 것이 기본).`;
      return;
    }
    const groups = new Set(query.items.filter(isGroupItem).map(groupNameOf)).size;
    st.textContent = groups
      ? `컴포넌트 그룹 ${fmt(groups)}개. 그룹 → 버전 → 측정을 고르면 그 측정의 컴포넌트를 한 화면에 봅니다. 컴포넌트 이름을 누르면 컴포넌트 탭에서 그 컴포넌트를 봅니다.`
      : '컴포넌트 그룹으로 측정해 저장한 이력이 없습니다. LOC 측정 탭에서 컴포넌트 구분을 ‘컴포넌트 그룹’으로 골라 측정하고 저장하세요.';
  }

  // ---- 컴포넌트 하위 탭: 컴포넌트 → 컴포넌트 버전 → 회차

  /** 지금 선택을 목록에 맞게 고친다 (없어진 값은 기본값으로: 컴포넌트는 가장 최근 측정, 버전은 가장 높은 것, 회차는 최근 것) */
  function qcState() {
    const items = query.items;
    const c = query.comp;
    const comps = uniqueSorted(items.map((i) => i.component));
    if (!comps.includes(c.component)) {
      c.component = items.length ? [...items].sort(newestFirst)[0].component : null;
      c.version = null;
      c.file = null;
    }
    const mine = items.filter((i) => i.component === c.component);
    const versions = versionsDesc(mine);
    if (!versions.includes(c.version)) {
      c.version = versions.length ? versions[0] : null;
      c.file = null;
    }
    const runs = mine.filter((i) => verOf(i) === c.version).sort(newestFirst);
    if (!runs.some((i) => i.file === c.file)) c.file = runs.length ? runs[0].file : null;
    return { comps, versions, runs };
  }

  function renderQueryComponentSelects() {
    const { comps, versions, runs } = qcState();
    fillSelect($('qc-component'), comps.map((n) => ({ value: n, label: n })), query.comp.component);
    fillSelect($('qc-version'), versions.map((v) => ({ value: 'v:' + v, label: versionText(v) })), 'v:' + query.comp.version);
    fillSelect($('qc-run'), runs.map((i) => ({ value: i.file, label: runLabel(i) })), query.comp.file);
  }

  async function loadQueryComponent() {
    const seq = ++query.compSeq;
    const file = query.comp.file;
    const key = `${query.listSeq}:${file}`;
    if (!file) {
      query.compModel = null;
    } else if (!(query.compModel && query.compModel.$key === key)) {
      try {
        const rec = await readQueryRecord(file);
        if (seq !== query.compSeq) return;
        query.compModel = Object.assign(modelFromHistory(rec, file), { $key: key });
      } catch (e) {
        if (seq !== query.compSeq) return;
        query.compModel = null;
        if (state.tab === 'query') {
          $('query-status').textContent = `“${file}” 을(를) 열지 못했습니다: ${e.message}${e.hint ? ' ' + e.hint : ''} 오른쪽 위 ‘새로고침’ 버튼을 눌러 다시 시도해 주세요.`;
        }
      }
    }
    showQueryResult();
  }

  /** 측정 추이에서 회차를 누름: 그 회차의 버전·회차를 선택한다 (버전이 다를 수 있음) */
  function openQueryRun(file) {
    const it = query.items.find((i) => i.file === file);
    if (!it) return;
    query.comp = { component: it.component, version: verOf(it), file };
    renderQueryComponentSelects();
    loadQueryComponent();
  }

  /** 그룹 결과에서 컴포넌트를 누름: 컴포넌트 하위 탭에서 그 컴포넌트·버전·회차(그 측정의 이력)를 보여준다. 그룹 선택은 그대로 둔다. */
  function openComponentFromGroup(file) {
    const it = query.items.find((i) => i.file === file);
    if (!it) return;
    query.comp = { component: it.component, version: verOf(it), file };
    showQuerySub('component');
    $('view-query').scrollIntoView({ behavior: reducedMotion() ? 'auto' : 'smooth', block: 'start' });
    $('query-tab-component').focus({ preventScroll: true });
  }

  $('qc-component').addEventListener('change', (ev) => {
    query.comp = { component: ev.target.value, version: null, file: null };
    renderQueryComponentSelects();
    loadQueryComponent();
  });
  $('qc-version').addEventListener('change', (ev) => {
    query.comp.version = ev.target.value.slice(2);    // 'v:' 접두어 (빈 버전도 값으로 구분하려고)
    query.comp.file = null;
    renderQueryComponentSelects();
    loadQueryComponent();
  });
  $('qc-run').addEventListener('change', (ev) => {
    query.comp.file = ev.target.value;
    loadQueryComponent();
  });

  // ---- 컴포넌트 그룹 하위 탭: 컴포넌트 그룹 → 버전 → 측정

  function qgState() {
    const items = query.items.filter(isGroupItem);
    const g = query.group;
    const groups = uniqueSorted(items.map(groupNameOf));
    if (!groups.includes(g.parent)) {
      g.parent = items.length ? groupNameOf([...items].sort(newestFirst)[0]) : null;
      g.version = null;
      g.batchId = null;
    }
    const mine = items.filter((i) => groupNameOf(i) === g.parent);
    const versions = versionsDesc(mine);
    if (!versions.includes(g.version)) {
      g.version = versions.length ? versions[0] : null;
      g.batchId = null;
    }
    const batches = batchesOf(mine.filter((i) => verOf(i) === g.version));
    if (!batches.some((b) => b.id === g.batchId)) g.batchId = batches.length ? batches[0].id : null;
    return { groups, versions, batches };
  }

  function renderQueryGroupSelects() {
    const { groups, versions, batches } = qgState();
    const stored = (id) => query.items.filter((i) => i.batchId === id).length;
    fillSelect($('qg-group'), groups.map((n) => ({ value: n, label: n })), query.group.parent);
    fillSelect($('qg-version'), versions.map((v) => ({ value: 'v:' + v, label: versionText(v) })), 'v:' + query.group.version);
    fillSelect($('qg-batch'), batches.map((b) => ({
      value: b.id,
      label: `${formatTime(b.measuredAt)} · 컴포넌트 ${fmt(b.size)}개` + (stored(b.id) < b.size ? ` (저장 ${fmt(stored(b.id))}개)` : ''),
    })), query.group.batchId);
  }

  async function loadQueryGroup() {
    const seq = ++query.groupSeq;
    const g = { ...query.group };
    const notice = $('qg-notice');
    const progress = $('qg-progress');
    const key = `${query.listSeq}:${g.batchId}`;
    if (!g.batchId) {
      query.groupModel = null;
      notice.hidden = true;
      progress.hidden = true;
      showQueryResult();
      return;
    }
    if (query.groupModel && query.groupModel.$key === key) {
      showQueryResult();
      return;
    }
    // 선택한 측정의 이력 파일 전부 (batchId 일치). 다른 측정의 값은 합치지 않는다.
    const members = query.items.filter((i) => i.batchId === g.batchId).sort((a, b) => a.component.localeCompare(b.component, 'ko'));
    const size = Math.max(0, ...members.map((i) => i.batchSize || 0));
    const recs = new Array(members.length);
    const failed = [];
    let next = 0;
    let done = 0;
    progress.hidden = false;
    progress.textContent = `이력 읽는 중… 0 / ${fmt(members.length)}`;
    const worker = async () => {
      while (next < members.length && seq === query.groupSeq) {     // 다른 측정을 고르면 남은 파일은 읽지 않는다
        const k = next++;
        try {
          recs[k] = await readQueryRecord(members[k].file);
        } catch (e) {
          failed.push({ item: members[k], error: e });
        }
        done++;
        if (seq === query.groupSeq) progress.textContent = `이력 읽는 중… ${fmt(done)} / ${fmt(members.length)}`;
      }
    };
    await Promise.all(Array.from({ length: Math.min(QUERY_FETCH_CONCURRENCY, members.length) }, worker));
    if (seq !== query.groupSeq) return;     // 그새 다른 측정을 골랐다
    progress.hidden = true;

    const msgs = [];
    if (members.length < size) {
      msgs.push(`측정 때 컴포넌트가 ${fmt(size)}개였으나 저장된 이력은 ${fmt(members.length)}개입니다. 일부가 저장되지 않았거나 삭제되었습니다.`);
    }
    if (failed.length) {
      msgs.push(`이력 ${fmt(failed.length)}개를 읽지 못해 결과에서 뺐습니다: `
        + failed.map((f) => `${f.item.component} (${f.item.file} — ${f.error.message})`).join(', ') + '. 새로고침을 눌러 다시 시도해 주세요.');
    }
    notice.replaceChildren(...msgs.map((t) => el('p', null, t)));
    notice.hidden = msgs.length === 0;
    const ok = members.map((_, k) => k).filter((k) => recs[k]);
    query.groupModel = ok.length
      ? Object.assign(modelFromGroup(ok.map((k) => recs[k]), ok.map((k) => members[k].file),
        { name: g.parent, version: g.version, batchId: g.batchId, size: Math.max(size, members.length) }, openComponentFromGroup), { $key: key })
      : null;
    showQueryResult();
  }

  $('qg-group').addEventListener('change', (ev) => {
    query.group = { parent: ev.target.value, version: null, batchId: null };
    renderQueryGroupSelects();
    loadQueryGroup();
  });
  $('qg-version').addEventListener('change', (ev) => {
    query.group.version = ev.target.value.slice(2);
    query.group.batchId = null;
    renderQueryGroupSelects();
    loadQueryGroup();
  });
  $('qg-batch').addEventListener('change', (ev) => {
    query.group.batchId = ev.target.value;
    loadQueryGroup();
  });

  // ---- 하위 탭 전환과 목록 읽기

  /** 하위 탭을 보인다. load 가 false 면 선택 상자와 이미 있는 결과만 다시 그린다. */
  function showQuerySub(sub, opts = {}) {
    query.sub = sub;
    for (const t of QUERY_SUBS) {
      const on = t === sub;
      const btn = $('query-tab-' + t);
      btn.classList.toggle('active', on);
      btn.setAttribute('aria-selected', String(on));
      btn.tabIndex = on ? 0 : -1;
      $('query-' + t).hidden = !on;
    }
    if (sub === 'group') {
      destroyTrend(query);
      $('qtrend').hidden = true;
    }
    if (!query.loaded) {
      $('qtrend').hidden = true;
      if (state.tab === 'query') renderModel(currentModel());
      return;
    }
    renderQueryStatus();
    if (sub === 'component') renderQueryComponentSelects();
    else renderQueryGroupSelects();
    if (opts.load === false) {
      showQueryResult();
      return;
    }
    if (sub === 'component') loadQueryComponent();
    else loadQueryGroup();
  }

  async function loadQueryList() {
    const seq = ++query.listSeq;
    const st = $('query-status');
    st.classList.remove('is-error');
    st.textContent = '이력 목록을 불러오는 중…';
    try {
      const r = await api('history.php');
      if (seq !== query.listSeq) return;
      query.items = Array.isArray(r.items) ? r.items : [];
      query.error = null;
    } catch (e) {
      if (seq !== query.listSeq) return;
      query.items = [];
      query.error = e;
      query.compModel = null;
      query.groupModel = null;
      $('qg-notice').hidden = true;
    }
    query.loaded = true;
    query.recs.clear();       // 다른 탭에서 버전을 고쳤을 수 있으므로 파일도 다시 읽는다
    showQuerySub(query.sub);
  }

  $('query-reload').addEventListener('click', loadQueryList);
  for (const t of QUERY_SUBS) $('query-tab-' + t).addEventListener('click', () => showQuerySub(t));
  document.querySelector('.query-tab-bar').addEventListener('keydown', (ev) => {
    if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(ev.key)) return;
    ev.preventDefault();
    const cur = QUERY_SUBS.indexOf(query.sub);
    const next = ev.key === 'Home' ? QUERY_SUBS[0] : ev.key === 'End' ? QUERY_SUBS[QUERY_SUBS.length - 1]
      : QUERY_SUBS[(cur + (ev.key === 'ArrowRight' ? 1 : QUERY_SUBS.length - 1)) % QUERY_SUBS.length];
    showQuerySub(next);
    $('query-tab-' + next).focus();
  });

  // ================================================================ 시작

  /**
   * 새로고침하면 입력칸은 비어 있어야 한다. 브라우저의 자동 완성(저장된 사용자 이름 등)이나 입력값 복원이
   * 페이지를 연 뒤 조금 늦게 채우는 경우가 있어, 처음 몇 초 동안 사용자가 키보드로 치지 않은 값은 지운다.
   */
  const AUTOFILL_GUARD_IDS = ['comp-version', 'hist-filter', 'rule-add-pattern', 'rule-add-note', 'rules-filter-q', 'hadmin-filter'];
  function guardAgainstAutofill() {
    const typed = new Set();
    const clear = () => {
      for (const id of AUTOFILL_GUARD_IDS) {
        const x = $(id);
        if (x && x.value && !typed.has(id)) {
          x.value = '';
          x.dispatchEvent(new Event('input', { bubbles: true }));   // 검색 결과 등도 원래대로
        }
      }
    };
    for (const id of AUTOFILL_GUARD_IDS) {
      const x = $(id);
      if (!x) continue;
      x.addEventListener('keydown', () => typed.add(id));
      // 타이핑·붙여넣기는 inputType 이 있는 InputEvent. 자동 완성은 inputType 이 없거나 insertReplacementText 다.
      x.addEventListener('input', (ev) => {
        if (ev instanceof InputEvent && ev.inputType && ev.inputType !== 'insertReplacementText') typed.add(id);
      });
    }
    clear();
    for (const ms of [100, 400, 1000, 2000, 3500]) setTimeout(clear, ms);
  }
  window.addEventListener('pageshow', (ev) => { if (ev.persisted) guardAgainstAutofill(); });   // 뒤로 가기로 돌아온 경우

  applyTheme();
  guardAgainstAutofill();
  fillRuleInputs();
  showTab('query');
  loadEnv();
})();
