<?php

declare(strict_types=1);

/**
 * ReportBuilder - Clase base para generar reportes de métricas
 *
 * Centraliza la lógica común para reportes de consumo, costo e impacto.
 * Cada tipo de reporte específico debe extender esta clase e implementar
 * los métodos abstractos para sus particularidades.
 */
abstract class ReportBuilder
{
  protected PDO $pdoMovs;
  protected PDO $pdoProd;
  protected array $appConfig;
  protected array $config;

  protected string $fechaDesde;
  protected string $campoFechaMovs;
  protected float $toleranciaPct;
  protected ?string $cveMov;
  protected string $modo;
  protected string $campoCosto;

  protected int $anioActual;
  protected int $anioAnterior;
  protected int $cardsPorPagina;
  protected int $filasPorPagina;
  protected int $intervaloActualizacion;

  protected string $metricaNombre;
  protected string $badgeRatio;
  protected string $metricaTitulo;
  protected string $metricaUnidad;

  protected array $detallePorPeriodo = [];
  protected array $produccionPorPeriodo = [];

  public function __construct(array $appConfig, array $dbConfig, array $config)
  {
    $this->appConfig = $appConfig;
    $this->config = $config;

    // Validar e inicializar configuración
    $this->validateConfig();
    $this->initializeConnections($dbConfig);
    $this->initializeParameters();
  }

  /**
   * Valida la configuración básica
   */
  protected function validateConfig(): void
  {
    if (!isset($this->config['fecha_desde'])) {
      throw new RuntimeException('fecha_desde no configurada.');
    }

    if (!isset($this->config['campo_fecha_movs'])) {
      throw new RuntimeException('campo_fecha_movs no configurada.');
    }
  }

  /**
   * Inicializa conexiones a las bases de datos
   */
  protected function initializeConnections(array $dbConfig): void
  {
    try {
      $this->pdoMovs = conectar($dbConfig['movs']);
      $this->pdoProd = conectar($dbConfig['prod']);
    } catch (Throwable $e) {
      throw new RuntimeException('Error de conexión: ' . $e->getMessage(), 0, $e);
    }
  }

  /**
   * Inicializa todos los parámetros básicos
   */
  protected function initializeParameters(): void
  {
    $this->fechaDesde = $this->config['fecha_desde'];
    $this->campoFechaMovs = $this->config['campo_fecha_movs'];
    $this->toleranciaPct = (float)($this->config['tolerancia_pct'] ?? 10);
    $this->cveMov = $this->config['cve_mov'] ?? null;
    $this->modo = $this->config['modo'] ?? 'consumo';
    $this->campoCosto = $this->config['campo_costo'] ?? 'COST_ENT';

    $this->cardsPorPagina = (int)($this->appConfig['cards_por_pagina'] ?? 9);
    $this->filasPorPagina = (int)($this->appConfig['filas_por_pagina'] ?? 15);
    $this->intervaloActualizacion = (int)($this->appConfig['intervalo_actualizacion'] ?? 300000);

    $this->anioActual = (int)date('Y');
    $this->anioAnterior = $this->anioActual - 1;

    // Validar columnas dinámicas
    $this->validateColumnNames();

    // Configurar métrica según modo
    $this->configurarMetrica();
  }

  /**
   * Valida los nombres de columnas dinámicas
   */
  protected function validateColumnNames(): void
  {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $this->campoFechaMovs)) {
      throw new RuntimeException('Nombre de columna de fecha inválido en movs.');
    }

    if (!preg_match('/^[A-Za-z0-9_]+$/', $this->campoCosto)) {
      throw new RuntimeException('Nombre de columna de costo inválido en movs.');
    }
  }

  /**
   * Configura la métrica según el modo (consumo, costo, impacto)
   */
  protected function configurarMetrica(): void
  {
    $this->metricaNombre = 'consumo';
    $this->metricaUnidad = 'kg';

    if ($this->modo === 'costo') {
      $this->metricaNombre = 'costo';
      $this->metricaUnidad = '$';
    } elseif ($this->modo === 'impacto') {
      $this->metricaNombre = 'impacto';
      $this->metricaUnidad = '$';
    }
  }

  /**
   * Obtiene la expresión SQL para calcular consumo
   */
  protected function getConsumoExpression(): string
  {
    return "
            SUM(
                CASE
                    WHEN UPPER(TRIM(m.UNIUSU)) IN ('KG','KGS','KILO','KILOS') THEN m.CANT_PROD
                    WHEN UPPER(TRIM(m.UNIUSU)) IN ('G','GR','GRAMO','GRAMOS') THEN m.CANT_PROD / 1000
                    ELSE m.CANT_PROD
                END
            )
        ";
  }

  /**
   * Obtiene la expresión SQL para calcular costo promedio
   */
  protected function getCostoExpression(): string
  {
    return "AVG(m.`{$this->campoCosto}`)";
  }

  /**
   * Obtiene el campo de fecha SQL escapeado
   */
  protected function getDateFieldSql(): string
  {
    return "m.`{$this->campoFechaMovs}`";
  }

  /**
   * Resuelve el semáforo según el valor y modo
   */
  protected function resolverSemaforo(?float $valor, ?float $base, string $modo): array
  {
    if ($modo === 'impacto') {
      if ($valor === null) {
        return ['Sin dato', 'gris', '#94a3b8'];
      }

      if ($valor <= 0) {
        return ['Ahorro', 'verde', '#10b981'];
      }

      return ['Sobrecosto', 'rojo', '#ef4444'];
    }

    return semaforo($valor, $base, $this->toleranciaPct);
  }

  /**
   * Obtiene datos de producción semanal desde la BD
   */
  protected function fetchProductionData(): array
  {
    $dateField = $this->getDateFieldSql();
    $sqlProduccion = "
            SELECT
                YEARWEEK(tar_fecha, 3) AS periodo,
                DATE_FORMAT(tar_fecha, '%x-S%v') AS semana_iso,
                DATE_FORMAT(DATE_SUB(DATE(tar_fecha), INTERVAL WEEKDAY(tar_fecha) DAY), '%Y-%m-%d') AS semana_inicio,
                DATE_FORMAT(DATE_ADD(DATE(tar_fecha), INTERVAL (6 - WEEKDAY(tar_fecha)) DAY), '%Y-%m-%d') AS semana_fin,
                SUM(tar_kilos) AS kilos_producidos
            FROM tarimas
            WHERE YEAR(tar_fecha) IN (?, ?)
            GROUP BY YEARWEEK(tar_fecha, 3)
            ORDER BY periodo
        ";

    $stmt = $this->pdoProd->prepare($sqlProduccion);
    $stmt->execute([$this->anioAnterior, $this->anioActual]);

    $produccion = [];
    while ($row = $stmt->fetch()) {
      $periodo = (int)$row['periodo'];
      $produccion[$periodo] = $row;
    }

    return $produccion;
  }

  /**
   * Construye el reporte - método principal
   * Debe ser implementado por subclases
   */
  abstract public function build(): array;

  /**
   * Obtiene la query detallada - debe ser implementado por subclases
   */
  abstract protected function buildDetailQuery(): array;

  /**
   * Procesa los datos detallados - debe ser implementado por subclases
   */
  abstract protected function processReportData(array $detalle, array $produccion): array;
}
