import './bootstrap';

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
