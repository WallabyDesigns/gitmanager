@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'border-slate-300 bg-white text-slate-900 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100']) }}>
