<?php

namespace Tests\Feature;

use App\Models\Material;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Database\Seeders\ReferenceDataSeeder;
use Tests\TestCase;

class EducationalRepositoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_baseline_project_categories_are_repeatable_and_not_duplicated(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(ReferenceDataSeeder::class);

        $this->assertSame(4, DB::table('categories')->count());
        $this->assertSame(4, DB::table('categories')->where('status', 'active')->count());
        $this->assertDatabaseHas('categories', [
            'name' => 'Teaching and Planning',
            'description' => 'Project BAYANI organizational grouping; use Material Type for the specific resource format.',
            'status' => 'active',
        ]);
    }

    public function test_admin_can_manage_subjects_curricula_and_grade_mappings_only(): void
    {
        $admin=User::factory()->create(['role'=>'admin']); $contributor=User::factory()->create(['role'=>'contributor']);
        $this->actingAs($contributor)->post('/admin/education/subjects',['name'=>'Science'])->assertForbidden();
        $this->actingAs($admin);
        $subject=DB::table('subjects')->where('name','Mathematics')->first();
        $this->post('/admin/education/subjects',['name'=>'Media Arts and Digital Literacy','code'=>'MEDIA-ARTS'])->assertRedirect();
        $this->assertDatabaseHas('subjects',['name'=>'Media Arts and Digital Literacy','status'=>'inactive']);
        $this->post('/admin/education/curricula',['name'=>'Test MATATAG','code'=>'TEST-MATATAG','source_url'=>'https://www.deped.gov.ph/'])->assertRedirect();
        $curriculum=DB::table('curricula')->where('code','TEST-MATATAG')->first(); $grade=DB::table('grade_levels')->where('code','G2')->first();
        $this->post('/admin/education/mappings',['curriculum_id'=>$curriculum->id,'subject_id'=>$subject->id,'grade_level_id'=>$grade->id,'classification'=>'learning area'])->assertRedirect();
        $this->from('/admin/education/mappings')->post('/admin/education/mappings',['curriculum_id'=>$curriculum->id,'subject_id'=>$subject->id,'grade_level_id'=>$grade->id])->assertSessionHasErrors('curriculum_id');
        $this->put('/admin/education/subjects/'.$subject->id,['name'=>'Mathematics','code'=>$subject->code,'status'=>'active'])->assertRedirect();
        $this->assertDatabaseHas('curriculum_subjects',['curriculum_id'=>$curriculum->id,'subject_id'=>$subject->id,'grade_level_id'=>$grade->id]);
        $this->get('/admin/education/mappings')->assertOk()->assertSee('Mathematics')->assertSee('Grade 2');
        $this->actingAs($contributor)->get('/admin/education/subjects')->assertForbidden();
    }

    public function test_multisubject_multigrade_resource_has_optional_local_history_and_filters_publicly(): void
    {
        $user=User::factory()->create(['role'=>'contributor']);
        $curriculum=DB::table('curricula')->where('code','MATATAG')->first();
        $subjectA=DB::table('subjects')->where('name','Reading and Literacy')->value('id'); DB::table('subjects')->where('id',$subjectA)->update(['status'=>'active']);
        $subjectB=DB::table('subjects')->where('name','Makabansa')->value('id'); DB::table('subjects')->where('id',$subjectB)->update(['status'=>'active']);
        $g1=DB::table('grade_levels')->where('code','G1')->first(); $g2=DB::table('grade_levels')->where('code','G2')->first();
        foreach([$subjectA,$subjectB] as $subject) foreach([$g1,$g2] as $grade) DB::table('curriculum_subjects')->insert(['curriculum_id'=>$curriculum->id,'subject_id'=>$subject,'grade_level_id'=>$grade->id,'created_at'=>now(),'updated_at'=>now()]);
        $type=DB::table('material_types')->where('name','Worksheet')->first();
        $payload=['title'=>'Early literacy worksheet','description'=>'A cross-grade reading resource','curriculum_id'=>$curriculum->id,'material_type_id'=>$type->id,'subjects'=>[$subjectA,$subjectB],'grade_levels'=>[$g1->id,$g2->id],'topic'=>'Reading fluency','keywords'=>'reading, literacy','intent'=>'draft'];
        $this->actingAs($user)->post('/contributor/submissions',$payload)->assertRedirect('/contributor/submissions');
        $material=Material::where('title','Early literacy worksheet')->firstOrFail();
        $this->assertCount(2,$material->subjects); $this->assertCount(2,$material->gradeLevels);
        $material->update(['status'=>'Approved','published_at'=>now()]);
        $this->get('/repository?subject='.$subjectA.'&grade='.$g2->id.'&curriculum='.$curriculum->id.'&material_type='.$type->id)->assertOk()->assertSee('Early literacy worksheet')->assertSee('Reading and Literacy');
        $this->get('/repository?subject=999999')->assertOk()->assertDontSee('Early literacy worksheet');
    }

    public function test_curriculum_grade_subject_mismatch_and_shs_without_track_are_rejected(): void
    {
        $user=User::factory()->create(['role'=>'contributor']); $curriculum=DB::table('curricula')->where('code','MATATAG')->first();
        $subject=DB::table('subjects')->insertGetId(['name'=>'Unmapped subject','created_at'=>now(),'updated_at'=>now()]);
        $g2=DB::table('grade_levels')->where('code','G2')->first();
        $this->actingAs($user)->from('/contributor/upload')->post('/contributor/submissions',['title'=>'Invalid mapping','description'=>'Test','curriculum_id'=>$curriculum->id,'subjects'=>[$subject],'grade_levels'=>[$g2->id],'intent'=>'draft'])->assertSessionHasErrors('subjects');
        $shs=DB::table('grade_levels')->where('code','G11')->first();
        $this->from('/contributor/upload')->post('/contributor/submissions',['title'=>'SHS resource','description'=>'Test','grade_levels'=>[$shs->id],'intent'=>'draft'])->assertSessionHasErrors('track_id');
        $this->assertDatabaseCount('materials',0);
    }

    public function test_shs_track_strand_and_material_type_are_saved_and_scoped_to_curriculum(): void
    {
        $admin=User::factory()->create(['role'=>'admin']);
        $this->actingAs($admin)->post('/admin/education/curricula',['name'=>'Legacy SHS test','code'=>'LEGACY-TEST'])->assertRedirect();
        $curriculum=DB::table('curricula')->where('code','LEGACY-TEST')->first();
        $this->post('/admin/education/tracks',['curriculum_id'=>$curriculum->id,'name'=>'TVL','code'=>'TVL'])->assertRedirect();
        $track=DB::table('tracks')->where('name','TVL')->first();
        $this->post('/admin/education/strands',['track_id'=>$track->id,'name'=>'ICT','code'=>'ICT'])->assertRedirect();
        $this->post('/admin/education/material-types',['name'=>'Interactive activity','description'=>'A managed resource format'])->assertRedirect();
        $strand=DB::table('strands')->where('track_id',$track->id)->first(); $type=DB::table('material_types')->where('name','Interactive activity')->first();
        $contributor=User::factory()->create(['role'=>'contributor']); $g11=DB::table('grade_levels')->where('code','G11')->first();
        $payload=['title'=>'SHS ICT activity','description'=>'A track resource','curriculum_id'=>$curriculum->id,'track_id'=>$track->id,'strand_id'=>$strand->id,'material_type_id'=>$type->id,'grade_levels'=>[$g11->id],'intent'=>'draft'];
        $this->actingAs($contributor)->post('/contributor/submissions',$payload)->assertRedirect('/contributor/submissions');
        $this->assertDatabaseHas('materials',['title'=>'SHS ICT activity','track_id'=>$track->id,'strand_id'=>$strand->id,'material_type_id'=>$type->id]);
        $otherTrack=DB::table('tracks')->insertGetId(['curriculum_id'=>$curriculum->id,'name'=>'ABM','created_at'=>now(),'updated_at'=>now()]);
        $this->from('/contributor/upload')->post('/contributor/submissions',[...$payload,'title'=>'Invalid strand','track_id'=>$otherTrack])->assertSessionHasErrors('strand_id');
    }

    public function test_new_catalog_pivots_reject_invalid_foreign_keys(): void
    {
        try {
            DB::table('curriculum_subjects')->insert(['curriculum_id'=>999999,'subject_id'=>999999,'grade_level_id'=>999999,'created_at'=>now(),'updated_at'=>now()]);
            $this->fail('The curriculum mapping accepted missing related rows.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
    }
}
