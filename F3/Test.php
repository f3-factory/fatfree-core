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
 * Unit test kit
 */
class Test
{

    //@{ Reporting level
    const
        FLAG_False = 0,
        FLAG_True = 1,
        FLAG_Both = 2;
    //@}

    // Test results
    protected array $data = [];
    // Success indicator
    protected bool $passed = true;
    // Reporting level
    protected int $level;

    /**
     * Return test results
     **/
    public function results(): array
    {
        return $this->data;
    }

    /**
     * Return FALSE if at least one test case fails
     **/
    public function passed(): bool
    {
        return $this->passed;
    }

    /**
     * Evaluate condition and save test result
     */
    public function expect(mixed $cond, ?string $text = null): static
    {
        $out = (bool) $cond;
        if ($this->level == $out || $this->level == self::FLAG_Both) {
            $data = ['status' => $out, 'text' => $text, 'source' => null];
            foreach (debug_backtrace(limit: 100) as $frame)
                if (isset($frame['file'])) {
                    $data['source'] =
                        Base::instance()->fixslashes($frame['file']).':'.$frame['line'];
                    break;
                }
            $this->data[] = $data;
        }
        if (!$out && $this->passed)
            $this->passed = false;
        return $this;
    }

    /**
     * Append message to test results
     */
    public function message(string $text): void
    {
        $this->expect(true, $text);
    }

    public function __construct(int $level = self::FLAG_Both)
    {
        $this->level = $level;
    }

}
