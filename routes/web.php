<?php

use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\MaterialController;
use App\Http\Controllers\CmsController;
use App\Http\Controllers\EducationCatalogController;
use App\Http\Controllers\FavoriteController;
use App\Http\Controllers\NotificationController;
use App\Models\MaterialAttachment;

Route::get('/sample-files/{filename}', function (string $filename): BinaryFileResponse {
	abort_unless($filename === 'sample-history.pdf', 404);

	return response()->file(public_path('sample-files/sample-history.pdf'), [
		'Content-Type' => 'application/pdf',
	]);
})->name('sample-file');

Route::get('/', [MaterialController::class, 'home'])->name('home');
Route::get('/repository', [MaterialController::class, 'repository'])->name('repository');
Route::view('/about', 'bayani', ['page' => 'about'])->name('about');
Route::view('/help', 'bayani', ['page' => 'help'])->name('help');
Route::view('/help/feedback', 'bayani', ['page' => 'feedback'])->name('help.feedback');
Route::get('/materials/{material}', [MaterialController::class, 'show'])->whereNumber('material')->name('material');
Route::get('/materials/{material}/preview', [MaterialController::class, 'preview'])->whereNumber('material')->name('preview');
Route::get('/materials/{material}/thumbnail', [MaterialController::class, 'thumbnail'])->whereNumber('material')->name('materials.thumbnail');
Route::post('/materials/{material}/thumbnail-cache', [MaterialController::class, 'cacheMaterialThumbnail'])->whereNumber('material')->name('materials.thumbnail.cache');
Route::get('/materials/{material}/file', [MaterialController::class, 'download'])->whereNumber('material')->name('materials.file');
Route::get('/materials/{material}/attachments/{attachment}/preview', [MaterialController::class, 'previewAttachment'])->whereNumber('material')->name('materials.attachments.preview');
Route::get('/materials/{material}/attachments/{attachment}/thumbnail', [MaterialController::class, 'thumbnailAttachment'])->whereNumber('material')->name('materials.attachments.thumbnail');
Route::post('/materials/{material}/attachments/{attachment}/thumbnail-cache', [MaterialController::class, 'cacheAttachmentThumbnail'])->whereNumber('material')->name('materials.attachments.thumbnail.cache');
Route::get('/materials/{material}/attachments/{attachment}/file', [MaterialController::class, 'downloadAttachment'])->whereNumber('material')->name('materials.attachments.file');
Route::get('/search', [MaterialController::class, 'repository'])->name('search');

