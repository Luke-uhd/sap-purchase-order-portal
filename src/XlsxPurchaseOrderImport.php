<?php
declare(strict_types=1);

namespace App;

final class XlsxPurchaseOrderImport
{
    private const NS_MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const NS_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    public const HEADERS = [
        'DocEntry',
        'DocNum',
        'LineNum',
        'CardCode',
        'CardName',
        'ItemCode',
        'Descricao',
        'QuantidadeAtual',
        'NovaQuantidade',
        'EntregaAtual',
        'NovaDataEntrega',
        'Acao',
    ];

    public static function createTemplate(array $rows, string $outputFile): void
    {
        if (!class_exists(\ZipArchive::class) || !class_exists(\DOMDocument::class)) {
            throw new \RuntimeException('Extensões ZIP/XML indisponíveis no PHP.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($outputFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Não foi possível criar o arquivo XLSX.');
        }

        try {
            $zip->addFromString('[Content_Types].xml', self::contentTypesXml());
            $zip->addFromString('_rels/.rels', self::rootRelsXml());
            $zip->addFromString('xl/workbook.xml', self::workbookXml());
            $zip->addFromString('xl/_rels/workbook.xml.rels', self::workbookRelsXml());
            $zip->addFromString('xl/styles.xml', self::stylesXml());
            $zip->addFromString('xl/worksheets/sheet1.xml', self::sheetXml($rows));
        } finally {
            $zip->close();
        }
    }

    public static function readUpload(string $file): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('Extensão ZIP indisponível no PHP.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($file) !== true) {
            throw new \InvalidArgumentException('Arquivo XLSX inválido ou corrompido.');
        }

