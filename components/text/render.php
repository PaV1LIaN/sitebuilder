<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

$block = is_array($block ?? null) ? $block : [];
$props = is_array($block['props'] ?? null) ? $block['props'] : [];

if (!function_exists('sb_text_is_list_array')) {
    function sb_text_is_list_array(array $array): bool
    {
        if ($array === []) {
            return true;
        }

        return array_keys($array) === range(0, count($array) - 1);
    }
}

if (!function_exists('sb_text_value_to_string')) {
    function sb_text_value_to_string($value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_string($value) || is_numeric($value)) {
            return (string)$value;
        }

        if (is_bool($value)) {
            return $value ? '1' : '';
        }

        if (!is_array($value)) {
            return '';
        }

        /*
         * Частые варианты хранения текста:
         * text, content, html, value, body, children.
         */
        $preferredKeys = [
            'html',
            'text',
            'content',
            'value',
            'body',
            'children',
            'items',
        ];

        foreach ($preferredKeys as $key) {
            if (!array_key_exists($key, $value)) {
                continue;
            }

            $result = sb_text_value_to_string($value[$key]);

            if (trim(strip_tags($result)) !== '') {
                return $result;
            }
        }

        /*
         * Если это список элементов, собираем их в один текст.
         */
        if (sb_text_is_list_array($value)) {
            $parts = [];

            foreach ($value as $item) {
                $part = sb_text_value_to_string($item);

                if (trim(strip_tags($part)) !== '') {
                    $parts[] = $part;
                }
            }

            return implode("\n", $parts);
        }

        /*
         * Последний fallback:
         * аккуратно собираем строковые значения из массива.
         */
        $parts = [];

        foreach ($value as $key => $item) {
            if (in_array((string)$key, ['align', 'size', 'color', 'lineHeight', 'maxWidth'], true)) {
                continue;
            }

            $part = sb_text_value_to_string($item);

            if (trim(strip_tags($part)) !== '') {
                $parts[] = $part;
            }
        }

        return implode("\n", $parts);
    }
}

if (!function_exists('sb_text_get_content')) {
    function sb_text_get_content(array $block, array $props): string
    {
        $keys = [
            'text',
            'content',
            'html',
            'value',
            'body',
        ];

        foreach ($keys as $key) {
            if (array_key_exists($key, $props)) {
                $result = sb_text_value_to_string($props[$key]);

                if (trim(strip_tags($result)) !== '') {
                    return $result;
                }
            }
        }

        foreach ($keys as $key) {
            if (array_key_exists($key, $block)) {
                $result = sb_text_value_to_string($block[$key]);

                if (trim(strip_tags($result)) !== '') {
                    return $result;
                }
            }
        }

        return '';
    }
}

$text = sb_text_get_content($block, $props);

$align = (string)($props['align'] ?? 'left');
$size = (string)($props['size'] ?? '16');
$color = (string)($props['color'] ?? '#111827');
$lineHeight = (string)($props['lineHeight'] ?? '1.6');
$maxWidth = (string)($props['maxWidth'] ?? '');

if (!in_array($align, ['left', 'center', 'right', 'justify'], true)) {
    $align = 'left';
}

$sizeNumber = (int)$size;

if ($sizeNumber <= 0) {
    $sizeNumber = 16;
}

$allowedTags = '<br><b><strong><i><em><u><s><p><span><ul><ol><li><a>';

$safeText = strip_tags($text, $allowedTags);

$style = [
    'text-align:' . $align,
    'font-size:' . $sizeNumber . 'px',
    'line-height:' . htmlspecialchars($lineHeight, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
    'color:' . htmlspecialchars($color, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
];

if ($maxWidth !== '') {
    $style[] = 'max-width:' . htmlspecialchars($maxWidth, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

?>

<div class="sb-text-block" style="<?= implode(';', $style) ?>">
    <?= $safeText !== '' ? nl2br($safeText) : '' ?>
</div>