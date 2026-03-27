(() => {
  const fallbackPartials = {
    'partials/nav.html': `<div class="nav-shell">
  <button class="nav-toggle" type="button" aria-controls="doc-nav" aria-expanded="false" aria-label="Open menu">
    <span class="nav-icon" aria-hidden="true">
      <span></span>
      <span></span>
      <span></span>
    </span>
    <span class="nav-label">Menu</span>
  </button>
  <div class="nav-overlay" aria-hidden="true"></div>
  <nav class="side-nav" id="doc-nav" aria-label="Documentation navigation">
    <a href="index.html">Overview</a>
    <a href="quick-start.html">Quick Start</a>
    <a href="installation.html">Installation</a>
    <a href="configuration.html">Configuration</a>
    <a href="projects.html">Projects & Deployments</a>
    <a href="deploy-behavior.html">Deploy Behavior</a>
    <a href="health.html">Health Checks</a>
    <a href="preview.html">Preview Builds</a>
    <a href="security.html">Security & Dependabot</a>
    <a href="workflows.html">Workflows</a>
    <a href="email-settings.html">Email Settings</a>
    <a href="users.html">User Management</a>
    <a href="scheduler.html">Scheduler Settings</a>
    <a href="deploy-queue.html">Deploy Queue</a>
    <a href="webhooks.html">GitHub Webhooks</a>
    <a href="self-update.html">Self Update</a>
    <a href="use-cases.html">Use Cases</a>
    <a href="troubleshooting.html">Troubleshooting</a>
    <div class="nav-divider" aria-hidden="true"></div>
    <a class="nav-cta" href="https://github.com/WallabyDesigns/gitmanager" target="_blank" rel="noopener">GitHub Repository</a>
    <a class="nav-cta nav-coffee" href="https://www.buymeacoffee.com/wallaby" target="_blank" rel="noopener">Buy Me a Coffee</a>
  </nav>
</div>`,
    'partials/footer.html': `<footer>
  <p class="footer-text">Git Web Manager for Git © 2026 <a href="https://wallabydesigns.com/" title="Website built by Wallaby Designs">Wallaby Designs LLC</a>. MIT License.</p>
  <p class="footer-disclaimer">Git Web Manager is not affiliated with, endorsed by, or sponsored by Git or its maintainers.</p>
  <a class="wallaby" href="https://wallabydesigns.com/" title="Website built by Wallaby Designs">
    <img width="175" height="62" src="./images/wallaby-w.png" alt="wallaby designs">
  </a>
</footer>`,
  };

  const applyPartial = (node, html) => {
    if (!html) {
      return;
    }
    node.outerHTML = html;
  };

  const setupNavDrawer = () => {
    const navShell = document.querySelector('.nav-shell');
    if (!navShell) {
      return;
    }
    const toggle = navShell.querySelector('.nav-toggle');
    const overlay = navShell.querySelector('.nav-overlay');
    const drawer = navShell.querySelector('.side-nav');
    if (!toggle || !overlay || !drawer) {
      return;
    }
    const label = toggle.querySelector('.nav-label');

    const heroTitle = document.querySelector('.page-hero-inner h1, .hero-inner h1');
    if (heroTitle) {
      const titleBlock = heroTitle.parentElement;
      if (titleBlock) {
        let row = titleBlock.querySelector('.hero-title-row');
        if (!row) {
          row = document.createElement('div');
          row.className = 'hero-title-row';
          titleBlock.insertBefore(row, heroTitle);
        }
        if (!row.contains(heroTitle)) {
          row.appendChild(heroTitle);
        }
        if (!row.contains(toggle)) {
          row.appendChild(toggle);
        }
      }
    }

    const closeDrawer = () => {
      navShell.classList.remove('is-open');
      toggle.classList.remove('is-open');
      toggle.setAttribute('aria-expanded', 'false');
      toggle.setAttribute('aria-label', 'Open menu');
      if (label) {
        label.textContent = 'Menu';
      }
      document.body.classList.remove('nav-open');
    };

    const openDrawer = () => {
      navShell.classList.add('is-open');
      toggle.classList.add('is-open');
      toggle.setAttribute('aria-expanded', 'true');
      toggle.setAttribute('aria-label', 'Close menu');
      if (label) {
        label.textContent = 'Close';
      }
      document.body.classList.add('nav-open');
    };

    toggle.addEventListener('click', () => {
      if (navShell.classList.contains('is-open')) {
        closeDrawer();
        return;
      }
      openDrawer();
    });

    overlay.addEventListener('click', closeDrawer);
    drawer.querySelectorAll('a').forEach((link) => {
      link.addEventListener('click', closeDrawer);
    });

    window.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        closeDrawer();
      }
    });
  };

  const includePartials = async () => {
    const placeholders = Array.from(document.querySelectorAll('[data-include]'));
    await Promise.all(
      placeholders.map(async (node) => {
        const file = node.getAttribute('data-include');
        if (!file) {
          return;
        }
        try {
          const response = await fetch(file);
          if (!response.ok) {
            console.warn(`Failed to load ${file}: ${response.status}`);
            applyPartial(node, fallbackPartials[file]);
            return;
          }
          const html = await response.text();
          applyPartial(node, html);
        } catch (error) {
          console.warn(`Failed to load ${file}:`, error);
          applyPartial(node, fallbackPartials[file]);
        }
      })
    );

    const path = window.location.pathname.split('/').pop() || 'index.html';
    document.querySelectorAll('.side-nav a').forEach((link) => {
      const href = link.getAttribute('href');
      if (href === path) {
        link.classList.add('active');
      }
    });

    setupNavDrawer();
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', includePartials);
  } else {
    includePartials();
  }
})();
