(() => {
  const fallbackPartials = {
    'partials/nav.html': `<nav class="side-nav">
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
  <a href="deploy-queue.html">Deploy Queue</a>
  <a href="webhooks.html">GitHub Webhooks</a>
  <a href="self-update.html">Self Update</a>
  <a href="use-cases.html">Use Cases</a>
  <a href="troubleshooting.html">Troubleshooting</a>
</nav>`,
    'partials/footer.html': `<footer>
  <p class="footer-text">Git Web Manager for Git © 2026 <a href="https://wallabydesigns.com/" title="Website built by Wallaby Designs">Wallaby Designs LLC</a>. All rights reserved.</p>
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
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', includePartials);
  } else {
    includePartials();
  }
})();
