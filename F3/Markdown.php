<?php

/**
 *
 * Copyright (c) 2025 F3::Factory, All rights reserved.
 *
 * This file is part of the Fat-Free Framework (https://fatfreeframework.com).
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
 * Markdown-to-HTML converter
 */
class Markdown
{
    use Prefab;

    // Parsing rules
    protected array $blocks = [];
    // Special characters
    protected $special;

    /**
     * Process blockquote
     */
    protected function _blockquote(string $str): string
    {
        $str = preg_replace('/(?<=^|\n)\h?>\h?(.*?(?:\n+|$))/', '\1', $str);
        return strlen($str) ?
            ('<blockquote>'.$this->build($str).'</blockquote>'."\n\n") : '';
    }

    /**
     * Process whitespace-prefixed code block
     */
    protected function _pre(string $str): string
    {
        $str = preg_replace(
            '/(?<=^|\n)(?: {4}|\t)(.+?(?:\n+|$))/',
            '\1',
            $this->esc($str),
        );
        return strlen($str) ?
            ('<pre><code>'.
                $this->esc($this->snip($str)).
                '</code></pre>'."\n\n") :
            '';
    }

    /**
     * Process fenced code block
     */
    protected function _fence(string $hint, string $str): string
    {
        $str = $this->snip($str);
        $fw = Base::instance();
        if ($fw->HIGHLIGHT) {
            switch (strtolower($hint)) {
                case 'php':
                    $str = $fw->highlight($str);
                    break;
                case 'apache':
                    preg_match_all(
                        '/(?<=^|\n)(\h*)'.
                        '(?:(<\/?)(\w+)((?:\h+[^>]+)*)(>)|'.
                        '(?:(\w+)(\h.+?)))(\h*(?:\n+|$))/',
                        $str,
                        $matches,
                        PREG_SET_ORDER,
                    );
                    $out = '';
                    foreach ($matches as $match)
                        $out .= $match[1].
                            ($match[3] ?
                                ('<span class="section">'.
                                    $this->esc($match[2]).$match[3].
                                    '</span>'.
                                    ($match[4] ?
                                        ('<span class="data">'.
                                            $this->esc($match[4]).
                                            '</span>') :
                                        '').
                                    '<span class="section">'.
                                    $this->esc($match[5]).
                                    '</span>') :
                                ('<span class="directive">'.
                                    $match[6].
                                    '</span>'.
                                    '<span class="data">'.
                                    $this->esc($match[7]).
                                    '</span>')).
                            $match[8];
                    $str = '<code>'.$out.'</code>';
                    break;
                case 'html':
                    preg_match_all(
                        '/<(\/?)(\w+)'.
                        '((?:\h+(?:\w+\h*=\h*)?".+?"|[^>]+)*|'.
                        '\h+.+?)(\h*\/?)>|(.+?)/s',
                        $str,
                        $matches,
                        PREG_SET_ORDER,
                    );
                    $out = '';
                    foreach ($matches as $match) {
                        if ($match[2]) {
                            $out .= '<span class="xml_tag">&lt;'.
                                $match[1].$match[2].'</span>';
                            if ($match[3]) {
                                preg_match_all(
                                    '/(?:\h+(?:(?:(\w+)\h*=\h*)?'.
                                    '(".+?")|(.+)))/',
                                    $match[3],
                                    $parts,
                                    PREG_SET_ORDER,
                                );
                                foreach ($parts as $part)
                                    $out .= ' '.
                                        (empty($part[3]) ?
                                            ((empty($part[1]) ?
                                                    '' :
                                                    ('<span class="xml_attr">'.
                                                        $part[1].'</span>=')).
                                                '<span class="xml_data">'.
                                                $part[2].'</span>') :
                                            ('<span class="xml_tag">'.
                                                $part[3].'</span>'));
                            }
                            $out .= '<span class="xml_tag">'.
                                $match[4].'&gt;</span>';
                        } else
                            $out .= $this->esc($match[5]);
                    }
                    $str = '<code>'.$out.'</code>';
                    break;
                case 'ini':
                    preg_match_all(
                        '/(?<=^|\n)(?:'.
                        '(;[^\n]*)|<\?php.+?\?>?|'.
                        '\[(.+?)]|'.
                        '(.+?)(\h*=\h*)'.
                        '((?:\\\\\h*\r?\n|.+?)*)'.
                        ')((?:\r?\n)+|$)/',
                        $str,
                        $matches,
                        PREG_SET_ORDER,
                    );
                    $out = '';
                    foreach ($matches as $match) {
                        if ($match[1])
                            $out .= '<span class="comment">'.$match[1].
                                '</span>';
                        elseif ($match[2])
                            $out .= '<span class="ini_section">['.$match[2].']'.
                                '</span>';
                        elseif ($match[3])
                            $out .= '<span class="ini_key">'.$match[3].
                                '</span>'.$match[4].
                                ($match[5] ?
                                    ('<span class="ini_value">'.
                                        $match[5].'</span>') : '');
                        else
                            $out .= $match[0];
                        if (isset($match[6]))
                            $out .= $match[6];
                    }
                    $str = '<code>'.$out.'</code>';
                    break;
                default:
                    $str = '<code>'.$this->esc($str).'</code>';
                    break;
            }
        } else
            $str = '<code>'.$this->esc($str).'</code>';
        return '<pre>'.$str.'</pre>'."\n\n";
    }

