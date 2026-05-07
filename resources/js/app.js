import './bootstrap';
import { registerSW } from 'virtual:pwa-register';

// Only activate the service worker on HTTPS — local HTTP installs are unaffected
if ('serviceWorker' in navigator && location.protocol === 'https:') {
    registerSW({ immediate: false });
}

// Navigation progress bar — replaces Livewire's built-in bar (disabled via config)
(function () {
    let bar = null;
    let trickleTimer = null;
    let width = 0;

    function getBar() {
        if (!bar) {
            bar = document.createElement('div');
            bar.id = 'gwm-nav-bar';
            document.body.appendChild(bar);
        }
        return bar;
    }

    function start() {
        const b = getBar();
        clearTimeout(trickleTimer);

        // Jump immediately to 8% so the bar is visible right away
        width = 8;
        b.style.transition = 'none';
        b.style.opacity = '1';
        b.style.width = width + '%';

        function trickle() {
            if (width < 82) {
                // Slow trickle that decelerates as it approaches 82%
                width += (82 - width) * 0.06 + Math.random() * 2;
                width = Math.min(width, 82);
                b.style.transition = 'width 0.5s ease';
                b.style.width = width + '%';
            }
            trickleTimer = setTimeout(trickle, 500);
        }

        trickleTimer = setTimeout(trickle, 200);
    }

    function done() {
        const b = getBar();
        clearTimeout(trickleTimer);

        // Complete to 100% quickly, then fade out
        b.style.transition = 'width 0.1s ease';
        b.style.width = '100%';

        setTimeout(() => {
            b.style.transition = 'opacity 0.25s ease';
            b.style.opacity = '0';
            setTimeout(() => {
                b.style.transition = 'none';
                b.style.width = '0';
                width = 0;
            }, 250);
        }, 100);
    }

    document.addEventListener('livewire:navigating', start);
    document.addEventListener('livewire:navigated', done);
})();

// Re-sync nav active states after wire:navigate (nav is @persist'd and doesn't re-render)
(function () {
    const desktopActive   = 'inline-flex items-center px-1 pt-1 border-b-2 border-indigo-400 text-sm font-medium leading-5 text-slate-100 focus:outline-none focus:border-indigo-700 transition duration-150 ease-in-out';
    const desktopInactive = 'inline-flex items-center px-1 pt-1 border-b-2 border-transparent text-sm font-medium leading-5 text-slate-300 hover:text-slate-100 hover:border-slate-600 focus:outline-none transition duration-150 ease-in-out';
    const mobileActive    = 'block w-full ps-3 pe-4 py-2 border-l-4 border-indigo-400 text-base font-medium text-indigo-200 bg-indigo-500/10 focus:outline-none transition duration-150 ease-in-out';
    const mobileInactive  = 'block w-full ps-3 pe-4 py-2 border-l-4 border-transparent text-base font-medium hover:border-slate-300 text-slate-300 hover:text-slate-100 hover:bg-slate-900 focus:outline-none transition duration-150 ease-in-out';

    function firstSeg(pathname) {
        return '/' + (pathname.split('/').find(Boolean) || '');
    }

    function syncNavActive() {
        const currentSeg = firstSeg(globalThis.location.pathname);

        document.querySelectorAll('a[data-navlink]').forEach(function (link) {
            const href = link.getAttribute('href');
            if (!href) return;
            const linkSeg = firstSeg(new URL(href, globalThis.location.origin).pathname);
            link.className = (currentSeg === linkSeg) ? desktopActive : desktopInactive;
        });

        document.querySelectorAll('a[data-rnavlink]').forEach(function (link) {
            const href = link.getAttribute('href');
            if (!href) return;
            const linkSeg = firstSeg(new URL(href, globalThis.location.origin).pathname);
            link.className = (currentSeg === linkSeg) ? mobileActive : mobileInactive;
        });
    }

    document.addEventListener('livewire:navigated', syncNavActive);
})();

console.log(`


                                                             @@    @@
                                                             @@@@@@@              @@@@
                                                             @@@@@@@              @@@@
                                                    @@@@@@@@@@@@@@@@              @@@@
            @@@@    @@@@@    @@@@@  @@@@@@@@@@    @@@@@@@@@@@@@@@@@@  @@@@@@@@@   @@@@ @@@@@@@  @@@@@     @@@@@
            @@@@@   @@@@@@   @@@@  @@@@@@@@@@@@  @@@@@@@@@@@@@@@@@@ @@@@@@@@@@@@  @@@@@@@@@@@@@@ @@@@@    @@@@@
             @@@@  @@@@@@@  @@@@@   @@@    @@@@  @@@@@@@@@@@@@@@@@@  @@@    @@@@@ @@@@@    @@@@@  @@@@   @@@@@
             @@@@@ @@@@@@@@ @@@@     @@@@@@@@@@  @@@@@@@@@@@@@@@@     @@@@@@@@@@@ @@@@      @@@@@ @@@@@  @@@@
              @@@@ @@@ @@@@ @@@@  @@@@@@@@@@@@@  @@@@@@@@@@@@@@@   @@@@@@@@@@@@@@ @@@@      @@@@@  @@@@ @@@@@
              @@@@@@@@  @@@@@@@   @@@@     @@@@  @@@@@@@@@@@@@@    @@@@     @@@@@ @@@@@    @@@@@    @@@@@@@@
               @@@@@@   @@@@@@@   @@@@@@@@@@@@@  @@@@@@@  @@@@     @@@@@@@@@@@@@@ @@@@@@@@@@@@@@    @@@@@@@
               @@@@@@    @@@@@     @@@@@@@@@@@@ @@@@ @@   @@@       @@@@@@@@@@@@@ @@@@ @@@@@@@       @@@@@@
                                              @@@@   @@@  @@@@                                       @@@@@
                                       @@@@@@@@      @@@@@@  @@@                                  @@@@@@@
                                                          @@@@@                                   @@@@@@


                                © WALLABY DESIGNS. ALL RIGHTS RESERVED. WALLABYDESIGNS.COM

	`)