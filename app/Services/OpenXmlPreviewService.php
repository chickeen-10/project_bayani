<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMNodeList;
use DOMXPath;
use ZipArchive;

/**
 * Read-only, bounded parsers for the text portions of DOCX and XLSX files.
 * These parsers never extract archive members, evaluate formulas, or return
 * document-supplied HTML.
 */
class OpenXmlPreviewService
{
    private const MAX_MEMBER_BYTES = 9_000_000;
    private const MAX_ARCHIVE_UNCOMPRESSED_BYTES = 60_000_000;
    private const MAX_SHEETS = 12;
    private const MAX_ROWS = 140;
    private const MAX_COLUMNS = 28;
    private const MAX_CELLS = 18_000;
    private const MAX_TEXT_BYTES = 500_000;

    public function spreadsheet(string $path): array
    {
        $zip = $this->archive($path);

        try {
            $workbook = $this->xml($zip, 'xl/workbook.xml');
            $relationships = $this->xml($zip, 'xl/_rels/workbook.xml.rels');
            $relationMap = [];
            foreach ($this->xpath($relationships, '//*[local-name()="Relationship"]') as $relation) {
                if ($relation instanceof DOMElement) {
                    $relationMap[$relation->getAttribute('Id')] = $relation->getAttribute('Target');
                }
            }

            $sharedStrings = [];
            $sharedStringBytes = 0;
            if ($this->memberExists($zip, 'xl/sharedStrings.xml')) {
                $strings = $this->xml($zip, 'xl/sharedStrings.xml');
                foreach ($this->xpath($strings, '//*[local-name()="sst"]/*[local-name()="si"]') as $item) {
                    $sharedString = $this->textNodes($item);
                    $sharedStringBytes += strlen($sharedString);
                    if ($sharedStringBytes > self::MAX_TEXT_BYTES) {
                        throw new \RuntimeException('Workbook shared strings exceed the preview limit.');
                    }
                    $sharedStrings[] = $sharedString;
                }
            }

            $sheets = [];
            $cellCount = 0;
            $textBytes = 0;
            foreach ($this->xpath($workbook, '//*[local-name()="sheets"]/*[local-name()="sheet"]') as $sheetNode) {
                if (count($sheets) >= self::MAX_SHEETS) {
                    break;
                }
                if (!$sheetNode instanceof DOMElement) {
                    continue;
                }
                $relationId = $sheetNode->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
                $target = $relationMap[$relationId] ?? '';
                $member = $this->worksheetMember($target);
                if (!$member || !$this->memberExists($zip, $member)) {
                    continue;
                }

                $sheetXml = $this->xml($zip, $member);
                $grid = [];
                foreach ($this->xpath($sheetXml, '//*[local-name()="sheetData"]/*[local-name()="row"]/*[local-name()="c"]') as $cell) {
                    if (!$cell instanceof DOMElement || $cellCount >= self::MAX_CELLS) {
                        break;
                    }
                    $coordinate = strtoupper($cell->getAttribute('r'));
                    if (!preg_match('/^([A-Z]{1,3})(\d{1,7})$/', $coordinate, $parts)) {
                        continue;
                    }
                    $row = (int) $parts[2];
                    $column = $this->columnNumber($parts[1]);
                    if ($row < 1 || $row > self::MAX_ROWS || $column < 1 || $column > self::MAX_COLUMNS) {
                        continue;
                    }

                    $type = $cell->getAttribute('t');
                    if ($type === 'inlineStr') {
                        $value = $this->textNodes($cell);
                    } else {
                        $valueNode = $this->xpath($cell, './*[local-name()="v"]')->item(0);
                        $value = $valueNode?->textContent ?? '';
                        if ($type === 's' && ctype_digit($value)) {
                            $value = $sharedStrings[(int) $value] ?? '';
                        } elseif ($type === 'b') {
                            $value = $value === '1' ? 'TRUE' : 'FALSE';
                        }
                    }
                    $value = $this->cleanText($value);
                    if ($value !== '') {
                        $textBytes += strlen($value);
                        if ($textBytes > self::MAX_TEXT_BYTES) {
                            break;
                        }
                        $grid[$row][$column] = mb_substr($value, 0, 500);
                    }
                    $cellCount++;
                }

                if (!$grid) {
                    $grid = [[1 => '(This worksheet has no readable values in the preview range.)']];
                }
                $maxRow = min(self::MAX_ROWS, max(array_keys($grid)));
                $maxColumn = min(self::MAX_COLUMNS, max(array_map(fn ($row) => max(array_keys($row)), $grid)));
                $rows = [];
                for ($row = 1; $row <= $maxRow; $row++) {
                    $rows[] = array_map(fn ($column) => $grid[$row][$column] ?? '', range(1, $maxColumn));
                }
                $sheets[] = [
                    'name' => mb_substr($this->cleanText($sheetNode->getAttribute('name') ?: 'Worksheet'), 0, 80),
                    'rows' => $rows,
                    'truncated' => $maxRow >= self::MAX_ROWS || $cellCount >= self::MAX_CELLS || $textBytes > self::MAX_TEXT_BYTES,
                ];
                if ($textBytes > self::MAX_TEXT_BYTES || $cellCount >= self::MAX_CELLS) {
                    break;
                }
            }

            if (!$sheets) {
                throw new \RuntimeException('This workbook has no readable worksheets.');
            }

            return ['sheets' => $sheets, 'truncated' => $cellCount >= self::MAX_CELLS];
        } finally {
            $zip->close();
        }
    }

