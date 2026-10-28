<?php

namespace Tests\Feature;

use App\Models\Material;
use App\Models\User;
use App\Services\MaterialThumbnailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class MaterialPreviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('bayani_uploads');
    }

    private function requiredCatalog(): array
    {
        return ['grade_levels' => [\DB::table('grade_levels')->where('code', 'G2')->value('id')], 'subjects' => [\DB::table('subjects')->where('name', 'Mathematics')->value('id')]];
    }

    private function officeUpload(string $filename, array $members): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'bayani-preview-');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::OVERWRITE);
        foreach ($members as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();
        $bytes = file_get_contents($path);
        @unlink($path);

        return UploadedFile::fake()->createWithContent($filename, $bytes);
    }

    private function xlsxUpload(): UploadedFile
    {
        return $this->officeUpload('scores.xlsx', [
            '[Content_Types].xml' => '<Types/>',
            'xl/workbook.xml' => '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Scores" sheetId="1" r:id="rId1"/><sheet name="Notes" sheetId="2" r:id="rId2"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="worksheet" Target="worksheets/sheet2.xml"/></Relationships>',
            'xl/sharedStrings.xml' => '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>Student</t></si><si><t>Score</t></si><si><t>Grade 2</t></si><si><t>98</t></si><si><t>Teacher note</t></si></sst>',
            'xl/worksheets/sheet1.xml' => '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c></row><row r="2"><c r="A2" t="s"><v>2</v></c><c r="B2" t="s"><v>3</v></c></row></sheetData></worksheet>',
            'xl/worksheets/sheet2.xml' => '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="s"><v>4</v></c></row></sheetData></worksheet>',
        ]);
    }

    private function docxUpload(): UploadedFile
    {
        return $this->officeUpload('lesson.docx', [
            '[Content_Types].xml' => '<Types/>',
            'word/document.xml' => '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:pPr><w:pStyle w:val="Heading1"/></w:pPr><w:r><w:t>Learning objectives</w:t></w:r></w:p><w:p><w:r><w:t>Read and discuss the text.</w:t></w:r></w:p><w:tbl><w:tr><w:tc><w:p><w:r><w:t>Question</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>Answer</w:t></w:r></w:p></w:tc></w:tr></w:tbl><w:sectPr/></w:body></w:document>',
        ]);
    }

    public function test_xlsx_preview_renders_actual_cells_and_tabs_separately_from_download(): void
    {
        $owner = User::factory()->create(['role' => 'contributor']);
        $this->actingAs($owner)->post(route('contributor.submissions.store'), [
            'title' => 'Grade 2 scores', 'description' => 'Workbook preview fixture.', 'intent' => 'submit', ...$this->requiredCatalog(), 'file' => $this->xlsxUpload(),
        ])->assertRedirect(route('contributor.submissions'));
        $material = Material::where('title', 'Grade 2 scores')->firstOrFail();
        $material->update(['status' => 'Approved', 'published_at' => now()]);
        $attachment = $material->attachments()->firstOrFail();

        $detail = $this->get(route('material', $material));
        $detail->assertOk();
        $this->assertFalse($detail->headers->has('Content-Disposition'));
        $detail->assertSee('scores.xlsx');

        $preview = $this->get(route('preview', $material));
        $preview->assertOk()->assertSee('file-preview', false)->assertSee('Scores')->assertSee('Grade 2')->assertSee('98')->assertSee('Notes');
        $this->assertFalse($preview->headers->has('Content-Disposition'));

        $download = $this->get(route('materials.file', $material));
        $download->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="scores.xlsx"');

        $stalePath = 'thumbnails/obsolete-openxml-snapshot.svg';
        Storage::disk('bayani_uploads')->put($stalePath, '<svg>old simplified snapshot</svg>');
        $material->forceFill(['thumbnail_path' => $stalePath])->save();
        $attachment->forceFill(['thumbnail_path' => $stalePath])->save();
        $thumbnail = $this->get(route('materials.thumbnail', $material));
        $thumbnail->assertOk()->assertHeader('Content-Type', 'image/svg+xml; charset=UTF-8')->assertSee('page-faithful thumbnail is unavailable');
        $this->assertNull($material->fresh()->thumbnail_path);
        Storage::disk('bayani_uploads')->assertExists($stalePath); // Still referenced by the attachment until its route is checked.

        $this->get(route('materials.attachments.preview',[$material,$attachment]))->assertOk()->assertSee('Scores')->assertSee('98');
        $this->get(route('materials.attachments.file',[$material,$attachment]))->assertOk()->assertHeader('Content-Disposition','attachment; filename="scores.xlsx"');
        $this->get(route('materials.attachments.thumbnail',[$material,$attachment]))->assertOk()->assertSee('page-faithful thumbnail is unavailable');
        $this->assertNull($attachment->fresh()->thumbnail_path);
        Storage::disk('bayani_uploads')->assertMissing($stalePath);
    }

    public function test_docx_preview_is_readable_and_macros_and_markup_are_not_emitted_as_active_html(): void
    {
        $owner = User::factory()->create(['role' => 'contributor']);
        $this->actingAs($owner)->post(route('contributor.submissions.store'), [
            'title' => 'Teaching guide', 'description' => 'Document preview fixture.', 'intent' => 'submit', ...$this->requiredCatalog(), 'file' => $this->docxUpload(),
        ])->assertRedirect(route('contributor.submissions'));
        $material = Material::where('title', 'Teaching guide')->firstOrFail();
        $material->update(['status' => 'Approved', 'published_at' => now()]);
        $attachment = $material->attachments()->firstOrFail();

        $this->get(route('preview', $material))->assertOk()->assertSee('Learning objectives')->assertSee('Read and discuss the text.')->assertSee('Question')->assertSee('Answer');
        $this->get(route('materials.attachments.preview',[$material,$attachment]))->assertOk()->assertSee('Learning objectives')->assertSee('Read and discuss the text.');
        $this->get(route('materials.attachments.file',[$material,$attachment]))->assertOk()->assertHeader('Content-Disposition','attachment; filename="lesson.docx"');
        $thumbnail = $this->get(route('materials.thumbnail', $material));
        $thumbnail->assertOk()->assertHeader('Content-Type', 'image/svg+xml; charset=UTF-8')->assertSee('page-faithful thumbnail is unavailable');
        $this->assertNull($material->fresh()->thumbnail_path);
    }

    public function test_thumbnails_and_previews_keep_pending_attachments_private(): void
    {
        $owner = User::factory()->create(['role' => 'contributor']);
        $other = User::factory()->create(['role' => 'contributor']);
        $this->actingAs($owner)->post(route('contributor.submissions.store'), [
            'title' => 'Private workbook', 'description' => 'Pending fixture.', 'intent' => 'submit', ...$this->requiredCatalog(), 'file' => $this->xlsxUpload(),
        ])->assertRedirect();
        $material = Material::where('title', 'Private workbook')->firstOrFail();

        auth()->logout();
        $this->get(route('preview', $material))->assertNotFound();
        $this->get(route('materials.thumbnail', $material))->assertNotFound();
        $this->get(route('materials.file', $material))->assertNotFound();

        $this->actingAs($other)->get(route('preview', $material))->assertNotFound();
        $this->get(route('materials.thumbnail', $material))->assertNotFound();

        $this->actingAs($owner)->get(route('preview', $material))->assertOk()->assertSee('Grade 2');
    }

    public function test_existing_pdf_and_image_previews_remain_inline_and_thumbnails_use_real_source_content(): void
    {
        $owner = User::factory()->create(['role' => 'contributor']);
        $png = UploadedFile::fake()->image('lesson.png', 20, 20);
        $this->actingAs($owner)->post(route('contributor.submissions.store'), [
            'title' => 'Picture lesson', 'description' => 'Image preview fixture.', 'intent' => 'submit', ...$this->requiredCatalog(), 'file' => $png,
        ])->assertRedirect();
        $material = Material::where('title', 'Picture lesson')->firstOrFail();
        $material->update(['status' => 'Approved', 'published_at' => now()]);
        $attachment = $material->attachments()->firstOrFail();

        $preview = $this->get(route('preview', $material));
        $preview->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('Content-Disposition', 'inline; filename="lesson.png"');
        $thumbnail = $this->get(route('materials.thumbnail', $material));
        $thumbnail->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertSame(Storage::disk('bayani_uploads')->get($material->file_path), $thumbnail->getContent());
        $this->get(route('materials.attachments.preview',[$material,$attachment]))->assertOk()->assertHeader('Content-Type','image/png');
        $this->get(route('materials.attachments.thumbnail',[$material,$attachment]))->assertOk()->assertHeader('Content-Type','image/png');
        $this->get(route('materials.attachments.file',[$material,$attachment]))->assertOk()->assertHeader('Content-Disposition','attachment; filename="lesson.png"');
    }

    public function test_pdf_thumbnail_is_a_raster_of_the_actual_first_page_and_cache_tracks_attachment_content(): void
    {
        $owner = User::factory()->create(['role' => 'contributor']);
        $pdf = UploadedFile::fake()->createWithContent('sample.pdf', file_get_contents(public_path('sample-files/sample-history.pdf')));
        $this->actingAs($owner)->post(route('contributor.submissions.store'), [
            'title' => 'Sample PDF', 'description' => 'PDF thumbnail fixture.', 'intent' => 'submit', ...$this->requiredCatalog(), 'file' => $pdf,
        ])->assertRedirect();
        $material = Material::where('title', 'Sample PDF')->firstOrFail();
        $material->update(['status' => 'Approved', 'published_at' => now()]);

        $thumbnail = $this->get(route('materials.thumbnail', $material));
        $thumbnail->assertOk()->assertHeader('Content-Type', 'image/png');
        $firstPath = $material->fresh()->thumbnail_path;
        $image = getimagesizefromstring($thumbnail->getContent());
        $this->assertGreaterThan(100, $image[0]);
        $this->assertGreaterThan(100, $image[1]);
        Storage::disk('bayani_uploads')->assertExists($firstPath);

        $material->update(['title' => 'Retitled PDF']);
        $this->get(route('materials.thumbnail', $material))->assertOk();
        $secondPath = $material->fresh()->thumbnail_path;
        $this->assertSame($firstPath, $secondPath);
        Storage::disk('bayani_uploads')->assertExists($secondPath);

        $source = Storage::disk('bayani_uploads')->get($material->file_path);
        Storage::disk('bayani_uploads')->put($material->file_path, $source."\n% changed attachment bytes\n");
        $material->forceFill(['file_sha256' => hash('sha256', $source."\n% changed attachment bytes\n")])->save();
        $changed = app(MaterialThumbnailService::class)->generate($material->fresh());
        $thirdPath = $material->fresh()->thumbnail_path;
        $this->assertNotSame($secondPath, $thirdPath);
        Storage::disk('bayani_uploads')->assertExists($thirdPath);
        Storage::disk('bayani_uploads')->assertMissing($secondPath);

    }

    public function test_authorized_browser_office_thumbnail_is_persisted_and_reused(): void
    {
        $owner = User::factory()->create(['role' => 'contributor']);
        $this->actingAs($owner)->post(route('contributor.submissions.store'), [
            'title' => 'Cached workbook', 'description' => 'Persistent browser thumbnail fixture.', 'intent' => 'submit', ...$this->requiredCatalog(), 'file' => $this->xlsxUpload(),
        ])->assertRedirect(route('contributor.submissions'));
        $material = Material::where('title', 'Cached workbook')->firstOrFail();
        $material->update(['status' => 'Approved', 'published_at' => now()]);
        $jpeg = imagecreatetruecolor(24, 24);
        ob_start(); imagejpeg($jpeg, null, 80); $bytes = ob_get_clean(); imagedestroy($jpeg);

        $this->post(route('materials.thumbnail.cache', $material), ['thumbnail' => UploadedFile::fake()->createWithContent('capture.jpg', $bytes)])
            ->assertCreated()->assertJson(['cached' => true, 'mime' => 'image/jpeg']);
        $path = $material->fresh()->thumbnail_path;
        $this->assertStringStartsWith('thumbnails/office-material-', $path);
        Storage::disk('bayani_uploads')->assertExists($path);
        $this->get(route('materials.thumbnail', $material))->assertOk()->assertHeader('Content-Type', 'image/jpeg')->assertHeader('Cache-Control', 'max-age=3600, private');
        $this->actingAs(User::factory()->create(['role' => 'contributor']))
            ->post(route('materials.thumbnail.cache', $material), ['thumbnail' => UploadedFile::fake()->createWithContent('capture.jpg', $bytes)])
            ->assertForbidden();
    }
}
