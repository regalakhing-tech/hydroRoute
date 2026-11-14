<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class Environment
{
    /** Local .env supports NAME=value and single-line quoted values. */
    public static function load(string $file): void
    {
        if (!is_file($file)) {
            return;
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new RuntimeException('Unable to read the local environment file.');
        }

        foreach ($lines as $index => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (!preg_match('/^([A-Z_][A-Z0-9_]*)\s*=(.*)$/', $line, $matches)) {
                throw new RuntimeException('Invalid environment entry at line ' . ($index + 1) . '.');
            }

            [, $name, $value] = $matches;
            if (getenv($name) !== false) {
                continue;
            }

            $value = trim($value);
            if (str_starts_with($value, '"') || str_starts_with($value, "'")) {
                if (strlen($value) < 2 || $value[0] !== $value[strlen($value) - 1]) {
                    throw new RuntimeException('Unclosed environment value at line ' . ($index + 1) . '.');
                }
                $value = substr($value, 1, -1);
            }

            if (str_contains($value, "\0")) {
                throw new RuntimeException('Invalid environment value.');
            }

            putenv($name . '=' . $value);
        }
    }
}
