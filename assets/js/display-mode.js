(function () {
  const root = document.documentElement;
  const currentScript = document.currentScript;
  const storageKey = 'reportDisplayMode';
  const reportReturnKey = 'reportsIndexReturnUrl';
  const onValues = new Set(['1', 'true', 'tv', 'pantalla', 'directivos', 'executive']);
  const offValues = new Set(['0', 'false', 'off', 'normal']);
  const navigationLoaderId = 'reportGlobalLoader';
  const numericValueSelector = [
    '[class*="-value"]',
    '.department-amount',
    '.annual-total strong',
    '.expense-content strong',
    '.metric-main strong',
    '.overview-metric strong',
    '.summary-content strong'
  ].join(',');
  let fitValuesTimer = null;

  function ensureNavigationLoader() {
    let loader = document.getElementById(navigationLoaderId);
    if (loader || !document.body) {
      return loader;
    }

    loader = document.createElement('div');
    loader.id = navigationLoaderId;
    loader.className = 'report-global-loader';
    loader.setAttribute('role', 'status');
    loader.setAttribute('aria-live', 'polite');
    loader.setAttribute('aria-hidden', 'true');
    loader.innerHTML = [
      '<div class="report-global-loader-card">',
      '<span class="report-global-loader-spinner" aria-hidden="true"></span>',
      '<strong data-report-loader-message>Cargando reporte...</strong>',
      '<small>Consultando y preparando la información</small>',
      '</div>'
    ].join('');
    document.body.appendChild(loader);
    return loader;
  }

  function showNavigationLoader(message) {
    const loader = ensureNavigationLoader();
    if (!loader) {
      return;
    }

    const messageNode = loader.querySelector('[data-report-loader-message]');
    if (messageNode) {
      messageNode.textContent = String(message || 'Cargando reporte...');
    }
    loader.setAttribute('aria-hidden', 'false');
    root.classList.add('report-is-loading');
  }

  function hideNavigationLoader() {
    const loader = document.getElementById(navigationLoaderId);
    root.classList.remove('report-is-loading');
    if (loader) {
      loader.setAttribute('aria-hidden', 'true');
    }
  }

  function shouldShowLoaderForLink(link, event) {
    if (!link || link.dataset.noLoader === '1' || link.hasAttribute('download') || link.target === '_blank') {
      return false;
    }
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
      return false;
    }

    const href = String(link.getAttribute('href') || '').trim();
    if (href === '' || href === '#' || href.startsWith('javascript:') || href.startsWith('mailto:') || href.startsWith('tel:')) {
      return false;
    }

    try {
      const target = new URL(href, window.location.href);
      if (target.origin !== window.location.origin) {
        return false;
      }
      return !(target.pathname === window.location.pathname && target.search === window.location.search && target.hash !== '');
    } catch (error) {
      return false;
    }
  }

  function enableNavigationLoader() {
    ensureNavigationLoader();

    document.addEventListener('click', function (event) {
      const link = event.target instanceof Element ? event.target.closest('a[href]') : null;
      if (shouldShowLoaderForLink(link, event)) {
        showNavigationLoader('Cargando reporte...');
      }
    });

    document.addEventListener('submit', function (event) {
      if (!event.defaultPrevented) {
        showNavigationLoader('Actualizando reporte...');
      }
    });

    if (!HTMLFormElement.prototype.reportLoaderSubmit) {
      const nativeSubmit = HTMLFormElement.prototype.submit;
      Object.defineProperty(HTMLFormElement.prototype, 'reportLoaderSubmit', {
        value: true,
        configurable: true
      });
      HTMLFormElement.prototype.submit = function () {
        showNavigationLoader('Actualizando reporte...');
        return nativeSubmit.call(this);
      };
    }
  }

  function availableElementWidth(element) {
    const ownWidth = element.clientWidth;
    const parent = element.parentElement;
    if (!parent) {
      return ownWidth;
    }

    const parentStyles = window.getComputedStyle(parent);
    const parentWidth = parent.clientWidth -
      (parseFloat(parentStyles.paddingLeft) || 0) -
      (parseFloat(parentStyles.paddingRight) || 0);

    if (ownWidth > 0 && parentWidth > 0) {
      return Math.min(ownWidth, parentWidth);
    }

    return Math.max(ownWidth, parentWidth, 0);
  }

  function fitNumericValue(element) {
    if (!(element instanceof HTMLElement) || !/[0-9]/.test(element.textContent || '')) {
      return;
    }

    if (!Object.prototype.hasOwnProperty.call(element.dataset, 'fitNumberOriginalSize')) {
      element.dataset.fitNumberOriginalSize = element.style.fontSize || '__css__';
    }

    element.style.fontSize = element.dataset.fitNumberOriginalSize === '__css__'
      ? ''
      : element.dataset.fitNumberOriginalSize;

    const availableWidth = availableElementWidth(element);
    const contentWidth = element.scrollWidth;
    if (availableWidth <= 0 || contentWidth <= availableWidth + 1) {
      return;
    }

    const originalSize = parseFloat(window.getComputedStyle(element).fontSize) || 16;
    const configuredMinimum = Number(element.dataset.fitNumberMin);
    const minimumSize = Number.isFinite(configuredMinimum)
      ? Math.max(8, configuredMinimum)
      : Math.max(10, originalSize * 0.45);
    let fittedSize = Math.max(minimumSize, originalSize * (availableWidth / contentWidth) * 0.97);

    element.style.fontSize = fittedSize.toFixed(2) + 'px';
    while (element.scrollWidth > availableWidth + 1 && fittedSize > minimumSize) {
      fittedSize = Math.max(minimumSize, fittedSize - 0.5);
      element.style.fontSize = fittedSize.toFixed(2) + 'px';
    }
  }

  function fitNumericValues() {
    document.querySelectorAll(numericValueSelector).forEach(fitNumericValue);
  }

  function scheduleNumericValueFit() {
    window.clearTimeout(fitValuesTimer);
    fitValuesTimer = window.setTimeout(fitNumericValues, 40);
  }

  function identifyReport() {
    const match = String(window.location.pathname || '').match(/\/reports\/([^/]+)\//i);
    if (match && match[1]) {
      document.body.dataset.reportSlug = decodeURIComponent(match[1]).toLowerCase();
    }
  }

  function ensureDisplayStyles() {
    if (!currentScript || !currentScript.src || document.getElementById('reportDisplayModeStyles')) {
      return;
    }

    const stylesheet = document.createElement('link');
    stylesheet.id = 'reportDisplayModeStyles';
    stylesheet.rel = 'stylesheet';
    stylesheet.href = new URL('../css/display-mode.css', currentScript.src).href;
    document.head.appendChild(stylesheet);
  }

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

  function isCaptureView() {
    const params = new URLSearchParams(window.location.search || '');
    return params.has('capture') || document.body?.classList.contains('capture-mode');
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
    const executiveMode = !isCaptureView() && (
      storedMode === 'executive' ||
      (storedMode !== 'normal' && isLargeTouchPanel())
    );

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

  window.ReportNumberFit = {
    refresh: scheduleNumericValueFit
  };

  window.ReportLoader = {
    show: showNavigationLoader,
    hide: hideNavigationLoader
  };

  ensureDisplayStyles();
  rememberReportsIndex();
  applyDisplayMode();
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      identifyReport();
      enhanceBackButtons();
      enableNavigationLoader();
      scheduleNumericValueFit();
    });
  } else {
    identifyReport();
    enhanceBackButtons();
    enableNavigationLoader();
    scheduleNumericValueFit();
  }
  window.addEventListener('pageshow', hideNavigationLoader);
  if (document.fonts && document.fonts.ready) {
    document.fonts.ready.then(scheduleNumericValueFit);
  }
  const valueObserver = new MutationObserver(scheduleNumericValueFit);
  valueObserver.observe(document.documentElement, { childList: true, characterData: true, subtree: true });
  window.addEventListener('resize', function () {
    applyDisplayMode();
    scheduleNumericValueFit();
  }, { passive: true });
  window.addEventListener('orientationchange', function () {
    applyDisplayMode();
    scheduleNumericValueFit();
  }, { passive: true });
})();
