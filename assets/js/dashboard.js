(function () {
  window.escapeHtml = function (str) {
    if (!str) return '';
    return String(str).replace(/[&<>]/g, function (m) {
      if (m === '&') return '&amp;';
      if (m === '<') return '&lt;';
      if (m === '>') return '&gt;';
      return m;
    });
  };

  window.formatNumber = function (num, decimals) {
    if (num === undefined || num === null) return '-';
    return Number(num).toLocaleString('es-MX', {
      minimumFractionDigits: decimals,
      maximumFractionDigits: decimals,
    });
  };

  window.updatePaginationButtons = function (type, totalPages, currentPage) {
    const container = document.getElementById(`${type}PageNumbers`);
    if (!container) return;

    if (totalPages <= 1) {
      container.innerHTML = '';
    } else {
      let buttons = '';
      const maxButtons = 5;
      let startPage = Math.max(0, currentPage - Math.floor(maxButtons / 2));
      let endPage = Math.min(totalPages - 1, startPage + maxButtons - 1);

      if (endPage - startPage + 1 < maxButtons) {
        startPage = Math.max(0, endPage - maxButtons + 1);
      }

      for (let i = startPage; i <= endPage; i++) {
        buttons += `<div class="page-number ${i === currentPage ? 'active' : ''}" onclick="goToPage('${type}', ${i})">${i + 1}</div>`;
      }

      container.innerHTML = buttons;
    }

    const suffix = type.charAt(0).toUpperCase() + type.slice(1);
    const prevBtn = document.getElementById(`prevPage${suffix}`);
    const nextBtn = document.getElementById(`nextPage${suffix}`);

    if (prevBtn) prevBtn.disabled = currentPage === 0;
    if (nextBtn) nextBtn.disabled = currentPage >= totalPages - 1;
  };
})();
