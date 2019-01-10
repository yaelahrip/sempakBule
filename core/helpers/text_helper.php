<?php

defined('BASEPATH') OR exit('No direct script access allowed');

// ------------------------------------------------------------------------

if (!function_exists('word_limiter')) {

    function word_limiter($str, $limit = 100, $end_char = '&#8230;') {
        if (trim($str) === '') {
            return $str;
        }

        preg_match('/^\s*+(?:\S++\s*+){1,' . (int) $limit . '}/', $str, $matches);

        if (strlen($str) === strlen($matches[0])) {
            $end_char = '';
        }

        return rtrim($matches[0]) . $end_char;
    }

}

// ------------------------------------------------------------------------

if (!function_exists('character_limiter')) {

    function character_limiter($str, $n = 500, $end_char = '&#8230;') {
        if (mb_strlen($str) < $n) {
            return $str;
        }

        // a bit complicated, but faster than preg_replace with \s+
        $str = preg_replace('/ {2,}/', ' ', str_replace(array("\r", "\n", "\t", "\x0B", "\x0C"), ' ', $str));

        if (mb_strlen($str) <= $n) {
            return $str;
        }

        $out = '';
        foreach (explode(' ', trim($str)) as $val) {
            $out .= $val . ' ';

            if (mb_strlen($out) >= $n) {
                $out = trim($out);
                return (mb_strlen($out) === mb_strlen($str)) ? $out : $out . $end_char;
            }
        }
    }

}

// ------------------------------------------------------------------------

if (!function_exists('ascii_to_entities')) {

    function ascii_to_entities($str) {
        $out = '';
        for ($i = 0, $s = strlen($str) - 1, $count = 1, $temp = array(); $i <= $s; $i++) {
            $ordinal = ord($str[$i]);

            if ($ordinal < 128) {
                if (count($temp) === 1) {
                    $out .= '&#' . array_shift($temp) . ';';
                    $count = 1;
                }

                $out .= $str[$i];
            } else {
                if (count($temp) === 0) {
                    $count = ($ordinal < 224) ? 2 : 3;
                }

                $temp[] = $ordinal;

                if (count($temp) === $count) {
                    $number = ($count === 3) ? (($temp[0] % 16) * 4096) + (($temp[1] % 64) * 64) + ($temp[2] % 64) : (($temp[0] % 32) * 64) + ($temp[1] % 64);

                    $out .= '&#' . $number . ';';
                    $count = 1;
                    $temp = array();
                }
                // If this is the last iteration, just output whatever we have
                elseif ($i === $s) {
                    $out .= '&#' . implode(';', $temp) . ';';
                }
            }
        }

        return $out;
    }

}

// ------------------------------------------------------------------------

if (!function_exists('entities_to_ascii')) {

    function entities_to_ascii($str, $all = TRUE) {
        if (preg_match_all('/\&#(\d+)\;/', $str, $matches)) {
            for ($i = 0, $s = count($matches[0]); $i < $s; $i++) {
                $digits = $matches[1][$i];
                $out = '';

                if ($digits < 128) {
                    $out .= chr($digits);
                } elseif ($digits < 2048) {
                    $out .= chr(192 + (($digits - ($digits % 64)) / 64)) . chr(128 + ($digits % 64));
                } else {
                    $out .= chr(224 + (($digits - ($digits % 4096)) / 4096))
                            . chr(128 + ((($digits % 4096) - ($digits % 64)) / 64))
                            . chr(128 + ($digits % 64));
                }

                $str = str_replace($matches[0][$i], $out, $str);
            }
        }

        if ($all) {
            return str_replace(
                    array('&amp;', '&lt;', '&gt;', '&quot;', '&apos;', '&#45;'), array('&', '<', '>', '"', "'", '-'), $str
            );
        }

        return $str;
    }

}

// ------------------------------------------------------------------------

if (!function_exists('word_censor')) {

    function word_censor($str, $censored, $replacement = '') {
        if (!is_array($censored)) {
            return $str;
        }

        $str = ' ' . $str . ' ';
        $delim = '[-_\'\"`(){}<>\[\]|!?@#%&,.:;^~*+=\/ 0-9\n\r\t]';

        foreach ($censored as $badword) {
            if ($replacement !== '') {
                $str = preg_replace("/({$delim})(" . str_replace('\*', '\w*?', preg_quote($badword, '/')) . ")({$delim})/i", "\\1{$replacement}\\3", $str);
            } else {
                $str = preg_replace("/({$delim})(" . str_replace('\*', '\w*?', preg_quote($badword, '/')) . ")({$delim})/ie", "'\\1'.str_repeat('#', strlen('\\2')).'\\3'", $str);
            }
        }

        return trim($str);
    }

}

// ------------------------------------------------------------------------

