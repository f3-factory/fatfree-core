<?php

/**
 *
 * Copyright (c) 2025 F3::Factory, All rights reserved.
 *
 * This file is part of the Fat-Free Framework (http://fatfreeframework.com).
 *
 * This is free software: you can redistribute it and/or modify it under the
 * terms of the GNU General Public License as published by the Free Software
 * Foundation, either version 3 of the License, or later.
 *
 * Fat-Free Framework is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with Fat-Free Framework. If not, see <http://www.gnu.org/licenses/>.
 */

namespace F3;

/**
 * Unicode string utilities
 */
class UTF
{
    use Prefab;

    /**
     * Get string length
     */
    public function strlen(string $str): int
    {
        preg_match_all('/./us', $str, $parts);
        return count($parts[0]);
    }

    /**
     * Reverse a string
     */
    public function strrev(string $str): string
    {
        preg_match_all('/./us', $str, $parts);
        return implode('', array_reverse($parts[0]));
    }

    /**
     * Find position of first occurrence of a string (case-insensitive)
     */
    public function stripos(string $stack, string $needle, int $ofs = 0): false|int
    {
        return $this->strpos($stack, $needle, $ofs, true);
    }

    /**
     * Find position of first occurrence of a string
     */
    public function strpos(
        string $stack,
        string $needle,
        int $ofs = 0,
        bool $case = false,
    ): false|int {
        return preg_match(
            '/^(.{'.$ofs.'}.*?)'.
            preg_quote($needle, '/').'/us'.($case ? 'i' : ''),
            $stack,
            $match,
        ) ?
            $this->strlen($match[1]) : false;
    }

    /**
     *
     * Returns part of haystack string from the first occurrence of
     * needle to the end of haystack (case-insensitive)
     */
    public function stristr(string $stack, string $needle, bool $before = false): false|string
    {
        return $this->strstr($stack, $needle, $before, true);
    }

    /**
     * Returns part of haystack string from the first occurrence of
     * needle to the end of haystack
     */
    public function strstr(
        string $stack,
        string $needle,
        bool $before = false,
        bool $case = false,
    ): false|string {
        if (!$needle)
            return false;
        preg_match(
            '/^(.*?)'.preg_quote($needle, '/').'/us'.($case ? 'i' : ''),
            $stack,
            $match,
        );
        return isset($match[1]) ?
            ($before ?
                $match[1] :
                $this->substr($stack, $this->strlen($match[1]))) :
            false;
    }

    /**
     * Return part of a string
     */
    public function substr(string $str, int $start, int $len = 0): false|string
    {
        if ($start < 0)
            $start = $this->strlen($str) + $start;
        if (!$len)
            $len = $this->strlen($str) - $start;
        return preg_match('/^.{'.$start.'}(.{0,'.$len.'})/us', $str, $match) ?
            $match[1] : false;
    }

    /**
     * Count the number of substring occurrences
     */
    public function substr_count(string $stack, string $needle): int
    {
        preg_match_all(
            '/'.preg_quote($needle, '/').'/us',
            $stack,
            $matches,
            PREG_SET_ORDER,
        );
        return count($matches);
    }

    /**
     * Strip whitespaces from the beginning of a string
     */
    public function ltrim(string $str): string
    {
        return preg_replace('/^[\pZ\pC]+/u', '', $str);
    }

    /**
     * Strip whitespaces from the end of a string
     */
    public function rtrim(string $str): string
    {
        return preg_replace('/[\pZ\pC]+$/u', '', $str);
    }

    /**
     * Strip whitespaces from the beginning and end of a string
     */
    public function trim(string $str): string
    {
        return preg_replace('/^[\pZ\pC]+|[\pZ\pC]+$/u', '', $str);
    }

    /**
     * Return UTF-8 byte order mark
     */
    public function bom(): string
    {
        return chr(0xef).chr(0xbb).chr(0xbf);
    }

    /**
     * Convert code points to Unicode symbols
     */
    public function translate(string $str): string
    {
        return html_entity_decode(
            preg_replace('/\\\\u([[:xdigit:]]+)/i', '&#x\1;', $str),
        );
    }

    /**
     * Translate emoji tokens to Unicode font-supported symbols
     */
    public function emojify(string $str): string
    {
        $map = [
                ':(' => '\u2639', // frown
                ':)' => '\u263a', // smile
                '<3' => '\u2665', // heart
                ':D' => '\u1f603', // grin
                'XD' => '\u1f606', // laugh
                ';)' => '\u1f609', // wink
                ':P' => '\u1f60b', // tongue
                ':,' => '\u1f60f', // think
                ':/' => '\u1f623', // skeptic
                '8O' => '\u1f632', // oops
            ] + Base::instance()->EMOJI;
        return $this->translate(
            str_replace(
                array_keys($map),
                array_values($map),
                $str,
            ),
        );
    }

}