    /**
     * Process horizontal rule
     */
    protected function _hr(): string
    {
        return '<hr />'."\n\n";
    }

    /**
     * Process atx-style heading
     */
    protected function _atx(string $type, string $str): string
    {
        $level = strlen($type);
        return '<h'.$level.' id="'.Web::instance()->slug($str).'">'.
            $this->scan($str).'</h'.$level.'>'."\n\n";
    }

    /**
     * Process setext-style heading
     */
    protected function _setext(string $str, string $type): string
    {
        $level = strpos('=-', $type) + 1;
        return '<h'.$level.' id="'.Web::instance()->slug($str).'">'.
            $this->scan($str).'</h'.$level.'>'."\n\n";
    }

    /**
     * Process ordered/unordered list
     */
    protected function _li(string $str): string
    {
        // Initialize list parser
        $len = strlen($str);
        $ptr = 0;
        $dst = '';
        $first = true;
        $tight = true;
        $type = 'ul';
        // Main loop
        while ($ptr < $len) {
            if (preg_match(
                '/^\h*[*\-](?:\h?[*\-]){2,}(?:\n+|$)/',
                substr($str, $ptr),
                $match,
            )) {
                $ptr += strlen($match[0]);
                // Embedded horizontal rule
                return (strlen($dst) ?
                        ('<'.$type.'>'."\n".$dst.'</'.$type.'>'."\n\n") : '').
                    '<hr />'."\n\n".$this->build(substr($str, $ptr));
            } elseif (preg_match(
                '/(?<=^|\n)([*+\-]|\d+\.)\h'.
                '(.+?(?:\n+|$))((?:(?: {4}|\t)+.+?(?:\n+|$))*)/s',
                substr($str, $ptr),
                $match,
            )) {
                $match[3] = preg_replace('/(?<=^|\n)(?: {4}|\t)/', '', $match[3]);
                $found = false;
                foreach (array_slice($this->blocks, 0, -1) as $regex)
                    if (preg_match($regex, $match[3])) {
                        $found = true;
                        break;
                    }
                // List
                if ($first) {
                    // First pass
                    if (is_numeric($match[1]))
                        $type = 'ol';
                    if (preg_match(
                        '/\n{2,}$/',
                        $match[2].
                        ($found ? '' : $match[3]),
                    ))
                        // Loose structure; Use paragraphs
                        $tight = false;
                    $first = false;
                }
                // Strip leading whitespaces
                $ptr += strlen($match[0]);
                $tmp = $this->snip($match[2].$match[3]);
                if ($tight) {
                    if ($found)
                        $tmp = $match[2].$this->build($this->snip($match[3]));
                } else
                    $tmp = $this->build($tmp);
                $dst .= '<li>'.$this->scan(trim($tmp)).'</li>'."\n";
            }
        }
        return strlen($dst) ?
            ('<'.$type.'>'."\n".$dst.'</'.$type.'>'."\n\n") : '';
    }

    /**
     * Ignore raw HTML
     */
    protected function _raw(string $str): string
    {
        return $str;
    }

    /**
     * Process paragraph
     */
    protected function _p(string $str): string
    {
        $str = trim($str);
        if (strlen($str)) {
            if (preg_match('/^(.+?\n)([>#].+)$/s', $str, $parts))
                return $this->_p($parts[1]).$this->build($parts[2]);
            $str = preg_replace_callback(
                '/([^<>\[]+)?(<[?%].+?[?%]>|<.+?>|\[.+?]\s*\(.+?\))|'.
                '(.+)/s',
                function ($expr) {
                    $tmp = '';
                    if (isset($expr[4]))
                        $tmp .= $this->esc($expr[4]);
                    else {
                        if (isset($expr[1]))
                            $tmp .= $this->esc($expr[1]);
                        $tmp .= $expr[2];
                        if (isset($expr[3]))
                            $tmp .= $this->esc($expr[3]);
                    }
                    return $tmp;
                },
                $str,
            );
            $str = preg_replace('/\s{2}\r?\n/', '<br />', $str);
            return '<p>'.$this->scan($str).'</p>'."\n\n";
        }
        return '';
    }

