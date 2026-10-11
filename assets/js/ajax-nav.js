(function () {
  'use strict';

  function getMain(root) {
    root = root || document;
    return root.querySelector('#main-content, #pageContent, main');
  }

  function isInternalPageLink(link) {
    if (!link || !link.href || link.target === '_blank' || link.hasAttribute('download')) return false;
    if (link.origin !== window.location.origin) return false;
    if (link.hash && link.pathname === window.location.pathname && link.search === window.location.search) return false;
    return ['http:', 'https:'].indexOf(link.protocol) !== -1;
  }

  function normalizeUrls(root, baseUrl) {
    root.querySelectorAll('a[href], form[action], img[src], script[src], link[href]').forEach(function (element) {
      const attribute = element.hasAttribute('href') ? 'href' : element.hasAttribute('action') ? 'action' : 'src';
      const value = element.getAttribute(attribute);
      if (!value || value.charAt(0) === '#' || value.indexOf('javascript:') === 0 || value.indexOf('data:') === 0) return;
      try {
        element.setAttribute(attribute, new URL(value, baseUrl).href);
      } catch (error) {
        // Leave malformed or unsupported URLs unchanged.
      }
    });
  }

  function extractPage(html, url) {
    const parsed = new DOMParser().parseFromString(html, 'text/html');
    normalizeUrls(parsed, url);
    const content = getMain(parsed);
    return {
      content: content ? content.innerHTML : html,
      title: parsed.title,
      body: parsed.body.innerHTML,
      bodyClass: parsed.body.className,
      layout: parsed.querySelector('#main-content') ? 'admin' : 'client',
      hasMain: !!content
    };
  }

  async function loadUrlIntoMain(url, pushState) {
    const main = getMain();
    if (!main) {
      window.location.href = url;
      return;
    }

    const res = await fetch(url, {
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html, application/json' }
    });
    if (!res.ok) throw new Error('Navigation failed with status ' + res.status);

    const contentType = res.headers.get('content-type') || '';
    if (contentType.indexOf('application/json') !== -1) {
      const response = await res.json();
      if (response.redirect) return loadUrlIntoMain(new URL(response.redirect, url).href, pushState);
      if (response.html) main.innerHTML = response.html;
    } else {
      const page = extractPage(await res.text(), url);
      if (!page.hasMain) {
        window.location.href = url;
        return;
      }
      const currentLayout = document.querySelector('#main-content') ? 'admin' : 'client';
      if (currentLayout !== page.layout) {
        document.body.className = page.bodyClass;
        document.body.innerHTML = page.body;
      } else {
        main.innerHTML = page.content;
      }
      if (window.applyAuthContext) window.applyAuthContext(getMain());
      if (page.title) document.title = page.title;
    }

    if (pushState) history.pushState({ url: url }, '', url);
    runScripts(getMain());
    window.scrollTo(0, 0);
  }

  function runScripts(container) {
    if (!container) return;
    const scripts = Array.from(container.querySelectorAll('script'));
    scripts.forEach(old => {
      const script = document.createElement('script');
      if (old.src) { script.src = old.src; }
      if (old.type) script.type = old.type;
      if (!old.src) script.textContent = old.textContent;
      document.head.appendChild(script);
      document.head.removeChild(script);
    });
  }

  async function ajaxSubmit(form) {
    let url = form.action || location.href;
    const method = (form.method || 'GET').toUpperCase();
    const fd = new FormData(form);
    const headers = { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json, text/html' };
    if (method === 'GET') {
      const query = new URLSearchParams(fd).toString();
      url = url.split('?')[0] + (query ? '?' + query : '');
    }

    const res = await fetch(url, { method, body: method === 'GET' ? null : fd, credentials: 'same-origin', headers });
    const ct = res.headers.get('content-type') || '';
    if (ct.indexOf('application/json') !== -1) {
      const j = await res.json();
      if (j.redirect) {
        await loadUrlIntoMain(new URL(j.redirect, url).href, true);
      } else if (j.html) {
        const main = getMain(); if (main) main.innerHTML = j.html; if (main) runScripts(main);
      }
    } else {
      const page = extractPage(await res.text(), url);
      if (!page.hasMain) {
        window.location.href = url;
        return;
      }
      const main = getMain();
      if (main) {
        const currentLayout = document.querySelector('#main-content') ? 'admin' : 'client';
        if (currentLayout !== page.layout) {
          document.body.className = page.bodyClass;
          document.body.innerHTML = page.body;
        } else {
          main.innerHTML = page.content;
        }
        if (window.applyAuthContext) window.applyAuthContext(getMain());
        if (page.title) document.title = page.title;
        runScripts(getMain());
      }
      history.pushState({ url: url }, '', url);
    }
  }

  // Intercept forms with data-ajax="true" or class "ajax"
  document.addEventListener('submit', function(e){
    const form = e.target;
    if (!(form instanceof HTMLFormElement)) return;
    if (form.dataset.ajax === 'true' || form.classList.contains('ajax')) {
      e.preventDefault();
      ajaxSubmit(form).catch(err => console.error('AJAX form submit failed', err));
    }
  }, true);

  // Intercept all same-origin page links, while allowing opt-out with data-ajax="false".
  document.addEventListener('click', function(e){
    if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    const a = e.target.closest('a');
    if (!isInternalPageLink(a) || a.dataset.ajax === 'false') return;
    e.preventDefault();
    loadUrlIntoMain(a.href, true).catch(function (err) {
      console.error('AJAX link load failed', err);
      window.location.href = a.href;
    });
  });

  // Handle popstate to load pages
  window.addEventListener('popstate', function(e){
    const url = (e.state && e.state.url) || location.href;
    loadUrlIntoMain(url, false).catch(function () {
      window.location.reload();
    });
  });

})();