        try {
            $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
            if ($sheetXml === false) {
                throw new \InvalidArgumentException('Aba Alteracoes não encontrada no XLSX.');
            }

            $sharedStrings = self::readSharedStrings($zip);
            return self::readSheetRows($sheetXml, $sharedStrings);
        } finally {
            $zip->close();
        }
    }

    private static function readSharedStrings(\ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }

        $doc = self::loadXml($xml, 'sharedStrings.xml');
        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('x', self::NS_MAIN);

        $strings = [];
        foreach ($xpath->query('//x:si') as $node) {
            $value = '';
            foreach ($xpath->query('.//x:t', $node) as $text) {
                $value .= $text->textContent;
            }
            $strings[] = $value;
        }

        return $strings;
    }

    private static function readSheetRows(string $xml, array $sharedStrings): array
    {
        $doc = self::loadXml($xml, 'sheet1.xml');
        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('x', self::NS_MAIN);

        $rows = [];

        foreach ($xpath->query('//x:sheetData/x:row') as $rowNode) {
            $row = [];

            foreach ($xpath->query('./x:c', $rowNode) as $cell) {
                $reference = (string) $cell->attributes->getNamedItem('r')?->nodeValue;
                $column = self::columnIndexFromReference($reference);

                $type = (string) $cell->attributes->getNamedItem('t')?->nodeValue;
                $valueNode = $xpath->query('./x:v', $cell)->item(0);
                $inlineNode = $xpath->query('./x:is/x:t', $cell)->item(0);
                $value = '';

                if ($inlineNode !== null) {
                    $value = $inlineNode->textContent;
                } elseif ($valueNode !== null) {
                    $raw = $valueNode->textContent;
                    $value = $type === 's'
                        ? ($sharedStrings[(int) $raw] ?? '')
                        : $raw;
                }

                $formula = $xpath->query('./x:f', $cell)->item(0);
                if ($formula !== null && $column >= 8) {
                    throw new \InvalidArgumentException(
                        'Fórmula não permitida nas colunas editáveis do modelo.'
                    );
                }

                $row[$column] = trim($value);
            }

            if ($row) {
                $rows[] = $row;
            }
        }

        if (!$rows) {
            throw new \InvalidArgumentException('Planilha vazia.');
        }

        $header = [];
        foreach (self::HEADERS as $index => $name) {
            $header[] = $rows[0][$index] ?? '';
        }

        if ($header !== self::HEADERS) {
            throw new \InvalidArgumentException(
                'Cabeçalhos inválidos. Baixe novamente o modelo XLSX do sistema.'
            );
        }

        $data = [];
        foreach (array_slice($rows, 1) as $position => $row) {
            $empty = true;
            $mapped = [];

            foreach (self::HEADERS as $index => $name) {
                $value = trim((string) ($row[$index] ?? ''));
                $mapped[$name] = $value;
                if ($value !== '') {
                    $empty = false;
                }
            }

            if (!$empty) {
                $mapped['_row'] = $position + 2;
                $data[] = $mapped;
            }
        }

        return $data;
    }

    private static function sheetXml(array $rows): string
    {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = false;

        $worksheet = $doc->createElementNS(self::NS_MAIN, 'worksheet');
        $worksheet->setAttribute('xmlns:r', self::NS_REL);
        $doc->appendChild($worksheet);

        $sheetViews = $doc->createElement('sheetViews');
        $sheetView = $doc->createElement('sheetView');
        $sheetView->setAttribute('workbookViewId', '0');
        $sheetViews->appendChild($sheetView);
        $worksheet->appendChild($sheetViews);

        $cols = $doc->createElement('cols');
        $widths = [12, 12, 10, 16, 34, 18, 38, 18, 18, 16, 18, 14];
        foreach ($widths as $index => $width) {
            $col = $doc->createElement('col');
            $col->setAttribute('min', (string) ($index + 1));
            $col->setAttribute('max', (string) ($index + 1));
            $col->setAttribute('width', (string) $width);
            $col->setAttribute('customWidth', '1');
            $cols->appendChild($col);
        }
        $worksheet->appendChild($cols);

        $sheetData = $doc->createElement('sheetData');
        $worksheet->appendChild($sheetData);

        self::appendRow($doc, $sheetData, 1, self::HEADERS, true);

        $line = 2;
        foreach ($rows as $row) {
            self::appendRow($doc, $sheetData, $line++, [
                $row['DocEntry'] ?? '',
                $row['DocNum'] ?? '',
                $row['LineNum'] ?? '',
                $row['CardCode'] ?? '',
                $row['CardName'] ?? '',
                $row['ItemCode'] ?? '',
                $row['Descricao'] ?? '',
                $row['QuantidadeAtual'] ?? '',
                '',
                $row['EntregaAtual'] ?? '',
                '',
                'ATUALIZAR',
            ]);
        }


        return $doc->saveXML() ?: '';
    }

    private static function appendRow(
        \DOMDocument $doc,
        \DOMElement $sheetData,
        int $rowNumber,
        array $values,
        bool $header = false
    ): void {
        $row = $doc->createElement('row');
        $row->setAttribute('r', (string) $rowNumber);

        foreach ($values as $index => $value) {
            $reference = self::columnLetter($index + 1) . $rowNumber;
            $cell = $doc->createElement('c');
            $cell->setAttribute('r', $reference);
            $cell->setAttribute('t', 'inlineStr');
            if ($header) {
                $cell->setAttribute('s', '1');
            } elseif ($index >= 8 && $index <= 11) {
                $cell->setAttribute('s', '2');
            }

            $inline = $doc->createElement('is');
            $text = $doc->createElement('t');
            $text->appendChild($doc->createTextNode((string) $value));
            $inline->appendChild($text);
            $cell->appendChild($inline);
            $row->appendChild($cell);
        }

        $sheetData->appendChild($row);
    }

    private static function loadXml(string $xml, string $name): \DOMDocument
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $doc = new \DOMDocument();
            $ok = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_COMPACT);

            if (!$ok) {
                throw new \InvalidArgumentException("XML inválido em {$name}.");
            }

            return $doc;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private static function columnIndexFromReference(string $reference): int
    {
        if (!preg_match('/^([A-Z]+)\d+$/', $reference, $match)) {
            return 0;
        }

        $letters = $match[1];
        $index = 0;

        for ($i = 0, $length = strlen($letters); $i < $length; $i++) {
            $index = ($index * 26) + (ord($letters[$i]) - 64);
        }

        return $index - 1;
    }

    private static function columnLetter(int $index): string
    {
        $result = '';

        while ($index > 0) {
            $index--;
            $result = chr(($index % 26) + 65) . $result;
            $index = intdiv($index, 26);
        }

        return $result;
    }

    private static function contentTypesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';
    }

    private static function rootRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private static function workbookXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="' . self::NS_MAIN . '" xmlns:r="' . self::NS_REL . '">'
            . '<sheets><sheet name="Alteracoes" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';
    }

    private static function workbookRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
    }

    private static function stylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="' . self::NS_MAIN . '">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFF2F2F2"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="3">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            . '<xf numFmtId="0" fontId="0" fillId="2" borderId="0" xfId="0" applyFill="1"/>'
            . '</cellXfs>'
            . '</styleSheet>';
    }
}
