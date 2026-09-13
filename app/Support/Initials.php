<?php

namespace App\Support;

final class Initials
{
    /** "Andi Pratama" -> "AP", "Budi" -> "BU" */
    public static function from(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($parts === []) {
            return '?';
        }

        if (count($parts) === 1) {
            return mb_strtoupper(mb_substr($parts[0], 0, 2));
        }

        return mb_strtoupper(
            mb_substr($parts[0], 0, 1) . mb_substr($parts[count($parts) - 1], 0, 1)
        );
    }
}