(function () {
  const root = document.documentElement;
  const storageKey = 'reportDisplayMode';
  const reportReturnKey = 'reportsIndexReturnUrl';
  const onValues = new Set(['1', 'true', 'tv', 'pantalla', 'directivos', 'executive']);
  const offValues = new Set(['0', 'false', 'off', 'normal']);

  function getRequestedMode() {
    const params = new URLSearchParams(window.location.search || '');
    return String(
      params.get('tv') ||
      params.get('display') ||
      params.get('vista') ||
      params.get('pantalla') ||
      ''
    ).trim().toLowerCase();
  }

  function getStoredMode() {
    try {
      return String(window.localStorage.getItem(storageKey) || '').toLowerCase();
    } catch (error) {
      return '';
    }
  }

  function setStoredMode(mode) {
    try {
      if (mode) {
        window.localStorage.setItem(storageKey, mode);
      } else {
        window.localStorage.removeItem(storageKey);
      }
    } catch (error) {
      // Storage can be unavailable on locked-down browsers.
    }
  }

  function isLargeTouchPanel() {
    const userAgent = String(navigator.userAgent || '');
    const samsungPanel = /SamsungBrowser|Tizen|SMART-TV|SmartTV|Samsung/i.test(userAgent);
    const hasTouch = (navigator.maxTouchPoints || 0) > 0 ||
      (window.matchMedia && (
        window.matchMedia('(pointer: coarse)').matches ||
        window.matchMedia('(any-pointer: coarse)').matches
      ));

    const screenWidth = Number(window.screen && window.screen.width) || 0;
    const screenHeight = Number(window.screen && window.screen.height) || 0;
    const viewportWidth = Number(window.innerWidth) || 0;
    const viewportHeight = Number(window.innerHeight) || 0;
    const longSide = screenWidth && screenHeight
      ? Math.max(screenWidth, screenHeight)
      : Math.max(viewportWidth, viewportHeight);
    const shortSide = screenWidth && screenHeight
      ? Math.min(screenWidth, screenHeight)
      : Math.min(viewportWidth, viewportHeight);

    return (hasTouch && longSide >= 1024 && shortSide >= 540) ||
      (samsungPanel && longSide >= 960 && shortSide >= 540);
  }

  function updateViewport(executiveMode) {
    const viewport = document.querySelector('meta[name="viewport"]');
    if (!viewport) {
      return;
    }

    if (!viewport.dataset.originalContent) {
      viewport.dataset.originalContent = viewport.getAttribute('content') || 'width=device-width, initial-scale=1.0';
    }

    viewport.setAttribute(
      'content',
      executiveMode
        ? 'width=1366, initial-scale=1.0, viewport-fit=cover'
        : viewport.dataset.originalContent
    );
  }

  function applyDisplayMode() {
    const requestedMode = getRequestedMode();

    if (onValues.has(requestedMode)) {
      setStoredMode('executive');
    } else if (offValues.has(requestedMode)) {
      setStoredMode('normal');
    }

    const storedMode = getStoredMode();
    const executiveMode = storedMode === 'executive' ||
      (storedMode !== 'normal' && isLargeTouchPanel());

    root.classList.toggle('executive-display', executiveMode);
    root.dataset.displayMode = executiveMode ? 'executive' : 'normal';
    updateViewport(executiveMode);
    window.dispatchEvent(new CustomEvent('report-display-mode-change', {
      detail: {
        mode: executiveMode ? 'executive' : 'normal'
      }
    }));

    return executiveMode ? 'executive' : 'normal';
  }

  function setDisplayMode(mode) {
    const normalized = String(mode || '').trim().toLowerCase();
    setStoredMode(normalized === 'executive' ? 'executive' : 'normal');
    return applyDisplayMode();
  }

  function toggleDisplayMode() {
    return setDisplayMode(root.dataset.displayMode === 'executive' ? 'normal' : 'executive');
  }

  function isReportsIndexUrl(url) {
    return /\/reports\/index\.php$/i.test(url.pathname) || /\/reports\/$/i.test(url.pathname);
  }

  function rememberReportsIndex() {
    try {
      if (isReportsIndexUrl(window.location)) {
        window.sessionStorage.setItem(reportReturnKey, window.location.href);
      }
    } catch (error) {
      // Storage can be unavailable on locked-down browsers.
    }
  }

  function getStoredReportsIndex() {
    try {
      const stored = String(window.sessionStorage.getItem(reportReturnKey) || '');
      if (!stored) {
        return null;
      }

      const url = new URL(stored, window.location.href);
      if (url.origin === window.location.origin && isReportsIndexUrl(url)) {
        return url;
      }
    } catch (error) {
      // Ignore invalid or unavailable stored URLs.
    }

    return null;
  }

  function buildReportsFallback(link) {
    const storedIndex = getStoredReportsIndex();
    if (storedIndex) {
      return storedIndex.href;
    }

    const target = new URL(link.getAttribute('href') || '../index.php', window.location.href);
    const currentParams = new URLSearchParams(window.location.search || '');
    ['mode', 'modo'].forEach(function (key) {
      const value = currentParams.get(key);
      if (value && !target.searchParams.has(key)) {
        target.searchParams.set(key, value);
      }
    });

    return target.href;
  }

  function shouldEnhanceBackButton(link) {
    if (!link || link.dataset.smartBack === 'off') {
      return false;
    }

    const canSmartBack = link.classList.contains('back-btn') || link.dataset.smartBack === 'reports-index';
    if (!canSmartBack) {
      return false;
    }

    try {
      const target = new URL(link.getAttribute('href') || '', window.location.href);
      return target.origin === window.location.origin && isReportsIndexUrl(target);
    } catch (error) {
      return false;
    }
  }

  function enhanceBackButtons() {
    document.querySelectorAll('a.back-btn').forEach(function (link) {
      if (!shouldEnhanceBackButton(link) || link.dataset.smartBackReady === '1') {
        return;
      }

      link.dataset.smartBackReady = '1';
      link.addEventListener('click', function (event) {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
          return;
        }

        event.preventDefault();

        const fallback = buildReportsFallback(link);
        let canUseHistory = false;
        try {
          const referrer = document.referrer ? new URL(document.referrer) : null;
          canUseHistory = window.history.length > 1 &&
            referrer !== null &&
            referrer.origin === window.location.origin &&
            isReportsIndexUrl(referrer);
        } catch (error) {
          canUseHistory = false;
        }

        if (canUseHistory) {
          window.history.back();
          window.setTimeout(function () {
            if (!document.hidden && window.location.href !== fallback) {
              window.location.href = fallback;
            }
          }, 700);
          return;
        }

        window.location.href = fallback;
      });
    });
  }

  window.ReportDisplayMode = {
    apply: applyDisplayMode,
    set: setDisplayMode,
    toggle: toggleDisplayMode,
    get: function () {
      return root.dataset.displayMode || 'normal';
    }
  };

  rememberReportsIndex();
  applyDisplayMode();
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', enhanceBackButtons);
  } else {
    enhanceBackButtons();
  }
  window.addEventListener('resize', applyDisplayMode, { passive: true });
  window.addEventListener('orientationchange', applyDisplayMode, { passive: true });
})();
