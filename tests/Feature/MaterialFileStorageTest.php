<?php

namespace Tests\Feature;

use App\Models\Material;
use App\Models\MaterialAttachment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MaterialFileStorageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('bayani_uploads');
    }

    private function png(string $name = 'lesson.png'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 20, 20);
    }

    private function payload(UploadedFile $file, string $title = 'Uploaded lesson'): array
    {
        return ['title' => $title, 'description' => 'A saved learning resource.', 'intent' => 'submit', 'grade_levels' => [\DB::table('grade_levels')->where('code','G2')->value('id')], 'subjects' => [\DB::table('subjects')->where('name','Mathematics')->value('id')], 'file' => $file];
    }

    public function test_minimal_submission_needs_no_description_and_persists_catalog_metadata(): void
    {
        $owner = User::factory()->create(['role' => 'contributor']);
        $curriculum = \DB::table('curricula')->where('code', 'MATATAG')->first();
        $grade = \DB::table('grade_levels')->where('code', 'G2')->first();
        $subject = \DB::table('subjects')->where('name', 'Mathematics')->first();
        $payload = ['title' => 'Minimal worksheet', 'curriculum_id' => $curriculum->id, 'grade_levels' => [$grade->id], 'subjects' => [$subject->id], 'attachments' => [$this->png('minimal.png')], 'intent' => 'submit'];

        $this->actingAs($owner)->post(route('contributor.submissions.store'), $payload)->assertRedirect(route('contributor.submissions'));
        $material = Material::where('title', 'Minimal worksheet')->firstOrFail();
        $this->assertNull($material->description);
        $this->assertSame([$grade->id], $material->gradeLevels()->pluck('grade_levels.id')->all());
        $this->assertSame([$subject->id], $material->subjects()->pluck('subjects.id')->all());
        $this->assertDatabaseHas('material_attachments', ['material_id' => $material->id, 'original_filename' => 'minimal.png']);
    }

    public function test_submission_requires_attachment_grade_and_subject_but_draft_may_be_incomplete(): void
    {
        $owner = User::factory()->create(['role' => 'contributor']);
        $curriculum = \DB::table('curricula')->where('code', 'MATATAG')->first();
        $grade = \DB::table('grade_levels')->where('code', 'G2')->first();
        $subject = \DB::table('subjects')->where('name', 'Mathematics')->first();
        $this->actingAs($owner)->from('/contributor/upload')->post(route('contributor.submissions.store'), ['title' => 'No file', 'curriculum_id' => $curriculum->id, 'grade_levels' => [$grade->id], 'subjects' => [$subject->id]])->assertSessionHasErrors('attachments');
        $this->from('/contributor/upload')->post(route('contributor.submissions.store'), ['title' => 'No classifications', 'file' => $this->png()])->assertSessionHasErrors(['grade_levels', 'subjects']);
        $this->post(route('contributor.submissions.store'), ['title' => 'Incomplete draft', 'intent' => 'draft'])->assertRedirect(route('contributor.submissions'));
        $this->assertDatabaseHas('materials', ['title' => 'Incomplete draft', 'status' => 'Draft']);
    }

    public function test_contributor_upload_persists_metadata_and_private_bytes_and_blocks_other_users(): void
    {
        $owner = User::factory()->create(['role' => 'contributor']);
        $other = User::factory()->create(['role' => 'contributor']);
        $this->actingAs($owner)->post(route('contributor.submissions.store'), $this->payload($this->png()))->assertRedirect(route('contributor.submissions'));
        $material = Material::where('title', 'Uploaded lesson')->firstOrFail();
        $this->assertSame($owner->id, $material->contributor_id);
        $this->assertSame('Pending', $material->status);
        $this->assertSame('bayani_uploads', $material->file_disk);
        $this->assertSame('lesson.png', $material->original_filename);
        $this->assertSame(64, strlen($material->file_sha256));
        Storage::disk('bayani_uploads')->assertExists($material->file_path);
        auth()->logout();
        $this->get(route('materials.file', $material))->assertNotFound();
        $this->actingAs($other)->get(route('materials.file', $material))->assertNotFound();
        $this->actingAs($other)->get(route('preview', $material))->assertNotFound();
        $this->actingAs($owner)->get(route('materials.file', $material))->assertOk()->assertHeader('Content-Disposition');
        $this->actingAs($owner)->get(route('preview', $material))->assertOk()->assertHeader('Content-Type', 'image/png');
    }

    public function test_approved_files_are_public_and_missing_files_return_404(): void
    {
        $owner = User::factory()->create(['role' => 'contributor']);
        $this->actingAs($owner)->post(route('contributor.submissions.store'), $this->payload($this->png()))->assertRedirect();
        $material = Material::firstOrFail();
        $material->update(['status' => 'Approved', 'published_at' => now()]);
        auth()->logout();
        $this->get(route('materials.file', $material))->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get(route('preview', $material))->assertOk()->assertHeader('Content-Disposition');
        Storage::disk('bayani_uploads')->delete($material->file_path);
        $this->get(route('materials.file', $material))->assertNotFound();
    }

    public function test_pdf_preview_is_inline_and_download_uses_the_original_filename(): void
    {
        $owner = User::factory()->create(['role' => 'contributor']);
        $pdf = UploadedFile::fake()->createWithContent('lesson.pdf', file_get_contents(public_path('sample-files/sample-history.pdf')));
        $this->actingAs($owner)->post(route('contributor.submissions.store'), $this->payload($pdf, 'PDF lesson'))->assertRedirect();
        $material = Material::where('title', 'PDF lesson')->firstOrFail();
        $material->update(['status' => 'Approved', 'published_at' => now()]);
        $page=$this->get(route('preview', $material));
        $page->assertOk()->assertViewHas('backend.format','pdf')->assertViewHas('backend.preview_url',route('preview',[$material,'stream'=>1]));
        $this->get(route('preview',[$material,'stream'=>1]))->assertOk()->assertHeader('Content-Type','application/pdf')->assertHeader('Content-Disposition','inline; filename="lesson.pdf"');
        $this->get(route('materials.file', $material))->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="lesson.pdf"');
    }

    public function test_media_preview_supports_authorized_byte_ranges_and_keeps_download_separate(): void
    {
        $owner = User::factory()->create(['role'=>'contributor']);
        $other = User::factory()->create(['role'=>'contributor']);
        $this->actingAs($owner)->post(route('contributor.submissions.store'), $this->payload(UploadedFile::fake()->createWithContent('lesson.mp4', "0123456789"), 'Range fixture'));
        $material = Material::where('title','Range fixture')->firstOrFail();
        $material->update(['status'=>'Approved','published_at'=>now()]);

        $partial=$this->get(route('preview',[$material,'stream'=>1]),['Range'=>'bytes=2-5']);
        $partial->assertStatus(206)
            ->assertHeader('Content-Type','video/mp4')->assertHeader('Accept-Ranges','bytes')
            ->assertHeader('Content-Range','bytes 2-5/10');
        $this->assertSame('2345',$partial->streamedContent());
        $suffix=$this->get(route('preview',[$material,'stream'=>1]),['Range'=>'bytes=-3']);
        $suffix->assertStatus(206)
            ->assertHeader('Content-Range','bytes 7-9/10');
        $this->assertSame('789',$suffix->streamedContent());
        $this->get(route('preview',[$material,'stream'=>1]),['Range'=>'bytes=10-'])->assertStatus(416)
            ->assertHeader('Content-Range','bytes */10');
        $this->get(route('materials.file',$material))->assertOk()->assertHeader('Content-Disposition','attachment; filename="lesson.mp4"');
        $private = Material::where('title','Range fixture')->firstOrFail();
        $private->update(['status'=>'Pending','published_at'=>null]);
        $this->actingAs($other)->get(route('preview',[$private,'stream'=>1]),['Range'=>'bytes=0-1'])->assertNotFound();
    }

    public function test_invalid_and_oversized_files_are_rejected_without_material_records(): void
    {
        $owner = User::factory()->create(['role' => 'contributor']);
        $this->actingAs($owner)->from('/contributor/upload')->post(route('contributor.submissions.store'), $this->payload(UploadedFile::fake()->createWithContent('script.php', '<?php echo 1;')))->assertSessionHasErrors('file');
        config(['app.bayani_max_upload_mb' => 1]);
        $this->from('/contributor/upload')->post(route('contributor.submissions.store'), $this->payload(UploadedFile::fake()->image('large.png', 100, 100)->size(2048)))->assertSessionHasErrors('file');
        $this->assertDatabaseCount('materials', 0);
        $this->assertSame([], Storage::disk('bayani_uploads')->allFiles());
    }

    public function test_office_files_require_a_well_formed_open_xml_archive(): void
    {
        $owner = User::factory()->create(['role' => 'contributor']);
        $badOfficeFile = UploadedFile::fake()->createWithContent('bad.docx', 'This is not an Office archive.');
        $this->actingAs($owner)->from('/contributor/upload')->post(route('contributor.submissions.store'), $this->payload($badOfficeFile))->assertSessionHasErrors('file');

        $archivePath = tempnam(sys_get_temp_dir(), 'bayani-docx-');
        $archive = new \ZipArchive();
        $archive->open($archivePath, \ZipArchive::OVERWRITE);
        $archive->addFromString('[Content_Types].xml', '<Types/>');
        $archive->addFromString('word/document.xml', '<document/>');
        $archive->close();
        try {
            $validDocx = UploadedFile::fake()->createWithContent('lesson.docx', file_get_contents($archivePath));
            $this->post(route('contributor.submissions.store'), $this->payload($validDocx, 'Valid Office lesson'))->assertRedirect(route('contributor.submissions'));
        } finally {
            @unlink($archivePath);
        }
        $this->assertDatabaseHas('materials', ['title' => 'Valid Office lesson', 'original_filename' => 'lesson.docx']);
    }

    public function test_storage_failure_returns_a_validation_error_without_saving_a_material(): void
    {
        $owner = User::factory()->create(['role' => 'contributor']);
        Storage::shouldReceive('disk')->with('bayani_uploads')->andThrow(new \RuntimeException('disk unavailable'));
        $this->actingAs($owner)->from('/contributor/upload')->post(route('contributor.submissions.store'), $this->payload($this->png()))->assertSessionHasErrors('file');
        $this->assertDatabaseCount('materials', 0);
    }

    public function test_replacement_keeps_new_file_and_removes_old_file_after_success_and_removal_is_owner_only(): void
    {
        $owner = User::factory()->create(['role' => 'contributor']);
        $other = User::factory()->create(['role' => 'contributor']);
        $this->actingAs($owner)->post(route('contributor.submissions.store'), $this->payload($this->png('first.png')))->assertRedirect();
        $material = Material::firstOrFail();
        $material->update(['status' => 'Revision Requested']);
        $oldPath = $material->file_path;
        $this->actingAs($other)->delete(route('contributor.file.remove', $material))->assertForbidden();
        $this->actingAs($owner)->put(route('contributor.update', $material), $this->payload($this->png('replacement.png'), 'Uploaded lesson'))->assertRedirect(route('contributor.submissions'));
        $material->refresh();
        $this->assertSame('replacement.png', $material->original_filename);
        $this->assertNotSame($oldPath, $material->file_path);
        Storage::disk('bayani_uploads')->assertMissing($oldPath);
        Storage::disk('bayani_uploads')->assertExists($material->file_path);
        $this->actingAs($owner)->delete(route('contributor.file.remove', $material))->assertForbidden();
        $material->update(['status' => 'Revision Requested']);
        $this->actingAs($owner)->delete(route('contributor.file.remove', $material))->assertRedirect();
        $material->refresh();
        $this->assertNull($material->file_path);
        $this->assertNull($material->original_filename);
        $this->assertDatabaseCount('material_attachments',0);
        $this->assertSame([], Storage::disk('bayani_uploads')->allFiles());
    }

    public function test_replacing_an_approved_attachment_returns_it_to_pending_review(): void
    {
        $owner = User::factory()->create(['role' => 'contributor']);
        $this->actingAs($owner)->post(route('contributor.submissions.store'), $this->payload($this->png()))->assertRedirect();
        $material = Material::firstOrFail();
        $material->update(['status' => 'Approved', 'published_at' => now()]);
        $this->put(route('contributor.update', $material), $this->payload($this->png('revised.png')))->assertForbidden();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->put('/admin/materials/'.$material->id, $this->payload($this->png('admin-revision.png')))->assertRedirect(route('admin.materials'));
        $this->assertDatabaseHas('materials', ['id' => $material->id, 'status' => 'Pending', 'published_at' => null, 'original_filename' => 'admin-revision.png']);
    }

    public function test_subject_mapping_selection_is_curriculum_grade_and_track_scoped(): void
    {
        $owner = User::factory()->create(['role' => 'contributor']);
        $curriculum = \DB::table('curricula')->where('code', 'MATATAG')->first();
        $grade = \DB::table('grade_levels')->where('code', 'G2')->first();
        $math = \DB::table('subjects')->where('name', 'Mathematics')->first();
        $gmrc = \DB::table('subjects')->where('name', 'GMRC')->first();
        $payload = ['title' => 'Grade 2 lesson', 'description' => 'Grade matched content.', 'curriculum_id' => $curriculum->id, 'grade_levels' => [$grade->id], 'subjects' => [$math->id, $gmrc->id], 'intent' => 'draft'];
        $this->actingAs($owner)->post(route('contributor.submissions.store'), $payload)->assertRedirect(route('contributor.submissions'));
        $this->assertDatabaseHas('curriculum_subjects', ['curriculum_id' => $curriculum->id, 'subject_id' => $math->id, 'grade_level_id' => $grade->id, 'school_year' => '2025-2026', 'status' => 'active']);
        $this->assertDatabaseHas('curriculum_subjects', ['curriculum_id' => $curriculum->id, 'subject_id' => $gmrc->id, 'grade_level_id' => $grade->id, 'school_year' => '2025-2026', 'status' => 'active']);
        $this->assertDatabaseHas('curriculum_subjects', ['curriculum_id' => $curriculum->id, 'subject_id' => \DB::table('subjects')->where('name', 'Science')->value('id'), 'grade_level_id' => \DB::table('grade_levels')->where('code', 'G5')->value('id'), 'school_year' => '2025-2026', 'status' => 'active']);
        $this->assertDatabaseHas('curriculum_subjects', ['curriculum_id' => $curriculum->id, 'subject_id' => \DB::table('subjects')->where('name', 'Filipino')->value('id'), 'grade_level_id' => \DB::table('grade_levels')->where('code', 'G7')->value('id'), 'school_year' => '2024-2025', 'status' => 'active']);
        $legacyId = \DB::table('curricula')->where('code', 'K12-LEGACY')->value('id');
        $this->assertDatabaseHas('curriculum_subjects', ['curriculum_id' => $legacyId, 'subject_id' => \DB::table('subjects')->where('name', 'Araling Panlipunan')->value('id'), 'grade_level_id' => \DB::table('grade_levels')->where('code', 'G9')->value('id'), 'status' => 'active']);
        $this->assertDatabaseHas('curriculum_subjects', ['curriculum_id' => $legacyId, 'subject_id' => \DB::table('subjects')->where('name', 'Edukasyon sa Pagpapakatao (EsP)')->value('id'), 'grade_level_id' => \DB::table('grade_levels')->where('code', 'G2')->value('id'), 'status' => 'active']);
        $this->assertDatabaseHas('curriculum_subjects', ['curriculum_id' => $legacyId, 'subject_id' => \DB::table('subjects')->where('name', 'Mother Tongue')->value('id'), 'grade_level_id' => \DB::table('grade_levels')->where('code', 'G2')->value('id'), 'status' => 'active']);
        $this->from('/contributor/upload')->post(route('contributor.submissions.store'), [...$payload, 'grade_levels' => [\DB::table('grade_levels')->where('code', 'G3')->value('id')]])->assertSessionHasErrors('subjects');
    }

    public function test_subject_mapping_can_restrict_an_shs_subject_to_its_verified_strand(): void
    {
        $owner = User::factory()->create(['role' => 'contributor']);
        $legacyId = \DB::table('curricula')->where('code', 'K12-LEGACY')->value('id');
        $academic = \DB::table('tracks')->where('curriculum_id', $legacyId)->where('name', 'Academic')->first();
        $stem = \DB::table('strands')->where('track_id', $academic->id)->where('name', 'STEM')->first();
        $abm = \DB::table('strands')->where('track_id', $academic->id)->where('name', 'ABM')->first();
        $earthScience = \DB::table('subjects')->where('name', 'Earth Science')->value('id');
        $grade11 = \DB::table('grade_levels')->where('code', 'G11')->value('id');
        $payload = ['title' => 'STEM Earth Science', 'description' => 'Track-mapped material.', 'curriculum_id' => $legacyId, 'track_id' => $academic->id, 'strand_id' => $stem->id, 'grade_levels' => [$grade11], 'subjects' => [$earthScience], 'intent' => 'draft'];
        $this->actingAs($owner)->post(route('contributor.submissions.store'), $payload)->assertRedirect(route('contributor.submissions'));
        $this->from('/contributor/upload')->post(route('contributor.submissions.store'), [...$payload, 'title' => 'Wrong strand', 'strand_id' => $abm->id])->assertSessionHasErrors('subjects');
    }

    public function test_multiple_files_are_saved_as_private_attachments_and_individually_previewed_and_downloaded(): void
    {
        $owner = User::factory()->create(['role' => 'contributor']);
        $other = User::factory()->create(['role' => 'contributor']);
        $payload = ['title'=>'Filipino Grade 4 Learning Materials','description'=>'Three attached files.','intent'=>'submit','grade_levels'=>[\DB::table('grade_levels')->where('code','G2')->value('id')],'subjects'=>[\DB::table('subjects')->where('name','Mathematics')->value('id')],
            'attachments'=>[$this->png('lesson.png'),$this->png('activity.png'),$this->png('assessment.png')]];
        $this->actingAs($owner)->post(route('contributor.submissions.store'),$payload)->assertRedirect(route('contributor.submissions'));
        $material = Material::where('title',$payload['title'])->firstOrFail()->load('attachments');
        $this->assertSame(3,$material->attachments->count());
        $this->assertSame(1,$material->attachments->where('is_primary',true)->count());
        $this->assertSame($material->attachments->first()->stored_path,$material->file_path);
        foreach ($material->attachments as $attachment) {
            Storage::disk('bayani_uploads')->assertExists($attachment->stored_path);
            $this->actingAs($other)->get(route('materials.attachments.file',[$material,$attachment]))->assertNotFound();
            $this->actingAs($owner)->get(route('materials.attachments.file',[$material,$attachment]))->assertOk()->assertHeader('Content-Disposition','attachment; filename="'.$attachment->original_filename.'"');
            $this->actingAs($owner)->get(route('materials.attachments.preview',[$material,$attachment]))->assertOk()->assertHeader('Content-Type','image/png')->assertHeader('Content-Disposition','inline; filename="'.$attachment->original_filename.'"');
        }
        $this->assertDatabaseCount('material_attachments',3);
        $this->get(route('contributor.detail',$material))->assertOk()->assertViewHas('backend.material.attachments',fn ($rows)=>count($rows)===3);
        $admin=User::factory()->create(['role'=>'admin']);
        $this->actingAs($admin)->get(route('admin.review',$material))->assertOk()->assertViewHas('backend.material.attachments',fn ($rows)=>count($rows)===3);
        $material->update(['status'=>'Approved','published_at'=>now()]);
        auth()->logout();
        $this->get(route('material',$material))->assertOk()->assertViewHas('backend.material.attachments',fn ($rows)=>count($rows)===3);
        foreach($material->attachments as $attachment){
            $this->get(route('materials.attachments.file',[$material,$attachment]))->assertOk();
            $this->get(route('materials.attachments.preview',[$material,$attachment]))->assertOk();
        }
    }

    public function test_edit_adds_attachments_without_replacing_existing_and_primary_and_order_are_individual(): void
    {
        $owner = User::factory()->create(['role'=>'contributor']);
        $this->actingAs($owner)->post(route('contributor.submissions.store'),$this->payload($this->png('first.png')))->assertRedirect();
        $material = Material::firstOrFail();
        $material->update(['status'=>'Revision Requested']);
        $first = $material->attachments()->firstOrFail();
        $this->actingAs($owner)->put(route('contributor.update',$material),[
            'title'=>$material->title,'description'=>$material->description,'intent'=>'submit','grade_levels'=>[\DB::table('grade_levels')->where('code','G2')->value('id')],'subjects'=>[\DB::table('subjects')->where('name','Mathematics')->value('id')],'attachments'=>[$this->png('second.png')],
        ])->assertRedirect(route('contributor.submissions'));
        $this->assertSame(2,$material->attachments()->count());
        Storage::disk('bayani_uploads')->assertExists($first->stored_path);
        $second = $material->attachments()->where('original_filename','second.png')->firstOrFail();
        \DB::table('materials')->where('id',$material->id)->update(['status'=>'Revision Requested']);
        $this->assertSame('Revision Requested',$material->fresh()->status);
        $this->assertSame((int)$owner->id,(int)$material->contributor_id);
        $this->actingAs($owner)->patch(route('contributor.attachments.update',[$material,$second]),['is_primary'=>true,'display_order'=>0])->assertRedirect();
        $material->refresh();
        $this->assertSame($second->id,$material->attachments()->where('is_primary',true)->value('id'));
        $this->assertSame($second->stored_path,$material->file_path);
        $this->actingAs($owner)->delete(route('contributor.attachments.remove',[$material,$first]))->assertRedirect();
        $this->assertDatabaseMissing('material_attachments',['id'=>$first->id]);
        Storage::disk('bayani_uploads')->assertMissing($first->stored_path);
        Storage::disk('bayani_uploads')->assertExists($second->stored_path);
    }

    public function test_multiple_file_validation_is_per_file_and_rejects_oversized_combined_payload(): void
    {
        $owner = User::factory()->create(['role'=>'contributor']);
        config(['app.bayani_max_upload_mb'=>1]);
        $payload=['title'=>'Batch','description'=>'Batch validation','intent'=>'submit','grade_levels'=>[\DB::table('grade_levels')->where('code','G2')->value('id')],'subjects'=>[\DB::table('subjects')->where('name','Mathematics')->value('id')],'attachments'=>[
            UploadedFile::fake()->image('valid.png',20,20), UploadedFile::fake()->createWithContent('unsafe.php','<?php echo 1;'),
        ]];
        $this->actingAs($owner)->from('/contributor/upload')->post(route('contributor.submissions.store'),$payload)->assertSessionHasErrors('attachments.1');
        config(['app.bayani_max_upload_mb'=>60]);
        $oversized=['title'=>'Batch','description'=>'Batch validation','intent'=>'submit','grade_levels'=>[\DB::table('grade_levels')->where('code','G2')->value('id')],'subjects'=>[\DB::table('subjects')->where('name','Mathematics')->value('id')],'attachments'=>[
            UploadedFile::fake()->image('one.png',20,20)->size(45000), UploadedFile::fake()->image('two.png',20,20)->size(45000),
        ]];
        $this->actingAs($owner)->from('/contributor/upload')->post(route('contributor.submissions.store'),$oversized)->assertSessionHasErrors('attachments');
        $this->assertDatabaseCount('materials',0);
        $this->assertDatabaseCount('material_attachments',0);
        $this->assertSame([],Storage::disk('bayani_uploads')->allFiles());
    }
}
