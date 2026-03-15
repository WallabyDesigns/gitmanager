<div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
    <div>
        <h2 class="text-xl font-semibold text-slate-900 dark:text-slate-100">New Project</h2>
        <p class="text-sm text-slate-500 dark:text-slate-400">Add a new deployment target and connect it to Git.</p>
    </div>
    <a href="{{ route('projects.index') }}" wire:navigate class="text-sm text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200">
        Back to projects
    </a>
</div>
