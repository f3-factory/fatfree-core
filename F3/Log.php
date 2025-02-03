<?php

/*

	Copyright (c) 2009-2019 F3::Factory/Bong Cosca, All rights reserved.

	This file is part of the Fat-Free Framework (http://fatfreeframework.com).

	This is free software: you can redistribute it and/or modify it under the
	terms of the GNU General Public License as published by the Free Software
	Foundation, either version 3 of the License, or later.

	Fat-Free Framework is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
	General Public License for more details.

	You should have received a copy of the GNU General Public License along
	with Fat-Free Framework.  If not, see <http://www.gnu.org/licenses/>.

*/

namespace F3;

/**
 * Custom logger
 */
class Log {

    /** File name */
    protected string $file;

    /**
     * Write specified text to log file
     * @param string $text
     * @param string $format
     */
    function write(string $text, string $format = 'r'): void
    {
        $fw = Base::instance();
        $ip = isset($fw->SERVER['REMOTE_ADDR'])
            ? (' ['.$fw->SERVER['REMOTE_ADDR'].
                (($fwd = \filter_var($fw->get('HEADERS.X-Forwarded-For'),
                    FILTER_VALIDATE_IP))?(' ('.$fwd.')'):'')
                .']')
            :'';
        foreach (\preg_split('/\r?\n|\r/', \trim($text)) as $line)
            $fw->write(
                $this->file,
                \date($format).$ip.' '.\trim($line).PHP_EOL,
                TRUE
            );
    }

    /**
     * Erase log file
     * @return void
     */
    function erase(): void
    {
        @\unlink($this->file);
    }

    /**
     * @param string $file filepath within LOGS dir
     */
    function __construct(string $file)
    {
        $fw = Base::instance();
        if (!\is_dir($dir = $fw->LOGS))
            \mkdir($dir,Base::MODE,TRUE);
        $this->file = $dir.$file;
    }

}