    public function document(string $path): array
    {
        $zip = $this->archive($path);

        try {
            $document = $this->xml($zip, 'word/document.xml');
            $body = $this->xpath($document, '//*[local-name()="body"]')->item(0);
            if (!$body) {
                throw new \RuntimeException('This document contains no readable body.');
            }

            $blocks = [];
            $textBytes = 0;
            foreach ($body->childNodes as $child) {
                if (!$child instanceof DOMElement || count($blocks) >= 1_000) {
                    continue;
                }
                if ($child->localName === 'p') {
                    $text = $this->paragraphText($child);
                    if ($text === '') {
                        continue;
                    }
                    $textBytes += strlen($text);
                    if ($textBytes > self::MAX_TEXT_BYTES) {
                        break;
                    }
                    $style = $this->xpath($child, './*[local-name()="pPr"]/*[local-name()="pStyle"]')->item(0);
                    $isList = $this->xpath($child, './*[local-name()="pPr"]/*[local-name()="numPr"]')->length > 0;
                    $styleName = $style instanceof DOMElement
                        ? strtolower($style->getAttributeNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'val') ?: $style->getAttribute('val'))
                        : '';
                    $level = preg_match('/heading\s*([1-6])/', $styleName, $match) ? (int) $match[1] : null;
                    $blocks[] = ['type' => $isList ? 'list-item' : ($level ? 'heading' : 'paragraph'), 'level' => $level ?: 2, 'text' => $text];
                } elseif ($child->localName === 'tbl') {
                    $rows = [];
                    foreach ($this->xpath($child, './*[local-name()="tr"]') as $rowNode) {
                        $row = [];
                        foreach ($this->xpath($rowNode, './*[local-name()="tc"]') as $cellNode) {
                            $paragraphs = [];
                            foreach ($this->xpath($cellNode, './/*[local-name()="p"]') as $paragraph) {
                                $paragraphs[] = $this->paragraphText($paragraph);
                                if (count($paragraphs) >= 20) {
                                    break;
                                }
                            }
                            $cellText = mb_substr($this->cleanText(implode(' ', array_filter($paragraphs))), 0, 500);
                            $textBytes += strlen($cellText);
                            $row[] = $cellText;
                            if (count($row) >= 16 || $textBytes > self::MAX_TEXT_BYTES) {
                                break;
                            }
                        }
                        if ($row) {
                            $rows[] = array_slice($row, 0, 16);
                        }
                        if (count($rows) >= 100 || $textBytes > self::MAX_TEXT_BYTES) {
                            break;
                        }
                    }
                    if ($rows) {
                        $blocks[] = ['type' => 'table', 'rows' => $rows];
                    }
                }
            }

            if (!$blocks) {
                throw new \RuntimeException('This document has no readable text in the preview range.');
            }

            return ['blocks' => $blocks, 'truncated' => count($blocks) >= 1_000 || $textBytes > self::MAX_TEXT_BYTES];
        } finally {
            $zip->close();
        }
    }

    public function thumbnailSvg(string $kind, array $preview, string $title): string
    {
        $title = $this->xmlText(mb_substr($this->cleanText($title), 0, 90));
        if ($kind === 'xlsx') {
            return $this->spreadsheetThumbnailSvg($preview);
        }

        $lines = [];
        foreach (array_slice($preview['blocks'] ?? [], 0, 8) as $block) {
            $text = $block['type'] === 'table'
                ? implode('  |  ', array_map(fn ($row) => implode('  ', $row), array_slice($block['rows'], 0, 2)))
                : ($block['text'] ?? '');
            if ($text !== '') {
                $lines[] = ['text' => mb_substr($text, 0, 90), 'heading' => ($block['type'] ?? '') === 'heading'];
            }
        }

        if (!$lines) {
            $lines[] = ['text' => 'No readable content in the first preview range.', 'heading' => false];
        }

        $content = '';
        $y = 170;
        foreach ($lines as $line) {
            if ($y > 560) {
                break;
            }
            $content .= '<text x="74" y="'.$y.'" font-size="'.($line['heading'] ? '28' : '20').'" font-weight="'.($line['heading'] ? '700' : '400').'" fill="#23394b">'.$this->xmlText($line['text']).'</text>';
            $y += $line['heading'] ? 47 : 42;
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" width="1000" height="625" viewBox="0 0 1000 625" role="img"><rect width="1000" height="625" fill="#eef2f3"/><rect x="38" y="32" width="924" height="561" rx="8" fill="#fff" stroke="#cbd5dc"/><text x="74" y="91" font-family="Arial,sans-serif" font-size="16" font-weight="700" letter-spacing="1" fill="#526574">DOCUMENT CONTENT SNAPSHOT</text><text x="74" y="133" font-family="Arial,sans-serif" font-size="25" font-weight="700" fill="#17324d">'.$title.'</text>'.$content.'</svg>';
    }

    private function spreadsheetThumbnailSvg(array $preview): string
    {
        $selected = null;
        $selectedRows = [];
        foreach ($preview['sheets'] ?? [] as $candidate) {
            $rows = [];
            $cellCount = 0;
            foreach ($candidate['rows'] ?? [] as $row) {
                $cells = array_slice($row, 0, 5);
                if (count(array_filter($cells, fn ($value) => trim((string) $value) !== '')) > 0) {
                    $rows[] = $cells;
                    $cellCount += count(array_filter($cells, fn ($value) => trim((string) $value) !== ''));
                }
            }
            if ($selected === null && $cellCount > 0) {
                $selected = $candidate;
                $selectedRows = $rows;
            }
            if ($cellCount >= 2) {
                $selected = $candidate;
                $selectedRows = $rows;
                break;
            }
        }

        $sheetName = $this->xmlText(mb_substr($this->cleanText((string) ($selected['name'] ?? 'Worksheet')), 0, 36));
        $rows = array_slice($selectedRows, 0, 8);
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="1000" height="625" viewBox="0 0 1000 625" role="img" aria-label="Worksheet '. $sheetName .'"><rect width="1000" height="625" fill="#fff"/><rect x="0" y="0" width="1000" height="54" fill="#f3f5f7"/><text x="30" y="36" font-family="Arial,sans-serif" font-size="22" font-weight="600" fill="#273746">'.$sheetName.'</text><rect x="0" y="66" width="1000" height="44" fill="#f3f5f7"/>';
        $rowNumberWidth = 56;
        $columnWidth = 188;
        $top = 110;
        $rowHeight = 58;
        $columnLetters = ['A', 'B', 'C', 'D', 'E'];
        foreach ($columnLetters as $column => $letter) {
            $x = $rowNumberWidth + $column * $columnWidth;
            $svg .= '<rect x="'.$x.'" y="66" width="'.$columnWidth.'" height="44" fill="#f3f5f7" stroke="#d6dce1"/><text x="'.($x + 10).'" y="94" font-family="Arial,sans-serif" font-size="18" fill="#52616e">'.$letter.'</text>';
        }
        foreach ($rows as $rowIndex => $row) {
            $y = $top + $rowIndex * $rowHeight;
            $rowNumber = $rowIndex + 1;
            $svg .= '<rect x="0" y="'.$y.'" width="'.$rowNumberWidth.'" height="'.$rowHeight.'" fill="#f7f8f9" stroke="#d6dce1"/><text x="18" y="'.($y + 35).'" font-family="Arial,sans-serif" font-size="16" fill="#64717c">'.$rowNumber.'</text>';
            foreach ($columnLetters as $column => $letter) {
                $x = $rowNumberWidth + $column * $columnWidth;
                $value = $this->cleanText((string) ($row[$column] ?? ''));
                $value = mb_substr($value, 0, 21);
                $svg .= '<rect x="'.$x.'" y="'.$y.'" width="'.$columnWidth.'" height="'.$rowHeight.'" fill="#fff" stroke="#d6dce1"/>';
                if ($value !== '') {
                    $svg .= '<text x="'.($x + 10).'" y="'.($y + 35).'" font-family="Arial,sans-serif" font-size="17" fill="#253746">'.$this->xmlText($value).'</text>';
                }
            }
        }
        $svg .= '</svg>';

        return $svg;
    }

    private function archive(string $path): ZipArchive
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true || $zip->numFiles > 5_000) {
            throw new \RuntimeException('This Office document cannot be opened safely.');
        }
        $uncompressedBytes = 0;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            $uncompressedBytes += (int) ($stat['size'] ?? 0);
            if ($uncompressedBytes > self::MAX_ARCHIVE_UNCOMPRESSED_BYTES) {
                $zip->close();
                throw new \RuntimeException('The Office document exceeds the preview processing limit.');
            }
        }
        return $zip;
    }

    private function xml(ZipArchive $zip, string $member): DOMDocument
    {
        $stat = $zip->statName($member);
        if (!$stat || (int) ($stat['size'] ?? 0) > self::MAX_MEMBER_BYTES) {
            throw new \RuntimeException('The document part exceeds the preview size limit.');
        }
        $bytes = $zip->getFromName($member);
        if (!is_string($bytes) || strlen($bytes) > self::MAX_MEMBER_BYTES) {
            throw new \RuntimeException('A document part could not be read safely.');
        }
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($bytes, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded || $document->doctype) {
            throw new \RuntimeException('A document part is malformed or contains a DTD.');
        }
        return $document;
    }

    private function xpath(DOMNode $node, string $query): DOMNodeList
    {
        $result = (new DOMXPath($node instanceof DOMDocument ? $node : $node->ownerDocument))->query($query, $node);
        if (!$result) {
            throw new \RuntimeException('A document part could not be processed safely.');
        }
        return $result;
    }

    private function memberExists(ZipArchive $zip, string $member): bool
    {
        return $zip->locateName($member, ZipArchive::FL_NOCASE) !== false;
    }

    private function worksheetMember(string $target): ?string
    {
        $target = str_replace('\\', '/', trim($target));
        if ($target === '' || str_starts_with($target, '/') || str_contains($target, ':') || str_contains($target, '..')) {
            return null;
        }
        $member = str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
        return preg_match('#^xl/worksheets/[A-Za-z0-9_.-]+\.xml$#', $member) ? $member : null;
    }

    private function textNodes(DOMNode $node): string
    {
        $parts = [];
        foreach ($this->xpath($node, './/*[local-name()="t"]') as $text) {
            $parts[] = $text->textContent;
        }
        return $this->cleanText(implode('', $parts));
    }

    private function paragraphText(DOMNode $paragraph): string
    {
        $parts = [];
        foreach ($this->xpath($paragraph, './/*[local-name()="t" or local-name()="tab" or local-name()="br"]') as $node) {
            $parts[] = match ($node->localName) {
                'tab' => "\t",
                'br' => "\n",
                default => $node->textContent,
            };
        }
        return mb_substr($this->cleanText(implode('', $parts)), 0, 2_000);
    }

    private function cleanText(string $text): string
    {
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';
        return trim($text);
    }

    private function xmlText(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function columnNumber(string $letters): int
    {
        $number = 0;
        foreach (str_split($letters) as $letter) {
            $number = ($number * 26) + ord($letter) - 64;
        }
        return $number;
    }
}

