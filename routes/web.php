<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\BrandController;
use App\Http\Controllers\CompetitorController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\IndustryAnalysisController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\PostPromptController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    if (Auth::check()) {
        return redirect()->route('dashboard');
    }

    return redirect()->route('login');
})->name('home');

// Agency Invitation Routes - Public routes (NO authentication required)
Route::get('invitation/{token}', [\App\Http\Controllers\Agency\InvitationController::class, 'show'])->name('agency.invitation.accept');
Route::post('invitation/accept', [\App\Http\Controllers\Agency\InvitationController::class, 'accept'])->name('agency.invitation.store');

Route::middleware(['auth', 'verified'])->group(function () {
    // Working import routes - using controllers to get proper data
    Route::get('posts/agency-import', [\App\Http\Controllers\PostImportController::class, 'index'])
        ->name('posts.agency-import')
        ->middleware('role.permission:null,agency');
    Route::post('posts/agency-import', [\App\Http\Controllers\PostImportController::class, 'import'])
        ->name('posts.agency-import.store')
        ->middleware('role.permission:null,agency');
    Route::get('posts/agency-import/template', [\App\Http\Controllers\PostImportController::class, 'downloadTemplate'])
        ->name('posts.agency-import.template')
        ->middleware('role.permission:null,agency');

    Route::get('posts/admin-import', [\App\Http\Controllers\Admin\PostImportController::class, 'index'])
        ->name('posts.admin-import')
        ->middleware('role.permission:view-admin-panel,manage-system');
    Route::post('posts/admin-import', [\App\Http\Controllers\Admin\PostImportController::class, 'import'])
        ->name('posts.admin-import.store')
        ->middleware('role.permission:view-admin-panel,manage-system');
    Route::get('posts/admin-import/template', [\App\Http\Controllers\Admin\PostImportController::class, 'downloadTemplate'])
        ->name('posts.admin-import.template')
        ->middleware('role.permission:view-admin-panel,manage-system');

    Route::get('dashboard', [DashboardController::class, 'index'])
        ->name('dashboard')
        ->middleware('role.permission:view-dashboard');

    // Docs & Files Routes
    Route::get('docs-files', [\App\Http\Controllers\DocsFileController::class, 'index'])
        ->name('docs-files.index');
    Route::post('docs-files', [\App\Http\Controllers\DocsFileController::class, 'store'])
        ->name('docs-files.store');
    Route::get('docs-files/folders', [\App\Http\Controllers\DocsFileController::class, 'getFolders'])
        ->name('docs-files.folders.index');
    Route::put('docs-files/{file}/move', [\App\Http\Controllers\DocsFileController::class, 'move'])
        ->name('docs-files.move');
    Route::post('docs-files/{file}/copy', [\App\Http\Controllers\DocsFileController::class, 'copy'])
        ->name('docs-files.copy');
    Route::delete('docs-files/{file}', [\App\Http\Controllers\DocsFileController::class, 'destroy'])
        ->name('docs-files.destroy');
    Route::post('docs-files/folders', [\App\Http\Controllers\DocsFileController::class, 'storeFolder'])
        ->name('docs-files.folders.store');
    Route::put('docs-files/folders/{folder}', [\App\Http\Controllers\DocsFileController::class, 'updateFolder'])
        ->name('docs-files.folders.update');
    Route::put('docs-files/folders/{folder}/move', [\App\Http\Controllers\DocsFileController::class, 'moveFolder'])
        ->name('docs-files.folders.move');
    Route::post('docs-files/folders/{folder}/copy', [\App\Http\Controllers\DocsFileController::class, 'copyFolder'])
        ->name('docs-files.folders.copy');
    Route::delete('docs-files/folders/{folder}', [\App\Http\Controllers\DocsFileController::class, 'destroyFolder'])
        ->name('docs-files.folders.destroy');
    Route::get('docs-files/folders/{folder}/download', [\App\Http\Controllers\DocsFileController::class, 'downloadFolder'])
        ->name('docs-files.folders.download');

    // Brand-specific dashboard
    Route::get('brands/{brand}/dashboard', [\App\Http\Controllers\BrandController::class, 'dashboard'])
        ->name('brands.dashboard')
        ->middleware('role.permission:view-dashboard');

    Route::get('mypage', function () {
        return Inertia::render('mypage');
    })->name('mypage');

    Route::get('/competitors', [CompetitorController::class, 'generalIndex'])->name('competitors.general-index');
    Route::get('/brands/{brand}/competitors', [CompetitorController::class, 'index'])->name('competitors.index');
    Route::post('/brands/{brand}/competitors/fetch-sync', [CompetitorController::class, 'fetchCompetitorsSync'])->name('competitors.fetch-sync');
    Route::post('/brands/{brand}/competitors/refresh', [CompetitorController::class, 'refreshCompetitors'])->name('competitors.refresh');
    Route::post('/brands/{brand}/competitors', [CompetitorController::class, 'store'])->name('competitors.store');
    Route::post('/brands/{brand}/competitors/fetch', [CompetitorController::class, 'fetchFromAI'])->name('competitors.fetch');
    Route::put('/competitors/{competitor}', [CompetitorController::class, 'update'])->name('competitors.update');
    Route::delete('/competitors/{competitor}', [CompetitorController::class, 'destroy'])->name('competitors.destroy');

    // Stats analysis routes
    Route::post('/competitors/{competitor}/fetch-stats', [CompetitorController::class, 'fetchCompetitorStats'])->name('competitors.fetch-stats');
    Route::post('/competitors/analyze-json', [CompetitorController::class, 'analyzeCompetitorFromJson'])->name('competitors.analyze-json');

    // User Management Routes - Protected by permissions
    Route::middleware('role.permission:view-users')->group(function () {
        Route::get('users', [UserController::class, 'index'])->name('users.index');
        Route::get('users/create', [UserController::class, 'create'])->name('users.create')->middleware('role.permission:create-users');
        Route::post('users', [UserController::class, 'store'])->name('users.store')->middleware('role.permission:create-users');
        Route::get('users/{user}/edit', [UserController::class, 'edit'])->name('users.edit')->middleware('role.permission:edit-users');
        Route::patch('users/{user}', [UserController::class, 'update'])->name('users.update')->middleware('role.permission:edit-users');
        Route::delete('users/{user}', [UserController::class, 'destroy'])->name('users.destroy')->middleware('role.permission:delete-users');
    });

    // Admin Panel Routes - Protected by admin role or permission
    Route::middleware('role.permission:view-admin-panel')->group(function () {
        Route::get('admin', [AdminController::class, 'index'])->name('admin.index');
        Route::get('admin/brands', [AdminController::class, 'brands'])->name('admin.brands');
        Route::post('admin/select-brand', [AdminController::class, 'selectBrand'])->name('admin.select-brand');

        // Agency Management - Admin only
        Route::resource('admin/agencies', \App\Http\Controllers\Admin\AgencyController::class)->only(['index', 'create', 'store', 'edit', 'update', 'destroy'])->names([
            'index' => 'admin.agencies.index',
            'create' => 'admin.agencies.create',
            'store' => 'admin.agencies.store',
            'edit' => 'admin.agencies.edit',
            'update' => 'admin.agencies.update',
            'destroy' => 'admin.agencies.destroy',
        ]);

        // AI Models Management - Admin only
        Route::resource('admin/ai-models', \App\Http\Controllers\Admin\AiModelController::class)->names([
            'index' => 'admin.ai-models.index',
            'create' => 'admin.ai-models.create',
            'store' => 'admin.ai-models.store',
            'show' => 'admin.ai-models.show',
            'edit' => 'admin.ai-models.edit',
            'update' => 'admin.ai-models.update',
            'destroy' => 'admin.ai-models.destroy',
        ]);
        Route::post('admin/ai-models/{aiModel}/toggle', [\App\Http\Controllers\Admin\AiModelController::class, 'toggle'])
            ->name('admin.ai-models.toggle');
        Route::post('admin/ai-models/{aiModel}/test', [\App\Http\Controllers\Admin\AiModelController::class, 'test'])
            ->name('admin.ai-models.test');

        // Citation Check Management - Admin only (requires additional manage-citation-check permission)
        Route::prefix('admin/citation-check')->name('admin.citation-check.')->middleware('role.permission:manage-citation-check')->group(function () {
            Route::get('/', [\App\Http\Controllers\Admin\CitationCheckController::class, 'index'])->name('index');
            Route::get('/{post}', [\App\Http\Controllers\Admin\CitationCheckController::class, 'show'])->name('show');
            Route::post('/run-check', [\App\Http\Controllers\Admin\CitationCheckController::class, 'runCheck'])->name('run-check');
            Route::post('/{post}/recheck', [\App\Http\Controllers\Admin\CitationCheckController::class, 'recheck'])->name('recheck');
            Route::post('/bulk-check', [\App\Http\Controllers\Admin\CitationCheckController::class, 'bulkCheck'])->name('bulk-check');
        });

        // Job Monitoring - Admin only
        Route::prefix('admin/jobs')->name('admin.jobs.')->group(function () {
            Route::get('/', [\App\Http\Controllers\Admin\JobMonitorController::class, 'index'])->name('index');
            Route::post('/{jobId}/retry', [\App\Http\Controllers\Admin\JobMonitorController::class, 'retry'])->name('retry');
            Route::delete('/{jobId}', [\App\Http\Controllers\Admin\JobMonitorController::class, 'delete'])->name('delete');
            Route::post('/clear', [\App\Http\Controllers\Admin\JobMonitorController::class, 'clear'])->name('clear');
        });

        // System Settings - Admin only (requires additional manage-system permission)
        Route::prefix('admin/settings')->name('admin.settings.')->middleware('role.permission:manage-system')->group(function () {
            Route::get('/', [\App\Http\Controllers\Admin\SystemSettingsController::class, 'index'])->name('index');
            Route::post('/update', [\App\Http\Controllers\Admin\SystemSettingsController::class, 'update'])->name('update');
        });

        // Post Permissions Management - Admin only (requires additional manage-system permission)
        Route::prefix('admin/post-permissions')->name('admin.post-permissions.')->middleware('role.permission:manage-system')->group(function () {
            Route::get('/', [\App\Http\Controllers\Admin\PostPermissionController::class, 'index'])->name('index');
            Route::patch('/users/{user}', [\App\Http\Controllers\Admin\PostPermissionController::class, 'updateUser'])->name('users.update');
            Route::patch('/brands/{brand}', [\App\Http\Controllers\Admin\PostPermissionController::class, 'updateBrand'])->name('brands.update');
            Route::post('/users/bulk-update', [\App\Http\Controllers\Admin\PostPermissionController::class, 'bulkUpdateUsers'])->name('users.bulk-update');
            Route::post('/brands/bulk-update', [\App\Http\Controllers\Admin\PostPermissionController::class, 'bulkUpdateBrands'])->name('brands.bulk-update');
        });

        // Post Management - Admin only (requires additional manage-system permission)
        Route::prefix('admin/posts')->name('admin.posts.')->middleware('role.permission:manage-system')->group(function () {
            Route::get('/', [\App\Http\Controllers\Admin\PostController::class, 'index'])->name('index');
            Route::get('/create', [\App\Http\Controllers\Admin\PostController::class, 'create'])->name('create');
            Route::post('/', [\App\Http\Controllers\Admin\PostController::class, 'store'])->name('store');
            Route::get('/{post}', [\App\Http\Controllers\Admin\PostController::class, 'show'])->name('show');
            Route::get('/{post}/edit', [\App\Http\Controllers\Admin\PostController::class, 'edit'])->name('edit');
            Route::get('/{post}/prompts', [\App\Http\Controllers\Admin\PostController::class, 'getPrompts'])->name('prompts');
            Route::post('/{post}/prompts/generate', [\App\Http\Controllers\Admin\PostController::class, 'generatePrompts'])->name('prompts.generate');
            Route::post('/{post}/prompts/bulk-update', [\App\Http\Controllers\Admin\PostController::class, 'bulkUpdatePrompts'])->name('prompts.bulk-update');
            Route::patch('/{post}', [\App\Http\Controllers\Admin\PostController::class, 'update'])->name('update');
            Route::delete('/{post}', [\App\Http\Controllers\Admin\PostController::class, 'destroy'])->name('destroy');

            // CSV Import routes for admin
            Route::get('/import', [\App\Http\Controllers\Admin\PostImportController::class, 'index'])->name('import.index');
            Route::post('/import', [\App\Http\Controllers\Admin\PostImportController::class, 'import'])->name('import.store');
            Route::get('/import/template', [\App\Http\Controllers\Admin\PostImportController::class, 'downloadTemplate'])->name('import.template');
        });

        // User Management - Admin only (requires additional manage-system permission)
        Route::prefix('admin/users')->name('admin.users.')->middleware('role.permission:manage-system')->group(function () {
            Route::get('/', [\App\Http\Controllers\Admin\UserController::class, 'index'])->name('index');
            Route::get('/create', [\App\Http\Controllers\Admin\UserController::class, 'create'])->name('create');
            Route::post('/', [\App\Http\Controllers\Admin\UserController::class, 'store'])->name('store');
            Route::get('/{user}', [\App\Http\Controllers\Admin\UserController::class, 'show'])->name('show');
            Route::get('/{user}/edit', [\App\Http\Controllers\Admin\UserController::class, 'edit'])->name('edit');
            Route::patch('/{user}', [\App\Http\Controllers\Admin\UserController::class, 'update'])->name('update');
            Route::delete('/{user}', [\App\Http\Controllers\Admin\UserController::class, 'destroy'])->name('destroy');
            Route::patch('/{user}/toggle-post-permission', [\App\Http\Controllers\Admin\UserController::class, 'togglePostPermission'])->name('toggle-post-permission');
            Route::post('/bulk-update-post-permissions', [\App\Http\Controllers\Admin\UserController::class, 'bulkUpdatePostPermissions'])->name('bulk-update-post-permissions');
        });

        // Prompt Management - Admin only
        Route::prefix('admin/prompts')->name('admin.prompts.')->middleware('role.permission:view-admin-panel')->group(function () {
            Route::get('/', [\App\Http\Controllers\Admin\PromptController::class, 'index'])->name('index');
            Route::post('/', [\App\Http\Controllers\Admin\PromptController::class, 'store'])->name('store');
            Route::delete('/{id}', [\App\Http\Controllers\Admin\PromptController::class, 'destroy'])->name('destroy');
        });

        // Competitor Management - Admin only
        Route::prefix('admin/competitors')->name('admin.competitors.')->middleware('role.permission:view-admin-panel')->group(function () {
            Route::get('/', [\App\Http\Controllers\Admin\CompetitorController::class, 'index'])->name('index');
            Route::post('/', [\App\Http\Controllers\Admin\CompetitorController::class, 'store'])->name('store');
            Route::post('/fetch-ai', [\App\Http\Controllers\Admin\CompetitorController::class, 'fetchFromAI'])->name('fetchFromAI');
            Route::put('/{competitor}', [\App\Http\Controllers\Admin\CompetitorController::class, 'update'])->name('update');
            Route::delete('/{competitor}', [\App\Http\Controllers\Admin\CompetitorController::class, 'destroy'])->name('destroy');
        });
    });

    // Brand Creation Routes - For authenticated users during brand creation
    Route::middleware('auth')->group(function () {
        Route::post('brands/generate-multi-model-prompts', [BrandController::class, 'generateMultiModelPrompts'])->name('brands.generateMultiModelPrompts');

        // Test CSRF route
        Route::post('test-csrf', function () {
            return response()->json(['success' => true, 'message' => 'CSRF test successful']);
        })->name('test.csrf');

        // Test AI page
        Route::get('test-ai', function () {
            return view('test-ai');
        })->name('test.ai');

        // Competitor fetch for brand creation (doesn't require existing brand)
        Route::post('api/competitors/fetch-for-brand-creation', [App\Http\Controllers\CompetitorController::class, 'fetchForBrandCreation'])->name('competitors.fetch-for-brand-creation');
    });

    // Brand Management Routes - Only for agency users
    Route::middleware('role.permission:null,agency')->group(function () {
        Route::resource('brands', BrandController::class);
        Route::put('brands/{brand}/status', [BrandController::class, 'updateStatus'])->name('brands.status');
        Route::post('brands/generate-prompts', [BrandController::class, 'generatePrompts'])->name('brands.generatePrompts');
        Route::post('brands/get-prompts-with-ratio', [BrandController::class, 'getPromptsWithRatio'])->name('brands.getPromptsWithRatio');
        Route::post('brands/get-existing-prompts', [BrandController::class, 'getExistingPrompts'])->name('brands.getExistingPrompts');
        Route::get('brands/{brand}/prompts-with-competitor-urls', [BrandController::class, 'getPromptsWithCompetitorUrls'])->name('brands.prompts-with-competitor-urls');
        Route::post('brands/{brand}/trigger-prompt-analysis', [BrandController::class, 'triggerPromptAnalysis'])->name('brands.trigger-prompt-analysis');

        // Step-by-step brand creation routes
        Route::post('brands/create/step/1', [BrandController::class, 'storeStep1'])->name('brands.create.step1');
        Route::get('brands/{brand}/create/step/{step}', [BrandController::class, 'showCreateStep'])->name('brands.create.step')->where('step', '[2-5]');
        Route::post('brands/{brand}/create/step/2', [BrandController::class, 'updateStep2'])->name('brands.update.step2');
        Route::post('brands/{brand}/create/step/3', [BrandController::class, 'updateStep3'])->name('brands.update.step3');
        Route::post('brands/{brand}/create/step/4', [BrandController::class, 'updateStep4'])->name('brands.update.step4');
        Route::post('brands/{brand}/create/step/5', [BrandController::class, 'updateStep5'])->name('brands.update.step5');

        // Bulk save and individual status update routes for brand creation
        Route::post('brands/{brand}/competitors/save-bulk', [BrandController::class, 'saveBulkCompetitors'])->name('brands.competitors.save-bulk');
        Route::patch('brands/{brand}/competitors/{competitor}/status', [BrandController::class, 'updateCompetitorStatus'])->name('brands.competitors.update-status');
        Route::post('brands/{brand}/prompts/save-bulk', [BrandController::class, 'saveBulkPrompts'])->name('brands.prompts.save-bulk');
        Route::patch('brands/{brand}/prompts/{prompt}/status', [BrandController::class, 'updatePromptStatus'])->name('brands.prompts.update-status');

        // Brand Ranking Page
        Route::get('brands/{brand}/ranking', [BrandController::class, 'ranking'])->name('brands.ranking');

        // Competitive Analysis Routes
        Route::get('brands/{brand}/competitive-stats', [\App\Http\Controllers\Brand\CompetitiveStatsController::class, 'index'])->name('brands.competitive-stats.index');
        Route::post('brands/{brand}/competitive-stats/analyze', [\App\Http\Controllers\Brand\CompetitiveStatsController::class, 'runAnalysis'])->name('brands.competitive-stats.analyze');
        Route::get('brands/{brand}/competitive-stats/history', [\App\Http\Controllers\Brand\CompetitiveStatsController::class, 'history'])->name('brands.competitive-stats.history');
        Route::delete('brands/{brand}/competitive-stats/{sessionId}', [\App\Http\Controllers\Brand\CompetitiveStatsController::class, 'deleteSession'])->name('brands.competitive-stats.delete-session');

        // Brand Prompts Management Routes
        Route::get('brands/{brand}/prompts', [\App\Http\Controllers\Brand\BrandPromptController::class, 'index'])->name('brands.prompts.index');
        Route::post('brands/{brand}/prompts/generate-ai', [\App\Http\Controllers\Brand\BrandPromptController::class, 'generateAI'])->name('brands.prompts.generateAI');
        Route::put('brands/{brand}/prompts/{prompt}', [\App\Http\Controllers\Brand\BrandPromptController::class, 'update'])->name('brands.prompts.update');
        Route::delete('brands/{brand}/prompts/{prompt}', [\App\Http\Controllers\Brand\BrandPromptController::class, 'destroy'])->name('brands.prompts.destroy');
        Route::post('brands/{brand}/prompts/bulk-update', [\App\Http\Controllers\Brand\BrandPromptController::class, 'bulkUpdate'])->name('brands.prompts.bulkUpdate');

        // Post Management Routes
        Route::resource('posts', PostController::class);

        // Brand-specific Order routes
        Route::get('brands/{brand}/orders', [OrderController::class, 'index'])->name('agency.orders.index');
        // Brand-specific post routes
        Route::get('brands/{brand}/posts', [PostController::class, 'brandIndex'])->name('brands.posts.index');
        Route::get('brands/{brand}/posts/create', [PostController::class, 'brandCreate'])->name('brands.posts.create');
        Route::post('brands/{brand}/posts', [PostController::class, 'brandStore'])->name('brands.posts.store');

        Route::post('posts/{post}/citations', [PostController::class, 'storeCitation'])->name('posts.citations.store');
        Route::get('posts/{post}/prompts', [PostController::class, 'showPrompts'])->name('posts.prompts');

        // CSV Import routes for agency users
        Route::prefix('posts')->name('posts.')->group(function () {
            Route::get('import', [\App\Http\Controllers\PostImportController::class, 'index'])->name('import.index');
            Route::post('import', [\App\Http\Controllers\PostImportController::class, 'import'])->name('import.store');
            Route::get('import/template', [\App\Http\Controllers\PostImportController::class, 'downloadTemplate'])->name('import.template');
        });

        // Post Prompts Management Routes
        Route::get('posts/{post}/manage-prompts', [PostPromptController::class, 'index'])->name('posts.prompts.index');
        Route::post('posts/{post}/generate-prompts', [PostPromptController::class, 'generatePrompts'])->name('posts.generatePrompts');
        Route::post('posts/{post}/get-prompts-with-ratio', [PostPromptController::class, 'getPromptsWithRatio'])->name('posts.getPromptsWithRatio');
        Route::post('posts/{post}/add-custom-prompt', [PostPromptController::class, 'addCustomPrompt'])->name('posts.addCustomPrompt');
        Route::put('post-prompts/{postPrompt}/selection', [PostPromptController::class, 'updateSelection'])->name('post-prompts.updateSelection');
        Route::delete('post-prompts/{postPrompt}', [PostPromptController::class, 'destroy'])->name('post-prompts.destroy');

        // Brand Prompts API Routes
        Route::prefix('api')->group(function () {
            Route::post('brand-prompts', [\App\Http\Controllers\Api\BrandPromptController::class, 'store']);
            Route::get('brand-prompts', [\App\Http\Controllers\Api\BrandPromptController::class, 'index']);
            Route::delete('brand-prompts/{prompt}', [\App\Http\Controllers\Api\BrandPromptController::class, 'destroy']);
        });

        // Agency Routes
        Route::prefix('agency')->name('agency.')->group(function () {
            // People Management
            Route::resource('people', \App\Http\Controllers\Agency\PeopleController::class)->only(['index', 'store', 'destroy']);
            Route::put('people/{member}/rights', [\App\Http\Controllers\Agency\PeopleController::class, 'updateRights'])->name('people.updateRights');

            // Invitation Management
            Route::post('invitations/{invitation}/resend', [\App\Http\Controllers\Agency\InvitationController::class, 'resend'])->name('invitations.resend');
            Route::delete('invitations/{invitation}', [\App\Http\Controllers\Agency\InvitationController::class, 'destroy'])->name('invitations.destroy');

            // Orders Management
            Route::get('orders', [\App\Http\Controllers\Agency\OrderController::class, 'index'])->name('orders.index');
        });
    });

    // Search Analytics / Industry Analysis Routes
    Route::prefix('search-analytics')->name('search-analytics.')->group(function () {
        Route::get('/', [IndustryAnalysisController::class, 'index'])->name('index');
        Route::post('/', [IndustryAnalysisController::class, 'store'])->name('store');
        Route::get('/{analysis}', [IndustryAnalysisController::class, 'show'])->name('show');
    });
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
