<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class EducationCatalogController extends Controller
{
    private const CATALOGS = [
        'curricula'=>['curricula','Curricula'], 'subjects'=>['subjects','Subjects'], 'grade-levels'=>['grade_levels','Grade levels'],
        'mappings'=>['curriculum_subjects','Curriculum subject mappings'], 'tracks'=>['tracks','Tracks'],
        'strands'=>['strands','Strands'], 'material-types'=>['material_types','Material types'],
    ];
    public function index(string $catalog)
    {
        [$table,$label]=$this->catalog($catalog); $query=DB::table($table)->orderBy($table.'.id');
        if ($catalog==='mappings') $query->join('curricula','curriculum_subjects.curriculum_id','=','curricula.id')->join('subjects','curriculum_subjects.subject_id','=','subjects.id')->join('grade_levels','curriculum_subjects.grade_level_id','=','grade_levels.id')->leftJoin('tracks as mapping_tracks','curriculum_subjects.track_id','=','mapping_tracks.id')->leftJoin('strands as mapping_strands','curriculum_subjects.strand_id','=','mapping_strands.id')->select('curriculum_subjects.*','curricula.name as curriculum_name','subjects.name as subject_name','grade_levels.name as grade_level_name','mapping_tracks.name as track_name','mapping_strands.name as strand_name');
        if ($catalog==='tracks') $query->leftJoin('curricula','tracks.curriculum_id','=','curricula.id')->select('tracks.*','curricula.name as curriculum_name');
        if ($catalog==='strands') $query->join('tracks','strands.track_id','=','tracks.id')->select('strands.*','tracks.name as track_name');
        return view('bayani',['page'=>'education-'.$catalog,'backend'=>['kind'=>'education-catalog','catalog'=>$catalog,'heading'=>$label,'records'=>$query->paginate(30),'curricula'=>DB::table('curricula')->orderBy('name')->get(),'subjects'=>DB::table('subjects')->orderBy('name')->get(),'grade_levels'=>DB::table('grade_levels')->orderBy('sequence')->get(),'tracks'=>DB::table('tracks')->orderBy('name')->get(),'strands'=>DB::table('strands')->orderBy('name')->get()]]);
    }
    public function store(Request $request,string $catalog) { $this->save($request,$catalog); return back()->with('status','Education catalog record saved.'); }
    public function update(Request $request,string $catalog,int $record) { $this->save($request,$catalog,$record); return back()->with('status','Education catalog record updated.'); }
    public function destroy(string $catalog,int $record)
    {
        [$table]=$this->catalog($catalog); $row=DB::table($table)->where('id',$record)->first(); abort_unless($row,404);
        if ($catalog==='subjects' && (DB::table('material_subject')->where('subject_id',$record)->exists() || DB::table('curriculum_subjects')->where('subject_id',$record)->exists())) abort(409,'Subject is linked to resources or curriculum mappings; deactivate it instead.');
        if ($catalog==='curricula' && (DB::table('materials')->where('curriculum_id',$record)->exists() || DB::table('curriculum_subjects')->where('curriculum_id',$record)->exists() || DB::table('tracks')->where('curriculum_id',$record)->exists())) abort(409,'Curriculum is in use; deactivate it instead.');
        if ($catalog==='grade-levels' && (DB::table('grade_level_material')->where('grade_level_id',$record)->exists() || DB::table('curriculum_subjects')->where('grade_level_id',$record)->exists())) abort(409,'Grade level is in use; keep it for historical resource compatibility.');
        if ($catalog==='tracks' && (DB::table('materials')->where('track_id',$record)->exists() || DB::table('strands')->where('track_id',$record)->exists())) abort(409,'Track is in use; deactivate it instead.');
        if ($catalog==='strands' && DB::table('materials')->where('strand_id',$record)->exists()) abort(409,'Strand is in use; deactivate it instead.');
        if ($catalog==='material-types' && DB::table('materials')->where('material_type_id',$record)->exists()) abort(409,'Material type is in use; deactivate it instead.');
        DB::table($table)->where('id',$record)->delete(); return back()->with('status','Unused catalog record deleted.');
    }
    private function save(Request $request,string $catalog,?int $id=null): void
    {
        [$table]=$this->catalog($catalog); $unique=fn($col)=>Rule::unique($table,$col)->ignore($id);
        $rules=match($catalog) {
            'curricula'=>['name'=>['required','string','max:255',$unique('name')],'code'=>['required','string','max:80',$unique('code')],'description'=>['nullable','string','max:5000'],'source_url'=>['nullable','url','max:2048'],'status'=>['sometimes',Rule::in(['active','inactive'])]],
            'subjects'=>['name'=>['required','string','max:255',$unique('name')],'code'=>['nullable','string','max:80',$unique('code')],'description'=>['nullable','string','max:5000'],'status'=>['sometimes',Rule::in(['active','inactive'])]],
            'grade-levels'=>['name'=>['required','string','max:100',$unique('name')],'code'=>['required','string','max:20',$unique('code')],'sequence'=>['required','integer','between:1,99',$unique('sequence')],'stage'=>['required',Rule::in(['kindergarten','elementary','junior_high','senior_high'])]],
            'mappings'=>['curriculum_id'=>['required','exists:curricula,id'],'subject_id'=>['required','exists:subjects,id'],'grade_level_id'=>['required','exists:grade_levels,id'],'track_id'=>['nullable','exists:tracks,id'],'strand_id'=>['nullable','exists:strands,id'],'classification'=>['nullable','string','max:40'],'school_year'=>['nullable','string','max:20'],'status'=>['sometimes',Rule::in(['active','inactive'])]],
            'tracks'=>['curriculum_id'=>['nullable','exists:curricula,id'],'name'=>['required','string','max:255'],'code'=>['nullable','string','max:80'],'status'=>['sometimes',Rule::in(['active','inactive'])]],
            'strands'=>['track_id'=>['required','exists:tracks,id'],'name'=>['required','string','max:255'],'code'=>['nullable','string','max:80'],'status'=>['sometimes',Rule::in(['active','inactive'])]],
            'material-types'=>['name'=>['required','string','max:255',$unique('name')],'description'=>['nullable','string','max:5000'],'status'=>['sometimes',Rule::in(['active','inactive'])]],
        };
        $data=$request->validate($rules);
        if ($catalog==='mappings') {
            if (!empty($data['strand_id']) && (!isset($data['track_id']) || !DB::table('strands')->where('id',$data['strand_id'])->where('track_id',$data['track_id'])->exists())) abort(422,'The strand must belong to the selected track.');
            if (!empty($data['track_id']) && DB::table('tracks')->where('id',$data['track_id'])->where(fn($q)=>$q->whereNull('curriculum_id')->orWhere('curriculum_id',$data['curriculum_id']))->doesntExist()) abort(422,'The track must belong to the selected curriculum.');
            $duplicate=DB::table('curriculum_subjects')->where('curriculum_id',$data['curriculum_id'])->where('subject_id',$data['subject_id'])->where('grade_level_id',$data['grade_level_id'])->where('track_id',$data['track_id']??null)->where('strand_id',$data['strand_id']??null)->when($id,fn($q)=>$q->where('id','!=',$id))->exists();
            if ($duplicate) throw \Illuminate\Validation\ValidationException::withMessages(['curriculum_id'=>'This curriculum, subject, grade, track, and strand mapping already exists.']);
        }
        if ($catalog==='subjects') $data['status']=$data['status']??($id?DB::table('subjects')->where('id',$id)->value('status'):'inactive');
        if ($catalog==='subjects' && $data['status']==='active' && (!$id || !DB::table('curriculum_subjects')->where('subject_id',$id)->where('status','active')->exists())) abort(422,'Map this subject to a verified curriculum and grade before activating it.');
        if ($catalog==='tracks') $data['curriculum_id']=$data['curriculum_id']?:null;
        if (($data['status']??null)===null) unset($data['status']);
        if ($id) DB::table($table)->where('id',$id)->update([...$data,'updated_at'=>now()]); else DB::table($table)->insert([...$data,'created_at'=>now(),'updated_at'=>now()]);
    }
    private function catalog(string $name): array { abort_unless(isset(self::CATALOGS[$name]),404); return self::CATALOGS[$name]; }
}
