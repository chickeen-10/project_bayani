<?php

namespace Tests\Unit;

use App\Services\OpenXmlPreviewService;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class OpenXmlPreviewServiceTest extends TestCase
{
    private function officeArchive(array $members): string
    {
        $path = tempnam(sys_get_temp_dir(), 'bayani-office-');
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path, ZipArchive::OVERWRITE));
        foreach ($members as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();

        return $path;
    }

    public function test_xlsx_preview_reads_cached_cells_and_worksheet_names_without_evaluating_formulas(): void
    {
        $path = $this->officeArchive([
            'xl/workbook.xml' => '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Results" sheetId="1" r:id="rId1"/><sheet name="Notes" sheetId="2" r:id="rId2"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="worksheet" Target="worksheets/sheet2.xml"/></Relationships>',
            'xl/sharedStrings.xml' => '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>Quarter</t></si><si><t>Actual value</t></si><si><t>&lt;script&gt;alert(1)&lt;/script&gt;</t></si></sst>',
            'xl/worksheets/sheet1.xml' => '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c></row><row r="2"><c r="A2" t="s"><v>2</v></c><c r="B2"><f>1+1</f><v>2</v></c></row></sheetData></worksheet>',
            'xl/worksheets/sheet2.xml' => '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>Teacher notes</t></is></c></row></sheetData></worksheet>',
        ]);

        try {
            $preview = (new OpenXmlPreviewService())->spreadsheet($path);
            $this->assertSame('Results', $preview['sheets'][0]['name']);
            $this->assertSame(['Quarter', 'Actual value'], $preview['sheets'][0]['rows'][0]);
            $this->assertSame('<script>alert(1)</script>', $preview['sheets'][0]['rows'][1][0]);
            $this->assertSame('2', $preview['sheets'][0]['rows'][1][1]);
            $this->assertSame('Notes', $preview['sheets'][1]['name']);
            $svg = (new OpenXmlPreviewService())->thumbnailSvg('xlsx', $preview, 'Worksheet <script>');
            $this->assertStringContainsString('&lt;script&gt;', $svg);
            $this->assertStringNotContainsString('<script>', $svg);
        } finally {
            @unlink($path);
        }
    }

    public function test_docx_preview_preserves_headings_lists_and_tables_as_plain_text(): void
    {
        $path = $this->officeArchive([
            'word/document.xml' => '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:pPr><w:pStyle w:val="Heading1"/></w:pPr><w:r><w:t>Lesson heading</w:t></w:r></w:p><w:p><w:pPr><w:numPr><w:ilvl w:val="0"/></w:numPr></w:pPr><w:r><w:t>First step</w:t></w:r></w:p><w:tbl><w:tr><w:tc><w:p><w:r><w:t>Term</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>Meaning</w:t></w:r></w:p></w:tc></w:tr></w:tbl><w:sectPr/></w:body></w:document>',
        ]);

        try {
            $preview = (new OpenXmlPreviewService())->document($path);
            $this->assertSame('heading', $preview['blocks'][0]['type']);
            $this->assertSame('list-item', $preview['blocks'][1]['type']);
            $this->assertSame('table', $preview['blocks'][2]['type']);
            $this->assertSame(['Term', 'Meaning'], $preview['blocks'][2]['rows'][0]);
            $svg = (new OpenXmlPreviewService())->thumbnailSvg('docx', $preview, 'Lesson heading');
            $this->assertStringContainsString('Lesson heading', $svg);
        } finally {
            @unlink($path);
        }
    }

    public function test_xlsx_thumbnail_selects_a_meaningful_worksheet_and_nonempty_rows(): void
    {
        $path = $this->officeArchive([
            'xl/workbook.xml' => '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Cover" sheetId="1" r:id="rId1"/><sheet name="Results" sheetId="2" r:id="rId2"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="worksheet" Target="worksheets/sheet2.xml"/></Relationships>',
            'xl/sharedStrings.xml' => '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>Cover page</t></si><si><t>Student</t></si><si><t>Score</t></si><si><t>Ana</t></si><si><t>95</t></si></sst>',
            'xl/worksheets/sheet1.xml' => '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="s"><v>0</v></c></row></sheetData></worksheet>',
            'xl/worksheets/sheet2.xml' => '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="s"><v>1</v></c><c r="B1" t="s"><v>2</v></c></row><row r="2"><c r="A2" t="s"><v>3</v></c><c r="B2" t="s"><v>4</v></c></row></sheetData></worksheet>',
        ]);

        try {
            $service = new OpenXmlPreviewService();
            $svg = $service->thumbnailSvg('xlsx', $service->spreadsheet($path), 'Assessment');
            $this->assertStringContainsString('Results', $svg);
            $this->assertStringContainsString('Ana', $svg);
            $this->assertStringContainsString('95', $svg);
            $this->assertStringNotContainsString('Cover', $svg);
            $this->assertStringContainsString('>A</text>', $svg);
            $this->assertStringNotContainsString('DOCUMENT CONTENT SNAPSHOT', $svg);
            $this->assertStringNotContainsString('Cover page', $svg);
        } finally {
            @unlink($path);
        }
    }

    public function test_xlsx_relationships_cannot_escape_the_archive_worksheet_folder(): void
    {
        $path = $this->officeArchive([
            'xl/workbook.xml' => '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Unsafe" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="worksheet" Target="../../custom.xml"/></Relationships>',
        ]);

        try {
            $this->expectException(\RuntimeException::class);
            (new OpenXmlPreviewService())->spreadsheet($path);
        } finally {
            @unlink($path);
        }
    }
}
