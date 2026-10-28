<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Curriculum;
use App\Models\GradeLevel;
use App\Models\MaterialType;
use App\Models\Subject;
use App\Models\Track;
use App\Models\Strand;
use App\Models\Material;
use App\Models\MaterialAttachment;
use App\Models\User;
use App\Notifications\MaterialWorkflowNotification;
use App\Services\MaterialThumbnailService;
use App\Services\OpenXmlPreviewService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MaterialController extends Controller
{
    public function home()
    {
        $approved = Material::where('status', 'Approved');
        return view('bayani', ['page' => 'home', 'backend' => ['kind' => 'home', 'featured' => (clone $approved)->withCount('attachments')->with(['category:id,name', 'contributor:id,name','curriculum:id,name','materialType:id,name','subjects:id,name','gradeLevels:id,name','track:id,name','strand:id,name'])->latest('published_at')->limit(3)->get(), 'stats' => [
            'approved' => (clone $approved)->count(), 'subjects' => Subject::where('status', 'active')->count(),
            'curricula' => Curriculum::where('status', 'active')->count(), 'material_types' => MaterialType::where('status', 'active')->count(),
        ]]]);
    }

    public function repository(Request $request)
    {
        $materials = Material::query()->where('status', 'Approved')->withCount('attachments')->with(['category:id,name', 'contributor:id,name','curriculum:id,name','materialType:id,name','subjects:id,name','gradeLevels:id,name','track:id,name','strand:id,name']);
        if ($request->user()) $materials->withExists(['favoritedBy as is_favorited' => fn ($q) => $q->where('users.id', $request->user()->id)]);
        $this->filters($materials, $request);
        $sort = $request->query('sort', 'newest');
        match ($sort) {
            'title' => $materials->orderBy('title'),
            'oldest' => $materials->orderBy('published_at')->orderBy('id'),
            default => $materials->orderByDesc('published_at')->orderByDesc('id'),
        };
        $contributors = User::whereHas('materials', fn ($q) => $q->where('status', 'Approved'))->orderBy('name')->get(['id','name']);
        $data = [
            'materials' => $materials->paginate(9)->withQueryString(),
            'categories' => Category::where('status', 'active')->orderBy('name')->get(),
            ...$this->educationReferences(),
            'contributors' => $contributors,
            'favorite_ids' => $request->user()?->favorites()->pluck('materials.id')->map(fn ($id) => (int) $id)->all() ?? [],
            'filter_values' => $request->only(['q','subject','curriculum','grade','material_type','track','strand','category','contributor','sort']),
        ];
        return view('bayani', ['page' => 'repository', 'backend' => ['kind' => 'repository', ...$data]]);
    }

    public function show(Material $material)
    {
        abort_unless($material->status === 'Approved', 404);
        $material->load(['category:id,name', 'contributor:id,name','curriculum:id,name','materialType:id,name','subjects:id,name','gradeLevels:id,name','track:id,name','strand:id,name','attachments']);
        if (request()->user()) $material->loadExists(['favoritedBy as is_favorited' => fn ($q) => $q->where('users.id', request()->user()->id)]);
        return view('bayani', ['page' => 'material', 'itemId' => $material->id, 'backend' => ['kind' => 'material', 'material' => $material]]);
    }

    public function preview(Request $request, Material $material, OpenXmlPreviewService $documents)
    {
        if ($request->boolean('stream')) return $this->serveFile($request, $material, true);
        [, , $sourcePath, $extension] = $this->readableAttachment($request, $material);
        if (in_array($extension, ['xlsx', 'docx', 'pdf'], true)) {
            if ($extension === 'pdf') {
                $material->loadMissing(['category:id,name','contributor:id,name','curriculum:id,name','materialType:id,name','subjects:id,name','gradeLevels:id,name','track:id,name','strand:id,name']);
                return view('bayani',['page'=>'file-preview','itemId'=>$material->id,'backend'=>['kind'=>'file-preview','material'=>$material,'format'=>'pdf','preview_data'=>[],'preview_error'=>null,'preview_url'=>route('preview',[$material,'stream'=>1]),'download_url'=>route('materials.file',$material)]]);
            }
            try {
                $data = $extension === 'xlsx' ? $documents->spreadsheet($sourcePath) : $documents->document($sourcePath);
                $message = null;
            } catch (\Throwable) {
                $data = [];
                $message = 'This file could not be rendered safely. You can download the original attachment.';
            }

            $material->loadMissing(['category:id,name', 'contributor:id,name', 'curriculum:id,name', 'materialType:id,name', 'subjects:id,name', 'gradeLevels:id,name', 'track:id,name', 'strand:id,name']);
            return view('bayani', [
                'page' => 'file-preview',
                'itemId' => $material->id,
                'backend' => [
                    'kind' => 'file-preview',
                    'material' => $material,
                    'format' => $extension,
                    'preview_data' => $data,
                    'preview_error' => $message,
                    'preview_url' => route('preview', $material),
                    'download_url' => route('materials.file', $material),
                ],
            ]);
        }

        return $this->serveFile($request, $material, true);
    }

    public function previewAttachment(Request $request, Material $material, MaterialAttachment $attachment, OpenXmlPreviewService $documents)
    {
        $source = $this->attachmentSource($request, $material, $attachment);
        if ($request->boolean('stream')) return $this->serveFile($request, $this->asMaterial($material,$source), true);
        return $this->previewSource($request, $material, $source, $documents, true);
    }

    private function previewSource(Request $request, Material $material, Material|MaterialAttachment $source, OpenXmlPreviewService $documents, bool $individual = false)
    {
        [, , $sourcePath, $extension] = $this->readableAttachment($request, $material, $source instanceof MaterialAttachment ? $source : null);
        if (in_array($extension, ['jpg','jpeg','png','webp'], true)) return $this->serveFile($request, $this->asMaterial($material, $source), true);
        if ($extension === 'pdf') {
            $viewMaterial=$this->asMaterial($material,$source);
            return view('bayani',['page'=>'file-preview','itemId'=>$material->id,'backend'=>['kind'=>'file-preview','material'=>$viewMaterial,'format'=>'pdf','preview_data'=>[],'preview_error'=>null,'preview_url'=>route('materials.attachments.preview',[$material,$source,'stream'=>1]),'download_url'=>route('materials.attachments.file',[$material,$source])]]);
        }
        if (in_array($extension, ['mp4','webm','mp3','wav','ogg'], true)) {
            $viewMaterial = $this->asMaterial($material, $source);
            return view('bayani', ['page'=>'file-preview','itemId'=>$material->id,'backend'=>[
                'kind'=>'file-preview','material'=>$viewMaterial,'format'=>$extension,'preview_data'=>[],
                'preview_error'=>null,'preview_url'=>$individual ? route('materials.attachments.preview',[$material,$source,'stream'=>1]) : route('preview',[$material,'stream'=>1]),
                'download_url'=>$individual ? route('materials.attachments.file',[$material,$source]) : route('materials.file',$material),
            ]]);
        }
        if (!in_array($extension, ['xlsx', 'docx'], true)) {
            $viewMaterial = $this->asMaterial($material, $source);
            return view('bayani', ['page'=>'file-preview','itemId'=>$material->id,'backend'=>[
                'kind'=>'file-preview','material'=>$viewMaterial,'format'=>$extension,'preview_data'=>[],
                'preview_error'=>null,'preview_url'=>route('materials.attachments.preview',[$material,$source]),
                'download_url'=>route('materials.attachments.file',[$material,$source]),
            ]]);
        }
        try {
            $data = $extension === 'xlsx' ? $documents->spreadsheet($sourcePath) : $documents->document($sourcePath);
            $message = null;
        } catch (\Throwable) {
            $data = [];
            $message = 'This file could not be rendered safely. You can download the original attachment.';
        }
        $viewMaterial = $this->asMaterial($material, $source);
        $viewMaterial->loadMissing(['category:id,name', 'contributor:id,name', 'curriculum:id,name', 'materialType:id,name', 'subjects:id,name', 'gradeLevels:id,name', 'track:id,name', 'strand:id,name']);
        return view('bayani', [
            'page' => 'file-preview', 'itemId' => $material->id,
            'backend' => ['kind' => 'file-preview', 'material' => $viewMaterial, 'format' => $extension,
                'preview_data' => $data, 'preview_error' => $message,
                'preview_url' => $individual ? route('materials.attachments.preview', [$material, $source]) : route('preview', $material),
                'download_url' => $individual ? route('materials.attachments.file', [$material, $source]) : route('materials.file', $material)],
        ]);
    }

    private function asMaterial(Material $material, Material|MaterialAttachment $source): Material
    {
        $copy = clone $material;
        if ($source instanceof MaterialAttachment) $copy->forceFill([
            'file_path' => $source->stored_path, 'file_disk' => $source->storage_disk,
            'original_filename' => $source->original_filename, 'file_type' => $source->mime_type,
            'file_size' => $source->file_size, 'file_sha256' => $source->sha256,
            'thumbnail_path' => $source->thumbnail_path,
        ]);
        return $copy;
    }

    public function thumbnailAttachment(Request $request, Material $material, MaterialAttachment $attachment, MaterialThumbnailService $thumbnails)
    {
        $this->attachmentSource($request, $material, $attachment);
        return $this->thumbnailResponse($request, $material, $attachment, $thumbnails);
    }

    public function cacheMaterialThumbnail(Request $request, Material $material, MaterialThumbnailService $thumbnails)
    {
        $this->readableAttachment($request, $material);
        $this->authorizeThumbnailCache($request, $material);
        return $this->storeOfficeThumbnail($request, $material, false, $thumbnails);
    }

    public function cacheAttachmentThumbnail(Request $request, Material $material, MaterialAttachment $attachment, MaterialThumbnailService $thumbnails)
    {
        $this->attachmentSource($request, $material, $attachment);
        $this->authorizeThumbnailCache($request, $material);
        return $this->storeOfficeThumbnail($request, $attachment, true, $thumbnails);
    }

    private function authorizeThumbnailCache(Request $request, Material $material): void
    {
        $user = $request->user();
        abort_unless($user && ($user->role === 'admin' || ($user->role === 'contributor' && (int) $user->id === (int) $material->contributor_id)), 403);
    }

    private function storeOfficeThumbnail(Request $request, Material|MaterialAttachment $source, bool $attachment, MaterialThumbnailService $thumbnails)
    {
        $request->validate(['thumbnail' => ['required', 'file', 'max:512']]);
        $file = $request->file('thumbnail');
        $stored = $thumbnails->storeOfficeCache($source, $attachment, (string) file_get_contents($file->getRealPath()), (string) $file->getMimeType());
        return response()->json(['cached' => true, 'mime' => $stored['mime']], 201, ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function thumbnailResponse(Request $request, Material $material, Material|MaterialAttachment $source, MaterialThumbnailService $thumbnails)
    {
        $this->readableAttachment($request, $material, $source instanceof MaterialAttachment ? $source : null);
        $extension = strtolower(pathinfo($source->original_filename ?: ($source instanceof MaterialAttachment ? $source->stored_path : $source->file_path), PATHINFO_EXTENSION));
        try {
            $thumbnail = $thumbnails->generate($source);
            if ($thumbnail === null) return response($this->thumbnailNoticeSvg($this->thumbnailUnavailableMessage($extension)), 200, ['Content-Type' => 'image/svg+xml; charset=UTF-8', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
            $disk = $source instanceof MaterialAttachment ? $source->storage_disk : ($source->file_disk ?: 'bayani_uploads');
            $body = Storage::disk($disk)->get($thumbnail['path']);
            return response($body, 200, ['Content-Type' => $thumbnail['mime'], 'Content-Disposition' => 'inline; filename="thumbnail.'.$extension.'"', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=3600', 'ETag' => '"'.hash('sha256', $thumbnail['path']).'"']);
        } catch (\Throwable $exception) {
            report($exception);
            return response($this->thumbnailNoticeSvg('A content thumbnail is unavailable for this file.'), 200, ['Content-Type' => 'image/svg+xml; charset=UTF-8', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
        }
    }

    public function downloadAttachment(Request $request, Material $material, MaterialAttachment $attachment): StreamedResponse|BinaryFileResponse
    {
        $this->attachmentSource($request, $material, $attachment);
        return $this->serveFile($request, $this->asMaterial($material, $attachment), false);
    }

    private function attachmentSource(Request $request, Material $material, MaterialAttachment $attachment): MaterialAttachment
    {
        abort_unless((int) $attachment->material_id === (int) $material->id, 404);
        $this->readableAttachment($request, $material, $attachment);
        return $attachment;
    }

    public function thumbnail(Request $request, Material $material, MaterialThumbnailService $thumbnails)
    {
        [$disk, $storage, , $extension] = $this->readableAttachment($request, $material);
        try {
            $thumbnail = $thumbnails->generate($material);
            if ($thumbnail === null) {
                return response($this->thumbnailNoticeSvg($this->thumbnailUnavailableMessage($extension)), 200, [
                    'Content-Type' => 'image/svg+xml; charset=UTF-8',
                    'Content-Disposition' => 'inline; filename="preview.svg"',
                    'X-Content-Type-Options' => 'nosniff',
                    'Cache-Control' => 'private, no-store',
                ]);
            }

            $body = $thumbnail['source']
                ? $storage->get($thumbnail['path'])
                : $storage->get($thumbnail['path']);
            return response($body, 200, [
                'Content-Type' => $thumbnail['mime'],
                'Content-Disposition' => 'inline; filename="thumbnail.'.$extension.'"',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, max-age=3600',
                'ETag' => '"'.hash('sha256', $thumbnail['path']).'"',
            ]);
        } catch (\Throwable $exception) {
            report($exception);
            $message = match ($extension) {
                'pdf' => 'The first PDF page could not be rendered. The source file may be corrupt or exceed rendering limits.',
                'xlsx' => 'No readable worksheet snapshot could be rendered. The workbook may be corrupt or exceed preview limits.',
                'docx' => 'No readable document snapshot could be rendered. The document may be corrupt or exceed preview limits.',
                default => 'A content thumbnail is unavailable for this file format.',
            };
            return response($this->thumbnailNoticeSvg($message), 200, [
                'Content-Type' => 'image/svg+xml; charset=UTF-8',
                'Content-Disposition' => 'inline; filename="preview-unavailable.svg"',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store',
            ]);
        }
    }

    public function download(Request $request, Material $material): StreamedResponse|BinaryFileResponse
    {
        return $this->serveFile($request, $material, false);
    }

    public function removeFile(Request $request, Material $material): RedirectResponse
    {
        $primary = $material->attachments()->where('is_primary', true)->first();
        if ($primary) return $this->removeAttachment($request, $material, $primary);
        $this->authorizeMaterial($request, $material, true);
        abort_unless($material->file_path, 404);
        $oldPath = $material->file_path;
        $oldThumbnailPath = $material->thumbnail_path;
        $disk = $material->file_disk ?: 'bayani_uploads';
        $previousStatus = $material->status;
        DB::transaction(function () use ($material): void {
            $material->update(['file_path' => null, 'file_disk' => null, 'original_filename' => null, 'file_type' => null, 'file_size' => null, 'file_sha256' => null, 'thumbnail_path' => null,
                ...($material->status === 'Approved' ? ['status' => 'Pending', 'published_at' => null, 'submitted_at' => now()] : [])]);
        });
        if (!Material::withTrashed()->where('file_disk', $disk)->where('file_path', $oldPath)->exists() && $this->isSafeStoredPath($oldPath)) Storage::disk($disk)->delete($oldPath);
        $this->deleteUnreferencedThumbnail($oldThumbnailPath, $disk);
        ActivityLog::create(['user_id' => $request->user()->id, 'action' => 'material.file_removed', 'subject_type' => Material::class, 'subject_id' => $material->id, 'details' => ['previous_status' => $previousStatus, 'new_status' => $material->fresh()->status, 're_review_required' => $previousStatus === 'Approved']]);
        return back()->with('status', 'Attachment removed.');
    }

    public function addAttachments(Request $request, Material $material): RedirectResponse
    {
        $this->authorizeMaterial($request, $material, true);
        if ($material->status === 'Pending' && $request->user()->role !== 'admin') abort(403);
        if ($material->status === 'Approved' && $request->user()->role !== 'admin') abort(403);
        $files = $this->validateAttachmentBatch($request);
        if (!$files) return back()->withErrors(['attachments' => 'Choose at least one file.']);
        $stored = $this->storeUploads($files);
        try {
            DB::transaction(function () use ($material, $stored): void {
                $locked = Material::query()->whereKey($material->id)->lockForUpdate()->firstOrFail();
                $order = (int) $locked->attachments()->max('display_order') + 1;
                foreach ($stored as $file) {
                    $locked->attachments()->create([...$file, 'display_order' => $order++, 'is_primary' => false]);
                }
                if (!$locked->attachments()->where('is_primary', true)->exists()) {
                    $this->setPrimary($locked, $locked->attachments()->orderBy('display_order')->firstOrFail());
                }
                if ($locked->status === 'Approved') $locked->update(['status' => 'Pending', 'published_at' => null, 'submitted_at' => now()]);
            });
        } catch (\Throwable $exception) {
            foreach ($stored as $file) Storage::disk($file['storage_disk'])->delete($file['stored_path']);
            throw $exception;
        }
        if ($material->status === 'Approved') $this->notifyAdministrators('submitted', $material->fresh());
        return back()->with('status', 'Attachments added.');
    }

    public function updateAttachment(Request $request, Material $material, MaterialAttachment $attachment): RedirectResponse
    {
        $this->authorizeMaterial($request, $material, true);
        abort_unless((int) $attachment->material_id === (int) $material->id, 404);
        $data = $request->validate(['is_primary' => ['sometimes', 'boolean'], 'display_order' => ['sometimes', 'integer', 'min:0', 'max:1000']]);
        DB::transaction(function () use ($material, $attachment, $data): void {
            $locked = Material::query()->whereKey($material->id)->lockForUpdate()->firstOrFail();
            if (array_key_exists('display_order', $data)) $attachment->update(['display_order' => $data['display_order']]);
            if (!empty($data['is_primary'])) $this->setPrimary($locked, $attachment);
        });
        return back()->with('status', 'Attachment settings saved.');
    }

    public function removeAttachment(Request $request, Material $material, MaterialAttachment $attachment): RedirectResponse
    {
        $this->authorizeMaterial($request, $material, true);
        abort_unless((int) $attachment->material_id === (int) $material->id, 404);
        if ($material->status === 'Pending' && $request->user()->role !== 'admin') abort(403);
        if ($material->status === 'Approved' && $request->user()->role !== 'admin') abort(403);
        $remaining = $material->attachments()->whereKeyNot($attachment->id)->orderBy('display_order')->get();
        DB::transaction(function () use ($material, $attachment, $remaining): void {
            $locked = Material::query()->whereKey($material->id)->lockForUpdate()->firstOrFail();
            $wasPrimary = $attachment->is_primary;
            $attachment->delete();
            if ($wasPrimary && $remaining->isNotEmpty()) $this->setPrimary($locked, $remaining->first());
            elseif ($wasPrimary) $locked->update(['file_path'=>null,'file_disk'=>null,'original_filename'=>null,'file_type'=>null,'file_size'=>null,'file_sha256'=>null,'thumbnail_path'=>null]);
            $locked->update(['status' => $locked->status === 'Approved' ? 'Pending' : $locked->status, 'published_at' => $locked->status === 'Approved' ? null : $locked->published_at]);
        });
        $fileReferenced = DB::table('material_attachments')->where('stored_path', $attachment->stored_path)->exists()
            || Material::withTrashed()->where('file_disk', $attachment->storage_disk)->where('file_path', $attachment->stored_path)->exists();
        if (!$fileReferenced && $this->isSafeStoredPath($attachment->stored_path)) Storage::disk($attachment->storage_disk)->delete($attachment->stored_path);
        $thumbnailReferenced = $attachment->thumbnail_path && (DB::table('material_attachments')->where('thumbnail_path', $attachment->thumbnail_path)->exists()
            || Material::withTrashed()->where('thumbnail_path', $attachment->thumbnail_path)->exists());
        if (!$thumbnailReferenced && $attachment->thumbnail_path && str_starts_with($attachment->thumbnail_path, 'thumbnails/')) Storage::disk($attachment->storage_disk)->delete($attachment->thumbnail_path);
        return back()->with('status', 'Attachment removed.');
    }

    private function setPrimary(Material $material, MaterialAttachment $attachment): void
    {
        abort_unless((int) $attachment->material_id === (int) $material->id, 404);
        $material->attachments()->update(['is_primary' => false, 'primary_material_id' => null]);
        $attachment->update(['is_primary' => true, 'primary_material_id' => $material->id]);
        $material->update([
            'file_path' => $attachment->stored_path, 'file_disk' => $attachment->storage_disk,
            'original_filename' => $attachment->original_filename, 'file_type' => $attachment->mime_type,
            'file_size' => $attachment->file_size, 'file_sha256' => $attachment->sha256,
            'thumbnail_path' => $attachment->thumbnail_path,
        ]);
    }

    private function validateAttachmentBatch(Request $request): array
    {
        $maxMb = max(1, (int) config('app.bayani_max_upload_mb', 20));
        $request->validate(['attachments' => ['sometimes', 'array', 'max:10'], 'attachments.*' => ['file', 'max:'.($maxMb * 1024), 'extensions:pdf,docx,pptx,xlsx,jpg,jpeg,png,webp,mp4,webm,mp3,wav,ogg']]);
        $files = array_values(array_filter($request->file('attachments', [])));
        if (array_sum(array_map(fn (UploadedFile $file) => (int) $file->getSize(), $files)) > 38 * 1024 * 1024) {
            throw ValidationException::withMessages(['attachments' => 'The combined upload size may not exceed 38 MB.']);
        }
        return $files;
    }

    private function storeUploads(array $files): array
    {
        $stored = [];
        try {
            foreach ($files as $file) {
                $record = $this->storeUpload($file);
                if (!$record) continue;
                $stored[] = [
                    'original_filename' => $record['original_filename'], 'stored_path' => $record['file_path'],
                    'storage_disk' => $record['file_disk'], 'mime_type' => $record['file_type'],
                    'file_size' => $record['file_size'], 'sha256' => $record['file_sha256'],
                ];
            }
            return $stored;
        } catch (\Throwable $exception) {
            foreach ($stored as $file) Storage::disk($file['storage_disk'])->delete($file['stored_path']);
            throw $exception;
        }
    }

    public function form(Request $request, ?Material $material = null)
    {
        if ($material) $this->authorizeMaterial($request, $material, true);
        return view('bayani', [
            'page' => $material ? 'edit' : 'upload', 'itemId' => $material?->id,
            'backend' => ['kind' => 'material-form', 'material' => $material?->load(['category:id,name','subjects:id,name','gradeLevels:id,name','attachments']), 'max_upload_mb' => max(1, (int) config('app.bayani_max_upload_mb', 20)), ...$this->references(), ...$this->educationReferences()],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $draft = $request->input('intent') === 'draft';
        if (!$draft && !$request->hasFile('attachments') && !$request->hasFile('file')) {
            throw ValidationException::withMessages(['attachments' => 'Choose at least one attachment before submitting this material.']);
        }
        [$values,$subjects,$grades] = $this->validated($request);
        $stored = $this->storeUploads($this->validateAttachmentBatch($request));
        try { $legacy = $this->storeUpload($request->file('file')); }
        catch (\Throwable $exception) { foreach ($stored as $file) Storage::disk($file['storage_disk'])->delete($file['stored_path']); throw $exception; }
        if ($legacy) $stored[] = ['original_filename'=>$legacy['original_filename'],'stored_path'=>$legacy['file_path'],'storage_disk'=>$legacy['file_disk'],'mime_type'=>$legacy['file_type'],'file_size'=>$legacy['file_size'],'sha256'=>$legacy['file_sha256']];
        $primary = $stored[0] ?? null;
        try {
            $material = DB::transaction(function () use ($request,$values,$subjects,$grades,$draft,$stored,$primary) {
                $legacyFields = $primary ? ['file_path'=>$primary['stored_path'],'file_disk'=>$primary['storage_disk'],'original_filename'=>$primary['original_filename'],'file_type'=>$primary['mime_type'],'file_size'=>$primary['file_size'],'file_sha256'=>$primary['sha256']] : [];
                $material=$request->user()->materials()->create([...$values,...$legacyFields,'status'=>$draft?'Draft':'Pending','submitted_at'=>$draft?null:now()]);
                foreach ($stored as $index=>$file) $material->attachments()->create([...$file,'display_order'=>$index,'is_primary'=>$index===0,'primary_material_id'=>$index===0?$material->id:null]);
                $material->subjects()->sync($subjects); $material->gradeLevels()->sync($grades); return $material;
            });
        } catch (\Throwable $exception) {
            foreach ($stored as $file) Storage::disk($file['storage_disk'])->delete($file['stored_path']);
            throw $exception;
        }
        ActivityLog::create(['user_id' => $request->user()->id, 'action' => $draft ? 'material.drafted' : 'material.submitted', 'subject_type' => Material::class, 'subject_id' => $material->id]);
        if (!$draft) $this->notifyAdministrators('submitted', $material);
        return redirect()->route('contributor.submissions')->with('status', $draft ? 'Draft saved.' : 'Material submitted for review.');
    }

    public function update(Request $request, Material $material): RedirectResponse
    {
        $isAdmin = $request->user()->role === 'admin';
        if (!$isAdmin) $this->authorizeMaterial($request, $material, true);
        if (!$isAdmin && $material->status === 'Pending') abort(403, 'A submission cannot be changed while it is under review.');
        [$values,$subjects,$grades] = $this->validated($request);
        $draft = $request->input('intent') === 'draft';
        if (!$isAdmin && $material->status !== 'Draft' && $draft) abort(422, 'Only a draft can be saved without submission.');
        if ($request->hasFile('file') && !$isAdmin && $material->status === 'Approved') abort(403);
        $newFiles = $this->storeUploads($this->validateAttachmentBatch($request));
        try { $stored = $this->storeUpload($request->file('file')); }
        catch (\Throwable $exception) { foreach ($newFiles as $file) Storage::disk($file['storage_disk'])->delete($file['stored_path']); throw $exception; }
        $oldPath = $stored ? $material->file_path : null;
        $oldThumbnailPath = $stored ? $material->thumbnail_path : null;
        $oldDisk = $stored ? ($material->file_disk ?: 'bayani_uploads') : null;
        $wasApproved = $material->status === 'Approved';
        try {
            $importantChange = $this->materialDetailsChanged($material, $values, $subjects, $grades);
            DB::transaction(function () use ($material, $values, $subjects, $grades, $draft, $isAdmin, $stored, $newFiles, $importantChange): void {
                $approvedReplacement = ($stored || $newFiles || $importantChange) && $material->status === 'Approved';
                if ($stored) $material->attachments()->where('is_primary', true)->delete();
                $attributes = [...$values, ...($stored ?? [])];
                if (!$isAdmin) $attributes = [...$attributes, 'status' => $draft ? 'Draft' : 'Pending', 'submitted_at' => $draft ? null : now()];
                elseif ($approvedReplacement) $attributes = [...$attributes, 'status' => 'Pending', 'published_at' => null, 'submitted_at' => now()];
                $material->update($attributes);
                if ($stored) {
                    $attachment = $material->attachments()->create([
                        'original_filename'=>$stored['original_filename'], 'stored_path'=>$stored['file_path'],
                        'storage_disk'=>$stored['file_disk'], 'mime_type'=>$stored['file_type'],
                        'file_size'=>$stored['file_size'], 'sha256'=>$stored['file_sha256'],
                        'display_order'=>0, 'is_primary'=>true, 'primary_material_id'=>$material->id,
                    ]);
                    $this->setPrimary($material, $attachment);
                }
                $nextOrder = (int) $material->attachments()->max('display_order') + 1;
                foreach ($newFiles as $file) $material->attachments()->create([...$file, 'display_order' => $nextOrder++, 'is_primary' => false]);
                if (!$material->attachments()->where('is_primary', true)->exists()) {
                    $first = $material->attachments()->orderBy('display_order')->first();
                    if ($first) $this->setPrimary($material, $first);
                }
                $material->subjects()->sync($subjects); $material->gradeLevels()->sync($grades);
            });
        } catch (\Throwable $exception) {
            if ($stored) Storage::disk($stored['file_disk'])->delete($stored['file_path']);
            foreach ($newFiles as $file) Storage::disk($file['storage_disk'])->delete($file['stored_path']);
            throw $exception;
        }
        if ($oldPath && !Material::withTrashed()->where('file_disk', $oldDisk)->where('file_path', $oldPath)->exists() && $this->isSafeStoredPath($oldPath)) Storage::disk($oldDisk)->delete($oldPath);
        if ($stored) $this->deleteUnreferencedThumbnail($oldThumbnailPath, $oldDisk ?: 'bayani_uploads');
        if ($isAdmin) {
            ActivityLog::create(['user_id' => $request->user()->id, 'action' => 'material.metadata_updated', 'subject_type' => Material::class, 'subject_id' => $material->id, 'details' => ['previous_status' => $wasApproved ? 'Approved' : null, 'new_status' => $material->fresh()->status, 're_review_required' => $wasApproved && ($stored || $importantChange)]]);
            if ($wasApproved && ($stored || $newFiles || $importantChange)) $material->contributor->notify(new MaterialWorkflowNotification('resubmitted', $material->id, $material->title));
            return redirect()->route('admin.materials')->with('status', 'Material metadata updated.');
        }
        ActivityLog::create(['user_id' => $request->user()->id, 'action' => $draft ? 'material.draft_updated' : 'material.resubmitted', 'subject_type' => Material::class, 'subject_id' => $material->id]);
        if (!$draft) $this->notifyAdministrators('submitted', $material->fresh());
        return redirect()->route('contributor.submissions')->with('status', $draft ? 'Draft updated.' : 'Material resubmitted for review.');
    }

    public function status(Request $request, Material $material): RedirectResponse
    {
        abort_unless($request->user()->role === 'admin', 403);
        $data = $request->validate(['status' => ['required', Rule::in(['Approved', 'Rejected', 'Revision Requested'])], 'remarks' => ['nullable', 'string', 'max:3000']]);
        if (in_array($data['status'], ['Rejected', 'Revision Requested'], true) && blank($data['remarks'] ?? null)) {
            throw ValidationException::withMessages(['remarks' => 'Reviewer remarks are required for rejection or revision requests.']);
        }
        if (!$material->canTransitionTo($data['status'])) return back()->withErrors(['status' => 'This status transition is not allowed.']);
        $reviewedAt = now();
        DB::transaction(function () use ($material, $data, $request, $reviewedAt): void {
            $locked = Material::query()->whereKey($material->id)->lockForUpdate()->firstOrFail();
            if (!$locked->canTransitionTo($data['status'])) {
                throw ValidationException::withMessages(['status' => 'This material has already changed state. Reload it before reviewing.']);
            }
            $previousStatus = $locked->status;
            $locked->update(['status' => $data['status'], 'published_at' => $data['status'] === 'Approved' ? $reviewedAt : null]);
            $locked->reviews()->create(['reviewer_id' => $request->user()->id, 'decision' => $data['status'], 'remarks' => $data['remarks'] ?? null, 'reviewed_at' => $reviewedAt]);
            ActivityLog::create(['user_id' => $request->user()->id, 'action' => 'material.reviewed', 'subject_type' => Material::class, 'subject_id' => $material->id,
                'details' => ['material_id' => $material->id, 'administrator_id' => $request->user()->id, 'action' => $data['status'], 'previous_status' => $previousStatus, 'new_status' => $data['status'], 'remarks' => $data['remarks'] ?? null, 'reviewed_at' => $reviewedAt->toISOString()]]);
        });
        $action = match ($data['status']) { 'Approved' => 'approved', 'Rejected' => 'rejected', default => 'revision_requested' };
        $material->fresh()->contributor->notify(new MaterialWorkflowNotification($action, $material->id, $material->title, $data['remarks'] ?? null));
        return redirect()->route('admin.queue')->with('status', 'Review decision saved.');
    }

    public function destroy(Request $request, Material $material): RedirectResponse
    {
        $this->authorizeMaterial($request, $material, false);
        $previousStatus = $material->status;
        abort_unless($material->canTransitionTo('Archived'), 403, 'This material cannot be archived.');
        DB::transaction(function () use ($material, $request, $previousStatus): void {
            $previousPublishedAt = $material->published_at?->toISOString();
            $material->update(['status' => 'Archived', 'published_at' => null]);
            $material->delete();
            ActivityLog::create(['user_id' => $request->user()->id, 'action' => 'material.archived', 'subject_type' => Material::class, 'subject_id' => $material->id, 'details' => ['material_id' => $material->id, 'previous_status' => $previousStatus, 'previous_published_at' => $previousPublishedAt, 'new_status' => 'Archived']]);
        });
        return back()->with('status', 'Material archived.');
    }

    public function archived(Request $request)
    {
        abort_unless(in_array($request->user()->role, ['admin', 'contributor'], true), 403);
        $query = Material::onlyTrashed()->where('status', 'Archived')
            ->withCount('attachments')->with(['category:id,name', 'contributor:id,name']);
        if ($request->user()->role !== 'admin') $query->where('contributor_id', $request->user()->id);
        $materials = $query->latest('deleted_at')->paginate(15)->withQueryString();

        return view('bayani', ['page' => 'archived-materials', 'backend' => [
            'kind' => 'records', 'materials' => $materials,
            'archived_count' => $materials->total(), 'can_permanently_delete' => $request->user()->role === 'admin',
        ]]);
    }

    public function archivedDetail(Request $request, string $id)
    {
        $material = Material::onlyTrashed()->where('status', 'Archived')->findOrFail($id);
        abort_unless($request->user()->role === 'admin' || $material->contributor_id === $request->user()->id, 403);
        $material->load(['category:id,name', 'contributor:id,name', 'curriculum:id,name', 'materialType:id,name', 'subjects:id,name', 'gradeLevels:id,name', 'track:id,name', 'strand:id,name', 'attachments']);
        $material->makeHidden(['file_path', 'file_disk', 'file_sha256', 'thumbnail_path']);
        $material->attachments->each(fn (MaterialAttachment $attachment) => $attachment->makeHidden(['stored_path', 'storage_disk', 'sha256', 'thumbnail_path']));

        return view('bayani', ['page' => 'archived-detail', 'itemId' => $material->id, 'backend' => ['kind' => 'archived-material', 'material' => $material]]);
    }

    public function restoreArchived(Request $request, string $id): RedirectResponse
    {
        $material = Material::onlyTrashed()->where('status', 'Archived')->findOrFail($id);
        abort_unless($request->user()->role === 'admin' || $material->contributor_id === $request->user()->id, 403);
        $restoredStatus = 'Draft';
        DB::transaction(function () use ($material, $request, &$restoredStatus): void {
            $locked = Material::onlyTrashed()->whereKey($material->id)->lockForUpdate()->firstOrFail();
            $archiveEvent = ActivityLog::where('action', 'material.archived')
                ->where('subject_type', Material::class)->where('subject_id', $locked->id)->latest('id')->first();
            $details = $archiveEvent?->details ?? [];
            $candidate = $details['previous_status'] ?? 'Draft';
            $restoredStatus = in_array($candidate, ['Draft', 'Pending', 'Approved', 'Revision Requested', 'Rejected'], true) ? $candidate : 'Draft';
            $publishedAt = $restoredStatus === 'Approved'
                ? ($details['previous_published_at'] ?? $locked->published_at ?? now())
                : null;
            $locked->restore();
            $locked->update(['status' => $restoredStatus, 'published_at' => $publishedAt]);
            ActivityLog::create(['user_id' => $request->user()->id, 'action' => 'material.restored', 'subject_type' => Material::class, 'subject_id' => $locked->id, 'details' => ['material_id' => $locked->id, 'restored_status' => $restoredStatus, 'restored_by' => $request->user()->id]]);
        });

        return back()->with('status', 'Material restored as '.$restoredStatus.'. Its attachments were preserved.');
    }

    public function permanentlyDeleteArchived(Request $request, string $id): RedirectResponse
    {
        abort_unless($request->user()->role === 'admin', 403);
        $request->validate(['confirm_permanent_delete' => ['required', 'accepted']]);
        $material = Material::onlyTrashed()->where('status', 'Archived')->with('attachments')->findOrFail($id);
        $materialId = $material->id;
        $title = $material->title;
        $candidates = collect([
            [$material->file_disk, $material->file_path, 'material'],
            [$material->file_disk, $material->thumbnail_path, 'thumbnail'],
        ])->merge($material->attachments->flatMap(fn (MaterialAttachment $attachment) => [
            [$attachment->storage_disk, $attachment->stored_path, 'material'],
            [$attachment->storage_disk, $attachment->thumbnail_path, 'thumbnail'],
        ]))->filter(fn ($entry) => filled($entry[1]))->unique(fn ($entry) => implode('|', [$entry[0] ?? '', $entry[1], $entry[2]]))->values();
        $safeToDelete = [];
        $deferredCount = 0;
        foreach ($candidates as [$disk, $path, $kind]) {
            $safePath = $kind === 'material' ? $this->isSafeStoredPath((string) $path)
                : (str_starts_with((string) $path, 'thumbnails/') && (string) $path === 'thumbnails/'.basename((string) $path) && !str_contains((string) $path, '..'));
            if ($disk !== 'bayani_uploads' || !$safePath) { $deferredCount++; continue; }
            if (DB::table('materials')->where('id', '<>', $materialId)->where('file_disk', $disk)->where(fn ($q) => $q->where('file_path', $path)->orWhere('thumbnail_path', $path))->exists()
                || DB::table('material_attachments')->where('material_id', '<>', $materialId)->where('storage_disk', $disk)->where(fn ($q) => $q->where('stored_path', $path)->orWhere('thumbnail_path', $path))->exists()) continue;
            $safeToDelete[$disk.'|'.$path] = [$disk, $path];
        }

        $audit = DB::transaction(function () use ($request, $title, $materialId, $deferredCount): ActivityLog {
            $locked = Material::onlyTrashed()->where('status', 'Archived')->whereKey($materialId)->lockForUpdate()->firstOrFail();
            $locked->forceDelete();

            return ActivityLog::create(['user_id' => $request->user()->id, 'action' => 'material.permanently_deleted', 'details' => [
                'material_id' => $materialId, 'title' => $title, 'deleted_by' => $request->user()->id,
                'cleanup_deferred' => $deferredCount, 'cleanup_failed' => 0,
            ]]);
        });

        $failedCount = 0;
        foreach ($safeToDelete as [$disk, $path]) {
            try {
                if (Storage::disk($disk)->exists($path) && !Storage::disk($disk)->delete($path)) $failedCount++;
            } catch (\Throwable) {
                $failedCount++;
            }
        }
        $audit->update(['details' => [...($audit->details ?? []), 'cleanup_failed' => $failedCount]]);
        $remaining = $deferredCount + $failedCount;
        $message = $remaining === 0
            ? 'Archived material permanently deleted; unreferenced private files and thumbnails were cleaned up.'
            : 'Material record permanently deleted. '.$remaining.' private file(s) could not be safely removed; the cleanup outcome is recorded in the activity log.';

        return redirect()->route($request->user()->role === 'admin' ? 'admin.archived' : 'contributor.archived')->with('status', $message);
    }

    public function records(Request $request, string $view)
    {
        abort_unless(in_array($view, ['contributor-dashboard', 'submissions', 'drafts', 'admin-dashboard', 'review-queue', 'manage-materials', 'review'], true), 404);
        $query = Material::query()->withCount('attachments')->with(['category:id,name', 'contributor:id,name', 'reviews.reviewer:id,name']);
        if ($request->user()->role !== 'admin') $query->where('contributor_id', $request->user()->id);
        if ($view === 'drafts') $query->where('status', 'Draft');
        if ($view === 'review-queue' && !$request->filled('status')) $query->where('status', 'Pending');
        $this->filters($query, $request);
        if ($request->filled('status') && in_array($request->query('status'), Material::STATUSES, true)) $query->where('status', $request->query('status'));
        match ($request->query('sort', 'newest')) {
            'title' => $query->orderBy('title'),
            'oldest' => $query->orderBy('created_at')->orderBy('id'),
            default => $query->orderByDesc('updated_at')->orderByDesc('id'),
        };
        $items = $view === 'contributor-dashboard' || $view === 'admin-dashboard' ? $query->latest()->limit(6)->get() : $query->latest()->paginate(15)->withQueryString();
        return view('bayani', ['page' => $view, 'backend' => ['kind' => 'records', 'materials' => $items, ...$this->references(), ...$this->educationReferences(), 'contributors' => User::whereHas('materials')->orderBy('name')->get(['id','name']), 'catalog_stats'=>['subjects'=>Subject::where('status','active')->count(),'curricula'=>Curriculum::where('status','active')->count()], 'stats' => [
            'total' => Material::when($request->user()->role !== 'admin', fn ($q) => $q->where('contributor_id', $request->user()->id))->count(),
            'pending' => Material::where('status', 'Pending')->when($request->user()->role !== 'admin', fn ($q) => $q->where('contributor_id', $request->user()->id))->count(),
            'approved' => Material::where('status', 'Approved')->when($request->user()->role !== 'admin', fn ($q) => $q->where('contributor_id', $request->user()->id))->count(),
            'archived' => $request->user()->role === 'admin' ? Material::onlyTrashed()->where('status', 'Archived')->count() : Material::onlyTrashed()->where('status', 'Archived')->where('contributor_id', $request->user()->id)->count(),
        ]]]);
    }

    public function contributorDetail(Request $request, Material $material)
    {
        abort_unless($material->contributor_id === $request->user()->id, 403);
        $material->load(['category:id,name', 'contributor:id,name', 'reviews.reviewer:id,name','curriculum:id,name','materialType:id,name','subjects:id,name','gradeLevels:id,name','track:id,name','strand:id,name','attachments']);
        return view('bayani', ['page' => 'submission-detail', 'itemId' => $material->id, 'backend' => ['kind' => 'material', 'material' => $material, 'contributor_view' => true]]);
    }

    public function notifications(Request $request)
    {
        $notifications = $request->user()->notifications()->latest()->paginate(20);
        $materialIds = collect($notifications->items())->map(fn ($notification) => (int) ($notification->data['material_id'] ?? 0))->filter()->unique()->values();
        $existingIds = $materialIds->isEmpty() ? collect() : Material::whereIn('id', $materialIds)->pluck('id')->map(fn ($id) => (int) $id);
        $unavailableIds = $materialIds->diff($existingIds)->values();

        return view('bayani', ['page' => 'notifications', 'backend' => ['kind' => 'notifications', 'notifications' => $notifications, 'unavailable_material_ids' => $unavailableIds]]);
    }

    public function adminForm(Material $material)
    {
        return view('bayani', ['page' => 'edit', 'itemId' => $material->id, 'backend' => ['kind' => 'material-form', 'admin' => true, 'material' => $material->load(['category:id,name','subjects:id,name','gradeLevels:id,name','attachments']), 'max_upload_mb' => max(1, (int) config('app.bayani_max_upload_mb', 20)), ...$this->references(), ...$this->educationReferences()]]);
    }

    public function adminReview(Material $material)
    {
        $material->load(['category:id,name', 'contributor:id,name', 'reviews.reviewer:id,name','curriculum:id,name','materialType:id,name','subjects:id,name','gradeLevels:id,name','track:id,name','strand:id,name','attachments']);
        return view('bayani', ['page' => 'review', 'itemId' => $material->id, 'backend' => ['kind' => 'material', 'material' => $material, 'transitions' => Material::STATUS_TRANSITIONS[$material->status] ?? []]]);
    }

    public function reports()
    {
        $statuses=Material::selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total','status');
        $bySubject=DB::table('material_subject')->join('materials','materials.id','=','material_subject.material_id')->join('subjects','subjects.id','=','material_subject.subject_id')->whereNull('materials.deleted_at')->select('subjects.name',DB::raw('COUNT(DISTINCT materials.id) AS total'))->groupBy('subjects.id','subjects.name')->orderByDesc('total')->limit(20)->get();
        $byGrade=DB::table('grade_level_material')->join('materials','materials.id','=','grade_level_material.material_id')->join('grade_levels','grade_levels.id','=','grade_level_material.grade_level_id')->whereNull('materials.deleted_at')->select('grade_levels.name',DB::raw('COUNT(DISTINCT materials.id) AS total'))->groupBy('grade_levels.id','grade_levels.name')->orderBy('grade_levels.sequence')->get();
        return view('bayani',['page'=>'reports','backend'=>['kind'=>'reports','statuses'=>$statuses,'by_subject'=>$bySubject,'by_grade'=>$byGrade,'total'=>Material::count(),'curricula'=>Curriculum::whereHas('subjects')->count(),'types'=>MaterialType::where('status','active')->count()]]);
    }

    private function filters(Builder $query, Request $request): void
    {
        $query->when($request->filled('q'), fn ($q) => $q->where(function ($nested) use ($request): void {
            $term = '%'.$request->string('q')->trim().'%';
            $nested->where('title', 'like', $term)->orWhere('description', 'like', $term)->orWhere('topic','like',$term)->orWhere('keywords','like',$term)
                ->orWhereHas('subjects',fn($s)=>$s->where('subjects.name','like',$term))
                ->orWhereHas('gradeLevels',fn($g)=>$g->where('grade_levels.name','like',$term))
                ->orWhereHas('curriculum',fn($c)=>$c->where('name','like',$term))
                ->orWhereHas('materialType',fn($t)=>$t->where('name','like',$term))
                ->orWhereHas('category',fn($c)=>$c->where('name','like',$term))
                ->orWhereHas('track',fn($t)=>$t->where('name','like',$term))
                ->orWhereHas('strand',fn($s)=>$s->where('name','like',$term))
                ->orWhereHas('contributor', fn ($user) => $user->where('name', 'like', $term)->orWhere('organization','like',$term)->orWhere('bio','like',$term)->orWhere('email','like',$term));
        }));
        $query->when($request->filled('subject'), fn($q)=>$q->whereHas('subjects',fn($s)=>$s->where('subjects.id',$request->integer('subject'))));
        $query->when($request->filled('curriculum'), fn($q)=>$q->where('curriculum_id',$request->integer('curriculum')));
        $query->when($request->filled('grade'), fn($q)=>$q->whereHas('gradeLevels',fn($g)=>$g->where('grade_levels.id',$request->integer('grade'))));
        $query->when($request->filled('contributor'), fn($q)=>$q->where('contributor_id', $request->integer('contributor')));
        $query->when($request->filled('material_type'), fn($q)=>$q->where('material_type_id',$request->integer('material_type')));
        $query->when($request->filled('track'), fn($q)=>$q->where('track_id',$request->integer('track')));
        $query->when($request->filled('strand'), fn($q)=>$q->where('strand_id',$request->integer('strand')));
        foreach (['category' => 'category_id'] as $param => $column) {
            $query->when($request->filled($param), fn ($q) => $q->where($column, $request->integer($param)));
        }
    }

    private function validated(Request $request): array
    {
        $maxMb = max(1, (int) config('app.bayani_max_upload_mb', 20));
        $data=$request->validate([
            'title' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:20000'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'], 'curriculum_id'=>['nullable','integer','exists:curricula,id'],
            'material_type_id'=>['nullable','integer','exists:material_types,id'], 'track_id'=>['nullable','integer','exists:tracks,id'], 'strand_id'=>['nullable','integer','exists:strands,id'],
            'topic'=>['nullable','string','max:255'],'keywords'=>['nullable','string','max:2000'],
            'subjects'=>[$request->user()->role==='contributor'&&$request->input('intent')!=='draft'?'required':'sometimes','array','min:'.($request->user()->role==='contributor'&&$request->input('intent')!=='draft'?'1':'0'),'max:20'],'subjects.*'=>['integer','distinct','exists:subjects,id'],
            'grade_levels'=>[$request->user()->role==='contributor'&&$request->input('intent')!=='draft'?'required':'sometimes','array','min:'.($request->user()->role==='contributor'&&$request->input('intent')!=='draft'?'1':'0'),'max:13'],'grade_levels.*'=>['integer','distinct','exists:grade_levels,id'],
            'file' => ['nullable', 'file', 'max:'.($maxMb * 1024), 'extensions:pdf,docx,pptx,xlsx,jpg,jpeg,png,webp,mp4,webm,mp3,wav,ogg'],
            'attachments' => ['sometimes', 'array', 'max:10'], 'attachments.*' => ['file', 'max:'.($maxMb * 1024), 'extensions:pdf,docx,pptx,xlsx,jpg,jpeg,png,webp,mp4,webm,mp3,wav,ogg'],
        ]);
        $this->validateAttachmentBatch($request);
        if (array_sum(array_map(fn (UploadedFile $file) => (int) $file->getSize(), array_merge($request->file('attachments', []), $request->file('file') ? [$request->file('file')] : []))) > 38 * 1024 * 1024) {
            throw ValidationException::withMessages(['attachments' => 'The combined upload size may not exceed 38 MB.']);
        }
        $subjects=$data['subjects']??[]; $grades=$data['grade_levels']??[];
        if (!empty($data['curriculum_id']) && $subjects && $grades) foreach($subjects as $subject) foreach($grades as $grade) {
            $available=DB::table('curriculum_subjects')->where('curriculum_id',$data['curriculum_id'])->where('subject_id',$subject)->where('grade_level_id',$grade)->where('status','active')
                ->where(function ($q) use ($data): void { $q->whereNull('track_id'); if (!empty($data['track_id'])) $q->orWhere('track_id', $data['track_id']); })
                ->where(function ($q) use ($data): void { $q->whereNull('strand_id'); if (!empty($data['strand_id'])) $q->orWhere('strand_id', $data['strand_id']); })->exists();
            if (!$available) throw \Illuminate\Validation\ValidationException::withMessages(['subjects'=>'A selected subject is not mapped to every selected grade in this curriculum.']);
        }
        $hasShs=GradeLevel::whereIn('id',$grades)->where('stage','senior_high')->exists();
        if ($hasShs && empty($data['track_id'])) throw \Illuminate\Validation\ValidationException::withMessages(['track_id'=>'Select a Senior High School track.']);
        if ($hasShs && !empty($data['curriculum_id']) && !Track::where('id',$data['track_id'])->where(fn($q)=>$q->whereNull('curriculum_id')->orWhere('curriculum_id',$data['curriculum_id']))->exists()) throw \Illuminate\Validation\ValidationException::withMessages(['track_id'=>'Select a track mapped to the selected curriculum.']);
        if (!empty($data['track_id']) && Track::find($data['track_id'])->strands()->where('status','active')->exists() && empty($data['strand_id'])) throw \Illuminate\Validation\ValidationException::withMessages(['strand_id'=>'Select a strand or specialization for this track.']);
        if (!empty($data['strand_id']) && (empty($data['track_id']) || !Strand::where('id',$data['strand_id'])->where('track_id',$data['track_id'])->exists())) throw \Illuminate\Validation\ValidationException::withMessages(['strand_id'=>'The strand must belong to the selected track.']);
        unset($data['subjects'],$data['grade_levels'],$data['file'],$data['attachments']);
        return [$data,$subjects,$grades];
    }

    private function authorizeMaterial(Request $request, Material $material, bool $forEdit): void
    {
        if ($request->user()->role === 'admin') return;
        abort_unless($request->user()->role === 'contributor' && $material->contributor_id === $request->user()->id, 403);
        abort_if($forEdit && !in_array($material->status, ['Draft', 'Rejected', 'Revision Requested'], true), 403);
    }

    private function storeUpload(?UploadedFile $file): ?array
    {
        if (!$file) return null;
        $extension = strtolower($file->getClientOriginalExtension());
        $allowed = ['pdf','docx','pptx','xlsx','jpg','jpeg','png','webp','mp4','webm','mp3','wav','ogg'];
        if (!in_array($extension, $allowed, true) || !$file->isValid()) {
            throw ValidationException::withMessages(['file' => 'Choose a valid PDF, DOCX, PPTX, XLSX, JPG, PNG, or WEBP file.']);
        }
        $mime = $file->getMimeType();
        $mimeByExtension = [
            'pdf' => ['application/pdf'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
            'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
            'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'], 'webp' => ['image/webp'],
            'mp4' => ['video/mp4', 'application/mp4'], 'webm' => ['video/webm'],
            'mp3' => ['audio/mpeg', 'audio/mp3'], 'wav' => ['audio/wav', 'audio/x-wav', 'audio/vnd.wave'], 'ogg' => ['audio/ogg', 'application/ogg'],
        ];
        if (!in_array($mime, $mimeByExtension[$extension] ?? [], true)) {
            throw ValidationException::withMessages(['file' => 'The file contents do not match the selected file type.']);
        }
        $officeDocuments = ['docx' => 'word/document.xml', 'pptx' => 'ppt/presentation.xml', 'xlsx' => 'xl/workbook.xml'];
        if (isset($officeDocuments[$extension])) {
            $archive = new \ZipArchive();
            $opened = $archive->open($file->getRealPath());
            $validOfficeFile = $opened === true && $archive->locateName('[Content_Types].xml') !== false && $archive->locateName($officeDocuments[$extension]) !== false;
            if ($opened === true) {
                $uncompressedBytes = 0;
                if ($archive->numFiles > 10000) $validOfficeFile = false;
                for ($index = 0; $validOfficeFile && $index < $archive->numFiles; $index++) {
                    $stat = $archive->statIndex($index);
                    if (!is_array($stat) || (int)($stat['size'] ?? 0) > 9_000_000) { $validOfficeFile = false; break; }
                    $uncompressedBytes += (int)$stat['size'];
                    if ($uncompressedBytes > 60_000_000) $validOfficeFile = false;
                }
                $archive->close();
            }
            if (!$validOfficeFile) throw ValidationException::withMessages(['file' => 'The Office document is malformed or does not match its extension.']);
        }
        $filename = Str::uuid().'.'.$extension;
        $path = null;
        try {
            $path = Storage::disk('bayani_uploads')->putFileAs('materials', $file, $filename);
            if (!$path) throw new \RuntimeException('The uploaded file could not be stored.');
            $sha256 = hash_file('sha256', $file->getRealPath());
            if (!$sha256) throw new \RuntimeException('The uploaded file could not be fingerprinted.');
        } catch (\Throwable $exception) {
            if ($path) Storage::disk('bayani_uploads')->delete($path);
            throw ValidationException::withMessages(['file' => 'The attachment could not be stored. Please retry.']);
        }
        return ['file_path' => $path, 'file_disk' => 'bayani_uploads', 'original_filename' => $this->safeOriginalFilename($file->getClientOriginalName()),
            'file_type' => $mime, 'file_size' => $file->getSize(), 'file_sha256' => $sha256];
    }

    private function serveFile(Request $request, Material $material, bool $inline): StreamedResponse|BinaryFileResponse|\Illuminate\Http\Response
    {
        [$disk, $storage] = $this->readableAttachment($request, $material);
        $extension = strtolower(pathinfo($material->original_filename ?: $material->file_path, PATHINFO_EXTENSION));
        $mimeTypes = ['pdf'=>'application/pdf','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp',
            'docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document','xlsx'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','pptx'=>'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'mp4'=>'video/mp4','webm'=>'video/webm','mp3'=>'audio/mpeg','wav'=>'audio/wav','ogg'=>'audio/ogg'];
        $inlineTypes = ['pdf','jpg','jpeg','png','webp','mp4','webm','mp3','wav','ogg'];
        $mime = $mimeTypes[$extension] ?? 'application/octet-stream';
        $previewRequested = $inline;
        $inline = $previewRequested && in_array($extension,$inlineTypes,true);
        if ($previewRequested && !$inline) abort(415, 'This file type is available as a download only.');
        $headers = [
            'Content-Type' => $mime,
            'Content-Disposition' => ($inline ? 'inline' : 'attachment').'; filename="'.addcslashes(basename($material->original_filename ?: ('material.'.$extension)), '"\\').'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ];
        if ($inline) {
            $size = (int) $storage->size($material->file_path);
            $range = $request->header('Range');
            $start = 0; $end = max(0, $size - 1); $partial = false;
            if ($range !== null) {
                if (!preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $matches) || ($matches[1] === '' && $matches[2] === '') || $size < 1) {
                    return response('',416,[...$headers,'Content-Range'=>'bytes */'.$size,'Accept-Ranges'=>'bytes']);
                }
                if ($matches[1] === '') {
                    $suffix = (int) $matches[2];
                    if ($suffix < 1) return response('',416,[...$headers,'Content-Range'=>'bytes */'.$size,'Accept-Ranges'=>'bytes']);
                    $start = max(0,$size-$suffix);
                } else {
                    $start = (int) $matches[1];
                    if ($start >= $size) return response('',416,[...$headers,'Content-Range'=>'bytes */'.$size,'Accept-Ranges'=>'bytes']);
                    if ($matches[2] !== '') $end = min($size-1,(int)$matches[2]);
                }
                if ($end < $start) return response('',416,[...$headers,'Content-Range'=>'bytes */'.$size,'Accept-Ranges'=>'bytes']);
                $partial = true;
            }
            $length = $size === 0 ? 0 : $end-$start+1;
            $headers['Accept-Ranges'] = 'bytes'; $headers['Content-Length'] = (string)$length;
            if ($partial) $headers['Content-Range'] = 'bytes '.$start.'-'.$end.'/'.$size;
            return response()->stream(function () use ($storage,$material,$start,$length): void {
                $stream=$storage->readStream($material->file_path);
                if (!is_resource($stream)) return;
                try {
                    if ($start > 0 && fseek($stream,$start)!==0) return;
                    $remaining=$length;
                    while ($remaining>0 && !feof($stream)) {
                        $chunk=fread($stream,min(1024*1024,$remaining));
                        if ($chunk===false || $chunk==='') break;
                        echo $chunk; $remaining-=strlen($chunk);
                        if (connection_aborted()) break;
                    }
                } finally { fclose($stream); }
            },$partial?206:200,$headers);
        }
        return $storage->download($material->file_path, basename($material->original_filename ?: ('material.'.$extension)), $headers);
    }

    /** Resolve a stored file only after the same visibility checks used by preview and download. */
    private function readableAttachment(Request $request, Material $material, ?MaterialAttachment $attachment = null): array
    {
        if ($attachment) {
            abort_unless((int) $attachment->material_id === (int) $material->id && $attachment->stored_path && $this->isSafeStoredPath($attachment->stored_path), 404);
        } else {
            abort_unless($material->file_path && $this->isSafeStoredPath($material->file_path), 404);
        }
        if ($material->status !== 'Approved') {
            abort_unless($request->user() && ($request->user()->role === 'admin' || ($request->user()->role === 'contributor' && $request->user()->id === $material->contributor_id)), 404);
        }
        $path = $attachment?->stored_path ?: $material->file_path;
        $diskName = $attachment?->storage_disk ?: $material->file_disk;
        $disk = in_array($diskName, ['bayani_uploads', 'local'], true) ? ($diskName ?: 'bayani_uploads') : 'bayani_uploads';
        $storage = Storage::disk($disk);
        abort_unless($storage->exists($path), 404);
        $extension = strtolower(pathinfo($attachment?->original_filename ?: $material->original_filename ?: $path, PATHINFO_EXTENSION));
        return [$disk, $storage, $storage->path($path), $extension];
    }

    private function thumbnailNoticeSvg(string $message): string
    {
        $text = htmlspecialchars($message, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
        return '<svg xmlns="http://www.w3.org/2000/svg" width="1000" height="625" viewBox="0 0 1000 625"><rect width="1000" height="625" fill="#eef2f3"/><text x="54" y="100" font-family="Arial,sans-serif" font-size="28" font-weight="700" fill="#17324d">Project BAYANI preview</text><text x="54" y="160" font-family="Arial,sans-serif" font-size="22" fill="#526574">'.$text.'</text></svg>';
    }

    private function thumbnailUnavailableMessage(string $extension): string
    {
        return match ($extension) {
            'xlsx', 'docx' => 'A page-faithful thumbnail is unavailable. This server has no configured Office document renderer; use the clearly labeled text preview or download the original.',
            'pptx' => 'PowerPoint slide rendering is not configured. Download the original presentation to view it.',
            'mp3','wav','ogg' => '♫  AUDIO FILE — open the preview to play this attachment.',
            'mp4','webm' => 'VIDEO FILE — a poster frame is shown when this browser can decode the video.',
            default => 'A content thumbnail is unavailable for this file format.',
        };
    }

    private function deleteUnreferencedThumbnail(?string $path, string $disk): void
    {
        if (!$path || !str_starts_with($path, 'thumbnails/') || str_contains($path, '..')) {
            return;
        }
        if (!Material::withTrashed()->where('thumbnail_path', $path)->exists()) {
            Storage::disk($disk)->delete($path);
        }
    }

    private function isSafeStoredPath(string $path): bool
    {
        return str_starts_with($path, 'materials/') && $path === 'materials/'.basename(str_replace('\\', '/', $path)) && !str_contains($path, '..');
    }

    private function safeOriginalFilename(string $name): string
    {
        $basename = basename(str_replace('\\', '/', $name));
        $safe = preg_replace('/[\x00-\x1F\x7F]/u', '_', $basename) ?: 'download';
        return mb_substr($safe, 0, 255);
    }

    private function references(): array
    {
        return [
            'categories' => Category::where('status', 'active')->orderBy('name')->get(),
        ];
    }

    private function educationReferences(): array
    {
        return ['curricula'=>Curriculum::where('status','active')->orderBy('name')->get(), 'subjects'=>Subject::where('status','active')->orderBy('name')->get(), 'grade_levels'=>GradeLevel::orderBy('sequence')->get(), 'material_types'=>MaterialType::where('status','active')->orderBy('name')->get(), 'tracks'=>Track::where('status','active')->orderBy('name')->get(), 'strands'=>Strand::where('status','active')->orderBy('name')->get(), 'subject_mappings'=>DB::table('curriculum_subjects')->where('status','active')->get(['curriculum_id','subject_id','grade_level_id','track_id','strand_id']), 'grade_metadata'=>GradeLevel::get(['id','stage']), 'track_metadata'=>Track::get(['id','curriculum_id']), 'strand_metadata'=>Strand::get(['id','track_id'])];
    }

    private function notifyAdministrators(string $action, Material $material): void
    {
        User::where('role', 'admin')->where('status', 'active')->each(
            fn (User $admin) => $admin->notify(new MaterialWorkflowNotification($action, $material->id, $material->title))
        );
    }

    private function materialDetailsChanged(Material $material, array $values, array $subjects, array $grades): bool
    {
        foreach (['title', 'description', 'topic', 'keywords', 'category_id', 'curriculum_id', 'material_type_id', 'track_id', 'strand_id'] as $key) {
            $before = $material->getAttribute($key);
            $after = $values[$key] ?? null;
            if (in_array($key, ['category_id', 'curriculum_id', 'material_type_id', 'track_id', 'strand_id'], true)) {
                $before = $before === null ? null : (int) $before;
                $after = $after === null || $after === '' ? null : (int) $after;
            }
            if ($before !== $after) return true;
        }

        $currentSubjects = $material->subjects()->pluck('subjects.id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $currentGrades = $material->gradeLevels()->pluck('grade_levels.id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $subjects = array_map('intval', $subjects);
        $grades = array_map('intval', $grades);
        sort($subjects);
        sort($grades);
        return $currentSubjects !== $subjects || $currentGrades !== $grades;
    }
}
