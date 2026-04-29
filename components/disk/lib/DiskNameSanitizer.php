<?php

class DiskNameSanitizer
{
    public static function sanitizeFolderName(string $name, string $fallback = 'Новая папка'): string
    {
        $name = trim($name);

        // Убираем явно проблемные символы для Disk / файловых имен
        $name = preg_replace('/[\\\\\\/\\:\\*\\?"<>\\|]+/u', ' ', $name);

        // Схлопываем пробелы
        $name = preg_replace('/\\s+/u', ' ', $name);

        $name = trim($name, " \t\n\r\0\x0B.-_");

        if ($name === '') {
            $name = $fallback;
        }

        // Ограничим длину
        if (mb_strlen($name) > 100) {
            $name = mb_substr($name, 0, 100);
            $name = rtrim($name, " \t\n\r\0\x0B.-_");
        }

        if ($name === '') {
            $name = $fallback;
        }

        return $name;
    }
}