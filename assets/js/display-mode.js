(function () {
  const root = document.documentElement;
  const storageKey = 'reportDisplayMode';
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
  }

  applyDisplayMode();
  window.addEventListener('resize', applyDisplayMode, { passive: true });
  window.addEventListener('orientationchange', applyDisplayMode, { passive: true });
})();
