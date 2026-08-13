<?php

declare(strict_types=1);

final class SiteBuilderSimpleZip
{
    private array $files = [];

    public function addFile(
        string $name,
        string $content
    ): void {
        $name =
            str_replace(
                '\\',
                '/',
                ltrim(
                    $name,
                    '/'
                )
            );

        if ($name === '') {
            throw new InvalidArgumentException(
                'ZIP_ENTRY_NAME_REQUIRED'
            );
        }

        $this->files[] = [
            'name' => $name,
            'content' => $content,
        ];
    }

    public function finish(): string
    {
        $local = '';
        $central = '';
        $offset = 0;
        [$dosTime, $dosDate] =
            self::dosTimeDate();

        foreach ($this->files as $file) {
            $name =
                (string)$file['name'];
            $content =
                (string)$file['content'];

            $crc =
                (int)sprintf(
                    '%u',
                    crc32($content)
                );

            $size =
                strlen($content);

            $flags =
                0x0800;

            $localHeader =
                pack(
                    'VvvvvvVVVvv',
                    0x04034b50,
                    20,
                    $flags,
                    0,
                    $dosTime,
                    $dosDate,
                    $crc,
                    $size,
                    $size,
                    strlen($name),
                    0
                )
                . $name;

            $local .=
                $localHeader
                . $content;

            $central .=
                pack(
                    'VvvvvvvVVVvvvvvVV',
                    0x02014b50,
                    20,
                    20,
                    $flags,
                    0,
                    $dosTime,
                    $dosDate,
                    $crc,
                    $size,
                    $size,
                    strlen($name),
                    0,
                    0,
                    0,
                    0,
                    0,
                    $offset
                )
                . $name;

            $offset +=
                strlen($localHeader)
                + $size;
        }

        $count =
            count($this->files);

        return
            $local
            . $central
            . pack(
                'VvvvvVVv',
                0x06054b50,
                0,
                0,
                $count,
                $count,
                strlen($central),
                strlen($local),
                0
            );
    }

    private static function dosTimeDate(): array
    {
        $now =
            getdate();

        $year =
            max(
                1980,
                (int)$now['year']
            );

        $time =
            (
                ((int)$now['hours'] << 11)
                | ((int)$now['minutes'] << 5)
                | intdiv(
                    (int)$now['seconds'],
                    2
                )
            );

        $date =
            (
                (($year - 1980) << 9)
                | ((int)$now['mon'] << 5)
                | (int)$now['mday']
            );

        return [
            $time,
            $date,
        ];
    }
}

final class FormExportService
{
    private const STATUS_LABELS = [
        'new' => 'Новая',
        'in_progress' => 'В работе',
        'done' => 'Готово',
        'spam' => 'Спам',
    ];

