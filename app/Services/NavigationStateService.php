<?php

namespace App\Services;

use App\Support\InstallContext;
use App\Models\AppUpdate;
use App\Models\AuditIssue;
use App\Models\Deployment;
use App\Models\DeploymentQueueItem;
use App\Models\Project;
use App\Models\SecurityAlert;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class NavigationStateService
{
    private const CACHE_SECONDS = 1800;

    /**
     * @var array<string, mixed>
     */
    private static array $requestCache = [];

    /**
     * @return array{
     *   openAlerts:int,
     *   updateAvailable:bool,
     *   checkUpdatesEnabled:bool,
     *   editionLabel:string,
     *   isEnterprise:bool,
     *   brandName:string
     * }
     */
    public function topNavigationState(?User $user): array
    {
        $shared = $this->sharedState($user);

        return [
            'openAlerts' => $shared['openAlerts'],
            'updateAvailable' => $shared['updateAvailable'],
            'checkUpdatesEnabled' => $shared['checkUpdatesEnabled'],
            'editionLabel' => $shared['editionLabel'],
            'isEnterprise' => $shared['isEnterprise'],
            'brandName' => $shared['brandName'],
        ];
    }

    /**
     * @return array{
     *   openAlerts:int,
     *   updateAvailable:bool,
     *   isEnterprise:bool,
     *   showLocalLicenseBadge:bool
     * }
     */
    public function systemSidebarState(?User $user): array
    {
        $shared = $this->sharedState($user);

        return [
            'openAlerts' => $shared['openAlerts'],
            'updateAvailable' => $shared['updateAvailable'],
            'isEnterprise' => $shared['isEnterprise'],
            'showLocalLicenseBadge' => $shared['showLocalLicenseBadge'],
        ];
    }

    /**
     * @return array{
     *   isAdmin:bool,
     *   isEnterprise:bool,
     *   queueCount:int,
     *   actionCenterCount:int
     * }
     */
    public function projectsSidebarState(?User $user): array
    {
        $userId = $user?->id;
        $isAdmin = $user?->isAdmin() ?? false;
        $cacheKey = 'projects-sidebar:'.($userId ?? 0).':'.($isAdmin ? 1 : 0);

        return $this->remember($cacheKey, function () use ($userId, $isAdmin): array {
            $isEnterprise = $this->editionService()->current() === EditionService::ENTERPRISE;
            $counts = $this->alertCounts($userId);
            $queueCount = $this->queueCount($userId);
            $dependencyIssueCount = $this->dependencyIssueCount($userId);
            $updateIssueCount = $isAdmin ? $this->latestUpdateIssueCount() : 0;

            return [
                'isAdmin' => $isAdmin,
                'isEnterprise' => $isEnterprise,
                'queueCount' => $queueCount,
                'actionCenterCount' => $counts['security'] + $counts['audit'] + $dependencyIssueCount + $updateIssueCount,
            ];
        });
    }

    /**
     * @return array{
     *   openAlerts:int,
     *   updateAvailable:bool,
     *   checkUpdatesEnabled:bool,
     *   editionLabel:string,
     *   isEnterprise:bool,
     *   brandName:string,
     *   showLocalLicenseBadge:bool
     * }
     */
    private function sharedState(?User $user): array
    {
        $userId = $user?->id;
        $isAdmin = $user?->isAdmin() ?? false;
        $cacheKey = 'shared-nav:'.($userId ?? 0).':'.($isAdmin ? 1 : 0);

        return $this->remember($cacheKey, function () use ($userId, $isAdmin): array {
            $counts = $this->alertCounts($userId);
            $updateIssueCount = $isAdmin ? $this->latestUpdateIssueCount() : 0;
            $checkUpdatesEnabled = (bool) $this->settingsService()->get('system.check_updates', true);
            $updateAvailable = false;
            if ($checkUpdatesEnabled) {
                $status = $this->selfUpdateService()->getUpdateStatus();
                $updateAvailable = ($status['status'] ?? '') === 'update-available';
            }

            $editionService = $this->editionService();
            $isEnterprise = $editionService->current() === EditionService::ENTERPRISE;
            $brandName = (string) config('app.name', 'Git Web Manager');
            if ($isEnterprise) {
                $customBrand = trim((string) $this->settingsService()->get('system.white_label.name', ''));
                if ($customBrand !== '') {
                    $brandName = $customBrand;
                }
            }

            $licenseState = $this->licenseService()->state();
            $licenseStatus = strtolower((string) ($licenseState['status'] ?? 'missing'));

            return [
                'openAlerts' => $counts['security'] + $counts['audit'] + $updateIssueCount,
                'updateAvailable' => $updateAvailable,
                'checkUpdatesEnabled' => $checkUpdatesEnabled,
                'editionLabel' => $editionService->label(),
                'isEnterprise' => $isEnterprise,
                'brandName' => $brandName,
                'showLocalLicenseBadge' => InstallContext::isLocalInstall() && $licenseStatus !== 'valid',
            ];
        });
    }

    /**
     * @return array{security:int,audit:int}
     */
    private function alertCounts(?int $userId): array
    {
        if (! $userId) {
            return ['security' => 0, 'audit' => 0];
        }

        $cacheKey = 'alert-counts:'.$userId;

        return $this->remember($cacheKey, function () use ($userId): array {
            return [
                'security' => SecurityAlert::query()
                    ->where('state', 'open')
                    ->whereHas('project', fn ($query) => $query->where('user_id', $userId))
                    ->count(),
                'audit' => AuditIssue::query()
                    ->where('status', 'open')
                    ->whereHas('project', fn ($query) => $query->where('user_id', $userId))
                    ->count(),
            ];
        });
    }

    private function queueCount(?int $userId): int
    {
        if (! $userId) {
            return 0;
        }

        return $this->remember('queue-count:'.$userId, function () use ($userId): int {
            return DeploymentQueueItem::query()
                ->whereIn('status', ['queued', 'running'])
                ->whereHas('project', fn ($query) => $query->where('user_id', $userId))
                ->count();
        });
    }

    private function dependencyIssueCount(?int $userId): int
    {
        if (! $userId) {
            return 0;
        }

        return $this->remember('dependency-issues:'.$userId, function () use ($userId): int {
            $composerStatusSubquery = Deployment::query()
                ->select('status')
                ->whereColumn('project_id', 'projects.id')
                ->whereIn('action', ['composer_install', 'composer_update', 'composer_audit'])
                ->latest('started_at')
                ->limit(1);

            $npmStatusSubquery = Deployment::query()
                ->select('status')
                ->whereColumn('project_id', 'projects.id')
                ->whereIn('action', ['npm_install', 'npm_update', 'npm_audit_fix', 'npm_audit_fix_force'])
                ->latest('started_at')
                ->limit(1);

            $statuses = Project::query()
                ->where('user_id', $userId)
                ->select('projects.id')
                ->selectSub($composerStatusSubquery, 'last_composer_status')
                ->selectSub($npmStatusSubquery, 'last_npm_status');

            return DB::query()
                ->fromSub($statuses, 'project_statuses')
                ->where(function ($query): void {
                    $query->whereIn('last_composer_status', ['failed', 'warning'])
                        ->orWhereIn('last_npm_status', ['failed', 'warning']);
                })
                ->count();
        });
    }

    private function latestUpdateIssueCount(): int
    {
        return $this->remember('latest-update-issue', function (): int {
            return AppUpdate::query()->latest('started_at')->value('status') === 'failed' ? 1 : 0;
        });
    }

    private function remember(string $key, callable $callback): mixed
    {
        if (array_key_exists($key, self::$requestCache)) {
            return self::$requestCache[$key];
        }

        $value = Cache::remember(
            'navigation-state:'.$key,
            now()->addSeconds(self::CACHE_SECONDS),
            $callback
        );

        self::$requestCache[$key] = $value;

        return $value;
    }

    private function settingsService(): SettingsService
    {
        return app(SettingsService::class);
    }

    private function selfUpdateService(): SelfUpdateService
    {
        return app(SelfUpdateService::class);
    }

    private function editionService(): EditionService
    {
        return app(EditionService::class);
    }

    private function licenseService(): LicenseService
    {
        return app(LicenseService::class);
    }
}
