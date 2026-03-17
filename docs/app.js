(() => {
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
            return;
          }
          const html = await response.text();
          node.outerHTML = html;
        } catch (error) {
          console.warn(`Failed to load ${file}:`, error);
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
