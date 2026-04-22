<?php

declare(strict_types=1);

namespace App\Livewire\Projects\Concerns;

use App\Models\Project;
use Illuminate\Support\Facades\Auth;

trait InteractsWithProjectTypes
{
    /**
     * @return array<int, string>
     */
    private function projectTypeValues(): array
    {
        return [
            'laravel',
            'node',
            'static',
            'nextjs',
            'react',
            'python',
            'container',
            'custom',
        ];
    }

    /**
     * @return array<int, array{value: string, label: string, description: string, locked: bool, locked_message?: string}>
     */
    private function projectTypeOptions(bool $isEnterprise): array
    {
        $containerLocked = ! $isEnterprise && $this->containerProjectCount >= $this->containerProjectLimit;

        return [
            [
                'value' => 'laravel',
                'label' => 'Laravel',
                'description' => 'Laravel defaults for health checks, Composer, npm, and tests.',
                'locked' => false,
            ],
            [
                'value' => 'node',
                'label' => 'Node',
                'description' => 'Node.js app defaults with npm install/build enabled.',
                'locked' => false,
            ],
            [
                'value' => 'static',
                'label' => 'Static',
                'description' => 'Static site pipeline with npm build support and minimal runtime checks.',
                'locked' => false,
            ],
            [
                'value' => 'nextjs',
                'label' => 'Next.js',
                'description' => 'Next.js defaults with npm build and remote runtime support.',
                'locked' => ! $isEnterprise,
                'locked_message' => 'Next.js projects are available in Enterprise Edition.',
            ],
            [
                'value' => 'react',
                'label' => 'React App',
                'description' => 'React app defaults with npm build and test commands.',
                'locked' => ! $isEnterprise,
                'locked_message' => 'React App projects are available in Enterprise Edition.',
            ],
            [
                'value' => 'python',
                'label' => 'Python',
                'description' => 'Python deployment flow with requirements.txt support.',
                'locked' => ! $isEnterprise,
                'locked_message' => 'Python projects are available in Enterprise Edition.',
            ],
            [
                'value' => 'container',
                'label' => 'Container',
                'description' => 'Container-focused project with build steps disabled by default.',
                'locked' => $containerLocked,
                'locked_message' => $containerLocked ? $this->containerLimitMessage() : null,
            ],
            [
                'value' => 'custom',
                'label' => 'Custom',
                'description' => 'Start from a blank deployment profile and configure each step manually.',
                'locked' => ! $isEnterprise,
                'locked_message' => 'Custom projects are available in Enterprise Edition.',
            ],
        ];
    }

    private function refreshProjectStats(): void
    {
        $userId = (int) Auth::id();
        if ($userId <= 0) {
            $this->projectCount = 0;
            return;
        }

        $this->projectCount = Project::query()
            ->where('user_id', $userId)
            ->count();
    }

    private function refreshContainerProjectStats(): void
    {
        $userId = (int) Auth::id();
        if ($userId <= 0) {
            $this->containerProjectCount = 0;
            return;
        }

        $query = Project::query()
            ->where('user_id', $userId)
            ->where('project_type', 'container');

        if (property_exists($this, 'project') && $this->project instanceof Project) {
            $query->whereKeyNot($this->project->id);
        }

        $this->containerProjectCount = $query->count();
    }

    private function canCreateProject(): bool
    {
        return $this->isEnterprise || $this->projectCount < $this->communityProjectLimit;
    }

    private function projectLimitMessage(): string
    {
        return "Community Edition is limited to {$this->communityProjectLimit} projects. Upgrade to Enterprise for unlimited projects.";
    }

    private function resolveContainerProjectLimit(): int
    {
        return 3;
    }

    private function canUseContainerProjectType(): bool
    {
        if ($this->isEnterprise) {
            return true;
        }

        $this->refreshContainerProjectStats();

        return $this->containerProjectCount < $this->containerProjectLimit;
    }

    private function containerLimitMessage(): string
    {
        return "Community Edition is limited to {$this->containerProjectLimit} container projects. Upgrade to Enterprise for unlimited container projects.";
    }

    private function isPremiumProjectType(string $value): bool
    {
        return in_array($value, ['nextjs', 'react', 'python', 'custom'], true);
    }

    private function projectTypeLabel(string $value): string
    {
        return match ($value) {
            'laravel' => 'Laravel',
            'node' => 'Node',
            'static' => 'Static',
            'nextjs' => 'Next.js',
            'react' => 'React App',
            'python' => 'Python',
            'container' => 'Container',
            default => 'Custom',
        };
    }
}
