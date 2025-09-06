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
 *
 * @author 2009-2019 Bong Cosca
 */

//! Legacy mode enabler
class F3
{
    //! Framework instance
    static \F3\Base $fw;

    /**
     * Forward function calls to framework
     */
    public static function __callStatic(string $func, array $args): mixed
    {
        if (!self::$fw)
            self::$fw = \F3\Base::instance();
        return call_user_func_array([self::$fw, $func], $args);
    }

}
