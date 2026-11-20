<?php

/**
 * Checks SimpleXlsx against a real spreadsheet library (Python openpyxl).
 *   php tests/manual/xlsx_test.php
 * Needs python3 with openpyxl for the cross-check.
 */

require __DIR__ . '/../../app/Libraries/SimpleXlsx.php';

use App\Libraries\SimpleXlsx;

$pass = 0;
$fail = 0;
function check(string $name, mixed $expected, mixed $actual): void
{
    global $pass, $fail;
    if ($expected === $actual) {
        echo "PASS  {$name}\n";
        $pass++;
    } else {
        echo "FAIL  {$name}\n      expected: " . json_encode($expected, JSON_UNESCAPED_UNICODE)
            . "\n      actual:   " . json_encode($actual, JSON_UNESCAPED_UNICODE) . "\n";
        $fail++;
    }
}

$dir = sys_get_temp_dir() . '/xlsx_test_' . getmypid();
mkdir($dir);

// ---------------------------------------------------------------------
// 1. PHP writes, openpyxl reads
// ---------------------------------------------------------------------
$rows = [
    ['Username', 'Full Name', 'Email', 'Post'],
    ['jdoe', 'Jane & "John" <Doe>', 'jane@example.edu', 'Head of Department'],
    ['0901CS211001', 'राहुल शर्मा', '', 'Warden'],        // leading zeros + Hindi must survive as text
    ['n1', 'Number', 42, 3.5],
];
$bytes = SimpleXlsx::write([
    [
        'name'        => 'Users',
        'rows'        => $rows,
        'widths'      => [18, 30, 28, 24],
        'validations' => [['sqref' => 'D2:D100', 'formula' => 'Lists!$A$2:$A$4']],
    ],
    ['name' => 'Lists', 'rows' => [['Post'], ['Head of Department'], ['Warden'], ['Registrar']], 'widths' => [28]],
]);
file_put_contents("$dir/php_written.xlsx", $bytes);

$py = <<<'PY'
import json, sys
from openpyxl import load_workbook
wb = load_workbook(sys.argv[1])
ws = wb["Users"]
out = {
  "sheets": wb.sheetnames,
  "rows": [[c.value for c in r] for r in ws.iter_rows()],
  "freeze": ws.freeze_panes,
  "header_bold": ws["A1"].font.b,
  "header_fill": ws["A1"].fill.fgColor.rgb,
  "width_B": ws.column_dimensions["B"].width,
  "validations": [(str(dv.sqref), dv.formula1, dv.type) for dv in ws.data_validations.dataValidation],
}
print(json.dumps(out, ensure_ascii=False))
PY;
file_put_contents("$dir/read.py", $py);
$out = json_decode((string) shell_exec('python3 -W error ' . escapeshellarg("$dir/read.py") . ' ' . escapeshellarg("$dir/php_written.xlsx") . ' 2>' . escapeshellarg("$dir/py_err.txt")), true);

check('openpyxl opens the PHP-written workbook with no warnings (python -W error)', true, is_array($out));
if (! is_array($out)) { echo '      ' . trim((string) @file_get_contents("$dir/py_err.txt")) . "\n"; }
check('sheet names', ['Users', 'Lists'], $out['sheets'] ?? null);
check('header row', ['Username', 'Full Name', 'Email', 'Post'], $out['rows'][0] ?? null);
check('special characters survive', 'Jane & "John" <Doe>', $out['rows'][1][1] ?? null);
check('leading zeros kept as text', '0901CS211001', $out['rows'][2][0] ?? null);
check('Hindi text survives', 'राहुल शर्मा', $out['rows'][2][1] ?? null);
check('numbers stay numbers', [42, 3.5], [$out['rows'][3][2] ?? null, $out['rows'][3][3] ?? null]);
check('header is frozen', 'A2', $out['freeze'] ?? null);
check('header is bold', true, $out['header_bold'] ?? null);
check('header has fill colour', true, in_array($out['header_fill'] ?? '', ['001F3A5F', 'FF1F3A5F'], true));
check('column width applied', 30.0, (float) ($out['width_B'] ?? 0));
check('drop-down validation present', [['D2:D100', 'Lists!$A$2:$A$4', 'list']], $out['validations'] ?? null);

// ---------------------------------------------------------------------
// 2. PHP reads its own file
// ---------------------------------------------------------------------
$back = SimpleXlsx::read("$dir/php_written.xlsx");
check('PHP re-reads own file: header', $rows[0], $back[1] ?? null);
check('PHP re-reads own file: text with symbols', $rows[1], $back[2] ?? null);
check('PHP re-reads own file: blank cell becomes empty string', '', $back[3][2] ?? null);
check('PHP re-reads numbers as digits', ['n1', 'Number', '42', '3.5'], $back[4] ?? null);
check('second sheet readable by name', ['Warden'], $back = SimpleXlsx::read("$dir/php_written.xlsx", 'Lists')[3] ?? null);