    /**
     * Process strong/em/strikethrough spans
     */
    protected function _text(string $str): string
    {
        $tmp = '';
        while ($str != $tmp)
            $str = preg_replace_callback(
                '/(?<=\s|^)(?<!\\\\)([*_])([*_]?)([*_]?)(.*?)(?!\\\\)\3\2\1(?=[\s[:punct:]]|$)/',
                function ($expr) {
                    if ($expr[3])
                        return '<strong><em>'.$expr[4].'</em></strong>';
                    if ($expr[2])
                        return '<strong>'.$expr[4].'</strong>';
                    return '<em>'.$expr[4].'</em>';
                },
                preg_replace(
                    '/(?<!\\\\)~~(.*?)(?!\\\\)~~(?=[\s[:punct:]]|$)/',
                    '<del>\1</del>',
                    $tmp = $str,
                ),
            );
        return $str;
    }

    /**
     * Process image span
     */
    protected function _img(string $str): string
    {
        return preg_replace_callback(
            '/!(?:\[(.+?)])?\h*\(<?(.*?)>?(?:\h*"(.*?)"\h*)?\)/',
            function ($expr) {
                return '<img src="'.$expr[2].'"'.
                    (empty($expr[1]) ?
                        '' :
                        (' alt="'.$this->esc($expr[1]).'"')).
                    (empty($expr[3]) ?
                        '' :
                        (' title="'.$this->esc($expr[3]).'"')).' />';
            },
            $str,
        );
    }

    /**
     * Process anchor span
     */
    protected function _a(string $str): string
    {
        return preg_replace_callback(
            '/(?<!\\\\)\[(.+?)(?!\\\\)]\h*\(<?(.*?)>?(?:\h*"(.*?)"\h*)?\)/',
            function ($expr) {
                return '<a href="'.$this->esc($expr[2]).'"'.
                    (empty($expr[3]) ?
                        '' :
                        (' title="'.$this->esc($expr[3]).'"')).
                    '>'.$this->scan($expr[1]).'</a>';
            },
            $str,
        );
    }

    /**
     * Auto-convert links
     */
    protected function _auto(string $str): string
    {
        return preg_replace_callback(
            '/`.*?<(.+?)>.*?`|<(.+?)>/',
            function ($expr) {
                if (empty($expr[1]) && parse_url($expr[2], PHP_URL_SCHEME)) {
                    $expr[2] = $this->esc($expr[2]);
                    return '<a href="'.$expr[2].'">'.$expr[2].'</a>';
                }
                return $expr[0];
            },
            $str,
        );
    }

    /**
     * Process code span
     */
    protected function _code(string $str): string
    {
        return preg_replace_callback(
            '/`` (.+?) ``|(?<!\\\\)`(.+?)(?!\\\\)`/',
            function ($expr) {
                return '<code>'.
                    $this->esc(empty($expr[1]) ? $expr[2] : $expr[1]).'</code>';
            },
            $str,
        );
    }

    /**
     * Convert characters to HTML entities
     */
    public function esc(string $str): string
    {
        if (!$this->special)
            $this->special = [
                '...' => '&hellip;',
                '(tm)' => '&trade;',
                '(r)' => '&reg;',
                '(c)' => '&copy;',
            ];
        foreach ($this->special as $key => $val)
            $str = preg_replace('/'.preg_quote($key, '/').'/i', $val, $str);
        return htmlspecialchars(
            $str,
            ENT_COMPAT,
            Base::instance()->ENCODING,
            false,
        );
    }

    /**
     * Reduce multiple line feeds
     */
    protected function snip(string $str): string
    {
        return preg_replace('/(?<=\n)\n+|\n+$/', "\n", $str);
    }

    /**
     * Scan line for convertible spans
     */
    public function scan(string $str): string
    {
        $inline = ['img', 'a', 'text', 'auto', 'code'];
        foreach ($inline as $func)
            $str = $this->{'_'.$func}($str);
        return $str;
    }