    public static function download(
        string $format,
        int $siteId,
        array $items
    ): void {
        $format =
            strtolower(
                trim($format)
            );

        if (
            !in_array(
                $format,
                ['csv', 'xlsx'],
                true
            )
        ) {
            throw new InvalidArgumentException(
                'EXPORT_FORMAT_INVALID'
            );
        }

        [$headers, $rows] =
            self::buildTable(
                $items
            );

        $stamp =
            date('Ymd-His');

        $filename =
            'forms-site-'
            . $siteId
            . '-'
            . $stamp
            . '.'
            . $format;

        header(
            'Cache-Control: private, no-store, max-age=0'
        );
        header(
            'Pragma: no-cache'
        );
        header(
            'X-Content-Type-Options: nosniff'
        );
        header(
            'Content-Disposition: attachment; filename="'
            . $filename
            . '"'
        );

        if ($format === 'csv') {
            self::outputCsv(
                $headers,
                $rows
            );

            return;
        }

        $file =
            self::createXlsxFile(
                $headers,
                $rows
            );

        try {
            header(
                'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            );
            header(
                'Content-Length: '
                . filesize($file)
            );

            readfile($file);
        } finally {
            @unlink($file);
        }
    }

    public static function buildTable(
        array $items
    ): array {
        $fieldColumns = [];

        foreach ($items as $item) {
            $payload =
                is_array(
                    $item['payload']
                    ?? null
                )
                    ? $item['payload']
                    : [];

            foreach ($payload as $key => $field) {
                $key =
                    (string)$key;

                if (
                    $key === ''
                    || isset(
                        $fieldColumns[$key]
                    )
                ) {
                    continue;
                }

                $label =
                    trim(
                        (string)(
                            $field['label']
                            ?? $key
                        )
                    );

                if ($label === '') {
                    $label = $key;
                }

                $fieldColumns[$key] =
                    $label;
            }
        }

        $headers = [
            'ID',
            'Дата',
            'Статус',
            'Форма',
            'Страница',
            'ID формы',
            'ID страницы',
            'Обработал (ID)',
            'Обработано',
        ];

        foreach (
            $fieldColumns
            as $key => $label
        ) {
            $headers[] =
                $label
                . ' ['
                . $key
                . ']';
        }

        $rows = [];

        foreach ($items as $item) {
            $meta =
                is_array(
                    $item['meta']
                    ?? null
                )
                    ? $item['meta']
                    : [];

            $payload =
                is_array(
                    $item['payload']
                    ?? null
                )
                    ? $item['payload']
                    : [];

            $row = [
                (string)(
                    $item['id']
                    ?? ''
                ),
                (string)(
                    $item['createdAt']
                    ?? ''
                ),
                self::statusLabel(
                    (string)(
                        $item['status']
                        ?? ''
                    )
                ),
                (string)(
                    $meta['formTitle']
                    ?? (
                        'Форма #'
                        . (int)(
                            $item[
                                'blockId'
                            ]
                            ?? 0
                        )
                    )
                ),
                (string)(
                    $meta['pageTitle']
                    ?? (
                        'Страница #'
                        . (int)(
                            $item[
                                'pageId'
                            ]
                            ?? 0
                        )
                    )
                ),
                (string)(
                    $item['blockId']
                    ?? ''
                ),
                (string)(
                    $item['pageId']
                    ?? ''
                ),
                (string)(
                    $item['handledBy']
                    ?? ''
                ),
                (string)(
                    $item['handledAt']
                    ?? ''
                ),
            ];

            foreach (
                $fieldColumns
                as $key => $_label
            ) {
                $field =
                    $payload[$key]
                    ?? null;

                $row[] =
                    is_array($field)
                        ? (string)(
                            $field['value']
                            ?? ''
                        )
                        : '';
            }

            $rows[] = $row;
        }

        return [
            $headers,
            $rows,
        ];
    }

    public static function createXlsxFile(
        array $headers,
        array $rows
    ): string {
        $sheetXml =
            self::sheetXml(
                $headers,
                $rows
            );

        $zip =
            new SiteBuilderSimpleZip();

        $zip->addFile(
            '[Content_Types].xml',
            self::contentTypesXml()
        );
        $zip->addFile(
            '_rels/.rels',
            self::rootRelsXml()
        );
        $zip->addFile(
            'xl/workbook.xml',
            self::workbookXml()
        );
        $zip->addFile(
            'xl/_rels/workbook.xml.rels',
            self::workbookRelsXml()
        );
        $zip->addFile(
            'xl/styles.xml',
            self::stylesXml()
        );
        $zip->addFile(
            'xl/worksheets/sheet1.xml',
            $sheetXml
        );

        $path =
            tempnam(
                sys_get_temp_dir(),
                'sb_forms_xlsx_'
            );

        if (
            $path === false
            || file_put_contents(
                $path,
                $zip->finish()
            ) === false
        ) {
            if (
                is_string($path)
            ) {
                @unlink($path);
            }

            throw new RuntimeException(
                'XLSX_EXPORT_WRITE_FAILED'
            );
        }

        return $path;
    }

    private static function outputCsv(
        array $headers,
        array $rows
    ): void {
        header(
            'Content-Type: text/csv; charset=UTF-8'
        );

        $stream =
            fopen(
                'php://output',
                'wb'
            );

        if ($stream === false) {
            throw new RuntimeException(
                'CSV_EXPORT_STREAM_FAILED'
            );
        }

        fwrite(
            $stream,
            "\xEF\xBB\xBF"
        );

        fputcsv(
            $stream,
            array_map(
                [self::class, 'csvSafe'],
                $headers
            ),
            ';'
        );

        foreach ($rows as $row) {
            fputcsv(
                $stream,
                array_map(
                    [self::class, 'csvSafe'],
                    $row
                ),
                ';'
            );
        }

        fclose($stream);
    }

    private static function csvSafe($value): string
    {
        $value =
            (string)$value;

        /*
         * Prevent spreadsheet formula injection from user submissions.
         */
        if (
            preg_match(
                '/^[=+\-@]/u',
                $value
            )
        ) {
            return "'"
                . $value;
        }

        return $value;
    }

    private static function statusLabel(
        string $status
    ): string {
        return
            self::STATUS_LABELS[
                $status
            ]
            ?? $status;
    }

    private static function xmlText(
        $value
    ): string {
        $value =
            (string)$value;

        $value =
            preg_replace(
                '/[^\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u',
                '',
                $value
            ) ?? '';

        return htmlspecialchars(
            $value,
            ENT_XML1
            | ENT_QUOTES
            | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }

    private static function columnName(
        int $index
    ): string {
        $index++;

        $name = '';

        while ($index > 0) {
            $index--;
            $name =
                chr(
                    65
                    + ($index % 26)
                )
                . $name;

            $index =
                intdiv(
                    $index,
                    26
                );
        }

        return $name;
    }

    private static function sheetXml(
        array $headers,
        array $rows
    ): string {
        $all = [
            array_values($headers),
            ...array_map(
                'array_values',
                $rows
            ),
        ];

        $columnCount =
            max(
                1,
                count($headers)
            );

        $rowCount =
            max(
                1,
                count($all)
            );

        $lastColumn =
            self::columnName(
                $columnCount - 1
            );

        $sheetRows = '';

        foreach ($all as $rowIndex => $row) {
            $excelRow =
                $rowIndex + 1;

            $cells = '';

            for (
                $column = 0;
                $column < $columnCount;
                $column++
            ) {
                $reference =
                    self::columnName(
                        $column
                    )
                    . $excelRow;

                $value =
                    (string)(
                        $row[$column]
                        ?? ''
                    );

                $cells .=
                    '<c r="'
                    . $reference
                    . '" t="inlineStr" s="'
                    . (
                        $rowIndex === 0
                            ? '1'
                            : '0'
                    )
                    . '"><is><t xml:space="preserve">'
                    . self::xmlText(
                        $value
                    )
                    . '</t></is></c>';
            }

            $sheetRows .=
                '<row r="'
                . $excelRow
                . '">'
                . $cells
                . '</row>';
        }

        $cols = '';

        for (
            $column = 1;
            $column <= $columnCount;
            $column++
        ) {
            $width =
                $column === 1
                    ? 10
                    : (
                        $column <= 9
                            ? 18
                            : 24
                    );

            $cols .=
                '<col min="'
                . $column
                . '" max="'
                . $column
                . '" width="'
                . $width
                . '" customWidth="1"/>';
        }

        return
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<dimension ref="A1:'
            . $lastColumn
            . $rowCount
            . '"/>'
            . '<sheetViews><sheetView workbookViewId="0">'
            . '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'
            . '</sheetView></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="18"/>'
            . '<cols>'
            . $cols
            . '</cols>'
            . '<sheetData>'
            . $sheetRows
            . '</sheetData>'
            . '<autoFilter ref="A1:'
            . $lastColumn
            . $rowCount
            . '"/>'
            . '</worksheet>';
    }

    private static function stylesXml(): string
    {
        return
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2">'
            . '<font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
            . '</fonts>'
            . '<fills count="3">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF1F4E78"/><bgColor indexed="64"/></patternFill></fill>'
            . '</fills>'
            . '<borders count="2">'
            . '<border/>'
            . '<border>'
            . '<left style="thin"><color rgb="FFD9E2F3"/></left>'
            . '<right style="thin"><color rgb="FFD9E2F3"/></right>'
            . '<top style="thin"><color rgb="FFD9E2F3"/></top>'
            . '<bottom style="thin"><color rgb="FFD9E2F3"/></bottom>'
            . '<diagonal/>'
            . '</border>'
            . '</borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    private static function contentTypesXml(): string
    {
        return
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
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
        return
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private static function workbookXml(): string
    {
        return
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Заявки" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';
    }

    private static function workbookRelsXml(): string
    {
        return
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
    }
}