if (!function_exists('highlight_code')) {

    function highlight_code($str) {
        $str = str_replace(
                array('&lt;', '&gt;', '<?', '?>', '<%', '%>', '\\', '</script>'), array('<', '>', 'phptagopen', 'phptagclose', 'asptagopen', 'asptagclose', 'backslashtmp', 'scriptclose'), $str
        );

        // The highlight_string function requires that the text be surrounded
        // by PHP tags, which we will remove later
        $str = highlight_string('<?php ' . $str . ' ?>', TRUE);

        // Remove our artificially added PHP, and the syntax highlighting that came with it
        $str = preg_replace(
                array(
            '/<span style="color: #([A-Z0-9]+)">&lt;\?php(&nbsp;| )/i',
            '/(<span style="color: #[A-Z0-9]+">.*?)\?&gt;<\/span>\n<\/span>\n<\/code>/is',
            '/<span style="color: #[A-Z0-9]+"\><\/span>/i'
                ), array(
            '<span style="color: #$1">',
            "$1</span>\n</span>\n</code>",
            ''
                ), $str
        );

        // Replace our markers back to PHP tags.
        return str_replace(
                array('phptagopen', 'phptagclose', 'asptagopen', 'asptagclose', 'backslashtmp', 'scriptclose'), array('&lt;?', '?&gt;', '&lt;%', '%&gt;', '\\', '&lt;/script&gt;'), $str
        );
    }

}

// ------------------------------------------------------------------------

if (!function_exists('highlight_phrase')) {

    function highlight_phrase($str, $phrase, $tag_open = '<mark>', $tag_close = '</mark>') {
        return ($str !== '' && $phrase !== '') ? preg_replace('/(' . preg_quote($phrase, '/') . ')/i' . (UTF8_ENABLED ? 'u' : ''), $tag_open . '\\1' . $tag_close, $str) : $str;
    }

}

// ------------------------------------------------------------------------

if (!function_exists('convert_accented_characters')) {

    function convert_accented_characters($str) {
        static $array_from, $array_to;

        if (!is_array($array_from)) {
            if (file_exists(APPPATH . 'config/foreign_chars.php')) {
                include(APPPATH . 'config/foreign_chars.php');
            }

            if (file_exists(APPPATH . 'config/' . ENVIRONMENT . '/foreign_chars.php')) {
                include(APPPATH . 'config/' . ENVIRONMENT . '/foreign_chars.php');
            }

            if (empty($foreign_characters) OR ! is_array($foreign_characters)) {
                $array_from = array();
                $array_to = array();

                return $str;
            }

            $array_from = array_keys($foreign_characters);
            $array_to = array_values($foreign_characters);
        }

        return preg_replace($array_from, $array_to, $str);
    }

}

// ------------------------------------------------------------------------

if (!function_exists('word_wrap')) {

    function word_wrap($str, $charlim = 76) {
        // Set the character limit
        is_numeric($charlim) OR $charlim = 76;

        // Reduce multiple spaces
        $str = preg_replace('| +|', ' ', $str);

        // Standardize newlines
        if (strpos($str, "\r") !== FALSE) {
            $str = str_replace(array("\r\n", "\r"), "\n", $str);
        }

        // If the current word is surrounded by {unwrap} tags we'll
        // strip the entire chunk and replace it with a marker.
        $unwrap = array();
        if (preg_match_all('|\{unwrap\}(.+?)\{/unwrap\}|s', $str, $matches)) {
            for ($i = 0, $c = count($matches[0]); $i < $c; $i++) {
                $unwrap[] = $matches[1][$i];
                $str = str_replace($matches[0][$i], '{{unwrapped' . $i . '}}', $str);
            }
        }

        // Use PHP's native function to do the initial wordwrap.
        // We set the cut flag to FALSE so that any individual words that are
        // too long get left alone. In the next step we'll deal with them.
        $str = wordwrap($str, $charlim, "\n", FALSE);

        // Split the string into individual lines of text and cycle through them
        $output = '';
        foreach (explode("\n", $str) as $line) {
            // Is the line within the allowed character count?
            // If so we'll join it to the output and continue
            if (mb_strlen($line) <= $charlim) {
                $output .= $line . "\n";
                continue;
            }

            $temp = '';
            while (mb_strlen($line) > $charlim) {
                // If the over-length word is a URL we won't wrap it
                if (preg_match('!\[url.+\]|://|www\.!', $line)) {
                    break;
                }

                // Trim the word down
                $temp .= mb_substr($line, 0, $charlim - 1);
                $line = mb_substr($line, $charlim - 1);
            }

            // If $temp contains data it means we had to split up an over-length
            // word into smaller chunks so we'll add it back to our current line
            if ($temp !== '') {
                $output .= $temp . "\n" . $line . "\n";
            } else {
                $output .= $line . "\n";
            }
        }

        // Put our markers back
        if (count($unwrap) > 0) {
            foreach ($unwrap as $key => $val) {
                $output = str_replace('{{unwrapped' . $key . '}}', $val, $output);
            }
        }

        return $output;
    }

}

// ------------------------------------------------------------------------

if (!function_exists('ellipsize')) {

    function ellipsize($str, $max_length, $position = 1, $ellipsis = '&hellip;') {
        // Strip tags
        $str = trim(strip_tags($str));

        // Is the string long enough to ellipsize?
        if (mb_strlen($str) <= $max_length) {
            return $str;
        }

        $beg = mb_substr($str, 0, floor($max_length * $position));
        $position = ($position > 1) ? 1 : $position;

        if ($position === 1) {
            $end = mb_substr($str, 0, -($max_length - mb_strlen($beg)));
        } else {
            $end = mb_substr($str, -($max_length - mb_strlen($beg)));
        }

        return $beg . $ellipsis . $end;
    }

}