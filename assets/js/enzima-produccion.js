(function () {
  const reportData = window.reportData || {};
  const reportConfig = window.reportConfig || {};

  let datosAnioAnterior = reportData.datosAnioAnterior || [];
  let datosAnioActual = reportData.datosAnioActual || [];
  let reporteData = reportData.reporte || [];
  let chartData = reportData.chartData || {};
  let maxRatio = Number(reportConfig.maxRatio || 0);

  const cardsPorPagina = Number(reportConfig.cardsPorPagina || 9);
  const filasPorPagina = Number(reportConfig.filasPorPagina || 15);
  const anioAnterior = reportConfig.anioAnterior || '';
  const anioActual = reportConfig.anioActual || '';
  const intervaloActualizacion = Number(
    reportConfig.intervaloActualizacion || 300000
  );
  const metricaTitulo = reportConfig.metricaTitulo || 'Consumo';
  const metricaUnidad = reportConfig.metricaUnidad || 'kg';
  const modo = reportConfig.modo || 'consumo';

  let currentCardsAnioAnteriorPage = 0;
  let currentCardsAnioActualPage = 0;
  let currentTablePage = 0;
  let currentFilter = 'all';
  let currentSort = { column: 'semana', direction: 'desc' };

  let chart;
  let autoUpdateEnabled = true;
  let updateTimer = intervaloActualizacion / 1000;
  let timerInterval;
  let countdownInterval;
  let isUpdating = false;

  function formatMetricValue(num, decimals = 2) {
    if (num === undefined || num === null) return '-';
    if (modo === 'costo') return '$ ' + formatNumber(num, decimals);
    return formatNumber(num, decimals) + ' ' + metricaUnidad;
  }

  function formatProductionValue(num, decimals = 2) {
    if (num === undefined || num === null) return '-';
    return formatNumber(num, decimals) + ' kg';
  }

  function renderCardsAnioAnterior() {
    const totalPages = Math.ceil(datosAnioAnterior.length / cardsPorPagina);
    const start = currentCardsAnioAnteriorPage * cardsPorPagina;
    const paginatedCards = datosAnioAnterior.slice(
      start,
      start + cardsPorPagina
    );

    const container = document.getElementById('cardsAnioAnteriorContainer');
    if (!container) return;

    if (paginatedCards.length === 0) {
      container.innerHTML = `<div class="empty-state" style="grid-column: 1/-1;"><i class="fas fa-inbox"></i><p>No hay datos para ${anioAnterior}</p></div>`;
    } else {
      container.innerHTML = paginatedCards
        .map((card) => {
          const ancho =
            card.ratio !== null && maxRatio > 0
              ? (card.ratio / maxRatio) * 100
              : 0;
          return `
          <div class="week-card b-${card.color}">
            <div class="week-head">
              <div>
                <div class="week-title">${escapeHtml(card.semana_iso)}</div>
                <div class="week-range">${escapeHtml(card.semana_inicio)} al ${escapeHtml(card.semana_fin)}</div>
              </div>
              <div class="luz-sm" style="background: ${card.colorHex}; box-shadow: 0 2px 8px ${card.colorHex}40;"></div>
            </div>
            <div class="metric">
              <div class="a"><i class="fas ${modo === 'costo' ? 'fa-dollar-sign' : 'fa-flask'}"></i> ${escapeHtml(metricaTitulo)}</div>
              <div class="b">${formatMetricValue(card.quimicos, modo === 'costo' ? 2 : 2)}</div>
            </div>
            <div class="metric">
              <div class="a"><i class="fas fa-box"></i> Kilos producidos</div>
              <div class="b">${formatProductionValue(card.produccion, 2)}</div>
            </div>
            <div class="metric">
              <div class="a"><i class="fas fa-chart-line"></i> ${modo === 'costo' ? 'Promedio' : 'Ratio'}</div>
              <div class="b">${card.ratio !== null ? formatNumber(card.ratio, 2) : '-'}</div>
            </div>
            <div class="metric">
              <div class="a"><i class="fas fa-flag-checkered"></i> Estado</div>
              <div class="b" style="color: ${card.colorHex};">${card.estado}</div>
            </div>
            <div class="bar-wrap">
              <div class="bar-label">Nivel visual</div>
              <div class="bar">
                <div class="fill" style="width: ${ancho}%; background: ${card.colorHex};"></div>
              </div>
            </div>
          </div>
        `;
        })
        .join('');
    }

    updatePaginationButtons(
      'cardsAnioAnterior',
      totalPages,
      currentCardsAnioAnteriorPage
    );
  }

  function renderCardsAnioActual() {
    const totalPages = Math.ceil(datosAnioActual.length / cardsPorPagina);
    const start = currentCardsAnioActualPage * cardsPorPagina;
    const paginatedCards = datosAnioActual.slice(start, start + cardsPorPagina);

    const container = document.getElementById('cardsAnioActualContainer');
    if (!container) return;

    if (paginatedCards.length === 0) {
      container.innerHTML = `<div class="empty-state" style="grid-column: 1/-1;"><i class="fas fa-inbox"></i><p>No hay datos para ${anioActual}</p></div>`;
    } else {
      container.innerHTML = paginatedCards
        .map((card) => {
          const ancho =
            card.ratio !== null && maxRatio > 0
              ? (card.ratio / maxRatio) * 100
              : 0;
          return `
          <div class="week-card b-${card.color}">
            <div class="week-head">
              <div>
                <div class="week-title">${escapeHtml(card.semana_iso)}</div>
                <div class="week-range">${escapeHtml(card.semana_inicio)} al ${escapeHtml(card.semana_fin)}</div>
              </div>
              <div class="luz-sm" style="background: ${card.colorHex}; box-shadow: 0 2px 8px ${card.colorHex}40;"></div>
            </div>
            <div class="metric">
              <div class="a"><i class="fas ${modo === 'costo' ? 'fa-dollar-sign' : 'fa-flask'}"></i> ${escapeHtml(metricaTitulo)}</div>
              <div class="b">${formatMetricValue(card.quimicos, modo === 'costo' ? 2 : 2)}</div>
            </div>
            <div class="metric">
              <div class="a"><i class="fas fa-box"></i> Kilos producidos</div>
              <div class="b">${formatProductionValue(card.produccion, 2)}</div>
            </div>
            <div class="metric">
              <div class="a"><i class="fas fa-chart-line"></i> ${modo === 'costo' ? 'Promedio' : 'Ratio'}</div>
              <div class="b">${card.ratio !== null ? formatNumber(card.ratio, 2) : '-'}</div>
            </div>
            <div class="metric">
              <div class="a"><i class="fas fa-flag-checkered"></i> Estado</div>
              <div class="b" style="color: ${card.colorHex};">${card.estado}</div>
            </div>
            <div class="bar-wrap">
              <div class="bar-label">Nivel visual</div>
              <div class="bar">
                <div class="fill" style="width: ${ancho}%; background: ${card.colorHex};"></div>
              </div>
            </div>
          </div>
        `;
        })
        .join('');
    }

    updatePaginationButtons(
      'cardsAnioActual',
      totalPages,
      currentCardsAnioActualPage
    );
  }

  function getFilteredData() {
    let filtered = [...reporteData];

    if (currentFilter !== 'all') {
      filtered = filtered.filter((row) => row.color === currentFilter);
    }

    const searchInput = document.getElementById('searchInput');
    const searchTerm = searchInput ? searchInput.value.toLowerCase() : '';

    if (searchTerm) {
      filtered = filtered.filter(
        (row) =>
          row.semana_iso.toLowerCase().includes(searchTerm) ||
          row.semana_inicio.includes(searchTerm) ||
          row.semana_fin.includes(searchTerm)
      );
    }

    return filtered;
  }

  function renderTable() {
    let filtered = getFilteredData();

    filtered.sort((a, b) => {
      let aVal, bVal;

      switch (currentSort.column) {
        case 'semana':
          aVal = a.semana_iso;
          bVal = b.semana_iso;
          break;
        case 'quimicos':
          aVal = a.quimicos;
          bVal = b.quimicos;
          break;
        case 'costo_promedio':
          aVal = a.costo_promedio_semana ?? -1;
          bVal = b.costo_promedio_semana ?? -1;
          break;
        case 'costo_base':
          aVal = a.costo_base ?? -1;
          bVal = b.costo_base ?? -1;
          break;
        case 'diferencia_precio':
          aVal = a.diferencia_precio ?? -1;
          bVal = b.diferencia_precio ?? -1;
          break;
        case 'consumo_kg':
          aVal = a.consumo_kg ?? -1;
          bVal = b.consumo_kg ?? -1;
          break;
        case 'produccion':
          aVal = a.produccion;
          bVal = b.produccion;
          break;
        case 'ratio':
          aVal = a.ratio ?? -1;
          bVal = b.ratio ?? -1;
          break;
        default:
          aVal = a.periodo;
          bVal = b.periodo;
      }

      return currentSort.direction === 'asc'
        ? aVal > bVal
          ? 1
          : -1
        : aVal < bVal
          ? 1
          : -1;
    });

    const totalPages = Math.ceil(filtered.length / filasPorPagina);
    const start = currentTablePage * filasPorPagina;
    const paginatedRows = filtered.slice(start, start + filasPorPagina);

    const tbody = document.getElementById('tableBody');
    if (!tbody) return;

    if (paginatedRows.length === 0) {
      const colspanVal = modo === 'impacto' ? '9' : '8';
      tbody.innerHTML = `<tr><td colspan="${colspanVal}"><div class="empty-state"><i class="fas fa-inbox"></i><p>No hay datos para mostrar</p></div></td></tr>`;
    } else {
      tbody.innerHTML = paginatedRows
        .map((row) => {
          const porcentaje =
            row.ratio !== null && maxRatio > 0
              ? (row.ratio / maxRatio) * 100
              : 0;

          // Para modo impacto, incluir columnas adicionales (sin producción, sin inicio/fin)
          if (modo === 'impacto') {
            return `
          <tr>
            <td><strong>${escapeHtml(row.semana_iso)}</strong></td>
            <td>$ ${row.costo_promedio_semana !== null ? formatNumber(row.costo_promedio_semana, 2) : '-'}</td>
            <td>$ ${row.costo_base !== null ? formatNumber(row.costo_base, 2) : '-'}</td>
            <td>$ ${row.diferencia_precio !== null ? formatNumber(row.diferencia_precio, 2) : '-'}</td>
            <td>${row.consumo_kg !== null ? formatNumber(row.consumo_kg, 2) + ' kg' : '-'}</td>
            <td>$ ${formatNumber(row.quimicos, 2)}</td>
            <td>${row.ratio !== null ? '$ ' + formatNumber(row.ratio, 2) : '-'}</td>
            <td>
              <span class="status-badge" style="background: ${row.colorHex}15; color: ${row.colorHex};">
                <span class="status-dot" style="background: ${row.colorHex};"></span>
                ${row.estado}
              </span>
            </td>
            <td>
              <div class="progress-bar">
                <div class="progress-fill" style="width: ${porcentaje}%; background: ${row.colorHex};"></div>
              </div>
            </td>
          </tr>
        `;
          }

          // Para otros modos
          return `
          <tr>
            <td><strong>${escapeHtml(row.semana_iso)}</strong></td>
            <td>${escapeHtml(row.semana_inicio)}</td>
            <td>${escapeHtml(row.semana_fin)}</td>
            <td>${modo === 'costo' ? '$ ' + formatNumber(row.quimicos, 2) : formatNumber(row.quimicos, 2) + ' ' + metricaUnidad}</td>
            <td>${formatProductionValue(row.produccion, 2)}</td>
            <td>${row.ratio !== null ? formatNumber(row.ratio, 2) : '-'}</td>
            <td>
              <span class="status-badge" style="background: ${row.colorHex}15; color: ${row.colorHex};">
                <span class="status-dot" style="background: ${row.colorHex};"></span>
                ${row.estado}
              </span>
            </td>
            <td>
              <div class="progress-bar">
                <div class="progress-fill" style="width: ${porcentaje}%; background: ${row.colorHex};"></div>
              </div>
            </td>
          </tr>
        `;
        })
        .join('');
    }

    updatePaginationButtons('table', totalPages, currentTablePage);
  }

  function initChart() {
    const canvas = document.getElementById('ratioChart');
    if (!canvas) return;

    const ctx = canvas.getContext('2d');

    const getWeekNumber = (semanaIso) => {
      const parts = semanaIso.split('-S');
      return parts.length === 2 ? parseInt(parts[1], 10) : null;
    };

    const datosPorSemana = {};
    for (let i = 1; i <= 53; i++) {
      datosPorSemana[i] = {
        ratioAnioAnterior: null,
        ratioAnioActual: null,
        colorAnioActual: '#94a3b8',
      };
    }

    datosAnioAnterior.forEach((item) => {
      const numSemana = getWeekNumber(item.semana_iso);
      if (numSemana && numSemana >= 1 && numSemana <= 53) {
        datosPorSemana[numSemana].ratioAnioAnterior =
          modo === 'impacto' ? item.impacto_total || 0 : item.ratio;
      }
    });

    datosAnioActual.forEach((item) => {
      const numSemana = getWeekNumber(item.semana_iso);
      if (numSemana && numSemana >= 1 && numSemana <= 53) {
        datosPorSemana[numSemana].ratioAnioActual =
          modo === 'impacto' ? item.impacto_total || 0 : item.ratio;
        datosPorSemana[numSemana].colorAnioActual = item.colorHex;
      }
    });

    const semanasOrdenadas = Array.from({ length: 53 }, (_, i) => i + 1);
    const labels = semanasOrdenadas.map((s) => `Semana ${s}`);
    const ratiosAnioAnterior = semanasOrdenadas.map(
      (s) => datosPorSemana[s].ratioAnioAnterior
    );
    const ratiosAnioActual = semanasOrdenadas.map(
      (s) => datosPorSemana[s].ratioAnioActual
    );
    const coloresAnioActual = semanasOrdenadas.map(
      (s) => datosPorSemana[s].colorAnioActual
    );

    if (chart) chart.destroy();

    const datasets = [
      {
        label:
          modo === 'costo'
            ? `Promedio ${anioActual}`
            : modo === 'impacto'
              ? `Impacto ${anioActual}`
              : `Ratio ${anioActual}`,
        data: ratiosAnioActual,
        borderColor: '#2a35d4',
        backgroundColor: 'rgba(16, 185, 129, 0.05)',
        borderWidth: 3,
        pointRadius: 6,
        pointHoverRadius: 8,
        pointBackgroundColor: coloresAnioActual,
        pointBorderColor: 'white',
        pointBorderWidth: 2,
        tension: 0.3,
        fill: false,
      },
    ];

    if (anioAnterior !== 2025) {
      datasets.push({
        label:
          modo === 'impacto'
            ? `Impacto Base ${anioAnterior}`
            : `Base ${anioAnterior}`,
        data: ratiosAnioAnterior,
        borderColor: '#3b82f6',
        backgroundColor: 'rgba(59, 130, 246, 0.08)',
        borderDash: [6, 4],
        borderWidth: 2,
        pointRadius: 0,
        tension: 0.3,
        fill: false,
      });
    }

    const ratioBase = reportConfig.ratioBase || 0;
    let limiteAmarillo = null;
    let limiteVerde = null;

    if (modo === 'impacto') {
      limiteVerde = 0;
      limiteAmarillo = ratioBase * 1.06;

      datasets.push({
        label: 'Límite Amarillo',
        data: Array(53).fill(limiteAmarillo),
        borderColor: '#f59e0b',
        borderWidth: 2,
        backgroundColor: 'transparent',
        borderDash: [6, 4],
        fill: false,
        pointRadius: 0,
        tension: 0,
      });
    } else {
      limiteVerde = ratioBase;
      limiteAmarillo =
        ratioBase * (1 + (reportConfig.toleranciaPct || 10) / 100);

      datasets.push({
        label: 'Límite Amarillo',
        data: Array(53).fill(limiteAmarillo),
        borderColor: '#f59e0b',
        borderWidth: 2,
        backgroundColor: 'transparent',
        borderDash: [6, 4],
        fill: false,
        pointRadius: 0,
        tension: 0,
      });
    }

    const semaforoPlugin = {
      id: 'semaforoShading',
      afterDraw(chart) {
        const ctx = chart.ctx;
        const yAxis = chart.scales.y;
        const chartArea = chart.chartArea;
        if (!yAxis || !chartArea) return;

        let verdePx, amarilloPx;

        if (modo === 'impacto') {
          verdePx = yAxis.getPixelForValue(0);
          amarilloPx = yAxis.getPixelForValue(limiteAmarillo);

          ctx.save();

          // Zona Verde (Ahorro): desde 0 hacia abajo
          ctx.fillStyle = 'rgba(16, 185, 129, 0.15)';
          ctx.fillRect(
            chartArea.left,
            verdePx,
            chartArea.width,
            chartArea.bottom - verdePx
          );

          // Zona Amarilla (Cuidado): desde 0 hasta el límite
          ctx.fillStyle = 'rgba(245, 158, 11, 0.15)';
          ctx.fillRect(
            chartArea.left,
            amarilloPx,
            chartArea.width,
            verdePx - amarilloPx
          );

          // Zona Roja (Sobrecosto): desde el límite hacia arriba
          ctx.fillStyle = 'rgba(239, 68, 68, 0.15)';
          ctx.fillRect(
            chartArea.left,
            chartArea.top,
            chartArea.width,
            amarilloPx - chartArea.top
          );
        } else {
          verdePx = yAxis.getPixelForValue(limiteVerde);
          amarilloPx = yAxis.getPixelForValue(limiteAmarillo);

          ctx.save();

          // Zona Roja (Alto): valores superiores al límite (arriba)
          ctx.fillStyle = 'rgba(239, 68, 68, 0.15)';
          ctx.fillRect(
            chartArea.left,
            chartArea.top,
            chartArea.width,
            verdePx - chartArea.top
          );

          // Zona Amarilla (Cuidado): desde el base hasta el límite
          ctx.fillStyle = 'rgba(245, 158, 11, 0.15)';
          ctx.fillRect(
            chartArea.left,
            verdePx,
            chartArea.width,
            amarilloPx - verdePx
          );

          // Zona Verde (Óptimo): valores inferiores al límite (abajo)
          ctx.fillStyle = 'rgba(16, 185, 129, 0.15)';
          ctx.fillRect(
            chartArea.left,
            amarilloPx,
            chartArea.width,
            chartArea.bottom - amarilloPx
          );
        }

        ctx.restore();
      },
    };

    const chartPlugins = [];
    chartPlugins.push(semaforoPlugin);

    chart = new Chart(ctx, {
      type: 'line',
      data: {
        labels,
        datasets,
      },
      plugins: chartPlugins,
      options: {
        responsive: true,
        maintainAspectRatio: true,
        interaction: {
          mode: 'index',
          intersect: false,
        },
        plugins: {
          legend: {
            display: true,
            position: 'top',
          },
          tooltip: {
            backgroundColor: 'white',
            titleColor: '#0f172a',
            bodyColor: '#475569',
            borderColor: '#e2e8f0',
            borderWidth: 1,
            callbacks: {
              label: function (context) {
                const label = context.dataset.label || '';
                const value = context.parsed.y;
                if (value === null) {
                  return `${label}: Sin dato`;
                }

                if (modo === 'impacto') {
                  return `${label}: $ ${value.toFixed(2)}`;
                }

                const formatted =
                  modo === 'costo' ? `$ ${value.toFixed(2)}` : value.toFixed(2);

                return `${label}: ${formatted}`;
              },
            },
          },
          filler: {
            propagate: true,
          },
        },
        scales: {
          y: {
            grid: { color: '#e2e8f0' },
            title: {
              display: true,
              text:
                modo === 'costo'
                  ? 'Promedio de costo'
                  : modo === 'impacto'
                    ? 'Impacto ($)'
                    : 'Ratio (kg químico / kg producción)',
              color: '#64748b',
            },
          },
          x: {
            grid: { display: false },
            title: {
              display: true,
              text: 'Número de Semana',
              color: '#64748b',
            },
          },
        },
      },
    });
  }

  async function fetchUpdatedData() {
    try {
      const currentParams = new URLSearchParams(window.location.search);
      currentParams.set('t', Date.now().toString());

      const response = await fetch('data.php?' + currentParams.toString(), {
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          'Cache-Control': 'no-cache',
        },
      });

      if (!response.ok) throw new Error('Error en la respuesta');

      const data = await response.json();
      if (!data.ok) throw new Error(data.message || 'Error al actualizar');

      datosAnioAnterior = data.datosAnioAnterior || [];
      datosAnioActual = data.datosAnioActual || [];
      reporteData = data.reporte || [];
      chartData = data.chartData || {};
      maxRatio = Number(data.maxRatio || 0);

      const modoActualizado = data.modo || modo;

      const kpi1 = document.querySelector('.kpi-card:nth-child(1) .kpi-value');
      const kpi1Trend = document.querySelector(
        '.kpi-card:nth-child(1) .kpi-trend'
      );
      if (kpi1) {
        kpi1.textContent =
          modoActualizado === 'costo'
            ? '$ ' + formatNumber(data.totalQuimicosAnioAnterior, 2)
            : modoActualizado === 'impacto'
              ? '$ ' + formatNumber(data.totalQuimicosAnioAnterior, 2)
              : formatMetricValue(data.totalQuimicosAnioAnterior, 2);
      }
      if (kpi1Trend) {
        kpi1Trend.innerHTML = `vs ${anioActual}: ${
          modoActualizado === 'costo'
            ? '$ ' + formatNumber(data.totalQuimicosAnioActual, 2)
            : modoActualizado === 'impacto'
              ? '$ ' + formatNumber(data.totalQuimicosAnioActual, 2)
              : formatMetricValue(data.totalQuimicosAnioActual, 2)
        }`;
      }

      const kpi2 = document.querySelector('.kpi-card:nth-child(2) .kpi-value');
      const kpi2Trend = document.querySelector(
        '.kpi-card:nth-child(2) .kpi-trend'
      );
      if (kpi2)
        kpi2.textContent = formatProductionValue(
          data.totalProduccionAnioAnterior,
          1
        );
      if (kpi2Trend)
        kpi2Trend.innerHTML = `vs ${anioActual}: ${formatProductionValue(data.totalProduccionAnioActual, 1)}`;

      const kpi3 = document.querySelector('.kpi-card:nth-child(3) .kpi-value');
      const kpi3Trend = document.querySelector(
        '.kpi-card:nth-child(3) .kpi-trend'
      );
      if (kpi3)
        kpi3.textContent =
          data.ratioBase !== null ? formatNumber(data.ratioBase, 2) : '-';
      if (kpi3Trend)
        kpi3Trend.innerHTML = `${anioActual}: ${data.ratioPromedioAnioActual !== null ? formatNumber(data.ratioPromedioAnioActual, 2) : '-'}`;

      const variacionValue = document.querySelector(
        '.kpi-card:nth-child(4) .kpi-value'
      );
      if (variacionValue) {
        variacionValue.textContent =
          data.variacionRatio !== null
            ? (data.variacionRatio > 0 ? '+' : '') +
              formatNumber(data.variacionRatio, 1) +
              '%'
            : '-';
        variacionValue.className = `kpi-value ${(data.variacionRatio ?? 0) < 0 ? 'trend-down' : (data.variacionRatio ?? 0) > 0 ? 'trend-up' : ''}`;
      }

      const lastUpdate = document.getElementById('lastUpdateText');
      if (lastUpdate)
        lastUpdate.textContent = new Date().toLocaleTimeString('es-MX');

      renderCardsAnioAnterior();
      renderCardsAnioActual();
      renderTable();
      initChart();

      return true;
    } catch (error) {
      console.error('Error actualizando datos:', error);
      return false;
    }
  }

  async function manualUpdate() {
    if (isUpdating) return;

    isUpdating = true;

    const btn = document.getElementById('manualUpdateBtn');
    const indicator = document.getElementById('updateIndicator');
    const statusText = document.getElementById('updateStatusText');
    const overlay = document.getElementById('loadingOverlay');

    if (btn) btn.disabled = true;
    if (indicator) indicator.classList.add('updating');
    if (statusText) statusText.textContent = 'Actualizando...';
    if (overlay) overlay.classList.add('active');

    const success = await fetchUpdatedData();

    if (success) {
      if (statusText) statusText.textContent = 'Actualizado';
      if (indicator) indicator.classList.remove('updating');
      setTimeout(() => {
        if (statusText) statusText.textContent = 'Conectado';
      }, 2000);
    } else {
      if (statusText) statusText.textContent = 'Error';
      if (indicator) indicator.style.background = '#ef4444';
      setTimeout(() => {
        if (statusText) statusText.textContent = 'Conectado';
        if (indicator) {
          indicator.style.background = '#10b981';
          indicator.classList.remove('updating');
        }
      }, 3000);
    }

    if (btn) btn.disabled = false;
    if (overlay) overlay.classList.remove('active');

    isUpdating = false;
    updateTimer = intervaloActualizacion / 1000;
    updateTimerDisplay();
  }

  function updateTimerDisplay() {
    const el = document.getElementById('updateTimer');
    if (!el) return;

    const minutes = Math.floor(updateTimer / 60);
    const seconds = updateTimer % 60;
    el.textContent = `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
  }

  function startAutoUpdate() {
    if (timerInterval) clearInterval(timerInterval);
    if (countdownInterval) clearInterval(countdownInterval);

    timerInterval = setInterval(async () => {
      if (autoUpdateEnabled && !isUpdating) {
        await manualUpdate();
      }
      updateTimer = intervaloActualizacion / 1000;
      updateTimerDisplay();
    }, intervaloActualizacion);

    countdownInterval = setInterval(() => {
      if (updateTimer > 0) {
        updateTimer--;
        updateTimerDisplay();
      }
    }, 1000);
  }

  window.changeCardsAnioAnteriorPage = function (delta) {
    const totalPages = Math.ceil(datosAnioAnterior.length / cardsPorPagina);
    const newPage = currentCardsAnioAnteriorPage + delta;
    if (newPage >= 0 && newPage < totalPages) {
      currentCardsAnioAnteriorPage = newPage;
      renderCardsAnioAnterior();
    }
  };

  window.changeCardsAnioActualPage = function (delta) {
    const totalPages = Math.ceil(datosAnioActual.length / cardsPorPagina);
    const newPage = currentCardsAnioActualPage + delta;
    if (newPage >= 0 && newPage < totalPages) {
      currentCardsAnioActualPage = newPage;
      renderCardsAnioActual();
    }
  };

  window.changeTablePage = function (delta) {
    const filtered = getFilteredData();
    const totalPages = Math.ceil(filtered.length / filasPorPagina);
    const newPage = currentTablePage + delta;
    if (newPage >= 0 && newPage < totalPages) {
      currentTablePage = newPage;
      renderTable();
    }
  };

  window.goToPage = function (type, page) {
    if (type === 'cardsAnioAnterior') {
      currentCardsAnioAnteriorPage = page;
      renderCardsAnioAnterior();
    } else if (type === 'cardsAnioActual') {
      currentCardsAnioActualPage = page;
      renderCardsAnioActual();
    } else if (type === 'table') {
      currentTablePage = page;
      renderTable();
    }
  };

  window.manualUpdate = manualUpdate;

  document.addEventListener('DOMContentLoaded', function () {
    const toggleBase = document.getElementById('toggleAnioAnteriorCards');
    if (toggleBase) {
      toggleBase.addEventListener('change', function (e) {
        const section = document.getElementById('cardsAnioAnteriorSection');
        if (section) section.classList.toggle('hidden', !e.target.checked);
      });
    }

    const autoToggle = document.getElementById('autoUpdateToggle');
    if (autoToggle) {
      autoToggle.addEventListener('change', function (e) {
        autoUpdateEnabled = e.target.checked;
        const status = document.getElementById('updateStatusText');
        if (status)
          status.textContent = autoUpdateEnabled ? 'Conectado' : 'Manual';
      });
    }

    document.querySelectorAll('.filter-btn').forEach((btn) => {
      btn.addEventListener('click', () => {
        document
          .querySelectorAll('.filter-btn')
          .forEach((b) => b.classList.remove('active'));
        btn.classList.add('active');
        currentFilter = btn.dataset.filter;
        currentTablePage = 0;
        renderTable();
      });
    });

    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
      searchInput.addEventListener('input', () => {
        currentTablePage = 0;
        renderTable();
      });
    }

    document.querySelectorAll('th[data-sort]').forEach((th) => {
      th.addEventListener('click', () => {
        const column = th.dataset.sort;
        if (currentSort.column === column) {
          currentSort.direction =
            currentSort.direction === 'asc' ? 'desc' : 'asc';
        } else {
          currentSort.column = column;
          currentSort.direction = 'desc';
        }
        currentTablePage = 0;
        renderTable();
      });
    });

    renderCardsAnioAnterior();
    renderCardsAnioActual();
    renderTable();
    initChart();
    startAutoUpdate();

    const lastUpdate = document.getElementById('lastUpdateText');
    if (lastUpdate)
      lastUpdate.textContent = new Date().toLocaleTimeString('es-MX');

    const loader = document.getElementById('pageLoader');
    if (loader) loader.classList.remove('active');
    document.body.classList.remove('page-loading');
  });
})();
