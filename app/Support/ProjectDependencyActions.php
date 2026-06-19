<?php

namespace App\Support;

final class ProjectDependencyActions
{
    /**
     * @return array<string, array{label: string, group: string, method: string, destructive?: bool}>
     */
    public static function definitions(): array
    {
        return [
            'composer_install' => [
                'label' => 'Install',
                'group' => 'Composer',
                'method' => 'composerInstall',
            ],
            'composer_update' => [
                'label' => 'Update',
                'group' => 'Composer',
                'method' => 'composerUpdate',
            ],
            'composer_audit' => [
                'label' => 'Audit',
                'group' => 'Composer',
                'method' => 'composerAudit',
            ],
            'app_clear_cache' => [
                'label' => 'Clear Cache',
                'group' => 'Laravel',
                'method' => 'appClearCache',
            ],
            'laravel_migrate' => [
                'label' => 'Migrate',
                'group' => 'Laravel',
                'method' => 'laravelMigrate',
            ],
            'npm_install' => [
                'label' => 'Install',
                'group' => 'Npm',
                'method' => 'npmInstall',
            ],
            'npm_update' => [
                'label' => 'Update',
                'group' => 'Npm',
                'method' => 'npmUpdate',
            ],
            'npm_audit_fix' => [
                'label' => 'Audit Fix',
                'group' => 'Npm',
                'method' => 'npmAuditFix',
            ],
            'npm_audit_fix_force' => [
                'label' => 'Audit Fix (Force)',
                'group' => 'Npm',
                'method' => 'npmAuditFixForce',
                'destructive' => true,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function defaultsFor(string $projectType): array
    {
        return match ($projectType) {
            'laravel' => array_keys(self::definitions()),
            'node', 'static', 'nextjs', 'react' => [
                'npm_install',
                'npm_update',
                'npm_audit_fix',
                'npm_audit_fix_force',
            ],
            default => [],
        };
    }

    /**
     * @param  mixed  $configured
     * @return list<string>
     */
    public static function resolve(string $projectType, $configured): array
    {
        $actions = is_array($configured)
            ? $configured
            : self::defaultsFor($projectType);

        return array_values(array_intersect(array_keys(self::definitions()), $actions));
    }
}
