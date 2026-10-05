<?php

declare(strict_types=1);

final class InventarioMateriaPrimaXlsxWriter
{
  public static function cell($value, string $type = 'string', string $style = 'default'): array
  {
    return ['value' => $value, 'type' => $type, 'style' => $style];
  }

  public static function create(array $sheets, string $title): string
  {
    if (!class_exists('ZipArchive')) throw new RuntimeException('La extensión ZIP no está disponible.');
    $path = tempnam(sys_get_temp_dir(), 'inventario-mp-');
    if ($path === false) throw new RuntimeException('No fue posible crear el archivo temporal.');

    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
      @unlink($path);
      throw new RuntimeException('No fue posible crear el archivo XLSX.');
    }

    $zip->addFromString('[Content_Types].xml', self::contentTypes(count($sheets)));
    $zip->addFromString('_rels/.rels', self::rootRelationships());
    $zip->addFromString('docProps/app.xml', self::appProperties($sheets));
    $zip->addFromString('docProps/core.xml', self::coreProperties($title));
    $zip->addFromString('xl/workbook.xml', self::workbook($sheets));
    $zip->addFromString('xl/_rels/workbook.xml.rels', self::workbookRelationships(count($sheets)));
    $zip->addFromString('xl/styles.xml', self::styles());
    foreach (array_values($sheets) as $index => $sheet) {
      $zip->addFromString('xl/worksheets/sheet' . ($index + 1) . '.xml', self::worksheet($sheet));
    }
    $zip->close();
    return $path;
  }

  private static function worksheet(array $sheet): string
  {
    $rows = array_values((array)($sheet['rows'] ?? []));
    $widths = array_values((array)($sheet['widths'] ?? []));
    $headerRow = max(1, (int)($sheet['header_row'] ?? 4));
    $columnCount = max(count($widths), self::maxColumns($rows));
    $lastColumn = self::columnName(max(1, $columnCount));
    $lastRow = max(1, count($rows));

    $cols = '';
    foreach ($widths as $index => $width) {
      $col = $index + 1;
      $cols .= '<col min="' . $col . '" max="' . $col . '" width="' . max(5, (float)$width) . '" customWidth="1"/>';
    }

    $rowXml = '';
    foreach ($rows as $rowIndex => $row) {
      $number = $rowIndex + 1;
      $height = $number === 1 ? ' ht="24" customHeight="1"' : ($number === $headerRow ? ' ht="32" customHeight="1"' : '');
      $rowXml .= '<row r="' . $number . '"' . $height . '>';
      foreach (array_values((array)$row) as $columnIndex => $cell) {
        $rowXml .= self::cellXml($number, $columnIndex + 1, (array)$cell);
      }
      $rowXml .= '</row>';
    }

    $autoFilter = $lastRow >= $headerRow
      ? '<autoFilter ref="A' . $headerRow . ':' . $lastColumn . $lastRow . '"/>'
      : '';

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
      . '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>'
      . '<dimension ref="A1:' . $lastColumn . $lastRow . '"/>'
      . '<sheetViews><sheetView workbookViewId="0" showGridLines="0">'
      . '<pane ySplit="' . $headerRow . '" topLeftCell="A' . ($headerRow + 1) . '" activePane="bottomLeft" state="frozen"/>'
      . '</sheetView></sheetViews><sheetFormatPr defaultRowHeight="18"/>'
      . ($cols !== '' ? '<cols>' . $cols . '</cols>' : '')
      . '<sheetData>' . $rowXml . '</sheetData>'
      . $autoFilter
      . '<mergeCells count="1"><mergeCell ref="A1:' . $lastColumn . '1"/></mergeCells>'
      . '<pageMargins left="0.25" right="0.25" top="0.4" bottom="0.4" header="0.2" footer="0.2"/>'
      . '<pageSetup orientation="landscape" fitToWidth="1" fitToHeight="0" paperSize="9"/>'
      . '</worksheet>';
  }

  private static function cellXml(int $row, int $column, array $cell): string
  {
    $reference = self::columnName($column) . $row;
    $style = self::styleIndex((string)($cell['style'] ?? 'default'));
    $value = $cell['value'] ?? null;
    $type = (string)($cell['type'] ?? 'string');

    if ($value === null || $value === '') {
      return '<c r="' . $reference . '" s="' . $style . '" t="inlineStr"><is><t>—</t></is></c>';
    }

    if (in_array($type, ['number', 'integer', 'date', 'datetime', 'percent_points'], true)) {
      if ($style === 0) {
        $style = ['number' => 7, 'integer' => 12, 'date' => 8, 'datetime' => 9, 'percent_points' => 10][$type] ?? 7;
      }
      return '<c r="' . $reference . '" s="' . $style . '"><v>' . self::xmlNumber((float)$value) . '</v></c>';
    }

    return '<c r="' . $reference . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">'
      . self::xml((string)$value) . '</t></is></c>';
  }

  private static function styleIndex(string $style): int
  {
    return [
      'default' => 0, 'header' => 1, 'title' => 2, 'verde' => 3,
      'amarillo' => 4, 'rojo' => 5, 'gris' => 6, 'number' => 7, 'integer' => 12,
      'date' => 8, 'datetime' => 9, 'percent_points' => 10, 'subtitle' => 11,
    ][$style] ?? 0;
  }

  private static function maxColumns(array $rows): int
  {
    $maximum = 0;
    foreach ($rows as $row) $maximum = max($maximum, count((array)$row));
    return $maximum;
  }

  private static function columnName(int $number): string
  {
    $name = '';
    while ($number > 0) {
      $number--;
      $name = chr(65 + ($number % 26)) . $name;
      $number = intdiv($number, 26);
    }
    return $name;
  }

  private static function xml(string $value): string
  {
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
  }

  private static function xmlNumber(float $value): string
  {
    return rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');
  }

  private static function contentTypes(int $sheetCount): string
  {
    $sheets = '';
    for ($i = 1; $i <= $sheetCount; $i++) {
      $sheets .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
    }
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
      . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
      . '<Default Extension="xml" ContentType="application/xml"/>'
      . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
      . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
      . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
      . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
      . $sheets . '</Types>';
  }

  private static function rootRelationships(): string
  {
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
      . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
      . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
      . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
      . '</Relationships>';
  }

  private static function workbook(array $sheets): string
  {
    $sheetXml = '';
    foreach (array_values($sheets) as $index => $sheet) {
      $sheetXml .= '<sheet name="' . self::xml((string)($sheet['name'] ?? ('Hoja ' . ($index + 1)))) . '" sheetId="' . ($index + 1) . '" r:id="rId' . ($index + 1) . '"/>';
    }
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
      . '<bookViews><workbookView xWindow="0" yWindow="0" windowWidth="28800" windowHeight="17400"/></bookViews>'
      . '<sheets>' . $sheetXml . '</sheets><calcPr calcId="191029" fullCalcOnLoad="1"/></workbook>';
  }

  private static function workbookRelationships(int $sheetCount): string
  {
    $relationships = '';
    for ($i = 1; $i <= $sheetCount; $i++) {
      $relationships .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
    }
    $relationships .= '<Relationship Id="rId' . ($sheetCount + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $relationships . '</Relationships>';
  }

  private static function styles(): string
  {
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
      . '<numFmts count="2"><numFmt numFmtId="164" formatCode="0.00&quot;%&quot;"/><numFmt numFmtId="165" formatCode="dd/mm/yyyy"/></numFmts>'
      . '<fonts count="4">'
      . '<font><sz val="10"/><name val="Arial"/><color rgb="FF24364B"/></font>'
      . '<font><b/><sz val="10"/><name val="Arial"/><color rgb="FFFFFFFF"/></font>'
      . '<font><b/><sz val="15"/><name val="Arial"/><color rgb="FF102A43"/></font>'
      . '<font><b/><sz val="10"/><name val="Arial"/><color rgb="FF111827"/></font>'
      . '</fonts>'
      . '<fills count="7"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
      . '<fill><patternFill patternType="solid"><fgColor rgb="FF174D6B"/><bgColor indexed="64"/></patternFill></fill>'
      . '<fill><patternFill patternType="solid"><fgColor rgb="FF2E8B57"/><bgColor indexed="64"/></patternFill></fill>'
      . '<fill><patternFill patternType="solid"><fgColor rgb="FFFACC15"/><bgColor indexed="64"/></patternFill></fill>'
      . '<fill><patternFill patternType="solid"><fgColor rgb="FFC94436"/><bgColor indexed="64"/></patternFill></fill>'
      . '<fill><patternFill patternType="solid"><fgColor rgb="FF94A3B8"/><bgColor indexed="64"/></patternFill></fill></fills>'
      . '<borders count="2"><border/><border><left/><right/><top/><bottom style="thin"><color rgb="FFDCE5ED"/></bottom><diagonal/></border></borders>'
      . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
      . '<cellXfs count="13">'
      . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyAlignment="1"><alignment vertical="center"/></xf>'
      . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
      . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="center"/></xf>'
      . '<xf numFmtId="4" fontId="1" fillId="3" borderId="1" xfId="0" applyNumberFormat="1"><alignment horizontal="right" vertical="center"/></xf>'
      . '<xf numFmtId="4" fontId="3" fillId="4" borderId="1" xfId="0" applyNumberFormat="1"><alignment horizontal="right" vertical="center"/></xf>'
      . '<xf numFmtId="4" fontId="1" fillId="5" borderId="1" xfId="0" applyNumberFormat="1"><alignment horizontal="right" vertical="center"/></xf>'
      . '<xf numFmtId="4" fontId="1" fillId="6" borderId="1" xfId="0" applyNumberFormat="1"><alignment horizontal="right" vertical="center"/></xf>'
      . '<xf numFmtId="4" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1"><alignment horizontal="right" vertical="center"/></xf>'
      . '<xf numFmtId="165" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1"><alignment horizontal="center" vertical="center"/></xf>'
      . '<xf numFmtId="22" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1"><alignment horizontal="center" vertical="center"/></xf>'
      . '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1"><alignment horizontal="right" vertical="center"/></xf>'
      . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"><alignment vertical="center"/></xf>'
      . '<xf numFmtId="3" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
      . '</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
  }

  private static function appProperties(array $sheets): string
  {
    $titles = '';
    foreach ($sheets as $sheet) $titles .= '<vt:lpstr>' . self::xml((string)($sheet['name'] ?? 'Hoja')) . '</vt:lpstr>';
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
      . '<Application>Reportes Direccion</Application><TitlesOfParts><vt:vector size="' . count($sheets) . '" baseType="lpstr">' . $titles . '</vt:vector></TitlesOfParts></Properties>';
  }

  private static function coreProperties(string $title): string
  {
    $now = gmdate('Y-m-d\TH:i:s\Z');
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
      . '<dc:title>' . self::xml($title) . '</dc:title><dc:creator>Reportes Direccion</dc:creator>'
      . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created>'
      . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:modified></cp:coreProperties>';
  }
}
