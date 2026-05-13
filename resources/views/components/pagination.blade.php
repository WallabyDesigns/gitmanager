@if ($paginator->hasPages())
    <nav class="gwm-pagination" role="navigation" aria-label="{{ __('Pagination Navigation') }}">
        <div class="gwm-pagination__summary">
            {{ __('Showing') }}
            <span>{{ $paginator->firstItem() }}</span>
            {{ __('to') }}
            <span>{{ $paginator->lastItem() }}</span>
            {{ __('of') }}
            <span>{{ $paginator->total() }}</span>
            {{ __('results') }}
        </div>

        <div class="gwm-pagination__controls">
            @if ($paginator->onFirstPage())
                <span class="gwm-pagination__button gwm-pagination__button--disabled" aria-disabled="true">
                    {{ __('Previous') }}
                </span>
            @else
                <button type="button" class="gwm-pagination__button" wire:click="previousPage('{{ $paginator->getPageName() }}')" wire:loading.attr="disabled" rel="prev">
                    {{ __('Previous') }}
                </button>
            @endif

            @foreach ($elements as $element)
                @if (is_string($element))
                    <span class="gwm-pagination__button gwm-pagination__button--disabled" aria-disabled="true">{{ $element }}</span>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span class="gwm-pagination__button gwm-pagination__button--active" aria-current="page">{{ $page }}</span>
                        @else
                            <button type="button" class="gwm-pagination__button" wire:click="gotoPage({{ $page }}, '{{ $paginator->getPageName() }}')" wire:loading.attr="disabled">
                                {{ $page }}
                            </button>
                        @endif
                    @endforeach
                @endif
            @endforeach

            @if ($paginator->hasMorePages())
                <button type="button" class="gwm-pagination__button" wire:click="nextPage('{{ $paginator->getPageName() }}')" wire:loading.attr="disabled" rel="next">
                    {{ __('Next') }}
                </button>
            @else
                <span class="gwm-pagination__button gwm-pagination__button--disabled" aria-disabled="true">
                    {{ __('Next') }}
                </span>
            @endif
        </div>
    </nav>
@endif
