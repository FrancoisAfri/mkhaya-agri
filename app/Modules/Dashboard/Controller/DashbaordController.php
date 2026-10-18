<?php

namespace App\Modules\Dashboard\Controller;

use App\Core\CompanyUnits\Models\CompanyUnit;
use App\Http\Controllers\Controller;
use App\Modules\Communication\ContentManagement\Services\CeoMessageService;
use App\Modules\Communication\ContentManagement\Services\ContentsService;
use App\Modules\Communication\ContentManagement\Services\ReactionService;
use App\Modules\Communication\Resources\Services\ResourceService;
use App\Modules\Dashboard\Requests\QuickAccessRequest;
use App\Modules\Dashboard\Services\DashboardService;
use App\Modules\Dashboard\Services\QuickAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class DashbaordController extends Controller
{
    public function __construct(
        protected DashboardService $dashboardService,
        protected CeoMessageService $ceoMessageService,
        protected ContentsService $contentsService,
        protected ResourceService $resourceService,
        protected ReactionService $reactionService,
        private QuickAccessService $quickAccessService

    ) {}

    /*
    |--------------------------------------------------------------------------
    | DASHBOARD
    |--------------------------------------------------------------------------
    */

    public function index(): Response|RedirectResponse
    {
        // Resellers are platform-level actors (no tenant) — send them to their portal
        // instead of the tenant dashboard, whichever login path brought them to '/'.
        if ($uid = Auth::id()) {
            if (\App\Modules\Tenancy\Models\Reseller::where('user_id', $uid)->where('status', 'active')->exists()) {
                return redirect()->route('reseller.dashboard');
            }

            // Same reasoning for a subcontractor or a client: they are outside the business, and
            // this dashboard is the business's own — headcount, leave, assets, the CEO's message.
            // Landing them here shows a third party the shape of the company they invoice, and
            // buries the one screen that is actually theirs. See PortalController.
            if (app(\App\Modules\Operations\ProjectManagement\Services\PermissionEvaluator::class)
                ->isExternalPartyOnly(Auth::user())) {
                return redirect()->route('project-management.portal');
            }
        }

        $dashboardData = $this->dashboardService->getDashboardData();
        $userId = Auth::id();

        // The dashboard adapts to the company's active modules: the greeting hero
        // is always shown, but the News/Events/CEO/Spotlights blocks require the
        // Content Management module and the Top Resources block requires Resources.
        // (Platform admin / no-company → everything is allowed.)
        $entitlements = app(\App\Modules\Tenancy\Services\CompanyEntitlements::class);
        $caps = [
            'content_management' => $entitlements->currentAllowsModule('content_management'),
            'resources'          => $entitlements->currentAllowsModule('resources'),
        ];

        // Only run the (potentially heavy) content queries when the module is on.
        $news = $events = $announcements = $internalNotices = [];
        $spotlights = $staffAnniversaries = $staffLeaves = [];
        if ($caps['content_management']) {
            $news            = $this->contentsService->getNewsbyDate();
            $events          = $this->contentsService->getEventbyDate();
            $announcements   = $this->contentsService->getAnnouncementbyDate();
            $internalNotices = $this->contentsService->getInternalNoticebyDate();
            $spotlights      = $this->dashboardService->getSpotlights(8);
            $staffAnniversaries = $this->dashboardService->getStaffAnniversaries();
            $staffLeaves     = $this->dashboardService->getLeaves();
        }

        // ── Quick Access ──────────────────────────────────────────────────────
        $accessibleRibbons = $this->quickAccessService->getAccessibleRibbonsForUser($userId);
        $userQuickAccess = $this->quickAccessService->getForUser($userId);
        // dd()

        $allContentIds = collect($news)
            ->merge($events)
            ->merge($announcements)
            ->merge($internalNotices)
            ->pluck('id');
        $spotlightIds  = collect($spotlights)->pluck('id');

        $reactionsSummary = [];

        foreach ($allContentIds as $contentId) {
            $reactionsSummary['content_' . $contentId] = [
                'like_count'    => $this->reactionService->countLikes(contentId: $contentId),
                'user_liked'    => $this->reactionService->hasUserLiked($userId, contentId: $contentId),
                'reaction_id'   => $this->reactionService->getUserReactionId($userId, contentId: $contentId),
                'comment_count' => $this->reactionService->countComments(contentId: $contentId),
            ];
        }
        foreach ($spotlightIds as $spotlightId) {
            $reactionsSummary['spotlight_' . $spotlightId] = [
                'like_count'    => $this->reactionService->countLikes(employeeSpotlightId: $spotlightId),
                'user_liked'    => $this->reactionService->hasUserLiked($userId, employeeSpotlightId: $spotlightId),
                'reaction_id'   => $this->reactionService->getUserReactionId($userId, employeeSpotlightId: $spotlightId),
                'comment_count' => $this->reactionService->countComments(employeeSpotlightId: $spotlightId),
            ];
        }

        return Inertia::render('Modules/Dashboard/Pages/Index', [
            'message'               => 'Welcome to the Dashboard!',
            'user'                  => $dashboardData['user'],
            'approvalAlert'         => $dashboardData['approvalAlert'],
            'profileAlert'          => $dashboardData['profileAlert'],
            'capabilities'          => $caps,
            // One compact KPI card per active module (adapts to the plan's modules).
            'moduleWidgets'         => app(\App\Modules\Dashboard\Services\ModuleWidgetService::class)->widgets(),
            'ceoMessage'            => $caps['content_management'] ? $this->ceoMessageService->ceoMessage() : null,
            'news'                  => $news,
            'events'                => $events,
            'announcements'         => $announcements,
            'internalNotices'       => $internalNotices,
            'companyUnits'          => $this->getCompanyUnits(),
            'spotlights'            => $spotlights,
            'staffAnniversaries'    => $staffAnniversaries,
            'staffLeaves'           => $staffLeaves,
            'reactionsSummary'      => $reactionsSummary,
            // ── Quick Access ──────────────────────────────────────────────────
            'accessibleRibbons'     => $accessibleRibbons,
            'userQuickAccess'       => $userQuickAccess,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | CONTENT MANAGEMENT
    |--------------------------------------------------------------------------
    */

    public function ceoMessage(): Response
    {
        return Inertia::render(
            'Modules/Communication/ContentManagement/CeoMessage/Index',
            [
                'ceo' => $this->ceoMessageService->ceoMessage(),
            ]
        );
    }

    public function news(): Response
    {
        $userId = Auth::id();
        $news = $this->contentsService->news();

        $reactionsSummary = [];
        foreach ($news as $item) {
            $reactionsSummary['content_' . $item->id] = [
                'like_count'    => $this->reactionService->countLikes(contentId: $item->id),
                'user_liked'    => $this->reactionService->hasUserLiked($userId, contentId: $item->id),
                'reaction_id'   => $this->reactionService->getUserReactionId($userId, contentId: $item->id),
                'comment_count' => $this->reactionService->countComments(contentId: $item->id),
            ];
        }

        return Inertia::render(
            'Modules/Communication/ContentManagement/Content/News/Index',
            [
                'news'            => $news,
                'companyUnits'    => $this->getCompanyUnits(),
                'reactionsSummary' => $reactionsSummary,
            ]
        );
    }

    public function events(): Response
    {
        $userId = Auth::id();
        $events = $this->contentsService->event();

        $reactionsSummary = [];
        foreach ($events as $item) {
            $reactionsSummary['content_' . $item->id] = [
                'like_count'    => $this->reactionService->countLikes(contentId: $item->id),
                'user_liked'    => $this->reactionService->hasUserLiked($userId, contentId: $item->id),
                'reaction_id'   => $this->reactionService->getUserReactionId($userId, contentId: $item->id),
                'comment_count' => $this->reactionService->countComments(contentId: $item->id),
            ];
        }

        return Inertia::render(
            'Modules/Communication/ContentManagement/Content/Event/Index',
            [
                'events'          => $events,
                'companyUnits'    => $this->getCompanyUnits(),
                'reactionsSummary' => $reactionsSummary,
            ]
        );
    }

    public function announcement(): Response
    {
        $userId = Auth::id();
        $announcements = $this->contentsService->announcement();

        $reactionsSummary = [];
        foreach ($announcements as $item) {
            $reactionsSummary['content_' . $item->id] = [
                'like_count'    => $this->reactionService->countLikes(contentId: $item->id),
                'user_liked'    => $this->reactionService->hasUserLiked($userId, contentId: $item->id),
                'reaction_id'   => $this->reactionService->getUserReactionId($userId, contentId: $item->id),
                'comment_count' => $this->reactionService->countComments(contentId: $item->id),
            ];
        }

        return Inertia::render(
            'Modules/Communication/ContentManagement/Content/Announcement/Index',
            [
                'announcements'    => $announcements,
                'companyUnits'     => $this->getCompanyUnits(),
                'reactionsSummary' => $reactionsSummary,
            ]
        );
    }

    public function internalNotice(): Response
    {
        $userId = Auth::id();
        $notices = $this->contentsService->internalNotice();

        $reactionsSummary = [];
        foreach ($notices as $item) {
            $reactionsSummary['content_' . $item->id] = [
                'like_count'    => $this->reactionService->countLikes(contentId: $item->id),
                'user_liked'    => $this->reactionService->hasUserLiked($userId, contentId: $item->id),
                'reaction_id'   => $this->reactionService->getUserReactionId($userId, contentId: $item->id),
                'comment_count' => $this->reactionService->countComments(contentId: $item->id),
            ];
        }

        return Inertia::render(
            'Modules/Communication/ContentManagement/Content/InternalNotice/Index',
            [
                'notices'          => $notices,
                'companyUnits'     => $this->getCompanyUnits(),
                'reactionsSummary' => $reactionsSummary,
            ]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | CORPORATE BRANDING
    |--------------------------------------------------------------------------
    */

    public function corporateBranding(): Response
    {
        return Inertia::render(
            'Modules/Communication/Resources/CorporateBrandings/Index',
            [
                'branding' => $this->dashboardService->getCorporateBranding(),
            ]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | RESOURCES
    |--------------------------------------------------------------------------
    */

    public function forms(): Response
    {
        return $this->renderResourcePage(
            view: 'Modules/Communication/Resources/Resource/Forms/Index',
            resources: $this->resourceService->getForms(),
            key: 'forms',
        );
    }

    public function legalDocuments(): Response
    {

        return $this->renderResourcePage(
            view: 'Modules/Communication/Resources/Resource/Legal/Index',

            resources: $this->resourceService->getLegalDocuments(),
        );
    }

    public function handbooks(): Response
    {
        return $this->renderResourcePage(
            view: 'Modules/Communication/Resources/Resource/Handbook/Index',
            resources: $this->resourceService->getHandbooks(),
        );
    }

    public function healthSafety(): Response
    {
        return $this->renderResourcePage(
            view: 'Modules/Communication/Resources/Resource/HealthSafety/Index',
            resources: $this->resourceService->getHealthSafety(),
        );
    }

    public function templates(): Response
    {
        return $this->renderResourcePage(
            view: 'Modules/Communication/Resources/Resource/Templates/Index',
            resources: $this->resourceService->getTemplates(),
        );
    }

    public function trainingMaterials(): Response
    {
        return $this->renderResourcePage(
            view: 'Modules/Communication/Resources/Resource/Training/Index',
            resources: $this->resourceService->getTrainingMaterials(),
        );
    }

    public function sops(): Response
    {
        return $this->renderResourcePage(
            view: 'Modules/Communication/Resources/Resource/Sop/Index',
            resources: $this->resourceService->getSOPs(),
        );
    }

    public function downloads(): Response
    {
        return $this->renderResourcePage(
            view: 'Modules/Communication/Resources/Resource/Downloads/Index',
            resources: $this->resourceService->getDownloads(),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | EMPLOYEE SPOTLIGHTS
    |--------------------------------------------------------------------------
    */

    public function employeeSpotlights(Request $request): Response
    {
        $spotlights = $this->dashboardService->getPaginated(
            perPage: (int) $request->integer('per_page', 10),
            page: (int) $request->integer('page', 1),
            search: (string) $request->string('search', ''),
        );

        return Inertia::render(
            'Modules/Communication/ContentManagement/EmployeeTeam/Index',
            [
                'spotlights'   => $spotlights,
                'employees'    => $this->dashboardService->getEmployees(),
                'companyUnits' => $this->getCompanyUnits(),
            ]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | PRIVATE HELPERS
    |--------------------------------------------------------------------------
    */

    private function renderResourcePage(string $view, mixed $resources, string $key = 'resources',): Response
    {
        return Inertia::render($view, [
            $key            => $resources,
            'companyUnits'  => $this->getCompanyUnits(),
        ]);
    }

    private function getCompanyUnits(): array
    {
        $types = [
            'division',
            'department',
            'team',
            'section',
            'unit',
        ];

        $companyUnits = [];

        foreach ($types as $type) {
            $companyUnits["{$type}s"] = CompanyUnit::query()
                ->where('type', $type)
                ->where('active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get([
                    'id',
                    'name',
                    'parent_id',
                    'type',
                ]);
        }

        return $companyUnits;
    }

    /*
    |--------------------------------------------------------------------------
    | QUICK ACCESS
    |--------------------------------------------------------------------------
    */

    public function quickAccess(): Response
    {
        $userId = Auth::id();
        $quicks = $this->quickAccessService->getAll();
        $accessibleRibbons = $this->quickAccessService->getAccessibleRibbonsForUser($userId);

        return Inertia::render('Modules/QuickAccess/Index', [
            'quickAccessItems' => $quicks,
            'accessibleRibbons' => $accessibleRibbons,
        ]);
    }

    public function storeQuickAccess(QuickAccessRequest $request): RedirectResponse
    {
        $this->quickAccessService->create($request->validated());

        return redirect()->back();
    }

    public function updateQuickAccess(QuickAccessRequest $request, int $id): RedirectResponse
    {
        $this->quickAccessService->update($id, $request->validated());

        return redirect()->back();
    }

    public function destroyQuickAccess(int $id): RedirectResponse
    {
        $this->quickAccessService->delete($id);

        return redirect()->back();
    }

    public function addToUser(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ribbon_ids'   => ['required', 'array'],
            'ribbon_ids.*' => ['integer', 'exists:security_module_ribbons,id'],
            'names'        => ['sometimes', 'array'],
            'names.*'      => ['nullable', 'string', 'max:100'],
        ]);
        $userId = Auth::id();

        $this->quickAccessService->addToUser(
            $userId,
            $data['ribbon_ids'],
            $data['names'] ?? []
        );

        return response()->json(['message' => 'Quick access updated.']);
    }

    public function removeFromUser(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ribbon_ids'   => ['required'],
            'ribbon_ids.*' => ['integer', 'exists:security_module_ribbons,id'],
        ]);
        $userId = Auth::id();

        $this->quickAccessService->removeFromUser($userId, $data['ribbon_ids']);

        return response()->json(['message' => 'Quick access removed.']);
    }

}
