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

namespace F3\DB {

    use F3\Base;
    use F3\DB\Jig\StorageFormat;

    /**
     * In-memory / flat-file DB wrapper
     */
    class Jig
    {
        // UUID
        public readonly string $uuid;
        // Storage location
        public readonly string $dir;
        // Jig log
        protected string|false $log = '';
        // Memory-held data
        protected array $data = [];

        public function __construct(
            string $dir = '',
            // Current storage format
            public readonly StorageFormat $format = StorageFormat::JSON,
            // lazy load/save files
            protected bool $lazy = false
        ) {
            if ($dir && !is_dir($dir))
                mkdir($dir, Base::MODE, true);
            $this->uuid = Base::instance()->hash($this->dir = $dir);
        }

        /**
         * Read data from memory/file
         */
        public function &read(string $file): array
        {
            if (!$this->dir || !is_file($dst = $this->dir.$file)) {
                if (!isset($this->data[$file]))
                    $this->data[$file] = [];
                return $this->data[$file];
            }
            if ($this->lazy && isset($this->data[$file]))
                return $this->data[$file];
            $fw = Base::instance();
            $raw = $fw->read($dst);
            $this->data[$file] = match ($this->format) {
                StorageFormat::JSON => json_decode($raw, true),
                StorageFormat::Serialized => $fw->unserialize($raw),
            };
            return $this->data[$file];
        }

        /**
         * Write data to memory/file
         */
        public function write(string $file, ?array $data = null): false|int
        {
            if (!$this->dir || $this->lazy)
                return count($this->data[$file] = $data);
            $fw = Base::instance();
            $out = match ($this->format) {
                StorageFormat::JSON => json_encode(
                    $data,
                    JSON_PRETTY_PRINT | JSON_INVALID_UTF8_IGNORE,
                ),
                StorageFormat::Serialized => $fw->serialize($data),
            };
            return $fw->write($this->dir.$file, $out);
        }

        /**
         * Return directory
         */
        public function dir(): string
        {
            return $this->dir;
        }

        /**
         * Return UUID
         */
        public function uuid(): string
        {
            return $this->uuid;
        }

        /**
         * Return profiler results (or disable logging)
         */
        public function log(bool $flag = true): false|string
        {
            if (!$flag)
                $this->log = false;
            return $this->log;
        }

        /**
         * Jot down log entry
         */
        public function jot(string $frame): void
        {
            if ($frame)
                $this->log .= date('r').' '.$frame.PHP_EOL;
        }

        /**
         * Clean storage
         */
        public function drop(): void
        {
            if ($this->lazy) // intentional
                $this->data = [];
            if (!$this->dir)
                $this->data = [];
            elseif ($glob = @glob($this->dir.'/*', GLOB_NOSORT))
                foreach ($glob as $file)
                    @unlink($file);
        }

        // Prohibit cloning
        private function __clone() {}

        /**
         *    save file on destruction
         */
        public function __destruct()
        {
            if ($this->lazy) {
                $this->lazy = false;
                foreach ($this->data ?: [] as $file => $data)
                    $this->write($file, $data);
            }
        }

    }
}

namespace F3\DB\Jig {

    enum StorageFormat
    {
        case JSON;
        case Serialized;
    }
}