    /**
     * Assemble blocks
     */
    protected function build(string $str): string
    {
        if (!$this->blocks) {
            // Regexes for capturing entire blocks
            $this->blocks = [
                'blockquote' => '/^(?:\h?>\h?.*?(?:\n+|$))+/',
                'pre' => '/^(?:(?: {4}|\t).+?(?:\n+|$))+/',
                'fence' => '/^`{3}\h*(\w+)?.*?[^\n]*\n+(.+?)`{3}[^\n]*'.
                    '(?:\n+|$)/s',
                'hr' => '/^\h*[*_\-](?:\h?[\*_\-]){2,}\h*(?:\n+|$)/',
                'atx' => '/^\h*(#{1,6})\h?(.+?)\h*(?:#.*)?(?:\n+|$)/u',
                'setext' => '/^\h*(.+?)\h*\n([=\-])+\h*(?:\n+|$)/u',
                'li' => '/^(?:(?:[*+\-]|\d+\.)\h.+?(?:\n+|$)'.
                    '(?:(?: {4}|\t)+.+?(?:\n+|$))*)+/s',
                'raw' => '/^((?:<!--.+?-->|'.
                    '<(address|article|aside|audio|blockquote|canvas|dd|'.
                    'div|dl|fieldset|figcaption|figure|footer|form|h\d|'.
                    'header|hgroup|hr|noscript|object|ol|output|p|pre|'.
                    'section|table|tfoot|ul|video).*?'.
                    '(?:\/>|>(?:(?>[^><]+)|(?R))*<\/\2>))'.
                    '\h*(?:\n{2,}|\n*$)|<[\?%].+?[\?%]>\h*(?:\n?$|\n*))/s',
                'p' => '/^(.+?(?:\n{2,}|\n*$))/s',
            ];
        }
        // Treat lines with nothing but whitespaces as empty lines
        $str = preg_replace('/\n\h+(?=\n)/', "\n", $str);
        // Initialize block parser
        $len = strlen($str);
        $ptr = 0;
        $dst = '';
        // Main loop
        while ($ptr < $len) {
            if (preg_match(
                '/^ {0,3}\[([^\[\]]+)\]:\s*<?(.*?)>?\s*'.
                '(?:"([^\n]*)")?(?:\n+|$)/s',
                substr($str, $ptr),
                $match,
            )) {
                // Reference-style link; Backtrack
                $ptr += strlen($match[0]);
                $tmp = '';
                // Catch line breaks in title attribute
                $ref = preg_replace('/\h/', '\s', preg_quote($match[1], '/'));
                while ($dst != $tmp) {
                    $dst = preg_replace_callback(
                        '/(?<!\\\\)\[('.$ref.')(?!\\\\)]\s*\[\]|'.
                        '(!?)(?:\[([^\[\]]+)\]\s*)?'.
                        '(?<!\\\\)\[('.$ref.')(?!\\\\)]/',
                        function ($expr) use ($match) {
                            return (empty($expr[2])) ?
                                // Anchor
                                ('<a href="'.$this->esc($match[2]).'"'.
                                    (empty($match[3]) ?
                                        '' :
                                        (' title="'.
                                            $this->esc($match[3]).'"')).'>'.
                                    // Link
                                    $this->scan(
                                        empty($expr[3]) ?
                                            (empty($expr[1]) ?
                                                $expr[4] :
                                                $expr[1]) :
                                            $expr[3],
                                    ).'</a>') :
                                // Image
                                ('<img src="'.$match[2].'"'.
                                    (empty($expr[2]) ?
                                        '' :
                                        (' alt="'.
                                            $this->esc($expr[3]).'"')).
                                    (empty($match[3]) ?
                                        '' :
                                        (' title="'.
                                            $this->esc($match[3]).'"')).
                                    ' />');
                        },
                        $tmp = $dst,
                    );
                }
            } else
                foreach ($this->blocks as $func => $regex)
                    if (preg_match($regex, substr($str, $ptr), $match)) {
                        $ptr += strlen($match[0]);
                        $dst .= call_user_func_array(
                            [$this, '_'.$func],
                            count($match) > 1 ? array_slice($match, 1) : $match,
                        );
                        break;
                    }
        }
        return $dst;
    }

    /**
     * Render HTML equivalent of markdown
     */
    public function convert(string $txt): string
    {
        $txt = preg_replace_callback(
            '/(<code.*?>.+?<\/code>|'.
            '<[^>\n]+>|\([^\n\)]+\)|"[^"\n]+")|'.
            '\\\\(.)/s',
            // Process escaped characters
            fn($expr) => empty($expr[1]) ? $expr[2] : $expr[1],
            $this->build(preg_replace('/\r\n|\r/', "\n", $txt)),
        );
        return $this->snip($txt);
    }

}