Route::middleware('guest')->group(function (): void {
    Route::view('/login', 'bayani', ['page' => 'login'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:login');
    Route::view('/register', 'bayani', ['page' => 'register'])->name('register');
    Route::post('/register', [RegisteredUserController::class, 'store'])->name('register.store');
});
Route::redirect('/demo/login', '/login')->name('login.demo');

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    Route::view('/demo/profile', 'bayani', ['page' => 'profile'])->name('profile');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::get('/profile/avatar', [ProfileController::class, 'avatar'])->name('profile.avatar');
    Route::view('/demo/settings', 'bayani', ['page' => 'account-settings'])->name('account.settings');
    Route::get('/notifications', [MaterialController::class, 'notifications'])->name('notifications');
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
    Route::delete('/notifications/{notification}', [NotificationController::class, 'destroy'])->name('notifications.destroy');
    Route::get('/favorites', [FavoriteController::class, 'index'])->name('favorites');
    Route::post('/favorites/{material}', [FavoriteController::class, 'store'])->whereNumber('material')->name('favorites.store');
    Route::delete('/favorites/{material}', [FavoriteController::class, 'destroy'])->whereNumber('material')->name('favorites.destroy');
    Route::view('/recently-viewed', 'bayani', ['page' => 'recently-viewed'])->name('recently-viewed');
    Route::view('/download-history', 'bayani', ['page' => 'download-history'])->name('download-history');

    Route::middleware('role:contributor')->prefix('contributor')->name('contributor.')->group(function (): void {
        Route::get('/', fn (\Illuminate\Http\Request $r) => app(MaterialController::class)->records($r, 'contributor-dashboard'))->name('dashboard');
        Route::get('/upload', [MaterialController::class, 'form'])->name('upload');
        Route::get('/archived', [MaterialController::class, 'archived'])->name('archived');
        Route::get('/archived/{id}', [MaterialController::class, 'archivedDetail'])->whereNumber('id')->name('archived.detail');
        Route::patch('/archived/{id}/restore', [MaterialController::class, 'restoreArchived'])->whereNumber('id')->name('archived.restore');
        Route::post('/submissions', [MaterialController::class, 'store'])->name('submissions.store');
        Route::get('/submissions', fn (\Illuminate\Http\Request $r) => app(MaterialController::class)->records($r, 'submissions'))->name('submissions');
        Route::get('/drafts', fn (\Illuminate\Http\Request $r) => app(MaterialController::class)->records($r, 'drafts'))->name('drafts');
        Route::get('/submissions/{material}/edit', [MaterialController::class, 'form'])->whereNumber('material')->name('edit');
        Route::put('/submissions/{material}', [MaterialController::class, 'update'])->whereNumber('material')->name('update');
        Route::delete('/submissions/{material}/file', [MaterialController::class, 'removeFile'])->whereNumber('material')->name('file.remove');
        Route::delete('/submissions/{material}/attachments/{attachment}', [MaterialController::class, 'removeAttachment'])->whereNumber('material')->name('attachments.remove');
        Route::patch('/submissions/{material}/attachments/{attachment}', [MaterialController::class, 'updateAttachment'])->whereNumber('material')->name('attachments.update');
        Route::post('/submissions/{material}/attachments', [MaterialController::class, 'addAttachments'])->whereNumber('material')->name('attachments.store');
        Route::delete('/submissions/{material}', [MaterialController::class, 'destroy'])->whereNumber('material')->name('destroy');
        Route::get('/submissions/{material}', [MaterialController::class, 'contributorDetail'])->whereNumber('material')->name('detail');
    });

    Route::middleware('role:admin')->prefix('admin')->name('admin.')->group(function (): void {
        Route::get('/', fn (\Illuminate\Http\Request $r) => app(MaterialController::class)->records($r, 'admin-dashboard'))->name('dashboard');
        Route::get('/review', fn (\Illuminate\Http\Request $r) => app(MaterialController::class)->records($r, 'review-queue'))->name('queue');
        Route::get('/review/{material}', [MaterialController::class, 'adminReview'])->whereNumber('material')->name('review');
        Route::patch('/materials/{material}/status', [MaterialController::class, 'status'])->whereNumber('material')->name('materials.status');
        Route::get('/materials/{material}/edit', [MaterialController::class, 'adminForm'])->whereNumber('material')->name('materials.edit');
        Route::put('/materials/{material}', [MaterialController::class, 'update'])->whereNumber('material')->name('materials.update');
        Route::delete('/materials/{material}/file', [MaterialController::class, 'removeFile'])->whereNumber('material')->name('materials.file.remove');
        Route::delete('/materials/{material}/attachments/{attachment}', [MaterialController::class, 'removeAttachment'])->whereNumber('material')->name('materials.attachments.remove');
        Route::patch('/materials/{material}/attachments/{attachment}', [MaterialController::class, 'updateAttachment'])->whereNumber('material')->name('materials.attachments.update');
        Route::post('/materials/{material}/attachments', [MaterialController::class, 'addAttachments'])->whereNumber('material')->name('materials.attachments.store');
        Route::delete('/materials/{material}', [MaterialController::class, 'destroy'])->whereNumber('material')->name('materials.destroy');
        Route::get('/materials', fn (\Illuminate\Http\Request $r) => app(MaterialController::class)->records($r, 'manage-materials'))->name('materials');
        Route::get('/archived', [MaterialController::class, 'archived'])->name('archived');
        Route::get('/archived/{id}', [MaterialController::class, 'archivedDetail'])->whereNumber('id')->name('archived.detail');
        Route::patch('/archived/{id}/restore', [MaterialController::class, 'restoreArchived'])->whereNumber('id')->name('archived.restore');
        Route::delete('/archived/{id}', [MaterialController::class, 'permanentlyDeleteArchived'])->whereNumber('id')->name('archived.permanent-delete');
        foreach (['categories'] as $taxonomy) {
            Route::get('/'.$taxonomy, fn () => app(CmsController::class)->index($taxonomy))->name($taxonomy);
            Route::post('/'.$taxonomy, fn (\Illuminate\Http\Request $r) => app(CmsController::class)->store($r, $taxonomy))->name($taxonomy.'.store');
            Route::put('/'.$taxonomy.'/{record}', fn (\Illuminate\Http\Request $r, string $record) => app(CmsController::class)->update($r, $taxonomy, (int) $record))->whereNumber('record')->name($taxonomy.'.update');
            Route::patch('/'.$taxonomy.'/{record}/status', fn (\Illuminate\Http\Request $r, string $record) => app(CmsController::class)->toggle($r, $taxonomy, (int) $record))->whereNumber('record')->name($taxonomy.'.status');
            Route::delete('/'.$taxonomy.'/{record}', fn (string $record) => app(CmsController::class)->destroy($taxonomy, (int) $record))->whereNumber('record')->name($taxonomy.'.destroy');
        }
        Route::prefix('/education')->name('education.')->group(function (): void {
            foreach (['curricula','subjects','grade-levels','mappings','tracks','strands','material-types'] as $catalog) {
                Route::get('/'.$catalog, fn () => app(EducationCatalogController::class)->index($catalog))->name($catalog);
                Route::post('/'.$catalog, fn (\Illuminate\Http\Request $r) => app(EducationCatalogController::class)->store($r,$catalog))->name($catalog.'.store');
                Route::put('/'.$catalog.'/{record}', fn (\Illuminate\Http\Request $r,string $record) => app(EducationCatalogController::class)->update($r,$catalog,(int)$record))->whereNumber('record')->name($catalog.'.update');
                Route::delete('/'.$catalog.'/{record}', fn (string $record) => app(EducationCatalogController::class)->destroy($catalog,(int)$record))->whereNumber('record')->name($catalog.'.destroy');
            }
        });
        Route::get('/reports', [MaterialController::class, 'reports'])->name('reports');
        Route::view('/system-settings', 'bayani', ['page' => 'system-settings'])->name('system-settings');
        Route::view('/users', 'bayani', ['page' => 'users'])->name('users');
        Route::get('/users/{id}', fn ($id) => view('bayani', ['page' => 'user-detail', 'itemId' => $id]))->name('user-detail');
        Route::view('/activity', 'bayani', ['page' => 'activity'])->name('activity');
        Route::view('/data-cleanup', 'bayani', ['page' => 'data-cleanup'])->name('data-cleanup');
    });
});
