/*
 * 최소 구조의 .xlsx(Office Open XML) 파일을 브라우저에서 만든다.
 * 외부 라이브러리를 더 들이지 않고, 이미 쓰는 lib/jszip.min.js 로 ZIP 을 묶는다 (CDN 없음).
 *
 *   XlsxExport.build([{ name: '상세', rows: [[cell, ...], ...], widths: [20, 12], freezeHeader: true }])
 *     → Promise<Blob>
 *   cell: 숫자 | 문자열 | null | { v: 숫자|문자열, s: 'header'|'num'|'pct'|'boldNum'|'boldPct'|'bold' }
 */
(() => {
  'use strict';

  const MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
  const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
  const XML_HEAD = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n';

  // styles.xml 의 cellXfs 순서와 같아야 한다
  const STYLE = { default: 0, header: 1, num: 2, pct: 3, boldNum: 4, boldPct: 5, bold: 6 };

  const STYLES_XML = XML_HEAD
    + `<styleSheet xmlns="${NS}">`
    + '<numFmts count="1"><numFmt numFmtId="164" formatCode="0.0%"/></numFmts>'
    + '<fonts count="2"><font><sz val="11"/><name val="맑은 고딕"/><family val="2"/></font>'
    + '<font><b/><sz val="11"/><name val="맑은 고딕"/><family val="2"/></font></fonts>'
    + '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
    + '<fill><patternFill patternType="solid"><fgColor rgb="FFF1F5F9"/><bgColor indexed="64"/></patternFill></fill></fills>'
    + '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
    + '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
    + '<cellXfs count="7">'
    + '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
    + '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
    + '<xf numFmtId="3" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
    + '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
    + '<xf numFmtId="3" fontId="1" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1"/>'
    + '<xf numFmtId="164" fontId="1" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1"/>'
    + '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
    + '</cellXfs>'
    + '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
    + '</styleSheet>';

  /** XML 에 쓸 수 없는 제어 문자를 빼고 특수 문자를 이스케이프한다. */
  function esc(value) {
    return String(value)
      .replace(/[\u0000-\u0008\u000B\u000C\u000E-\u001F￾￿]/g, '')
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function colName(i) {
    let n = i + 1;
    let s = '';
    while (n > 0) {
      const m = (n - 1) % 26;
      s = String.fromCharCode(65 + m) + s;
      n = Math.floor((n - 1) / 26);
    }
    return s;
  }

  function cellXml(cell, ref) {
    if (cell === null || cell === undefined || cell === '') return '';
    const obj = typeof cell === 'object' ? cell : { v: cell };
    if (obj.v === null || obj.v === undefined || obj.v === '') {
      return obj.s ? `<c r="${ref}" s="${STYLE[obj.s] || 0}"/>` : '';
    }
    const s = obj.s ? ` s="${STYLE[obj.s] || 0}"` : '';
    if (typeof obj.v === 'number' && Number.isFinite(obj.v)) {
      return `<c r="${ref}"${s}><v>${obj.v}</v></c>`;
    }
    return `<c r="${ref}"${s} t="inlineStr"><is><t xml:space="preserve">${esc(obj.v)}</t></is></c>`;
  }

  function sheetXml(sheet) {
    const rows = sheet.rows.map((row, r) => {
      const cells = row.map((cell, c) => cellXml(cell, colName(c) + (r + 1))).join('');
      return `<row r="${r + 1}">${cells}</row>`;
    }).join('');
    const views = sheet.freezeHeader
      ? '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'
        + '<selection pane="bottomLeft" activeCell="A2" sqref="A2"/></sheetView></sheetViews>'
      : '<sheetViews><sheetView workbookViewId="0"/></sheetViews>';
    const cols = (sheet.widths || []).length
      ? '<cols>' + sheet.widths.map((w, i) => `<col min="${i + 1}" max="${i + 1}" width="${w}" customWidth="1"/>`).join('') + '</cols>'
      : '';
    return XML_HEAD + `<worksheet xmlns="${NS}">${views}<sheetFormatPr defaultRowHeight="16.5"/>${cols}<sheetData>${rows}</sheetData></worksheet>`;
  }

  /** 시트 이름 규칙: 31자 이하, \ / : * ? [ ] 불가, 중복 불가 */
  function sheetNames(sheets) {
    const used = new Set();
    return sheets.map((sh, i) => {
      let name = String(sh.name || `Sheet${i + 1}`).replace(/[\\/:*?[\]]/g, '_').slice(0, 31) || `Sheet${i + 1}`;
      while (used.has(name.toLowerCase())) name = (name.slice(0, 28) + '_' + i).slice(0, 31);
      used.add(name.toLowerCase());
      return name;
    });
  }

  async function build(sheets) {
    if (typeof JSZip === 'undefined') throw new Error('lib/jszip.min.js 가 없어 Excel 파일을 만들 수 없습니다.');
    const names = sheetNames(sheets);
    const zip = new JSZip();
    zip.file('[Content_Types].xml', XML_HEAD
      + '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
      + '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
      + '<Default Extension="xml" ContentType="application/xml"/>'
      + '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
      + '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
      + sheets.map((_, i) => `<Override PartName="/xl/worksheets/sheet${i + 1}.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>`).join('')
      + '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
      + '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
      + '</Types>');
    zip.file('_rels/.rels', XML_HEAD
      + '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
      + '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
      + '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
      + '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
      + '</Relationships>');
    const now = new Date().toISOString().replace(/\.\d+Z$/, 'Z');
    zip.file('docProps/core.xml', XML_HEAD
      + '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
      + '<dc:title>LOC Manager</dc:title><dc:creator>LOC_Mgmt</dc:creator>'
      + `<dcterms:created xsi:type="dcterms:W3CDTF">${now}</dcterms:created><dcterms:modified xsi:type="dcterms:W3CDTF">${now}</dcterms:modified>`
      + '</cp:coreProperties>');
    zip.file('docProps/app.xml', XML_HEAD
      + '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>LOC_Mgmt</Application></Properties>');
    zip.file('xl/workbook.xml', XML_HEAD
      + `<workbook xmlns="${NS}" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>`
      + names.map((n, i) => `<sheet name="${esc(n)}" sheetId="${i + 1}" r:id="rId${i + 1}"/>`).join('')
      + '</sheets></workbook>');
    zip.file('xl/_rels/workbook.xml.rels', XML_HEAD
      + '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
      + sheets.map((_, i) => `<Relationship Id="rId${i + 1}" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet${i + 1}.xml"/>`).join('')
      + `<Relationship Id="rId${sheets.length + 1}" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>`
      + '</Relationships>');
    zip.file('xl/styles.xml', STYLES_XML);
    sheets.forEach((sh, i) => zip.file(`xl/worksheets/sheet${i + 1}.xml`, sheetXml(sh)));
    return zip.generateAsync({ type: 'blob', compression: 'DEFLATE', mimeType: MIME });
  }

  /** Blob 을 파일로 내려받게 한다. */
  function download(blob, fileName) {
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = fileName;
    document.body.append(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 10000);
  }

  window.XlsxExport = { build, download, MIME };
})();