// ---------------------------------------------------------------------
// 3. openpyxl writes (Excel-style: shared strings, numeric cells, gaps), PHP reads
// ---------------------------------------------------------------------
$py2 = <<<'PY'
import sys
from openpyxl import Workbook
wb = Workbook()
ws = wb.active
ws.title = "Data"
ws["A1"] = "Username"; ws["B1"] = "Full Name"; ws["C1"] = "Phone"
ws["A2"] = "dean.acad"; ws["B2"] = "Dr. A. Kumar"; ws["C2"] = 9876543210      # big whole number
ws["A4"] = "reg"; ws["C4"] = 12.25                                           # row 3 empty, B4 empty
ws["A5"] = "  padded  "; ws["B5"] = "Ünïcode ✓"; ws["C5"] = True
ws["A7"] = "only-col-A"
ws["E8"] = "far-column"                                                      # gap columns B-D
ws2 = wb.create_sheet("Other"); ws2["A1"] = "second"
wb.save(sys.argv[1])
PY;
file_put_contents("$dir/write.py", $py2);
shell_exec('python3 ' . escapeshellarg("$dir/write.py") . ' ' . escapeshellarg("$dir/excel_style.xlsx") . ' 2>&1');
$r = SimpleXlsx::read("$dir/excel_style.xlsx");

check('reads first sheet by default', ['Username', 'Full Name', 'Phone'], $r[1] ?? null);
check('large whole number becomes plain digits', '9876543210', $r[2][2] ?? null);
check('empty rows are skipped but numbering kept', [1, 2, 4, 5, 7, 8], array_keys($r));
check('gaps are padded', ['reg', '', '12.25'], $r[4] ?? null);
check('text is trimmed', 'padded', $r[5][0] ?? null);
check('unicode survives', 'Ünïcode ✓', $r[5][1] ?? null);
check('boolean becomes TRUE', 'TRUE', $r[5][2] ?? null);
check('gap columns padded to far column', ['', '', '', '', 'far-column'], $r[8] ?? null);
check('named second sheet', ['second'], SimpleXlsx::read("$dir/excel_style.xlsx", 'Other')[1] ?? null);

// ---------------------------------------------------------------------
// 4. Bad input is rejected with a clear exception, never a fatal error
// ---------------------------------------------------------------------
file_put_contents("$dir/not_a_zip.xlsx", "this is plain text, not a workbook");
try {
    SimpleXlsx::read("$dir/not_a_zip.xlsx");
    check('plain text rejected', 'exception', 'no exception');
} catch (RuntimeException $e) {
    check('plain text rejected', true, str_contains($e->getMessage(), 'valid .xlsx'));
}

$zip = new ZipArchive();
$zip->open("$dir/empty_zip.xlsx", ZipArchive::CREATE);
$zip->addFromString('hello.txt', 'x');
$zip->close();
try {
    SimpleXlsx::read("$dir/empty_zip.xlsx");
    check('zip without a workbook rejected', 'exception', 'no exception');
} catch (RuntimeException $e) {
    check('zip without a workbook rejected', true, str_contains($e->getMessage(), 'valid .xlsx'));
}

try {
    SimpleXlsx::read("$dir/excel_style.xlsx", 'NoSuchSheet');
    check('unknown sheet name rejected', 'exception', 'no exception');
} catch (RuntimeException $e) {
    check('unknown sheet name rejected', true, str_contains($e->getMessage(), 'NoSuchSheet'));
}

// XML with an external entity must not be fetched or expanded
$evil = new ZipArchive();
$evil->open("$dir/evil.xlsx", ZipArchive::CREATE);
$evil->addFromString('xl/workbook.xml', '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]>'
    . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
    . '<sheets><sheet name="S" sheetId="1" r:id="rId1"/></sheets></workbook>');
$evil->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml"/></Relationships>');
$evil->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]>'
    . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>&e;</t></is></c></row></sheetData></worksheet>');
$evil->close();
try {
    $res = SimpleXlsx::read("$dir/evil.xlsx");
    $leak = str_contains(json_encode($res), 'root:');
    check('external entity (XXE) is not expanded', false, $leak);
} catch (RuntimeException $e) {
    check('external entity (XXE) is not expanded', true, true);
}

// ---------------------------------------------------------------------
// 5. CSV
// ---------------------------------------------------------------------
file_put_contents("$dir/a.csv", "\xEF\xBB\xBFUsername,Full Name\r\njdoe,\"Doe, Jane\"\r\n\r\nx,y\r\n");
check('CSV with BOM, quotes and blank line', [1 => ['Username', 'Full Name'], 2 => ['jdoe', 'Doe, Jane'], 4 => ['x', 'y']], SimpleXlsx::readCsv("$dir/a.csv"));
file_put_contents("$dir/b.csv", "Username;Full Name\njdoe;Jane\n");
check('semicolon-separated CSV (Excel in some regions)', [1 => ['Username', 'Full Name'], 2 => ['jdoe', 'Jane']], SimpleXlsx::readCsv("$dir/b.csv"));

echo "\nRESULT: {$pass} passed, {$fail} failed\n";
shell_exec('rm -rf ' . escapeshellarg($dir));
exit($fail === 0 ? 0 : 1);
