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

namespace F3 {

    use F3\Http\Router;
    use F3\Http\Status;
    use Psr\Http\Message\ResponseFactoryInterface;
    use Psr\Http\Message\ResponseInterface;
    use Psr\Http\Message\ServerRequestInterface;
    use Psr\Http\Message\StreamFactoryInterface;

    /**
     * Mixin for singleton instance access
     */
    trait Prefab
    {
        /**
         * Return class instance
         */
        public static function instance(...$args): static
        {
            if (!Registry::exists($class = static::class))
                Registry::set($class, new $class(...$args));
            return Registry::get($class);
        }

    }

    /**
     * Key-Value store
     */
    class Hive
    {
        public const E_Hive = 'Invalid hive key %s';

        /** @var array dynamic key-value store */
        protected array $_hive_data = [];

        /** list of reserved own properties */
        protected const OWN_PROPS = ['_hive_data'];

        public function __construct(array $data = [])
        {
            // set initial data without using setters
            foreach ($data as $key => $value) {
                // local typed property
                if (\property_exists($this, $key))
                    $this->$key = $value;
                // fluent hive data
                else
                    $this->_hive_data[$key] = $value;
            }
        }

        /**
         * return public class property names
         * @property bool $uninitialized include uninitialized properties
         * @property object $obj
         * @return string[]
         */
        public static function public(object $obj, bool $uninitialized = false): array
        {
            // NB: for uninitialized public typed properties with get hooks,
            // there's no check if they'd actually return a value or throw an exception
            // to keep it simple: we're assuming they do provide a value
            return \array_keys(
                $uninitialized ? \get_class_vars($obj::class)
                    : \Closure::bind(fn($a)
                    => \get_object_vars($a), null, null)
                (
                    $obj,
                ),
            );
        }

        /**
         * Return the parts of specified hive key
         */
        public static function cut(string $key): array
        {
            return \preg_split(
                '/\[[\'"]?(.+?)[\'"]?]|(->)|\./',
                $key,
                -1,
                PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE,
            );
        }

        /**
         * Get hive key reference/contents; Add non-existent hive keys,
         * array elements, and object properties by default.
         */
        public function &ref(array|string $key, bool $add = true, mixed $val = null): mixed
        {
            $null = null;
            $rootKey = null;
            $parts = \is_array($key) ? $key : self::cut($key);
            // select origin of value storage (property or dynamic data)
            if (\property_exists($this, $parts[0])
                && !\in_array($parts[0], static::OWN_PROPS)) {
                // shortcut
                if (\count($parts) === 1 && $val !== null) {
                    $this->{$parts[0]} = $val;
                    // updated value from property hook (if any)
                    $ref = $this->{$parts[0]};
                    return $ref;
                } elseif ($val === null
                    // this ensures that uninitialized properties can be set
                    || isset($this->{$parts[0]})
                ) {
                    if ($add)
                        $src =& $this->{$parts[0]};
                    else
                        $src = $this->{$parts[0]};
                    \array_shift($parts);
                } else {
                    // eagerly initialize property for reference
                    $rootKey = $parts[0];
                    $src = $null;
                    if (\count($parts) === 1) {
                        $this->{$rootKey} = $val;
                        $src =& $this->{$rootKey};
                        return $src;
                    }
                }
            } elseif ($add)
                $src =& $this->_hive_data;
            else
                $src = $this->_hive_data;
            $obj = \is_object($src);
            // assemble nested value access
            $i = 0;
            foreach ($parts as $part) {
                $i++;
                if ($part == '->') {
                    $obj = true;
                    continue;
                }
                // handle array access
                if (!$obj) {
                    if (!\is_array($src)) {
                        // auto-fix dot-notation for object access
                        if (\is_object($src))
                            $obj = true;
                        else
                            $src = [];
                    }
                    if (!$obj) {
                        if ($add || \array_key_exists($part, $src))
                            $src =& $src[$part];
                        else {
                            $src =& $null;
                            break;
                        }
                    }
                }
                // handle object access
                if ($obj) {
                    $obj = false;
                    if (!\is_object($src)) {
                        // wrongly accessed
                        if (\is_array($src)) {
                            return $null;
                        }
                        $src = new \stdClass();
                    }
                    $exists = isset($src->$part);
                    if (($exists) && !$add) {
                        $src = $src->$part;
                    } elseif ($exists && $add && $i === \count($parts)) {
                        $src->{$part} = $val;
                        $ref = $src->{$part};
                        return $ref;
                    } elseif ($add) {
                        $src =& $src->$part;
                    } else {
                        $src =& $null;
                        break;
                    }
                }
                if ($rootKey) {
                    // eager initialize for nested depth access
                    $this->{$parts[0]} = $src;
                    $src =& $this->{$parts[0]};
                    $rootKey = null;
                }
            }
            return $src;
        }

        /**
         * export hive to array
         */
        public function toArray(): array
        {
            $vars = static::public($this);
            $src = [...$vars, ...\array_keys($this->_hive_data)];
            foreach ($src as $key) {
                $out[$key] = $this->get($key);
            }
            return $out;
        }

        /**
         * Convenience method for removing hive key
         **/
        public function clear(string $key): void
        {
            $parts = self::cut($key);
            if (\array_key_exists($parts[0], $this->_hive_data)) {
                // fluent data
                eval('unset('.self::compile('@this->_hive_data.'.$key, false).');');
            } elseif (\property_exists($this, $parts[0])) {
                $null = false;
                if (isset($parts[1])) {
                    // do no unset class properties except of stdClass
                    $tmp = $parts;
                    \array_pop($tmp);
                    if (\end($tmp) === '->') {
                        \array_pop($tmp);
                        $null = !(($ref = $this->ref($tmp)) instanceof \stdClass);
                    }
                    if (!$null)
                        eval('unset('.self::compile('@this->'.$key, false).');');
                }
                if ($null || !isset($parts[1])) {
                    // for properties, set a default or other reasonable value
                    $rp = new \ReflectionProperty($ref ?? $this, \end($parts));
                    $ref = &$this->ref($key);
                    $type = $rp->getType();
                    if ($rp->hasDefaultValue()) {
                        $ref = $rp->getDefaultValue();
                    } elseif ($type->allowsNull()) {
                        $ref = null;
                    } elseif ($type->getName() === 'array') {
                        $ref = [];
                    } else throw new \Exception('Unable to clear typed property: '.$key);
                }
            }
        }

        /**
         * Return TRUE if a hive key is accessible and typed property is initialized
         */
        public function accessible(string $key): bool
        {
            if (\array_key_exists($key, $this->_hive_data))
                return true;
            if (!\in_array($key, static::OWN_PROPS) && isset($this->$key))
                return true;
            return false;
        }

        /**
         * Convert JS-style token to PHP expression
         * @param $str string
         * @param $evaluate bool compile expressions as well or only convert variable access
         * @return string
         */
        public static function compile(string $str, bool $evaluate = true): string
        {
            return (!$evaluate)
                ? \preg_replace_callback(
                    '/^@(\w+)((?:\..+|\[(?:[^\[\]]*|(?R))*]|(?:->|::)\w+)*)/',
                    function ($expr) {
                        $str = '$'.$expr[1];
                        if (isset($expr[2]))
                            $str .= \preg_replace_callback(
                                '/\.([^.\[\]]+)|\[((?:[^\[\]\'"]*|(?R))*)]/',
                                function ($sub) {
                                    $val = $sub[2] ?? $sub[1];
                                    if (\ctype_digit($val))
                                        $val = (int) $val;
                                    return '['.self::export($val).']';
                                },
                                $expr[2],
                            );
                        return $str;
                    },
                    $str,
                )
                : \preg_replace_callback(
                    '/(?<!\w)@(\w+(?:(?:->|::)\w+)?)'.
                    '((?:\.\w+|\[(?:[^\[\]]*|(?R))*]|(?:->|::)\w+|\()*)/',
                    function ($expr) {
                        $str = '$'.$expr[1];
                        if (isset($expr[2]))
                            $str .= \preg_replace_callback(
                                '/\.(\w+)(\()?|\[((?:[^\[\]]*|(?R))*)]/',
                                function ($sub) {
                                    if (empty($sub[2])) {
                                        if (\ctype_digit($sub[1]))
                                            $sub[1] = (int) $sub[1];
                                        $out = '['.
                                            (isset($sub[3])
                                                ? self::compile($sub[3])
                                                : self::export($sub[1])
                                            ).']';
                                    } else
                                        $out = \function_exists($sub[1])
                                            ? $sub[0]
                                            : ('['.self::export($sub[1]).']'.$sub[2]);
                                    return $out;
                                },
                                $expr[2],
                            );
                        return $str;
                    },
                    $str,
                );
        }

        /**
         * Return string representation of expression
         */
        public static function export(mixed $expr): string
        {
            return \var_export($expr, true);
        }

        /**
         * Bind value to hive key
         */
        public function set(string $key, mixed $val, ?int $ttl = null): mixed
        {
            $ref = &$this->ref($key, val: $val);
            // TODO: don't overwrite $ref with $val when value was already updated via property assignment
            return $ref = $val;
        }

        /**
         * Retrieve contents of hive key
         */
        public function get(string $key, string|array|null $args = null): mixed
        {
            $parts = self::cut($key);
            $val = $this->ref($parts, false);
            return (\is_string($val) && !\is_null($args))
                ? Base::instance()->format($val, ...(\is_array($args) ? $args : [$args]))
                : $val;
        }

        /**
         * Return TRUE if hive key is set
         */
        public function exists(string $key, mixed &$val = null): bool|array
        {
            $parts = self::cut($key);
            if (!$this->accessible($parts[0]))
                return false;
            if (\count($parts) === 1 && \property_exists($this, $parts[0]))
                return true;
            $val = $this->ref($key, false);
            return isset($val);
        }

        /**
         * Return TRUE if hive key is empty
         **/
        public function devoid(string $key, mixed &$val = null): bool
        {
            $parts = self::cut($key);
            if (!$this->accessible($parts[0]))
                return true;
            $val = $this->ref($key, false);
            return empty($val);
        }

        public function __isset(string $key): bool
        {
            $val = $this->ref($key, false);
            return isset($val);
        }

        public function __set(string $key, mixed $val): void
        {
            $ref = &$this->ref($key, val: $val);
            $ref = $val;
        }

        public function &__get(string $key): mixed
        {
            $val = &$this->ref($key);
            return $val;
        }

        public function __unset(string $key): void
        {
            $this->clear($key);
        }

    }


    /**
     * Shared Base Kernel
     *
     * @property ?array $GET
     * @property ?array $POST
     * @property ?array $REQUEST
     * @property ?array $COOKIE
     * @property ?array $SESSION
     * @property ?array $FILES
     * @property ?array $SERVER
     * @property ?array $ENV
     */
    final class Base extends Hive
    {

        use Prefab;
        use Router;

        public const string
            PACKAGE = 'Fat-Free Framework',
            VERSION = '4.0.0-alpha.8';

        // Mapped PHP globals
        public const string GLOBALS = 'GET|POST|COOKIE|REQUEST|SESSION|FILES|SERVER|ENV';
        // Default directory permissions
        public const int MODE = 0755;
        // Syntax highlighting stylesheet
        public const string CSS = 'code.css';

        //region Error messages
        public const string
            E_Pattern = 'Invalid routing pattern: %s',
            E_Named = 'Named route does not exist: %s',
            E_Alias = 'Invalid named route alias: %s',
            E_Fatal = 'Fatal error: %s',
            E_Open = 'Unable to open %s',
            E_Routes = 'No routes specified',
            E_Class = 'Invalid class %s',
            E_Method = 'Invalid method %s',
            E_Hive = 'Invalid hive key %s';
        //endregion

        // Language lookup sequence
        protected array $languages = [];

        // Mutex locks
        protected array $locks = [];

        // list of all reserved own properties
        protected const array OWN_PROPS = ['_hive_data', 'languages', 'locks'];

        public string $AGENT = '';
        public bool $AJAX = false;
        public ?string $ALIAS = null;
        public array $ALIASES = [];
        /** @var string|array{string, ?callable(string): string} */
        public string|array $AUTOLOAD = './';
        public string $BASE = '';
        public int $BITMASK = ENT_COMPAT | ENT_SUBSTITUTE;
        public ?string $BODY = null;
        public string|bool $CACHE = false {
            set => Cache::instance()->load($value);
        }
        public bool $CASELESS = false;
        public bool $CLI = false;
        public bool $NONBLOCKING = false;
        public array $CORS = [
            'headers' => '',
            'origin' => false,
            'credentials' => false,
            'expose' => false,
            'ttl' => 0,
        ];
        /** @var object|\Psr\Container\ContainerInterface|Service|null  */
        public ?object $CONTAINER = null;
        public int $DEBUG = 2;
        public array $DIACRITICS = [];
        public string|array $DNSBL = [];
        public array $EMOJI = [];
        public string $ENCODING = '' {
            set {
                if ($value !== $this->ENCODING) {
                    \ini_set('default_charset', $value);
                    if (\extension_loaded('mbstring'))
                        \mb_internal_encoding($value);
                    $this->ENCODING = $value;
                }
            }
        }
        /**
         * @var ?array{
         *  status: string,
         *  code: int,
         *  text: string,
         *  trace: string,
         *  level: int,
         * }
         */
        public ?array $ERROR = null;
        public bool $ESCAPE = true;
        public ?\Throwable $EXCEPTION = null;
        public string|array $EXEMPT = [];
        public string $FALLBACK = 'en' {
            set {
                $changed = $this->FALLBACK !== $value;
                $this->FALLBACK = $value;
                if ($changed) {
                    $this->language($this->LANGUAGE);
                    $this->loadLocales();
                }
            }
        }
        public array $FORMATS = [];
        public bool $HALT = true;
        public array $HEADERS = [];
        public bool $HIGHLIGHT = false;
        public string $HOST = '';
        public string $IP = '';
        public CookieJarConfig $JAR;
        /**
         * list of enabled language codes ISO 639-1 (- ISO 3166-1)
         */
        public string $LANGUAGE = '' {
            set(string $val) {
                $val = $this->language($val);
                $this->loadLocales();
                $this->LANGUAGE = $val;
            }
        }
        /**
         * path to locales directory
         */
        public ?string $LOCALES = null {
            set {
                $this->LOCALES = $value;
                $this->loadLocales();
            }
        }
        /**
         * locales cache time
         */
        public int $LOCALES_TTL = 0;
        public int $LOCK = LOCK_EX;
        public string|array $LOGGABLE = '*';
        public string $LOGS = './';
        public bool $MB = false;
        public mixed $ONERROR = null;
        /**
         * @var string|array|null|\Closure(string $url, bool $permanent, bool $exit):void
         */
        public \Closure|string|array|null $ONREROUTE = null;
        public string $PACKAGE = self::PACKAGE;
        public array $PARAMS = [];
        public string $PATH = '';
        public ?string $PATTERN = null;
        public int $PORT = 80;
        public ?string $PREFIX = null;
        public string $PREMAP = '';
        public string $QUERY = '';
        public bool $QUIET = false;
        public bool $RAW = false;
        public string $REALM = '';
        public bool $REROUTE_TRAILING_SLASH = true;
        public string|object $RESPONSE = '';
        public array $RESPONSE_HEADERS = [];
        public string $ROOT = '';
        public array $ROUTES = [];
        public string $SCHEME = 'http';
        public string $SEED = '';
        public string $SERIALIZER = 'php';
        public string $TEMP = 'tmp/';
        public float $TIME = 0;
        public string $TZ {
            get => $this->TZ ?? \date_default_timezone_get();
            set {
                \date_default_timezone_set($value);
                $this->TZ = $value;
            }
        }
        public string $UI = './';
        public mixed $UNLOAD = null;
        public string $UPLOADS = './';
        public string $URI = '';
        public string $VERB = '';
        public string $VERSION = self::VERSION;
        public string $XFRAME = 'SAMEORIGIN';

        /**
         * Sync PHP global with corresponding hive key
         */
        public function sync(?string $key = null): ?array
        {
            if (!$key) {
                foreach (\explode('|', Base::GLOBALS) as $key)
                    $this->sync($key);
                return null;
            }
            return $this->_hive_data[$key] = &$GLOBALS['_'.$key];
        }

        /**
         * drop sync of PHP global with corresponding hive key
         */
        public function desync(?string $key = null): void
        {
            if (!$key) {
                foreach (\explode('|', Base::GLOBALS) as $key)
                    $this->desync($key);
                return;
            }
            // prevent session from being started when none exists yet
            if ($key === 'SESSION' && \session_status() !== PHP_SESSION_ACTIVE)
                return;
            $data = $this->{$key};
            unset($GLOBALS['_'.$key]);
            $this->{$key} = $GLOBALS['_'.$key] = $data ?? [];
        }

        /**
         * Replace tokenized URL with available token values
         * @param $url array|string
         * @param $addParams bool merge default PARAMS from hive into args
         * @param $args array
         **/
        public function build(
            string|array $url,
            array $args = [],
            bool $addParams = true,
        ): array|string {
            if ($addParams)
                $args += $this->recursive($this->PARAMS, fn($val)
                    => \implode('/', \array_map('urlencode', \explode('/', $val ?? ''))));
            if (\is_array($url))
                foreach ($url as &$var) {
                    $var = $this->build($var, $args, false);
                    unset($var);
                }
            else {
                $i = 0;
                $url = \preg_replace_callback(
                    '/(\{)?@(\w+)(?(1)})|(\*)/',
                    function ($match) use (&$i, $args) {
                        if (isset($match[2]) &&
                            \array_key_exists($match[2], $args))
                            return $args[$match[2]];
                        if (isset($match[3]) &&
                            \array_key_exists($match[3], $args)) {
                            if (!is_array($args[$match[3]]))
                                return $args[$match[3]];
                            ++$i;
                            return $args[$match[3]][$i - 1];
                        }
                        return $match[0];
                    },
                    $url,
                );
            }
            return $url;
        }

        /**
         * Parse string containing key-value pairs
         */
        public function parse(string $str): array
        {
            \preg_match_all(
                '/(\w+|\*)\h*=\h*(?:\[(.+?)]|(.+?))(?=,|$)/',
                $str,
                $pairs,
                PREG_SET_ORDER,
            );
            $out = [];
            foreach ($pairs as $pair)
                if ($pair[2]) {
                    $out[$pair[1]] = [];
                    foreach (\explode(',', $pair[2]) as $val)
                        $out[$pair[1]][] = $val;
                } else
                    $out[$pair[1]] = \trim($pair[3]);
            return $out;
        }

        /**
         * Cast string variable to PHP type or constant
         */
        public function cast(mixed $val): mixed
        {
            if ($val && \preg_match('/^(?:0x[0-9a-f]+|0[0-7]+|0b[01]+)$/i', $val))
                return \intval($val, 0);
            if (\is_numeric($val))
                return $val + 0;
            $val = \trim($val ?: '');
            if (\preg_match('/^\w+$/i', $val) && \defined($val))
                return \constant($val);
            return $val;
        }

        /**
         * handle core-specific hive features
         */
        public function &ref(array|string $key, bool $add = true, mixed $val = null): mixed
        {
            $parts = \is_array($key) ? $key : self::cut($key);
            if ($parts[0] === 'SESSION') {
                if (!\headers_sent() && \session_status() !== PHP_SESSION_ACTIVE
                    && ($add || !empty($this->COOKIE[\session_name()]))) {
                    $this->session_start();
                    $this->sync('SESSION');
                }
            } elseif (!\preg_match('/^\w+$/', $parts[0]))
                throw new \Exception(\sprintf(self::E_Hive, $this->stringify($key)));
            $out = &parent::ref($parts, $add, val: $val);
            return $out;
        }

        /**
         * Retrieve contents of hive key, check cache if not found
         * use $args to format string values directly
         */
        public function get(string $key, string|array|null $args = null): mixed
        {
            $val = parent::get($key, $args);
            if (\is_null($val)) {
                // Attempt to retrieve from cache
                if (Cache::instance()->exists($this->hash($key).'.var', $data))
                    return $data;
            }
            return $val;
        }

        /**
         * Return TRUE if hive key is set
         * (or return timestamp and TTL if cached)
         */
        public function exists(string $key, mixed &$val = null): bool|array
        {
            $exists = parent::exists($key, $val);
            return $exists ? true : Cache::instance()->exists($this->hash($key).'.var', $val);
        }

        /**
         * Return TRUE if hive key is empty and not cached
         **/
        public function devoid(string $key, mixed &$val = null): bool
        {
            $devoid = parent::devoid($key, $val);
            return $devoid &&
                (!Cache::instance()->exists($this->hash($key).'.var', $val) ||
                    !$val);
        }

        /**
         * Bind value to hive key, configure framework, set value to cache
         */
        public function set(string $key, mixed $val, ?int $ttl = 0): mixed
        {
            $time = \ceil($this->TIME);
            if (\preg_match('/^(GET|POST|COOKIE|SESSION)\b(.+)/', $key, $expr)) {
                if ($expr[1] === 'SESSION') {
                    return parent::set($key, $val);
                } elseif ($expr[1] === 'COOKIE') {
                    $parts = self::cut($key);
                    $jar = $this->JAR->toArray();
                    if ($this->NONBLOCKING) {
                        $header = 'Set-Cookie: %s=%s; '.
                            'expires=%s; Max-Age=%d; path=%s; domain=%s; '.
                            ($this->JAR->secure ? 'secure; ' : '').
                            ($this->JAR->httponly ? 'HttpOnly; ' : '').
                            'SameSite=%s';
                        $args = [
                            \rawurlencode($parts[1]),
                            \rawurlencode($val ?: ''),
                            \gmdate('D, d M Y H:i:s T', $jar['expire'] ?? 1),
                            $jar['lifetime'],
                            $this->JAR->path,
                            $this->JAR->domain,
                            $this->JAR->samesite,
                        ];
                        if (parent::exists($key)) {
                            $cp = $args;
                            // clear value
                            $cp[1] = '';
                            $cp[2] = 0;
                            $this->header(\sprintf($header, ...$cp), false);
                        }
                        $this->header(\sprintf($header, ...$args), false);
                    } else {
                        unset($jar['lifetime'], $jar['expire']);
                        if ($ttl)
                            $jar['expires'] = $time + $ttl;
                        if (parent::exists($key))
                            \setcookie($parts[1], '', ['expires' => 0] + $jar);
                        \setcookie($parts[1], $val ?: '', $jar);
                    }
                    return parent::set($key, $val);
                } else {
                    $this->set('REQUEST'.$expr[2], $val);
                }
            }
            if (\in_array($key, ['CACHE', 'ENCODING', 'FALLBACK', 'LANGUAGE', 'LOCALES', 'TZ'])) {
                if (($key === 'LANGUAGE' || $key === 'LOCALES') && $ttl) {
                    $this->LOCALES_TTL = $ttl;
                }
                // NB: this does not automatically return the updated value from property hook
                return $this->{$key} = $val;
            }
            $ref = parent::set($key, $val);
            if ($ttl)
                // Persist the key-value pair
                Cache::instance()->set($this->hash($key).'.var', $val, $ttl);
            return $ref;
        }

        /**
         * Unset hive key
         */
        public function clear(string $key): void
        {
            // Normalize array literal
            $cache = Cache::instance();
            $parts = self::cut($key);
            if ($key == 'CACHE')
                // Clear cache contents
                $cache->reset();
            elseif ($parts[0] == 'COOKIE') {
                $jar = $this->JAR->toArray();
                unset($jar['lifetime']);
                $jar['expire'] = 1;
                $jar['expires'] = $jar['expire'];
                unset($jar['expire']);
                foreach (isset($parts[1]) ? [$parts[1]] : \array_keys($this->COOKIE) as $cKey) {
                    if ($this->NONBLOCKING) {
                        // cannot use setcookie, because headers are not send in cli mode
                        $this->header_remove(\sprintf('Set-Cookie: %s=', \rawurlencode($cKey)));
                        $this->header(
                            \sprintf(
                                'Set-Cookie: %s=deleted; expires=%s; path=%s; domain=%s; '.
                                ($this->JAR->secure ? 'secure; ' : '').
                                ($this->JAR->httponly ? 'HttpOnly; ' : '').
                                'SameSite=%s',
                                \rawurlencode($cKey),
                                \gmdate('D, d M Y H:i:s T', $jar['expires']),
                                $this->JAR->path,
                                $this->JAR->domain,
                                $this->JAR->samesite,
                            ),
                            false,
                        );
                    } else
                        \setcookie($cKey, '', $jar);
                    unset($_COOKIE[$cKey]);
                }
                (isset($parts[1]))
                    ? parent::clear($key)
                    : $this->set($key, []);
            } elseif (\preg_match('/^(GET|POST)\b(.+)/', $key, $expr)) {
                parent::clear('REQUEST'.$expr[2]);
                parent::clear($key);
            } elseif ($parts[0] == 'SESSION') {
                if (!\headers_sent() && \session_status() != PHP_SESSION_ACTIVE)
                    $this->session_start();
                if (empty($parts[1])) {
                    // End session
                    $this->set('SESSION', null);
                    \session_unset();
                    \session_destroy();
                    $this->clear('COOKIE.'.\session_name());
                } else {
                    parent::clear($key);
                    \session_commit();
                }
            } elseif (\in_array($key, $this->split(Base::GLOBALS))) {
                $this->set($key, []);
            } else {
                parent::clear($key);
                if ($cache->exists($hash = $this->hash($key).'.var'))
                    // Remove from cache
                    $cache->clear($hash);
            }
        }

        /**
         * Return TRUE if hive variable is 'on'
         */
        public function checked(string $key): bool
        {
            $ref = &$this->ref($key);
            return $ref == 'on';
        }

        /**
         * Return TRUE if property has public visibility
         */
        public function visible(object $obj, string $key): bool
        {
            if (\property_exists($obj, $key)) {
                $ref = new \ReflectionProperty($obj::class, $key);
                $out = $ref->isPublic();
                unset($ref);
                return $out;
            }
            return false;
        }

        /**
         * Multi-variable assignment using associative array
         */
        public function mset(array $vars, string $prefix = '', int $ttl = 0): void
        {
            foreach ($vars as $key => $val)
                $this->set($prefix.$key, $val, $ttl);
        }

        /**
         * Publish hive contents
         **/
        public function hive(): array
        {
            return $this->toArray();
        }

        /**
         * Copy contents of hive variable to another
         */
        public function copy(string $src, string $dst): void
        {
            $this->set($dst, $this->get($src));
        }

        /**
         * Concatenate string to hive string variable
         */
        public function concat(string $key, string $val): string
        {
            $ref = &$this->ref($key);
            $ref .= $val;
            return $ref;
        }

        /**
         * Swap keys and values of hive array variable
         */
        public function flip(string $key): array
        {
            $ref = &$this->ref($key);
            return $ref = array_combine(array_values($ref), array_keys($ref));
        }

        /**
         * Add element to the end of hive array variable
         */
        public function push(string $key, mixed $val): mixed
        {
            $ref = &$this->ref($key);
            $ref[] = $val;
            return $val;
        }

        /**
         * Remove last element of hive array variable
         */
        public function pop(string $key): mixed
        {
            $ref = &$this->ref($key);
            return array_pop($ref);
        }

        /**
         * Add element to the beginning of hive array variable
         */
        public function unshift(string $key, mixed $val): mixed
        {
            $ref = &$this->ref($key);
            array_unshift($ref, $val);
            return $val;
        }

        /**
         * Remove first element of hive array variable
         */
        public function shift(string $key): mixed
        {
            $ref = &$this->ref($key);
            return array_shift($ref);
        }

        /**
         * Merge array with hive array variable
         */
        public function merge(string $key, string|array $src, bool $keep = false): array
        {
            $ref = &$this->ref($key);
            if (!$ref)
                $ref = [];
            $out = [...$ref, ...(is_string($src) ? ($this->get($src) ?: []) : $src)];
            if ($keep)
                $ref = $out;
            return $out;
        }

        /**
         * Extend hive array variable with default values from $src
         */
        public function extend(string $key, string|array $src, bool $keep = false): array
        {
            $ref = &$this->ref($key);
            if (!$ref)
                $ref = [];
            $out = array_replace_recursive(
                is_string($src) ? ($this->get($src) ?: []) : $src,
                $ref,
            );
            if ($keep)
                $ref = $out;
            return $out;
        }

        /**
         * fetch a hive key result, then clear the key
         */
        public function pull(string $key): mixed
        {
            $value = $this->get($key);
            $this->clear($key);
            return $value;
        }

        /**
         * Convert backslashes to slashes
         */
        public function fixslashes(string $str): string
        {
            return $str ? \strtr($str, '\\', '/') : $str;
        }

        /**
         * Split comma-, semi-colon, or pipe-separated string
         */
        public function split(?string $str, bool $noEmpty = true): array
        {
            return \array_map(
                'trim',
                \preg_split('/[,;|]/', $str ?: '', 0, $noEmpty ? PREG_SPLIT_NO_EMPTY : 0),
            );
        }

        /**
         * Convert PHP expression/value to compressed exportable string
         */
        public function stringify(mixed $arg, ?array $stack = null): string
        {
            if (!$stack)
                $stack = [];
            elseif (\array_any($stack, fn($node) => $arg === $node))
                return '*RECURSION*';
            switch (\gettype($arg)) {
                case 'object':
                    $str = '';
                    foreach (\get_object_vars($arg) as $key => $val)
                        $str .= ($str ? ',' : '').
                            $this->export($key).'=>'.
                            $this->stringify($val, [...$stack, $arg]);
                    return $arg::class.'::__set_state(['.$str.'])';
                case 'array':
                    $str = '';
                    $num = isset($arg[0]) &&
                        \ctype_digit(\implode('', \array_keys($arg)));
                    foreach ($arg as $key => $val)
                        $str .= ($str ? ',' : '').
                            ($num ? '' : ($this->export($key).'=>')).
                            $this->stringify($val, [...$stack, $arg]);
                    return '['.$str.']';
                default:
                    return $this->export($arg);
            }
        }

        /**
         * Flatten array values and return as CSV string
         */
        public function csv(array $args): string
        {
            return \implode(
                ',',
                \array_map(
                    'stripcslashes',
                    \array_map([$this, 'stringify'], $args),
                ),
            );
        }

        /**
         * Convert snake_case string to CamelCase
         */
        public function camelCase(string $str): string
        {
            return \preg_replace_callback(
                '/_(\pL)/u',
                fn($match) => \strtoupper($match[1]),
                $str,
            );
        }

        /**
         * Convert CamelCase string to snake_case
         */
        public function snakeCase(string $str): string
        {
            return \strtolower(\preg_replace('/(?!^)\p{Lu}/u', '_\0', $str));
        }

        /**
         * Return -1 if specified number is negative, 0 if zero,
         * or 1 if the number is positive
         */
        public function sign(int|float $num): int
        {
            return $num ? ($num / \abs($num)) : 0;
        }

        /**
         * Extract values of array whose keys start with the given prefix
         */
        public function extract(array $arr, string $prefix): array
        {
            $out = [];
            foreach (\preg_grep('/^'.\preg_quote($prefix, '/').'/', \array_keys($arr)) as $key)
                $out[\substr($key, \strlen($prefix))] = $arr[$key];
            return $out;
        }

        /**
         * Convert class constants to array
         */
        public function constants(object|string $class, string $prefix = ''): array
        {
            $ref = new \ReflectionClass($class);
            return $this->extract($ref->getConstants(), $prefix);
        }

        /**
         * Generate 64bit/base36 hash
         */
        public function hash(string $str): string
        {
            return \str_pad(
                \base_convert(
                    \substr(\sha1($str ?: ''), -16),
                    16,
                    36,
                ),
                11,
                '0',
                STR_PAD_LEFT,
            );
        }

        /**
         * Return Base64-encoded equivalent
         */
        public function base64(string $data, string $mime): string
        {
            return 'data:'.$mime.';base64,'.\base64_encode($data);
        }

        /**
         * Convert special characters to HTML entities
         */
        public function encode(string $str): string
        {
            return \htmlspecialchars(
                $str,
                $this->BITMASK,
                $this->ENCODING,
            ) ?: $this->scrub($str);
        }

        /**
         * Convert HTML entities back to characters
         */
        public function decode(string $str): string
        {
            return \htmlspecialchars_decode($str, $this->BITMASK);
        }

        /**
         * Invoke callback recursively for all data types
         */
        public function recursive(mixed $arg, callable $func, array $stack = []): mixed
        {
            if ($stack) {
                foreach ($stack as $node)
                    if ($arg === $node)
                        return $arg;
            }
            switch (\gettype($arg)) {
                case 'object':
                    $ref = new \ReflectionClass($arg);
                    if ($ref->isCloneable()) {
                        $arg = clone($arg);
                        $cast = ($it = \is_a($arg, 'IteratorAggregate')) ?
                            \iterator_to_array($arg) : \get_object_vars($arg);
                        foreach ($cast as $key => $val) {
                            // skip inaccessible properties #350
                            if (!$it && !isset($arg->$key))
                                continue;
                            $arg->$key = $this->recursive($val, $func, [...$stack, $arg]);
                        }
                    }
                    return $arg;
                case 'array':
                    $copy = [];
                    foreach ($arg as $key => $val)
                        $copy[$key] = $this->recursive($val, $func, [...$stack, $arg]);
                    return $copy;
            }
            return $func($arg);
        }

        /**
         * Remove HTML tags (except those enumerated) and non-printable characters
         * NB: This method doesn't mitigate XSS/code injection attacks.
         */
        public function clean(mixed $arg, ?string $tags = null): mixed
        {
            return $this->recursive(
                $arg,
                function ($val) use ($tags) {
                    if ($tags != '*')
                        $val = \trim(
                            \strip_tags(
                                $val ?? '',
                                '<'.\implode('><', $this->split($tags)).'>',
                            ),
                        );
                    return \trim(
                        \preg_replace(
                            '/[\x00-\x08\x0B\x0C\x0E-\x1F]/',
                            '',
                            $val,
                        ),
                    );
                },
            );
        }

        /**
         * Similar to clean(), except that variable is passed by reference
         */
        public function scrub(mixed &$var, ?string $tags = null): mixed
        {
            return $var = $this->clean($var, $tags);
        }

        /**
         * Return locale-aware formatted string
         */
        public function format(...$args): string
        {
            $val = \array_shift($args);
            // Get formatting rules
            $conv = \localeconv();
            return \preg_replace_callback(
                '/\{\s*(?P<pos>\d+)\s*(?:,\s*(?P<type>\w+)\s*'.
                '(?:,\s*(?P<mod>(?:\w+(?:\s*\{.+?}\s*,?\s*)?)*)'.
                '(?:,\s*(?P<prop>.+?))?)?)?\s*}/',
                function ($expr) use ($args, $conv) {
                    /**
                     * @var string $pos
                     * @var string $mod
                     * @var string $type
                     * @var string $prop
                     */
                    \extract($expr);
                    /**
                     * @var string $thousands_sep
                     * @var string $negative_sign
                     * @var string $positive_sign
                     * @var string $frac_digits
                     * @var string $decimal_point
                     * @var string $int_curr_symbol
                     * @var string $currency_symbol
                     */
                    \extract($conv);
                    if (!\array_key_exists($pos, $args))
                        return $expr[0];
                    if (isset($type)) {
                        if (isset($this->FORMATS[$type]))
                            return $this->call(
                                $this->FORMATS[$type],
                                [$args[$pos], $mod ?? null, $prop ?? null],
                            );
                        switch ($type) {
                            case 'plural':
                                \preg_match_all(
                                    '/(?<tag>\w+)'.
                                    '\s*\{\s*(?<data>.*?)\s*}/',
                                    $mod,
                                    $matches,
                                    PREG_SET_ORDER,
                                );
                                $ord = ['zero', 'one', 'two'];
                                foreach ($matches as $match) {
                                    /** @var string $tag */
                                    /** @var string $data */
                                    \extract($match);
                                    if (isset($ord[$args[$pos]]) &&
                                        $tag == $ord[$args[$pos]] || $tag == 'other')
                                        return \str_replace('#', $args[$pos], $data);
                                }
                            case 'number':
                                if (isset($mod))
                                    switch ($mod) {
                                        case 'integer':
                                            return \number_format(
                                                $args[$pos],
                                                0,
                                                '',
                                                $thousands_sep,
                                            );
                                        case 'currency':
                                            $int = false;
                                            if (isset($prop) &&
                                                (!$int = ($prop == 'int')))
                                                $currency_symbol = $prop;
                                            $fmt = [
                                                0 => '(nc)',
                                                1 => '(n c)',
                                                2 => '(nc)',
                                                10 => '+nc',
                                                11 => '+n c',
                                                12 => '+ nc',
                                                20 => 'nc+',
                                                21 => 'n c+',
                                                22 => 'nc +',
                                                30 => 'n+c',
                                                31 => 'n +c',
                                                32 => 'n+ c',
                                                40 => 'nc+',
                                                41 => 'n c+',
                                                42 => 'nc +',
                                                100 => '(cn)',
                                                101 => '(c n)',
                                                102 => '(cn)',
                                                110 => '+cn',
                                                111 => '+c n',
                                                112 => '+ cn',
                                                120 => 'cn+',
                                                121 => 'c n+',
                                                122 => 'cn +',
                                                130 => '+cn',
                                                131 => '+c n',
                                                132 => '+ cn',
                                                140 => 'c+n',
                                                141 => 'c+ n',
                                                142 => 'c +n',
                                            ];
                                            if ($args[$pos] < 0) {
                                                $sgn = $negative_sign;
                                                $pre = 'n';
                                            } else {
                                                $sgn = $positive_sign;
                                                $pre = 'p';
                                            }
                                            return \trim(\str_replace(
                                                ['+', 'n', 'c'],
                                                [
                                                    $sgn,
                                                    \number_format(
                                                        \abs($args[$pos]),
                                                        $frac_digits,
                                                        $decimal_point,
                                                        $thousands_sep,
                                                    ),
                                                    $int ? $int_curr_symbol : $currency_symbol,
                                                ],
                                                $fmt[(int) (
                                                    (${$pre.'_cs_precedes'} % 2).
                                                    (${$pre.'_sign_posn'} % 5).
                                                    (${$pre.'_sep_by_space'} % 3)
                                                )],
                                            ));
                                        case 'percent':
                                            return \number_format(
                                                    $args[$pos] * 100,
                                                    0,
                                                    $decimal_point,
                                                    $thousands_sep,
                                                ).'%';
                                    }
                                $frac = $args[$pos] - (int) $args[$pos];
                                return \number_format(
                                    $args[$pos],
                                    $prop ?? ($frac ? \strlen($frac) - 2 : 0),
                                    $decimal_point,
                                    $thousands_sep,
                                );
                            case 'date':
                                $lang = $this->split($this->LANGUAGE);
                                // requires intl extension
                                $dateType =
                                    (empty($mod) || $mod == 'short') ? \IntlDateFormatter::SHORT :
                                        ($mod == 'full' ? \IntlDateFormatter::FULL :
                                            \IntlDateFormatter::LONG);
                                $pattern = $dateType === \IntlDateFormatter::SHORT
                                    ? \IntlDatePatternGenerator::create($lang[0])?->getBestPattern(
                                        'yyyyMMdd',
                                    ) : null;
                                $formatter = new \IntlDateFormatter(
                                    $lang[0], $dateType,
                                    \IntlDateFormatter::NONE, pattern: $pattern,
                                );
                                return $formatter->format($args[$pos]);
                            case 'time':
                                $lang = $this->split($this->LANGUAGE);
                                // requires intl extension
                                $formatter = new \IntlDateFormatter(
                                    $lang[0],
                                    \IntlDateFormatter::NONE,
                                    (empty($mod) || $mod == 'short')
                                        ? \IntlDateFormatter::SHORT :
                                        ($mod == 'full' ? \IntlDateFormatter::LONG :
                                            \IntlDateFormatter::MEDIUM),
                                    \IntlTimeZone::createTimeZone($this->TZ),
                                );
                                return $formatter->format($args[$pos]);
                            default:
                                return $expr[0];
                        }
                    }
                    return $args[$pos];
                },
                $val,
            );
        }

        /**
         * Assign/auto-detect language
         */
        public function language(string $code): string
        {
            $code = \preg_replace('/\h+|;q=[0-9.]+/', '', $code ?: '');
            $code .= ($code ? ',' : '').$this->FALLBACK;
            $ll = $this->languages;
            $this->languages = [];
            foreach (\array_reverse(\explode(',', $code)) as $lang)
                if (\preg_match('/^(\w{2})(?:-(\w{2}))?\b/i', $lang, $parts)) {
                    // Generic language
                    \array_unshift($this->languages, $parts[1]);
                    if (isset($parts[2])) {
                        // Specific language
                        $parts[0] = $parts[1].'-'.($parts[2] = \strtoupper($parts[2]));
                        \array_unshift($this->languages, $parts[0]);
                    }
                }
            $this->languages = \array_unique($this->languages);
            $out = \implode(',', $this->languages);
            if ($this->languages === $ll)
                // no change
                return $out;
            $locales = [];
            $windows = \preg_match('/^win/i', PHP_OS);
            // Work around PHP's Turkish locale bug
            foreach (\preg_grep('/^(?!tr)/i', $this->languages) as $locale) {
                if ($windows) {
                    $parts = \explode('-', $locale);
                    if (!\defined($lc = ISO::class.'::LC_'.$parts[0]))
                        continue;
                    $locale = \constant($lc);
                    if (isset($parts[1]) &&
                        \defined($cc = ISO::class.'::CC_'.\strtolower($parts[1])))
                        $locale .= '-'.\constant($cc);
                }
                $locale = \str_replace('-', '_', $locale);
                $locales[] = $locale.'.'.\ini_get('default_charset');
                $locales[] = $locale;
            }
            \setlocale(LC_ALL, $locales);
            return $out;
        }

        /**
         * load locales into hive
         */
        public function loadLocales(?int $ttl = 0): void
        {
            if (!$this->LOCALES)
                return;
            $lex = $this->lexicon($this->LOCALES, $ttl);
            foreach ($lex as $dt => $dd) {
                $ref = &$this->ref($this->PREFIX.$dt);
                $ref = $dd;
                unset($ref);
            }
        }

        /**
         * Return lexicon entries
         */
        public function lexicon(string $path, ?int $ttl = 0): array
        {
            $ttl = $ttl ?: $this->LOCALES_TTL;
            $languages = $this->languages ?: \explode(',', $this->FALLBACK);
            $cache = Cache::instance();
            if ($ttl && $cache->exists(
                    $hash = $this->hash(\implode(',', $languages).$path).'.dic',
                    $lex,
                ))
                return $lex;
            $lex = [];
            foreach ($languages as $lang)
                foreach ($this->split($path) as $dir)
                    if ((\is_file($file = ($base = $dir.$lang).'.php') ||
                            \is_file($file = $base.'.php')) &&
                        \is_array($dict = require($file)))
                        $lex += $dict;
                    elseif (\is_file($file = $base.'.json') &&
                        \is_array($dict = \json_decode(\file_get_contents($file), true)))
                        $lex += $dict;
                    elseif (\is_file($file = $base.'.ini')) {
                        \preg_match_all(
                            '/(?<=^|\n)(?:'.
                            '\[(?<prefix>.+?)]|'.
                            '(?<lval>[^\h\r\n;].*?)\h*=\h*'.
                            '(?<rval>(?:\\\\\h*\r?\n|.+?)*)'.
                            ')(?=\r?\n|$)/',
                            $this->read($file),
                            $matches,
                            PREG_SET_ORDER,
                        );
                        if ($matches) {
                            $prefix = '';
                            foreach ($matches as $match)
                                if ($match['prefix'])
                                    $prefix = $match['prefix'].'.';
                                elseif (!\array_key_exists($key = $prefix.$match['lval'], $lex))
                                    $lex[$key] = \trim(
                                        \preg_replace(
                                            '/\\\\\h*\r?\n/',
                                            "\n",
                                            $match['rval'],
                                        ),
                                    );
                        }
                    }
            if ($ttl)
                $cache->set($hash, $lex, $ttl);
            return $lex;
        }

        /**
         * Return string representation of PHP value
         */
        public function serialize(mixed $arg): string
        {
            return match (\strtolower($this->SERIALIZER)) {
                'igbinary' => \igbinary_serialize($arg),
                default => \serialize($arg),
            };
        }

        /**
         * Return PHP value derived from string
         */
        public function unserialize(string $arg): mixed
        {
            return match (\strtolower($this->SERIALIZER)) {
                'igbinary' => \igbinary_unserialize($arg),
                default => \unserialize($arg),
            };
        }

        /**
         * Send HTTP status header; Return text equivalent of status code
         */
        public function status(int $code, ?string $reason = null): string
        {
            if ($reason === null) {
                $reason = \defined($name = Status::class.'::HTTP_'.$code)
                    ? \constant($name)->value : '';
            }
            if ((!$this->CLI || $this->NONBLOCKING) && !\headers_sent())
                $this->header(
                    ($this->SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1').' '.$code.' '.$reason,
                );
            return $reason;
        }

        /**
         * Send cache metadata to HTTP client
         */
        public function expire(int $secs = 0): void
        {
            if (!$this->CLI && !\headers_sent()) {
                if ($this->PACKAGE)
                    $this->header('X-Powered-By: '.$this->PACKAGE);
                if ($this->XFRAME)
                    $this->header('X-Frame-Options: '.$this->XFRAME);
                $this->header('X-XSS-Protection: 1; mode=block');
                $this->header('X-Content-Type-Options: nosniff');
                if ($this->VERB == 'GET' && $secs) {
                    $time = \microtime(true);
                    $this->header_remove('Pragma');
                    $this->RESPONSE_HEADERS =
                        \preg_grep('/Pragma:/', $this->RESPONSE_HEADERS, PREG_GREP_INVERT);
                    $this->header('Cache-Control: max-age='.$secs);
                    $this->header('Expires: '.\gmdate('r', \round($time + $secs)));
                    $this->header('Last-Modified: '.\gmdate('r'));
                } else {
                    $this->header('Pragma: no-cache');
                    $this->header('Cache-Control: no-cache, no-store, must-revalidate');
                    $this->header('Expires: '.\gmdate('r', 0));
                }
            }
        }

        /**
         * Return HTTP user agent
         */
        public function agent(?array $headers = null): string
        {
            if (!$headers)
                $headers = $this->HEADERS;
            return $headers['User-Agent'] ?? '';
        }

        /**
         * Return TRUE if XMLHttpRequest detected
         */
        public function ajax(?array $headers = null): bool
        {
            if (!$headers)
                $headers = $this->HEADERS;
            return isset($headers['X-Requested-With']) &&
                $headers['X-Requested-With'] == 'XMLHttpRequest';
        }

        /**
         * Sniff IP address
         */
        public function ip(): string
        {
            $headers = $this->HEADERS;
            return $headers['Client-IP'] ?? (isset($headers['X-Forwarded-For'])
                ? \explode(',', $headers['X-Forwarded-For'])[0]
                : ($_SERVER['REMOTE_ADDR'] ?? '')
            );
        }

        /**
         * Return filtered stack trace as a formatted string (or array)
         */
        public function trace(?array $trace = null, bool $format = true): string|array
        {
            if (!$trace) {
                $trace = \debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 100);
                $frame = $trace[0];
                if (isset($frame['file']) && $frame['file'] == __FILE__)
                    \array_shift($trace);
            }
            $debug = $this->DEBUG;
            $trace = \array_filter(
                $trace,
                fn($frame)
                    => isset($frame['file']) &&
                    ($debug > 1 ||
                        (($frame['file'] != __FILE__ || $debug) &&
                            (empty($frame['function']) ||
                                !\preg_match(
                                    '/^(?:(?:trigger|user)_error|'.
                                    '__call|call_user_func)/',
                                    $frame['function'],
                                )))),
            );
            if (!$format)
                return $trace;
            $out = '';
            $eol = "\n";
            // Analyze stack trace
            foreach ($trace as $frame) {
                $line = '';
                if (isset($frame['class']))
                    $line .= $frame['class'].$frame['type'];
                if (isset($frame['function']))
                    $line .= $frame['function'].'('.
                        ($debug > 2 && isset($frame['args'])
                            ? $this->csv($frame['args'])
                            : ''
                        ).')';
                $src = $this->fixslashes(
                        \str_replace(
                            $_SERVER['DOCUMENT_ROOT'].
                            '/',
                            '',
                            $frame['file'],
                        ),
                    ).':'.$frame['line'];
                $out .= '['.$src.'] '.$line.$eol;
            }
            return $out;
        }

        /**
         * Log error; Execute ONERROR handler if defined, else display
         * default error page (HTML for synchronous requests, JSON string
         * for AJAX requests)
         */
        public function error(
            int $code,
            string $text = '',
            ?array $trace = null,
            int $level = 0,
            bool $halt = false,
            bool $throw = true,
        ): mixed {
            $prior = $this->ERROR;
            if ($code === 0)
                $code = 500;
            $header = $this->status($code);
            $req = $this->VERB.' '.$this->PATH;
            if ($this->QUERY)
                $req .= '?'.$this->QUERY;
            if (!$text)
                $text = 'HTTP '.$code.' ('.$req.')';
            $trace = $this->trace($trace);
            $loggable = $this->LOGGABLE;
            if (!\is_array($loggable))
                $loggable = $this->split($loggable);
            foreach ($loggable as $status)
                if ($status == '*' ||
                    \preg_match('/^'.\preg_replace('/\D/', '\d', $status).'$/', (string) $code)) {
                    \error_log($text);
                    foreach (\explode("\n", $trace) as $nexus)
                        if ($nexus)
                            \error_log($nexus);
                    break;
                }
            if ($highlight = (!$this->CLI && !$this->AJAX &&
                $this->HIGHLIGHT && \is_file($css = __DIR__.'/'.self::CSS)))
                $trace = $this->highlight($trace);
            $this->ERROR = [
                'status' => $header,
                'code' => $code,
                'text' => $text,
                'trace' => $trace,
                'level' => $level,
            ];
            $this->expire(-1);
            $handler = $this->ONERROR;
            $this->ONERROR = null;
            $eol = "\n";
            if ((!$handler ||
                    ($handlerOut = $this->callRoute(
                        $handler,
                        [$this, $this->PARAMS],
                        ['beforeRoute', 'afterRoute'],
                    )) === false) &&
                !$prior) {
                $error = \array_diff_key(
                    $this->ERROR,
                    $this->DEBUG ?
                        [] :
                        ['trace' => 1],
                );
                if ($this->CLI)
                    $out = PHP_EOL.'==================================='.PHP_EOL.
                        'ERROR '.$error['code'].' - '.$error['status'].PHP_EOL.
                        $error['text'].PHP_EOL.PHP_EOL.($error['trace'] ?? '');
                else {
                    $out = $this->AJAX ? \json_encode($error) :
                        ('<!DOCTYPE html>'.$eol.
                            '<html>'.$eol.
                            '<head>'.
                            '<title>'.$code.' '.$header.'</title>'.
                            ($highlight ?
                                ('<style>'.$this->read($css).'</style>') : '').
                            '</head>'.$eol.
                            '<body>'.$eol.
                            '<h1>'.$header.'</h1>'.$eol.
                            '<p>'.$this->encode($text ?: $req).'</p>'.$eol.
                            ($this->DEBUG ? ('<pre>'.$trace.'</pre>'.$eol) : '').
                            '</body>'.$eol.
                            '</html>');
                    $this->header('Content-Type: text/html');
                }
                $this->RESPONSE = $out;
            }
            if (isset($handlerOut) && $handlerOut !== false)
                $this->RESPONSE = $handlerOut;
            if (!$this->QUIET) {
                $this->send_headers();
                echo $this->RESPONSE;
            }
            if ($this->NONBLOCKING && $throw)
                throw new ResponseException();
            if ($this->HALT || $halt)
                die(1);
            return $this->RESPONSE;
        }

        /**
         * Mock HTTP request
         */
        public function mock(
            string $pattern,
            ?array $args = null,
            ?array $headers = null,
            ?string $body = null,
            bool $sandbox = false,
            bool $throw = false,
        ): mixed {
            if (!$args)
                $args = [];
            $types = ['sync', 'ajax', 'cli'];
            \preg_match(
                '/([|\w]+)\h+(?:@(\w+)(?:(\(.+?)\))*|(\H+))'.
                '(?:\h+\[('.\implode('|', $types).')])?/',
                $pattern,
                $parts,
            );
            $verb = \strtoupper($parts[1]);
            if ($parts[2]) {
                if (empty($this->ALIASES[$parts[2]]))
                    throw new \Exception(\sprintf(self::E_Named, $parts[2]));
                $parts[4] = $this->ALIASES[$parts[2]];
                $parts[4] = $this->build(
                    $parts[4],
                    isset($parts[3]) ? $this->parse($parts[3]) : [],
                );
            }
            if (empty($parts[4]))
                throw new \Exception(\sprintf(self::E_Pattern, $pattern));
            $url = \parse_url($parts[4]);
            $fw = $this;
            if ($sandbox)
                Registry::set($class = Base::class, $fw = clone $this);
            \parse_str($url['query'] ?? '', $fw->GET);
            if (\preg_match('/GET|HEAD/', $verb))
                $fw->GET = \array_merge($fw->GET, $args);
            $fw->POST = $verb === 'POST' ? $args : [];
            $fw->REQUEST = \array_merge($fw->GET ?? [], $fw->POST ?? []);
            $fw->HEADERS = $headers ?? [];
            foreach ($headers ?: [] as $key => $val)
                $fw->SERVER['HTTP_'.\strtr(\strtoupper($key), '-', '_')] = $val;
            if (isset($headers['Accept-Language'])) {
                if ($sandbox)
                    $bak_loc = \setlocale(LC_ALL, 0);
                $fw->LANGUAGE = $headers['Accept-Language'];
            }
            $fw->VERB = $fw->SERVER['REQUEST_METHOD'] = $verb;
            $fw->PATH = $url['path'];
            $fw->URI = $fw->SERVER['REQUEST_URI'] = $fw->BASE.$url['path'];
            $fw->REALM = $fw->SCHEME.'://'.$fw->HOST.
                (!\in_array($fw->PORT, [80, 443]) ? (':'.$fw->PORT) : '').$fw->URI;
            if ($fw->GET)
                $fw->URI .= '?'.\http_build_query($fw->GET);
            $fw->BODY = '';
            $fw->RESPONSE_HEADERS = [];
            if (!\preg_match('/GET|HEAD/', $verb))
                $fw->BODY = $body ?: \http_build_query($args);
            $fw->AJAX = (isset($parts[5]) &&
                \preg_match('/ajax/i', $parts[5])) || ($headers && $this->ajax($headers));
            $fw->CLI = isset($parts[5]) &&
                \preg_match('/cli/i', $parts[5]);
            $fw->TIME = $fw->SERVER['REQUEST_TIME_FLOAT'] = \microtime(true);
            try {
                $out = $fw->run();
            } catch (\Throwable $e) {
                $this->EXCEPTION = $e;
                if ($throw)
                    throw $e;
                else
                    $out = $this->error(
                        500,
                        $e->getMessage().' '.
                        '['.$e->getFile().':'.$e->getLine().']',
                        $e->getTrace(),
                    );
            }
            if ($sandbox) {
                $this->RESPONSE = $fw->RESPONSE;
                $this->RESPONSE_HEADERS = $fw->RESPONSE_HEADERS;
                Registry::set($class, $this);
            }
            if (isset($bak_loc))
                \setlocale(LC_ALL, $bak_loc);
            return $out;
        }

        /**
         * Assemble url from alias name
         */
        public function alias(
            string $name,
            array|string $params = [],
            string|array|null $query = null,
            ?string $fragment = null,
        ): string {
            if (!\is_array($params))
                $params = $this->parse($params);
            if (empty($this->ALIASES[$name]))
                throw new \Exception(\sprintf(self::E_Named, $name));
            $url = $this->build($this->ALIASES[$name], $params);
            if (\is_array($query))
                $query = \http_build_query($query);
            return $url.($query ? ('?'.$query) : '').($fragment ? '#'.$fragment : '');
        }

        /**
         * Return TRUE if IPv4 address exists in DNSBL
         */
        public function blacklisted(string $ip): bool
        {
            if ($this->DNSBL &&
                !\in_array(
                    $ip,
                    \is_array($this->EXEMPT) ?
                        $this->EXEMPT :
                        $this->split($this->EXEMPT),
                )) {
                // Reverse IPv4 dotted quad
                $rev = \implode('.', \array_reverse(\explode('.', $ip)));
                foreach (
                    \is_array($this->DNSBL)
                        ? $this->DNSBL
                        : $this->split($this->DNSBL) as $server
                )
                    // DNSBL lookup
                    if (\checkdnsrr($rev.'.'.$server, 'A'))
                        return true;
            }
            return false;
        }

        /**
         * get used traits from class
         */
        public function traits(object|string $class, array $traits = []): array
        {
            $reflection = ($class instanceof \ReflectionClass)
                ? $class : new \ReflectionClass($class);
            if ($reflection->getParentClass()) {
                $traits = $this->traits($reflection->getParentClass(), $traits);
            }
            foreach ($reflection->getTraits() as $trait_key => $trait) {
                $traits[$trait_key] = $trait->getName();
                $traits = $this->traits($trait, $traits);
            }
            return $traits;
        }

        /**
         * Loop until callback returns TRUE (for long polling)
         */
        public function until(callable|string $func, ?array $args = null, int $timeout = 60): mixed
        {
            if (!$args)
                $args = [];
            $time = \time();
            $max = \ini_get('max_execution_time');
            $limit = \max(0, ($max ? \min($timeout, $max) : $timeout) - 1);
            $out = '';
            // Turn output buffering on
            \ob_start();
            // Not for the weak of heart
            while (
                // No error occurred
                !$this->ERROR &&
                // Got time left?
                \time() - $time + 1 < $limit &&
                // Still alive?
                !\connection_aborted() &&
                // Restart session
                !\headers_sent() &&
                (\session_status() == PHP_SESSION_ACTIVE || $this->session_start()) &&
                // CAUTION: Callback will kill host if it never becomes truthy!
                !$out = $this->call($func, $args)
            ) {
                if (!$this->CLI)
                    \session_commit();
                // Hush down
                \sleep(1);
            }
            \ob_flush();
            \flush();
            return $out;
        }

        /**
         * Disconnect HTTP client;
         * Set FcgidOutputBufferSize to zero if server uses mod_fcgid;
         * Disable mod_deflate when rendering text/html output
         * @deprecated
         */
        function abort()
        {
            if (!\headers_sent() && \session_status() != PHP_SESSION_ACTIVE)
                $this->session_start();
            $out = '';
            while (\ob_get_level())
                $out = \ob_get_clean().$out;
            if (!\headers_sent()) {
                \header('Content-Length: '.\strlen($out));
                \header('Connection: close');
            }
            \session_commit();
            echo $out;
            \flush();
            if (\function_exists('fastcgi_finish_request'))
                \fastcgi_finish_request();
        }

        /**
         * Grab the callable behind a string or array callable expression
         */
        public function grab(string|array $func, ?array $args = null): string|array
        {
            if (\is_array($func)) {
                $func[0] = $this->make($func[0], $args ?? []);
                return $func;
            }
            if (\preg_match('/(.+)\h*(->|::)\h*(.+)/s', $func, $parts)) {
                // Convert string to executable PHP callback
                if (!\class_exists($parts[1])) {
                    throw new \Exception(\sprintf(self::E_Class, $parts[1]));
                }
                if ($parts[2] == '->') {
                    $parts[1] = $this->make($parts[1], $args ?? []);
                }
                $func = [$parts[1], $parts[3]];
            }
            return $func;
        }

        /**
         * make new class instance through container if available
         * @template Class
         * @param class-string<Class> $class
         * @return Class
         */
        public function make(string $class, array $args = []): mixed
        {
            if ($this->CONTAINER) {
                $container = $this->CONTAINER;
                if ($container instanceof \Closure)
                    return $container($class, $args);
                elseif (\is_object($container) && $container->has($class)) // PSR11
                    return $args
                        ? $container->make($class, $args)
                        : $container->get($class);
                elseif (\is_callable($container))
                    return \call_user_func($container, $class, $args);
                else
                    throw new \Exception(
                        \sprintf(
                            self::E_Class,
                            $this->stringify($class),
                        ),
                    );
            } elseif (\in_array(Prefab::class, $this->traits($class))) {
                return $class::instance();
            }
            $ref = new \ReflectionClass($class);
            return $ref->hasMethod('__construct') && $args
                ? $ref->newInstanceArgs([$this, $args])
                : $ref->newInstance();
        }

        /**
         * Execute callback (supports 'class->method' format)
         */
        public function call(callable|string|array $func, array $args = []): mixed
        {
            // Grab the real handler behind the string representation
            if ((!\is_callable($func) || \is_string($func))
                && !\is_callable($func = $this->grab($func, $args)))
                throw new \Exception(
                    \sprintf(
                        self::E_Method,
                        \is_string($func) ? $func : $this->stringify($func),
                    ),
                );
            if ($this->CONTAINER) {
                if ((\is_array($func) && !\method_exists(...$func))
                    || \is_string($func) && \function_exists($func)) {
                    $args = \array_values($args);
                } else {
                    $service = Service::instance();
                    $ref = \is_array($func)
                        ? new \ReflectionMethod(...$func)
                        : new \ReflectionFunction($func);
                    $out = [];
                    $i = 0;
                    foreach ($ref->getParameters() as $p) {
                        $out[$pn = $p->getName()] =
                            $args[$pn] ?? $service->resolveParam($p) ?? $args[$i++];
                    }
                    $args = $out;
                }
            } else
                $args = \array_values($args);
            return \call_user_func_array($func, $args);
        }

        /**
         * Execute specified callbacks in succession; Apply same arguments
         * to all callbacks
         */
        public function chain(
            array|string $funcs,
            mixed $args = null,
            bool $halt = false,
        ): array|false {
            $out = [];
            foreach (\is_array($funcs) ? $funcs : $this->split($funcs) as $func) {
                $out[] = $r = $this->call($func, $args);
                if ($halt && $r === false)
                    break;
            }
            return $out;
        }

        /**
         * Execute specified callbacks in succession; Relay result of
         * previous callback as argument to the next callback
         */
        public function relay(array|string $funcs, mixed $args = null, bool $halt = false): mixed
        {
            foreach (\is_array($funcs) ? $funcs : $this->split($funcs) as $func) {
                $args = [$out = $this->call($func, $args)];
                if ($halt && $out === false)
                    break;
            }
            return \array_shift($args);
        }

        /**
         * Configure framework according to .ini-style file settings;
         * If optional 2nd arg is provided, template strings are interpreted
         */
        public function config(string|array $source, bool $allow = false): Base
        {
            if (\is_string($source))
                $source = $this->split($source);
            if ($allow)
                $preview = Preview::instance();
            foreach ($source as $file) {
                \preg_match_all(
                    '/(?<=^|\n)(?:'.
                    '\[(?<section>.+?)]|'.
                    '(?<lval>[^\h\r\n;].*?)\h*=\h*'.
                    '(?<rval>(?:\\\\\h*\r?\n|.+?)*)'.
                    ')(?=\r?\n|$)/',
                    $this->read($file),
                    $matches,
                    PREG_SET_ORDER,
                );
                if ($matches) {
                    $sec = 'globals';
                    $cmd = [];
                    foreach ($matches as $match) {
                        if ($match['section']) {
                            $sec = $match['section'];
                            if (\preg_match(
                                    '/^(?!(?:global|config|route|map|redirect)s\b)'.
                                    '(.*?)\s*[:>]/i',
                                    $sec,
                                    $msec,
                                ) &&
                                !$this->exists($msec[1]))
                                $this->set($msec[1], null);
                            \preg_match(
                                '/^(config|route|map|redirect)s\b|'.
                                '^(.+?)\s*>\s*(.*)/i',
                                $sec,
                                $cmd,
                            );
                            continue;
                        }
                        if ($allow)
                            foreach (['lval', 'rval'] as $ndx)
                                $match[$ndx] =
                                    $preview->resolve($match[$ndx], null, 0, false, false);
                        if (!empty($cmd)) {
                            isset($cmd[3])
                                ? $this->call($cmd[3], [$match['lval'], $match['rval'], $cmd[2]])
                                : \call_user_func_array([$this, $cmd[1]],
                                \array_merge([$match['lval']],
                                    \str_getcsv(
                                        $cmd[1] == 'config' ?
                                            $this->cast($match['rval']) :
                                            $match['rval'],
                                        ",",
                                        '"',
                                        "",
                                    )),
                            );
                        } else {
                            $rval = \preg_replace(
                                '/\\\\\h*(\r?\n)/',
                                '\1',
                                $match['rval'],
                            );
                            $ttl = null;
                            if (\preg_match('/^(.+)\|\h*(\d+)$/', $rval, $tmp)) {
                                \array_shift($tmp);
                                [$rval, $ttl] = $tmp;
                            }
                            $args = \array_map(function ($val) {
                                $val = $this->cast($val);
                                if (\is_string($val))
                                    $val = \strlen($val) ?
                                        \preg_replace('/\\\\"/', '"', $val) :
                                        null;
                                return $val;
                            },
                                // Mark quoted strings with 0x00 whitespace
                                \str_getcsv(
                                    \preg_replace(
                                        '/(?<!\\\\)(")(.*?)\1/',
                                        "\\1\x00\\2\\1",
                                        \trim($rval),
                                    ),
                                    ",",
                                    '"',
                                    "",
                                ),
                            );
                            \preg_match(
                                '/^(?<section>[^:]+)(?::(?<func>.+))?/',
                                $sec,
                                $parts,
                            );
                            $func = $parts['func'] ?? null;
                            $custom = (\strtolower($parts['section']) != 'globals');
                            if ($func)
                                $args = [$this->call($func, $args)];
                            if (\count($args) > 1)
                                $args = [$args];
                            if (isset($ttl))
                                $args = \array_merge($args, [$ttl]);
                            \call_user_func_array(
                                [$this, 'set'],
                                \array_merge(
                                    [
                                        ($custom ? ($parts['section'].'.') : '').
                                        $match['lval'],
                                    ],
                                    $args,
                                ),
                            );
                        }
                    }
                }
            }
            return $this;
        }

        /**
         * Create mutex, invoke callback then drop ownership when done
         */
        public function mutex(string $id, callable|string $func, mixed $args = null): mixed
        {
            if (!\is_dir($tmp = $this->TEMP))
                \mkdir($tmp, self::MODE, true);
            // Use filesystem lock
            if (\is_file(
                    $lock = $tmp.
                        $this->SEED.'.'.$this->hash($id).'.lock',
                ) &&
                \filemtime($lock) + \ini_get('max_execution_time') < \microtime(true))
                // Stale lock
                @\unlink($lock);
            while (!($handle = @\fopen($lock, 'x')) && !\connection_aborted())
                \usleep(\mt_rand(0, 100));
            $this->locks[$id] = $lock;
            $out = $this->call($func, $args);
            \fclose($handle);
            @\unlink($lock);
            unset($this->locks[$id]);
            return $out;
        }

        /**
         * Read file (with option to apply Unix LF as standard line ending)
         */
        public function read(string $file, bool $lf = false): string|false
        {
            if (!file_exists($file)) {
                return false;
            }
            $out = \file_get_contents($file);
            return $lf ? \preg_replace('/\r\n|\r/', "\n", $out) : $out;
        }

        /**
         * Exclusive file write
         */
        public function write(string $file, mixed $data, bool $append = false): int|false
        {
            return \file_put_contents($file, $data, $this->LOCK | ($append ? FILE_APPEND : 0));
        }

        /**
         * Apply syntax highlighting
         */
        public function highlight(string $text): string
        {
            $out = '';
            $pre = false;
            $text = \trim($text);
            if ($text && !\preg_match('/^<\?php/', $text)) {
                $text = '<?php '.$text;
                $pre = true;
            }
            foreach (\token_get_all($text) as $token)
                if ($pre)
                    $pre = false;
                else
                    $out .= '<span'.
                        (\is_array($token) ?
                            (' class="'.
                                \substr(\strtolower(\token_name($token[0])), 2).'">'.
                                $this->encode($token[1])) :
                            ('>'.$this->encode($token))).
                        '</span>';
            return $out ? ('<code>'.$out.'</code>') : $text;
        }

        /**
         * Dump expression with syntax highlighting
         * @param $expr mixed
         **/
        public function dump(mixed $expr): void
        {
            echo $this->highlight($this->stringify($expr));
        }

        /**
         * Return path (and query parameters) relative to the base directory
         */
        public function rel(string $url): string
        {
            return \preg_replace(
                '/^(?:https?:\/\/)?'.
                \preg_quote($this->BASE, '/').'(\/.*|$)/',
                '\1',
                $url,
            );
        }

        /**
         * delegated session start handler
         * @return bool
         */
        public function session_start(): bool
        {
            $sessionName = \session_name();
            if ($this->NONBLOCKING) {
                if (!$this->exists('COOKIE.'.$sessionName, $sId)) {
                    $sId = \session_create_id();
                    $this->set('COOKIE.'.$sessionName, $sId);
                }
                \session_id($sId);
            }
            $out = \session_start();
            $this->COOKIE[$sessionName] = \session_id();
            return $out;
        }

        /**
         * Namespace-aware class autoloader
         */
        protected function autoload(string $class): void
        {
            $class = $this->fixslashes(\ltrim($class, '\\'));
            /** @var callable $func */
            $func = null;
            if (\is_array($path = $this->AUTOLOAD) &&
                isset($path[1]) && \is_callable($path[1]))
                [$path, $func] = $path;
            foreach ($this->split($path) as $auto)
                if (($func &&
                        \is_file($file = $func($auto.$class).'.php')) ||
                    \is_file($file = $auto.$class.'.php') ||
                    \is_file($file = $auto.\strtolower($class).'.php') ||
                    \is_file($file = \strtolower($auto.$class).'.php'))
                    require($file);
        }

        /**
         * Execute framework/application shutdown sequence
         */
        public function unload(string $cwd): void
        {
            \chdir($cwd);
            if (!($error = \error_get_last()) &&
                \session_status() == PHP_SESSION_ACTIVE) {
                \session_commit();
                $this->NONBLOCKING && \session_unset();
            }
            foreach ($this->locks as $lock)
                @\unlink($lock);
            $handler = $this->UNLOAD;
            if ((!$handler || $this->call($handler, [$this]) === false) &&
                $error && \in_array(
                    $error['type'],
                    [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR],
                ))
                // Fatal error detected
                $this->error(
                    500,
                    \sprintf(self::E_Fatal, $error['message']),
                    [$error],
                    halt: true,
                );
        }

        /**
         * Call function identified by hive key
         */
        public function __call(string $key, array $args): mixed
        {
            if ($this->exists($key, $val))
                return \call_user_func_array($val, $args);
            throw new \Exception(\sprintf(self::E_Method, $key));
        }

        /**
         * Bootstrap
         */
        public function __construct()
        {
            // Managed directives
            \ini_set('default_charset', $charset = 'UTF-8');
            if (\extension_loaded('mbstring'))
                \mb_internal_encoding($charset);
            \ini_set('display_errors', 0);
            // Intercept errors/exceptions
            $check = \error_reporting(E_ALL & ~(E_NOTICE | E_USER_NOTICE));
            \set_exception_handler(
                function (\Throwable $obj) {
                    $this->EXCEPTION = $obj;
                    $this->error(
                        500,
                        $obj->getMessage().' '.
                        '['.$obj->getFile().':'.$obj->getLine().']',
                        $obj->getTrace(),
                    );
                },
            );
            \set_error_handler(
                function ($level, $text, $file, $line) {
                    if ($level & \error_reporting()) {
                        $trace = $this->trace(null, false);
                        \array_unshift($trace, ['file' => $file, 'line' => $line]);
                        $this->error(500, $text, $trace, $level);
                    }
                },
            );
            if (!isset($_SERVER['SERVER_NAME']) || $_SERVER['SERVER_NAME'] === '')
                $_SERVER['SERVER_NAME'] = \gethostname();
            $headers = [];
            if ($cli = (PHP_SAPI == 'cli')) {
                // Emulate HTTP request
                $_SERVER['REQUEST_METHOD'] = 'GET';
                if (!isset($_SERVER['argv'][1])) {
                    ++$_SERVER['argc'];
                    $_SERVER['argv'][1] = '/';
                }
                $req = $query = '';
                if (\str_starts_with($_SERVER['argv'][1], '/')) {
                    $req = $_SERVER['argv'][1];
                    $query = \parse_url($req, PHP_URL_QUERY);
                } else {
                    foreach ($_SERVER['argv'] as $i => $arg) {
                        if (!$i) continue;
                        if (\preg_match('/^-(-)?(\w+)(?:=(.*))?$/', $arg, $m)) {
                            foreach ($m[1] ? [$m[2]] : \str_split($m[2]) as $k)
                                $query .= ($query ? '&' : '').\urlencode($k).'=';
                            if (isset($m[3]))
                                $query .= \urlencode($m[3]);
                        } else
                            $req .= '/'.$arg;
                    }
                    if (!$req)
                        $req = '/';
                    if ($query)
                        $req .= '?'.$query;
                }
                $_SERVER['REQUEST_URI'] = $req;
                \parse_str($query ?: '', $GLOBALS['_GET']);
            } else foreach (\getallheaders() as $key => $val) {
                $tmp = \strtoupper(\strtr($key, '-', '_'));
                $key = \ucwords(\strtolower($key), '-');
                $headers[$key] = $val;
                if (isset($_SERVER['HTTP_'.$tmp]))
                    $headers[$key] = $_SERVER['HTTP_'.$tmp];
            }
            if (isset($headers['X-Http-Method-Override']))
                $_SERVER['REQUEST_METHOD'] = $headers['X-Http-Method-Override'];
            elseif ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['_method']))
                $_SERVER['REQUEST_METHOD'] = \strtoupper($_POST['_method']);
            $scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on' ||
                isset($headers['X-Forwarded-Proto']) &&
                $headers['X-Forwarded-Proto'] == 'https' ? 'https' : 'http';
            $_SERVER['DOCUMENT_ROOT'] = \realpath($_SERVER['DOCUMENT_ROOT']);
            $base = '';
            if (!$cli)
                $base = \rtrim(
                    $this->fixslashes(
                        \dirname($_SERVER['SCRIPT_NAME']),
                    ),
                    '/',
                );
            $uri = \parse_url(
                (\preg_match('/^\w+:\/\//', $_SERVER['REQUEST_URI']) ? '' :
                    $scheme.'://'.$_SERVER['SERVER_NAME']).$_SERVER['REQUEST_URI'],
            );
            $_SERVER['REQUEST_URI'] = $uri['path'].
                (isset($uri['query']) ? '?'.$uri['query'] : '').
                (isset($uri['fragment']) ? '#'.$uri['fragment'] : '');
            $path = \preg_replace('/^'.\preg_quote($base, '/').'/', '', $uri['path']);
            $jar = new CookieJarConfig($_SERVER['REQUEST_TIME_FLOAT'], [
                'expire' => 0,
                'lifetime' => 0,
                'path' => $base ?: '/',
                'domain' => \str_contains($_SERVER['SERVER_NAME'], '.') &&
                !\filter_var($_SERVER['SERVER_NAME'], FILTER_VALIDATE_IP) ?
                    $_SERVER['SERVER_NAME'] : '',
                'secure' => ($scheme == 'https'),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            $port = 80;
            if (!empty($headers['X-Forwarded-Port']))
                $port = $headers['X-Forwarded-Port'];
            elseif (!empty($_SERVER['SERVER_PORT']))
                $port = $_SERVER['SERVER_PORT'];
            // Default configuration
            $init = [
                'AGENT' => $this->agent($headers),
                'AJAX' => $this->ajax($headers),
                'BASE' => $base,
                'CLI' => $cli,
                'ENCODING' => $charset,
                'HEADERS' => $headers,
                'HOST' => $_SERVER['SERVER_NAME'],
                'IP' => $this->ip(),
                'JAR' => $jar,
                'PATH' => $path,
                'PORT' => $port,
                'QUERY' => $uri['query'] ?? '',
                'REALM' => $scheme.'://'.$_SERVER['SERVER_NAME'].
                    (!\in_array($port, [80, 443]) ? (':'.$port) : '').
                    $_SERVER['REQUEST_URI'],
                'ROOT' => $_SERVER['DOCUMENT_ROOT'],
                'SCHEME' => $scheme,
                'SEED' => $this->hash($_SERVER['SERVER_NAME'].$base),
                'SERIALIZER' => 'php',
                'TIME' => $_SERVER['REQUEST_TIME_FLOAT'],
                'URI' => $_SERVER['REQUEST_URI'],
                'VERB' => $_SERVER['REQUEST_METHOD'],
            ];
            // Create hive
            parent::__construct(data: $init);
            // auto-configure language
            $this->LANGUAGE = $headers['Accept-Language'] ?? $this->FALLBACK;
            if (!\headers_sent() && \session_status() != PHP_SESSION_ACTIVE) {
                $jarSettings = $jar->toCookieSettings();
                \session_cache_limiter('');
                \session_set_cookie_params($jarSettings);
            }
            if (PHP_SAPI == 'cli-server' &&
                \preg_match('/^'.\preg_quote($base, '/').'$/', $this->URI))
                $this->reroute('/');
            if (\ini_get('auto_globals_jit')) {
                // Override setting
                $GLOBALS['_ENV'] = $_ENV;
                $GLOBALS['_REQUEST'] = $_REQUEST;
            }
            // Sync PHP globals with corresponding hive keys
            foreach (\explode('|', Base::GLOBALS) as $global) {
                // NB: syncing by reference is disabled by default
                $this->_hive_data[$global] = $GLOBALS['_'.$global] ?? [];
            }
            if ($check && $error = \error_get_last())
                // Error detected
                $this->error(
                    500,
                    \sprintf(self::E_Fatal, $error['message']),
                    [$error],
                );
            // Register framework autoloader
            \spl_autoload_register([$this, 'autoload']);
            // Register shutdown handler
            \register_shutdown_function([$this, 'unload'], \getcwd());
        }

        /**
         * load PSR-7 Server Request into app instance
         */
        public function request(ServerRequestInterface $request): void
        {
            $this->RESPONSE = '';
            // reuse current request instance instead of rebuilding it when used elsewhere
            $this->CONTAINER->set(ServerRequestInterface::class, $request);
            $this->SERVER = $request->getServerParams();
            $this->SERVER['SERVER_PROTOCOL'] = 'HTTP/'.$request->getProtocolVersion();
            $this->BASE = !$this->CLI && isset($this->SERVER['SCRIPT_NAME'])
                ? \rtrim(
                    $this->fixslashes(\dirname('/'.\ltrim($this->SERVER['SCRIPT_NAME'], '/'))),
                    '/',
                ) : '';
            $headers = [];
            foreach ($request->getHeaders() as $name => $value) {
                $headers[\ucwords($name, '-')] = \implode(',', $value);
            }
            $this->HEADERS = $headers;
            $this->AGENT = $this->agent($headers);
            $this->AJAX = $this->ajax($headers);
            $this->HOST = \rtrim($headers['X-Forwarded-Host'] ?? $this->SERVER['SERVER_NAME'], '.');
            $this->IP = $this->ip();
            $this->TIME = $this->SERVER['REQUEST_TIME_FLOAT'];
            $this->JAR->updateRequestTime($this->TIME);
            $this->JAR->domain = \str_contains($this->HOST, '.') &&
            !\filter_var($this->HOST, FILTER_VALIDATE_IP) ? $this->HOST : '';
            $uri = $request->getUri();
            $this->PATH = $uri->getPath();
            $scheme = isset($this->SERVER['HTTPS']) && $this->SERVER['HTTPS'] == 'on'
            || isset($headers['X-Forwarded-Proto']) && $headers['X-Forwarded-Proto'] == 'https'
                ? 'https' : 'http';
            $this->JAR->secure = $scheme == 'https';
            $port = 80;
            if (!empty($headers['X-Forwarded-Port']))
                $port = $headers['X-Forwarded-Port'];
            elseif (!empty($this->SERVER['SERVER_PORT']))
                $port = $this->SERVER['SERVER_PORT'];
            $this->PORT = $port;
            $this->QUERY = $request->getUri()->getQuery();
            $this->REALM = $scheme.'://'.$this->HOST.
                (!\in_array($port, [80, 443]) ? (':'.$port) : '').
                $uri->getPath().($this->QUERY ? '?'.$this->QUERY : '');
            $this->SCHEME = $scheme;
            $this->SEED = $this->hash($this->HOST.$this->BASE);
            $this->URI = $this->SERVER['REQUEST_URI'] = $uri->getPath();
            $this->VERB = $request->getMethod();
            $this->GET = $request->getQueryParams();
            $this->COOKIE = $request->getCookieParams();
            $this->POST = $request->getParsedBody();
            if (!empty($headers['Accept-Language']))
                $this->LANGUAGE = $headers['Accept-Language'];
            $this->BODY = $request->getBody();
        }

        /**
         * hande PSR7 request and provide a response
         * @throws \Throwable
         */
        public function handle(
            ServerRequestInterface $request,
            ?callable $runHandler = null,
        ): ?ResponseInterface {
            $this->request($request);
            // the output buffer is only needed when QUIET is false,
            // but it also aids as failsafe for headers, in case output leaks somewhere
            try {
                \ob_start();
                $psr7Response = $runHandler ? $runHandler() : $this->run();
            } catch (ResponseException $e) {
                $psr7Response = null;
                // simply continue
            } finally {
                $out = \ob_get_clean();
                if (!empty($out))
                    // Irregular output
                    $this->RESPONSE = $out;
            }
            return $psr7Response instanceof ResponseInterface
                ? $psr7Response : $this->respond();
        }

        /**
         * build PSR7 response object for current request
         * @return ResponseInterface
         */
        public function respond(): ResponseInterface
        {
            $headers = [];
            $rs = 200;
            $reason = '';
            foreach ($this->RESPONSE_HEADERS ?? [] as $value) {
                if (\preg_match('/HTTP\/\d(?:\.\d)?/', $value)) {
                    $status = \explode(' ', $value, 3);
                    $rs = (int) $status[1];
                    if (isset($status[2]))
                        $reason = $status[2];
                } else {
                    $parts = \explode(':', $value, 2);
                    if (isset($parts[1])) {
                        if (!isset($headers[$parts[0]])) {
                            $headers[$parts[0]] = [];
                        }
                        $headers[$parts[0]][] = trim($parts[1]);
                    }
                }
            }
            $responseBody = $this->RESPONSE instanceof ResponseInterface
                ? $this->RESPONSE
                : $this
                    ->make(StreamFactoryInterface::class)
                    ->createStream($this->RESPONSE);
            return $this
                ->make(ResponseFactoryInterface::class)
                ->createResponse($rs ?: 500, $reason)
                ->withHeaders($headers)
                ->withBody($responseBody);
        }

    }


    class CookieJarConfig
    {
        private bool $initialized = false;

        public function __construct(
            private float $requestTime,
            array $data,
        ) {
            foreach ($data as $key => $value)
                $this->$key = $value;
            $this->initialized = true;
            $this->updateCookieParams();
        }

        public int $expire = 0 {
            set {
                if ($value === $this->expire)
                    return;
                $time = \ceil($this->requestTime);
                $this->expire = $value;
                $this->lifetime = \max(0, $value - $time);
                $this->updateCookieParams();
            }
        }
        public int $lifetime = 0 {
            set {
                if ($value === $this->lifetime)
                    return;
                $time = \ceil($this->requestTime);
                $this->lifetime = $value;
                $this->expire = $value == 0 ? 0 :
                    (\is_int($value) ? $time + $value : \strtotime($value));
                $this->updateCookieParams();
            }
        }
        public string $path = '/' {
            set {
                $this->path = $value;
                $this->updateCookieParams();
            }
        }
        public string $domain = '' {
            set {
                $this->domain = $value;
                $this->updateCookieParams();
            }
        }
        public bool $secure = true {
            set {
                $this->secure = $value;
                $this->updateCookieParams();
            }
        }
        public bool $httponly = true {
            set {
                $this->httponly = $value;
                $this->updateCookieParams();
            }
        }
        public string $samesite = 'Lax' {
            set {
                $this->samesite = $value;
                $this->updateCookieParams();
            }
        }

        public function updateRequestTime(float $requestTime): void
        {
            $this->requestTime = $requestTime;
            $time = \ceil($this->requestTime);
            $this->expire = $this->lifetime == 0
                ? 0 : $time + $this->lifetime;
        }

        public function updateCookieParams(): void
        {
            if ($this->initialized && !\headers_sent() && \session_status() != PHP_SESSION_ACTIVE)
                \session_set_cookie_params($this->toCookieSettings());
        }

        public function toArray(): array
        {
            $out = [];
            foreach (['expire', 'lifetime', 'path', 'domain', 'secure', 'httponly', 'samesite'] as $key)
                $out[$key] = $this->$key;
            return $out;
        }

        public function toCookieSettings(): array
        {
            $jar = $this->toArray();
            unset($jar['expire']);
            return $jar;
        }
    }

    class ResponseException extends \Exception
    {
        /* throw response handler */
    }

    //! Cache engine
    class Cache
    {

        use Prefab;

        //! Cache DSN
        protected ?string $dsn = null;

        //! Prefix for cache entries
        protected ?string $prefix = null;

        /**
         * @var \Memcached|\Redis|null
         */
        protected ?object $ref = null;

        /**
         * Return timestamp and TTL of cache entry or FALSE if not found
         */
        public function exists(string $key, mixed &$val = null): array|false
        {
            $fw = Base::instance();
            if (!$this->dsn)
                return false;
            $ndx = $this->prefix.'.'.$key;
            $parts = \explode('=', $this->dsn, 2);
            $raw = match ($parts[0]) {
                'apcu' => \apcu_fetch($ndx),
                'redis', 'memcached' => $this->ref->get($ndx),
                'memcache' => \memcache_get($this->ref, $ndx),
                'folder' => $fw->read($parts[1].\str_replace(['/', '\\'], '', $ndx)),
                default => throw new \RuntimeException(
                    'Cache dsn not found: '.\var_export($this->dsn, true),
                ),
            };
            if (!empty($raw)) {
                [$val, $time, $ttl] = (array) $fw->unserialize($raw);
                if ($ttl === 0 || $time + $ttl > \microtime(true))
                    return [$time, $ttl];
                $val = null;
                $this->clear($key);
            }
            return false;
        }

        /**
         * Store value in cache
         */
        public function set(string $key, mixed $val, int $ttl = 0): bool
        {
            $fw = Base::instance();
            if (!$this->dsn)
                return true;
            $ndx = $this->prefix.'.'.$key;
            if ($cached = $this->exists($key))
                $ttl = $cached[1];
            $data = $fw->serialize([$val, \microtime(true), $ttl]);
            $parts = \explode('=', $this->dsn, 2);
            return (bool) match ($parts[0]) {
                'apcu' => \apcu_store($ndx, $data, $ttl),
                'redis' => $this->ref->set($ndx, $data, $ttl ? ['ex' => $ttl] : []),
                'memcache' => \memcache_set($this->ref, $ndx, $data, 0, $ttl),
                'memcached' => $this->ref->set($ndx, $data, $ttl),
                'folder' => $fw->write(
                    $parts[1].
                    \str_replace(['/', '\\'], '', $ndx),
                    $data,
                ),
                default => false,
            };
        }

        /**
         * cache return value of a callback, support cache tags
         * @param string $key
         * @param callable $func
         * @param int|array{0: int, 1: string} $ttl
         * @return mixed
         */
        public function remember(string $key, callable $func, int|array $ttl = 0): mixed
        {
            $ndx = $key;
            if (\is_array($ttl)) {
                $ndx.='.'.$ttl[1];
                $ttl = $ttl[0];
            }
            if ($this->exists($ndx, $val))
                return $val;
            $this->set($ndx, $val = $func(), $ttl);
            return $val;
        }

        /**
         * Retrieve value of cache entry
         */
        public function get(string $key): mixed
        {
            return $this->dsn && $this->exists($key, $data) ? $data : false;
        }

        /**
         * Delete cache entry
         */
        public function clear(string $key): bool
        {
            if (!$this->dsn)
                return false;
            $ndx = $this->prefix.'.'.$key;
            $parts = \explode('=', $this->dsn, 2);
            return match ($parts[0]) {
                'apcu' => \apcu_delete($ndx),
                'redis' => $this->ref->del($ndx),
                'memcache' => \memcache_delete($this->ref, $ndx),
                'memcached' => $this->ref->delete($ndx),
                'folder' => @\unlink($parts[1].$ndx),
                default => false,
            };
        }

        /**
         * Clear contents of cache backend
         */
        public function reset(?string $suffix = null): bool
        {
            if (!$this->dsn)
                return true;
            $regex = '/'.\preg_quote($this->prefix.'.', '/').'.*'.
                \preg_quote($suffix ?: '', '/').'/';
            $parts = \explode('=', $this->dsn, 2);
            switch ($parts[0]) {
                case 'apcu':
                    $info = \apcu_cache_info();
                    if (!empty($info['cache_list'])) {
                        $key = \array_key_exists(
                            'info',
                            $info['cache_list'][0],
                        ) ? 'info' : 'key';
                        foreach ($info['cache_list'] as $item)
                            if (\preg_match($regex, $item[$key]))
                                \apcu_delete($item[$key]);
                    }
                    return true;
                case 'redis':
                    $keys = $this->ref->keys($this->prefix.'.*'.$suffix);
                    foreach ($keys as $key)
                        $this->ref->del($key);
                    return true;
                case 'memcache':
                    foreach (\memcache_get_extended_stats($this->ref, 'slabs') as $slabs)
                        foreach (
                            \array_filter(\array_keys($slabs), 'is_numeric')
                            as $id
                        )
                            foreach (
                                \memcache_get_extended_stats($this->ref, 'cachedump', $id) as $data
                            )
                                if (\is_array($data))
                                    foreach (\array_keys($data) as $key)
                                        if (\preg_match($regex, $key))
                                            \memcache_delete($this->ref, $key);
                    return true;
                case 'memcached':
                    foreach ($this->ref->getAllKeys() ?: [] as $key)
                        if (\preg_match($regex, $key))
                            $this->ref->delete($key);
                    return true;
                case 'folder':
                    if ($glob = @\glob($parts[1].'*'))
                        foreach ($glob as $file)
                            if (\preg_match($regex, \basename($file)))
                                @\unlink($file);
                    return true;
            }
            return false;
        }

        /**
         * Load/auto-detect cache backend
         */
        public function load(bool|string $dsn, ?string $seed = null): string
        {
            $fw = Base::instance();
            if ($dsn = \trim($dsn)) {
                if (\preg_match('/^redis=(.+)/', $dsn, $parts) &&
                    \extension_loaded('redis')) {
                    [$host, $port, $db, $password] =
                        \explode(':', $parts[1]) + [1 => 6379, 2 => null, 3 => null];
                    $this->ref = new \Redis();
                    if (!$this->ref->connect($host, $port, 2))
                        $this->ref = null;
                    if (!empty($password))
                        $this->ref->auth($password);
                    if (isset($db))
                        $this->ref->select($db);
                } elseif (\preg_match('/^memcache=(.+)/', $dsn, $parts) &&
                    \extension_loaded('memcache'))
                    foreach ($fw->split($parts[1]) as $server) {
                        [$host, $port] = \explode(':', $server) + [1 => 11211];
                        if (empty($this->ref))
                            $this->ref = @\memcache_connect($host, $port) ?: null;
                        else
                            \memcache_add_server($this->ref, $host, $port);
                    }
                elseif (\preg_match('/^memcached=(.+)/', $dsn, $parts) &&
                    \extension_loaded('memcached'))
                    foreach ($fw->split($parts[1]) as $server) {
                        [$host, $port] = \explode(':', $server) + [1 => 11211];
                        if (empty($this->ref))
                            $this->ref = new \Memcached();
                        $this->ref->addServer($host, $port);
                    }
                // fallback to engines without further config
                if (empty($this->ref) && !\preg_match('/^folder\h*=/', $dsn))
                    $dsn = \extension_loaded('apcu') ?
                        'apcu' :
                        // Use filesystem as fallback
                        ('folder='.$fw->TEMP.'cache/');
                if (\preg_match('/^folder\h*=\h*(.+)/', $dsn, $parts) &&
                    !\is_dir($parts[1]))
                    \mkdir($parts[1], Base::MODE, true);
            }
            $this->prefix = $seed ?: $fw->SEED;
            return $this->dsn = $dsn;
        }

        /**
         * returns active cache engine in use
         */
        public function engine(): false|string
        {
            return $this->dsn ? \explode('=', $this->dsn)[0] : false;
        }

        /**
         * Class constructor
         */
        public function __construct(bool|string $dsn = false)
        {
            if ($dsn)
                $this->load($dsn);
        }

    }

    //! View handler
    class View
    {

        use Prefab;

        //! Temporary hive
        private ?array $temp;

        //! Template file
        protected string $file;

        //! Post-rendering handler
        protected array $trigger = [];

        //! Nesting level
        protected int $level = 0;

        protected Base $fw;

        public function __construct()
        {
            $this->fw = Base::instance();
        }

        /**
         * Encode characters to equivalent HTML entities
         */
        public function esc(mixed $arg): string|Hive|array
        {
            return $this->fw->recursive(
                $arg,
                fn($val) => is_string($val) ? $this->fw->encode($val) : $val,
            );
        }

        /**
         * Decode HTML entities to equivalent characters
         */
        public function raw(mixed $arg): string|array
        {
            return $this->fw->recursive(
                $arg,
                fn($val) => is_string($val) ? $this->fw->decode($val) : $val,
            );
        }

        /**
         * Create sandbox for template execution
         */
        protected function sandbox(Hive|array|null $hive = null, ?string $mime = null): string
        {
            $fw = $this->fw;
            $implicit = false;
            if (is_null($hive)) {
                $implicit = true;
                $hive = $fw->hive();
            }
            if ($this->level < 1 || $implicit) {
                if (!$fw->CLI && $mime && !headers_sent() &&
                    !preg_grep('/^Content-Type:/', headers_list()))
                    $fw->header(
                        'Content-Type: '.$mime.'; '.
                        'charset='.$fw->ENCODING,
                    );
                if ($fw->ESCAPE && (!$mime ||
                        preg_match('/^(text\/html|(application|text)\/(.+\+)?xml)$/i', $mime)))
                    $hive = $this->esc($hive);
                if (isset($hive['ALIASES']))
                    $hive['ALIASES'] = $fw->build($hive['ALIASES']);
            }
            $this->temp =
                is_object($hive) && method_exists($hive, 'toArray') ? $hive->toArray() : $hive;
            unset($fw, $hive, $implicit, $mime);
            extract($this->temp);
            $this->temp = null;
            ++$this->level;
            ob_start();
            require($this->file);
            --$this->level;
            return ob_get_clean();
        }

        /**
         * Render template
         */
        public function render(
            string $file,
            ?string $mime = 'text/html',
            ?array $hive = null,
            int $ttl = 0,
        ): string {
            $cache = Cache::instance();
            foreach ($this->fw->split($this->fw->UI) as $dir) {
                if ($cache->exists($hash = $this->fw->hash($dir.$file), $data))
                    return $data;
                if (is_file($this->file = $this->fw->fixslashes($dir.$file))) {
                    if (isset($this->fw->COOKIE[session_name()]) &&
                        !headers_sent() && session_status() != PHP_SESSION_ACTIVE)
                        $this->fw->session_start();
                    $this->fw->sync('SESSION');
                    $data = $this->sandbox($hive, $mime);
                    foreach ($this->trigger['afterRender'] ?? [] as $func)
                        $data = $this->fw->call($func, [$data, $dir.$file]);
                    if ($ttl)
                        $cache->set($hash, $data, $ttl);
                    return $data;
                }
            }
            throw new \Exception(sprintf(Base::E_Open, $file));
        }

        /**
         * post rendering handler
         */
        public function afterRender(callable|string $func): void
        {
            $this->trigger['afterRender'][] = $func;
        }

    }

    //! Lightweight template engine
    class Preview extends View
    {

        //! token filter
        protected array $filter = [
            'c' => '$this->c',
            'esc' => '$this->esc',
            'raw' => '$this->raw',
            'export' => Base::class.'::instance()->export',
            'alias' => Base::class.'::instance()->alias',
            'format' => Base::class.'::instance()->format',
        ];

        //! newline interpolation
        protected bool $interpolation = true;

        /**
         * Enable/disable markup parsing interpolation
         * mainly used for adding appropriate newlines
         */
        public function interpolation(bool $bool): void
        {
            $this->interpolation = $bool;
        }

        /**
         * Return C-locale equivalent of number
         */
        public function c(mixed $val): string
        {
            $locale = setlocale(LC_NUMERIC, 0);
            setlocale(LC_NUMERIC, 'C');
            $out = (string) (float) $val;
            $locale = setlocale(LC_NUMERIC, $locale);
            return $out;
        }

        /**
         * Convert token to variable
         */
        public function token(string $str): string
        {
            $str = trim(preg_replace('/\{\{(.+?)}}/s', '\1', $this->fw->compile($str)));
            if (preg_match(
                '/^(.+)(?<!\|)\|((?:\h*\w+\h*[,;]?)+)$/s',
                $str,
                $parts,
            )) {
                $str = trim($parts[1]);
                foreach ($this->fw->split(trim($parts[2], "\xC2\xA0")) as $func)
                    $str = ((empty($this->filter[$cmd = $func]) &&
                            function_exists($cmd)) ||
                        is_string($cmd = $this->filter($func)))
                        ? $cmd.'('.$str.')'
                        : Base::class.'::instance()->'.
                        'call($this->filter(\''.$func.'\'),['.$str.'])';
            }
            return $str;
        }

        /**
         * Register or get (one specific or all) token filters
         * @return array|Closure|string
         */
        public function filter(?string $key = null, callable|string|null $func = null): mixed
        {
            if (!$key)
                return array_keys($this->filter);
            $key = strtolower($key);
            if (!$func)
                return $this->filter[$key];
            $this->filter[$key] = $func;
            return null;
        }

        /**
         * Assemble markup
         */
        protected function build($node): string
        {
            return preg_replace_callback(
                '/\{~(.+?)~}|\{\*(.+?)\*}|\{-(.+?)-}|'.
                '\{\{(.+?)}}((\r?\n)*)/s',
                function ($expr) {
                    if ($expr[1])
                        $str = '<?php '.$this->token($expr[1]).' ?>';
                    elseif ($expr[2])
                        return '';
                    elseif ($expr[3])
                        $str = $expr[3];
                    else {
                        $str = '<?= ('.trim($this->token($expr[4])).')'.
                            ($this->interpolation
                                ? (!empty($expr[6]) ? '."'.$expr[6].'"' : '')
                                : ''
                            ).' ?>';
                        if (isset($expr[5]))
                            $str .= $expr[5];
                    }
                    return $str;
                },
                $node,
            );
        }

        /**
         * Render template string
         */
        public function resolve(
            string|array $node,
            ?array $hive = null,
            int $ttl = 0,
            bool $persist = false,
            ?bool $escape = null,
        ): string {
            $hash = null;
            $fw = $this->fw;
            $cache = Cache::instance();
            if ($escape !== null) {
                $esc = $fw->ESCAPE;
                $fw->ESCAPE = $escape;
            }
            if ($ttl || $persist)
                $hash = $fw->hash($fw->serialize($node));
            if ($ttl && $cache->exists($hash, $data))
                return $data;
            if ($persist) {
                if (!is_dir($tmp = $fw->TEMP))
                    mkdir($tmp, Base::MODE, true);
                if (!is_file($this->file = ($tmp.$fw->SEED.'.'.$hash.'.php')))
                    $fw->write($this->file, $this->build($node));
                if (isset($this->fw->COOKIE[session_name()]) &&
                    !headers_sent() && session_status() != PHP_SESSION_ACTIVE)
                    $this->fw->session_start();
                $fw->sync('SESSION');
                $data = $this->sandbox($hive);
            } else {
                if (!$hive)
                    $hive = $fw->hive();
                if ($fw->ESCAPE)
                    $hive = $this->esc($hive);
                extract($hive);
                unset($hive);
                ob_start();
                eval(' ?>'.$this->build($node).'<?php ');
                $data = ob_get_clean();
            }
            if ($ttl)
                $cache->set($hash, $data, $ttl);
            if ($escape !== null)
                $fw->ESCAPE = $esc;
            return $data;
        }

        /**
         * Parse template string
         */
        public function parse(string $text): array|string
        {
            // Remove PHP code and comments
            return preg_replace(
                '/\h*<\?(?!xml)(?:php|\s*=)?.+?\?>\h*|'.
                '\{\*.+?\*}/is',
                '',
                $text,
            );
        }

        /**
         * Render template
         */
        public function render(
            string $file,
            ?string $mime = 'text/html',
            Hive|array|null $hive = null,
            int $ttl = 0,
        ): string {
            $fw = $this->fw;
            $cache = Cache::instance();
            if (!is_dir($tmp = $fw->TEMP))
                mkdir($tmp, Base::MODE, true);
            foreach ($fw->split($fw->UI) as $dir) {
                if ($cache->exists($hash = $fw->hash($dir.$file), $data))
                    return $data;
                if (is_file($view = $fw->fixslashes($dir.$file))) {
                    if (!is_file($this->file = ($tmp.$fw->SEED.'.'.$fw->hash($view).'.php'))
                        || filemtime($this->file) < filemtime($view)
                    ) {
                        $contents = $fw->read($view);
                        foreach ($this->trigger['beforeRender'] ?? [] as $func)
                            $contents = $fw->call($func, [$contents, $view]);
                        $text = $this->parse($contents);
                        $fw->write($this->file, $this->build($text));
                    }
                    if (isset($this->fw->COOKIE[session_name()]) &&
                        !headers_sent() && session_status() != PHP_SESSION_ACTIVE)
                        $this->fw->session_start();
                    $fw->sync('SESSION');
                    $data = $this->sandbox($hive, $mime);
                    foreach ($this->trigger['afterRender'] ?? [] as $func)
                        $data = $fw->call($func, [$data, $view]);
                    if ($ttl)
                        $cache->set($hash, $data, $ttl);
                    return $data;
                }
            }
            throw new \Exception(sprintf(Base::E_Open, $file));
        }

        /**
         * post rendering handler
         */
        public function beforeRender(callable|string $func): void
        {
            $this->trigger['beforeRender'][] = $func;
        }

    }

    //! ISO language/country codes
    class ISO
    {

        use Prefab;

        //region ISO 3166-1 country codes
        const
            CC_af = 'Afghanistan',
            CC_ax = 'Åland Islands',
            CC_al = 'Albania',
            CC_dz = 'Algeria',
            CC_as = 'American Samoa',
            CC_ad = 'Andorra',
            CC_ao = 'Angola',
            CC_ai = 'Anguilla',
            CC_aq = 'Antarctica',
            CC_ag = 'Antigua and Barbuda',
            CC_ar = 'Argentina',
            CC_am = 'Armenia',
            CC_aw = 'Aruba',
            CC_au = 'Australia',
            CC_at = 'Austria',
            CC_az = 'Azerbaijan',
            CC_bs = 'Bahamas',
            CC_bh = 'Bahrain',
            CC_bd = 'Bangladesh',
            CC_bb = 'Barbados',
            CC_by = 'Belarus',
            CC_be = 'Belgium',
            CC_bz = 'Belize',
            CC_bj = 'Benin',
            CC_bm = 'Bermuda',
            CC_bt = 'Bhutan',
            CC_bo = 'Bolivia',
            CC_bq = 'Bonaire, Sint Eustatius and Saba',
            CC_ba = 'Bosnia and Herzegovina',
            CC_bw = 'Botswana',
            CC_bv = 'Bouvet Island',
            CC_br = 'Brazil',
            CC_io = 'British Indian Ocean Territory',
            CC_bn = 'Brunei Darussalam',
            CC_bg = 'Bulgaria',
            CC_bf = 'Burkina Faso',
            CC_bi = 'Burundi',
            CC_kh = 'Cambodia',
            CC_cm = 'Cameroon',
            CC_ca = 'Canada',
            CC_cv = 'Cape Verde',
            CC_ky = 'Cayman Islands',
            CC_cf = 'Central African Republic',
            CC_td = 'Chad',
            CC_cl = 'Chile',
            CC_cn = 'China',
            CC_cx = 'Christmas Island',
            CC_cc = 'Cocos (Keeling) Islands',
            CC_co = 'Colombia',
            CC_km = 'Comoros',
            CC_cg = 'Congo',
            CC_cd = 'Congo, The Democratic Republic of',
            CC_ck = 'Cook Islands',
            CC_cr = 'Costa Rica',
            CC_ci = 'Côte d\'ivoire',
            CC_hr = 'Croatia',
            CC_cu = 'Cuba',
            CC_cw = 'Curaçao',
            CC_cy = 'Cyprus',
            CC_cz = 'Czech Republic',
            CC_dk = 'Denmark',
            CC_dj = 'Djibouti',
            CC_dm = 'Dominica',
            CC_do = 'Dominican Republic',
            CC_ec = 'Ecuador',
            CC_eg = 'Egypt',
            CC_sv = 'El Salvador',
            CC_gq = 'Equatorial Guinea',
            CC_er = 'Eritrea',
            CC_ee = 'Estonia',
            CC_et = 'Ethiopia',
            CC_fk = 'Falkland Islands (Malvinas)',
            CC_fo = 'Faroe Islands',
            CC_fj = 'Fiji',
            CC_fi = 'Finland',
            CC_fr = 'France',
            CC_gf = 'French Guiana',
            CC_pf = 'French Polynesia',
            CC_tf = 'French Southern Territories',
            CC_ga = 'Gabon',
            CC_gm = 'Gambia',
            CC_ge = 'Georgia',
            CC_de = 'Germany',
            CC_gh = 'Ghana',
            CC_gi = 'Gibraltar',
            CC_gr = 'Greece',
            CC_gl = 'Greenland',
            CC_gd = 'Grenada',
            CC_gp = 'Guadeloupe',
            CC_gu = 'Guam',
            CC_gt = 'Guatemala',
            CC_gg = 'Guernsey',
            CC_gn = 'Guinea',
            CC_gw = 'Guinea-Bissau',
            CC_gy = 'Guyana',
            CC_ht = 'Haiti',
            CC_hm = 'Heard Island and McDonald Islands',
            CC_va = 'Holy See (Vatican City State)',
            CC_hn = 'Honduras',
            CC_hk = 'Hong Kong',
            CC_hu = 'Hungary',
            CC_is = 'Iceland',
            CC_in = 'India',
            CC_id = 'Indonesia',
            CC_ir = 'Iran, Islamic Republic of',
            CC_iq = 'Iraq',
            CC_ie = 'Ireland',
            CC_im = 'Isle of Man',
            CC_il = 'Israel',
            CC_it = 'Italy',
            CC_jm = 'Jamaica',
            CC_jp = 'Japan',
            CC_je = 'Jersey',
            CC_jo = 'Jordan',
            CC_kz = 'Kazakhstan',
            CC_ke = 'Kenya',
            CC_ki = 'Kiribati',
            CC_kp = 'Korea, Democratic People\'s Republic of',
            CC_kr = 'Korea, Republic of',
            CC_kw = 'Kuwait',
            CC_kg = 'Kyrgyzstan',
            CC_la = 'Lao People\'s Democratic Republic',
            CC_lv = 'Latvia',
            CC_lb = 'Lebanon',
            CC_ls = 'Lesotho',
            CC_lr = 'Liberia',
            CC_ly = 'Libya',
            CC_li = 'Liechtenstein',
            CC_lt = 'Lithuania',
            CC_lu = 'Luxembourg',
            CC_mo = 'Macao',
            CC_mk = 'Macedonia, The Former Yugoslav Republic of',
            CC_mg = 'Madagascar',
            CC_mw = 'Malawi',
            CC_my = 'Malaysia',
            CC_mv = 'Maldives',
            CC_ml = 'Mali',
            CC_mt = 'Malta',
            CC_mh = 'Marshall Islands',
            CC_mq = 'Martinique',
            CC_mr = 'Mauritania',
            CC_mu = 'Mauritius',
            CC_yt = 'Mayotte',
            CC_mx = 'Mexico',
            CC_fm = 'Micronesia, Federated States of',
            CC_md = 'Moldova, Republic of',
            CC_mc = 'Monaco',
            CC_mn = 'Mongolia',
            CC_me = 'Montenegro',
            CC_ms = 'Montserrat',
            CC_ma = 'Morocco',
            CC_mz = 'Mozambique',
            CC_mm = 'Myanmar',
            CC_na = 'Namibia',
            CC_nr = 'Nauru',
            CC_np = 'Nepal',
            CC_nl = 'Netherlands',
            CC_nc = 'New Caledonia',
            CC_nz = 'New Zealand',
            CC_ni = 'Nicaragua',
            CC_ne = 'Niger',
            CC_ng = 'Nigeria',
            CC_nu = 'Niue',
            CC_nf = 'Norfolk Island',
            CC_mp = 'Northern Mariana Islands',
            CC_no = 'Norway',
            CC_om = 'Oman',
            CC_pk = 'Pakistan',
            CC_pw = 'Palau',
            CC_ps = 'Palestinian Territory, Occupied',
            CC_pa = 'Panama',
            CC_pg = 'Papua New Guinea',
            CC_py = 'Paraguay',
            CC_pe = 'Peru',
            CC_ph = 'Philippines',
            CC_pn = 'Pitcairn',
            CC_pl = 'Poland',
            CC_pt = 'Portugal',
            CC_pr = 'Puerto Rico',
            CC_qa = 'Qatar',
            CC_re = 'Réunion',
            CC_ro = 'Romania',
            CC_ru = 'Russian Federation',
            CC_rw = 'Rwanda',
            CC_bl = 'Saint Barthélemy',
            CC_sh = 'Saint Helena, Ascension and Tristan da Cunha',
            CC_kn = 'Saint Kitts and Nevis',
            CC_lc = 'Saint Lucia',
            CC_mf = 'Saint Martin (French Part)',
            CC_pm = 'Saint Pierre and Miquelon',
            CC_vc = 'Saint Vincent and The Grenadines',
            CC_ws = 'Samoa',
            CC_sm = 'San Marino',
            CC_st = 'Sao Tome and Principe',
            CC_sa = 'Saudi Arabia',
            CC_sn = 'Senegal',
            CC_rs = 'Serbia',
            CC_sc = 'Seychelles',
            CC_sl = 'Sierra Leone',
            CC_sg = 'Singapore',
            CC_sk = 'Slovakia',
            CC_sx = 'Sint Maarten (Dutch Part)',
            CC_si = 'Slovenia',
            CC_sb = 'Solomon Islands',
            CC_so = 'Somalia',
            CC_za = 'South Africa',
            CC_gs = 'South Georgia and The South Sandwich Islands',
            CC_ss = 'South Sudan',
            CC_es = 'Spain',
            CC_lk = 'Sri Lanka',
            CC_sd = 'Sudan',
            CC_sr = 'Suriname',
            CC_sj = 'Svalbard and Jan Mayen',
            CC_sz = 'Swaziland',
            CC_se = 'Sweden',
            CC_ch = 'Switzerland',
            CC_sy = 'Syrian Arab Republic',
            CC_tw = 'Taiwan, Province of China',
            CC_tj = 'Tajikistan',
            CC_tz = 'Tanzania, United Republic of',
            CC_th = 'Thailand',
            CC_tl = 'Timor-Leste',
            CC_tg = 'Togo',
            CC_tk = 'Tokelau',
            CC_to = 'Tonga',
            CC_tt = 'Trinidad and Tobago',
            CC_tn = 'Tunisia',
            CC_tr = 'Turkey',
            CC_tm = 'Turkmenistan',
            CC_tc = 'Turks and Caicos Islands',
            CC_tv = 'Tuvalu',
            CC_ug = 'Uganda',
            CC_ua = 'Ukraine',
            CC_ae = 'United Arab Emirates',
            CC_gb = 'United Kingdom',
            CC_us = 'United States',
            CC_um = 'United States Minor Outlying Islands',
            CC_uy = 'Uruguay',
            CC_uz = 'Uzbekistan',
            CC_vu = 'Vanuatu',
            CC_ve = 'Venezuela',
            CC_vn = 'Viet Nam',
            CC_vg = 'Virgin Islands, British',
            CC_vi = 'Virgin Islands, U.S.',
            CC_wf = 'Wallis and Futuna',
            CC_eh = 'Western Sahara',
            CC_ye = 'Yemen',
            CC_zm = 'Zambia',
            CC_zw = 'Zimbabwe';
        //endregion

        //region ISO 639-1 language codes (Windows-compatibility subset)
        const
            LC_af = 'Afrikaans',
            LC_am = 'Amharic',
            LC_ar = 'Arabic',
            LC_as = 'Assamese',
            LC_ba = 'Bashkir',
            LC_be = 'Belarusian',
            LC_bg = 'Bulgarian',
            LC_bn = 'Bengali',
            LC_bo = 'Tibetan',
            LC_br = 'Breton',
            LC_ca = 'Catalan',
            LC_co = 'Corsican',
            LC_cs = 'Czech',
            LC_cy = 'Welsh',
            LC_da = 'Danish',
            LC_de = 'German',
            LC_dv = 'Divehi',
            LC_el = 'Greek',
            LC_en = 'English',
            LC_es = 'Spanish',
            LC_et = 'Estonian',
            LC_eu = 'Basque',
            LC_fa = 'Persian',
            LC_fi = 'Finnish',
            LC_fo = 'Faroese',
            LC_fr = 'French',
            LC_gd = 'Scottish Gaelic',
            LC_gl = 'Galician',
            LC_gu = 'Gujarati',
            LC_he = 'Hebrew',
            LC_hi = 'Hindi',
            LC_hr = 'Croatian',
            LC_hu = 'Hungarian',
            LC_hy = 'Armenian',
            LC_id = 'Indonesian',
            LC_ig = 'Igbo',
            LC_is = 'Icelandic',
            LC_it = 'Italian',
            LC_ja = 'Japanese',
            LC_ka = 'Georgian',
            LC_kk = 'Kazakh',
            LC_km = 'Khmer',
            LC_kn = 'Kannada',
            LC_ko = 'Korean',
            LC_lb = 'Luxembourgish',
            LC_lo = 'Lao',
            LC_lt = 'Lithuanian',
            LC_lv = 'Latvian',
            LC_mi = 'Maori',
            LC_ml = 'Malayalam',
            LC_mr = 'Marathi',
            LC_ms = 'Malay',
            LC_mt = 'Maltese',
            LC_ne = 'Nepali',
            LC_nl = 'Dutch',
            LC_no = 'Norwegian',
            LC_oc = 'Occitan',
            LC_or = 'Oriya',
            LC_pl = 'Polish',
            LC_ps = 'Pashto',
            LC_pt = 'Portuguese',
            LC_qu = 'Quechua',
            LC_ro = 'Romanian',
            LC_ru = 'Russian',
            LC_rw = 'Kinyarwanda',
            LC_sa = 'Sanskrit',
            LC_si = 'Sinhala',
            LC_sk = 'Slovak',
            LC_sl = 'Slovenian',
            LC_sq = 'Albanian',
            LC_sv = 'Swedish',
            LC_ta = 'Tamil',
            LC_te = 'Telugu',
            LC_th = 'Thai',
            LC_tk = 'Turkmen',
            LC_tr = 'Turkish',
            LC_tt = 'Tatar',
            LC_uk = 'Ukrainian',
            LC_ur = 'Urdu',
            LC_vi = 'Vietnamese',
            LC_wo = 'Wolof',
            LC_yo = 'Yoruba',
            LC_zh = 'Chinese';
        //endregion

        /**
         * Return list of languages indexed by ISO 639-1 language code
         */
        public function languages(): array
        {
            return Base::instance()->constants($this, 'LC_');
        }

        /**
         * Return list of countries indexed by ISO 3166-1 country code
         */
        public function countries(): array
        {
            return Base::instance()->constants($this, 'CC_');
        }

    }

    /**
     * Container for singular object instances
     */
    final class Registry
    {

        //! Object catalog
        private static array $table = [];

        /**
         * Return TRUE if object exists in catalog
         */
        public static function exists(string $key): bool
        {
            return isset(self::$table[$key]);
        }

        /**
         * Add object to catalog
         */
        public static function set(string $key, mixed $obj): mixed
        {
            return self::$table[$key] = $obj;
        }

        /**
         * Retrieve object from catalog
         */
        public static function get(string $key): mixed
        {
            return self::$table[$key];
        }

        /**
         * Delete object from catalog
         */
        public static function clear(string $key): void
        {
            self::$table[$key] = null;
            unset(self::$table[$key]);
        }

        /**
         * Deletes all existing entries from catalog
         */
        public static function reset(): void
        {
            foreach (array_keys(self::$table) as $key)
                self::clear($key);
        }

        //! Prohibit cloning
        private function __clone() {}

        //! Prohibit instantiation
        private function __construct() {}

    }
}

namespace F3\Http {

    use F3\Base;
    use F3\Cache;
    use F3\ResponseException;

    /**
     * HTTP request methods
     */
    enum Verb
    {

        case GET;
        case HEAD;
        case POST;
        case PUT;
        case DELETE;
        case CONNECT;
        case OPTIONS;
        case PATCH;

        public static function names(): array
        {
            return \array_map(fn($e) => $e->name, self::cases());
        }
    }

    /**
     * HTTP status codes (RFC 2616)
     */
    enum Status: string
    {
        case HTTP_100 = 'Continue';
        case HTTP_101 = 'Switching Protocols';
        case HTTP_103 = 'Early Hints';
        case HTTP_200 = 'OK';
        case HTTP_201 = 'Created';
        case HTTP_202 = 'Accepted';
        case HTTP_203 = 'Non-Authorative Information';
        case HTTP_204 = 'No Content';
        case HTTP_205 = 'Reset Content';
        case HTTP_206 = 'Partial Content';
        case HTTP_300 = 'Multiple Choices';
        case HTTP_301 = 'Moved Permanently';
        case HTTP_302 = 'Found';
        case HTTP_303 = 'See Other';
        case HTTP_304 = 'Not Modified';
        case HTTP_305 = 'Use Proxy';
        case HTTP_307 = 'Temporary Redirect';
        case HTTP_308 = 'Permanent Redirect';
        case HTTP_400 = 'Bad Request';
        case HTTP_401 = 'Unauthorized';
        case HTTP_402 = 'Payment Required';
        case HTTP_403 = 'Forbidden';
        case HTTP_404 = 'Not Found';
        case HTTP_405 = 'Method Not Allowed';
        case HTTP_406 = 'Not Acceptable';
        case HTTP_407 = 'Proxy Authentication Required';
        case HTTP_408 = 'Request Timeout';
        case HTTP_409 = 'Conflict';
        case HTTP_410 = 'Gone';
        case HTTP_411 = 'Length Required';
        case HTTP_412 = 'Precondition Failed';
        case HTTP_413 = 'Request Entity Too Large';
        case HTTP_414 = 'Request-URI Too Long';
        case HTTP_415 = 'Unsupported Media Type';
        case HTTP_416 = 'Requested Range Not Satisfiable';
        case HTTP_417 = 'Expectation Failed';
        case HTTP_421 = 'Misdirected Request';
        case HTTP_422 = 'Unprocessable Entity';
        case HTTP_423 = 'Locked';
        case HTTP_429 = 'Too Many Requests';
        case HTTP_451 = 'Unavailable For Legal Reasons';
        case HTTP_500 = 'Internal Server Error';
        case HTTP_501 = 'Not Implemented';
        case HTTP_502 = 'Bad Gateway';
        case HTTP_503 = 'Service Unavailable';
        case HTTP_504 = 'Gateway Timeout';
        case HTTP_505 = 'HTTP Version Not Supported';
        case HTTP_507 = 'Insufficient Storage';
        case HTTP_511 = 'Network Authentication Required';
    }

    ;


    trait Router
    {
        /** Request types */
        const
            REQ_SYNC = 1,
            REQ_AJAX = 2,
            REQ_CLI = 4;

        /**
         * Bind handler to route pattern
         */
        public function route(
            string|array $pattern,
            callable|array|string $handler,
            int $ttl = 0,
            int $kbps = 0,
        ): void {
            $types = ['sync', 'ajax', 'cli'];
            $alias = null;
            if (\is_array($pattern)) {
                foreach ($pattern as $item)
                    $this->route($item, $handler, $ttl, $kbps);
                return;
            }
            \preg_match(
                '/([|\w]+)\h+(?:@?(.+?)\h*:\h*)?(@(\w+)|\H+)'.
                '(?:\h+\[('.\implode('|', $types).')])?/u',
                $pattern,
                $parts,
            );
            if (isset($parts[2]) && $parts[2]) {
                if (!\preg_match('/^\w+$/', $parts[2]))
                    throw new \Exception(\sprintf(self::E_Alias, $parts[2]));
                $this->ALIASES[$alias = $parts[2]] = $parts[3];
            } elseif (!empty($parts[4])) {
                if (empty($this->ALIASES[$parts[4]]))
                    throw new \Exception(\sprintf(self::E_Named, $parts[4]));
                $parts[3] = $this->ALIASES[$alias = $parts[4]];
            }
            if (empty($parts[3]))
                throw new \Exception(\sprintf(self::E_Pattern, $pattern));
            $type = empty($parts[5]) ? 0 : \constant('self::REQ_'.\strtoupper($parts[5]));
            foreach ($this->split($parts[1]) as $verb) {
                if (!\constant(Verb::class.'::'.$verb))
                    $this->error(501, $verb.' '.$this->URI);
                $this->ROUTES[$parts[3]][$type][\strtoupper($verb)] =
                    [is_string($handler) ? trim($handler) : $handler, $ttl, $kbps, $alias];
            }
        }

        /**
         * Provide ReST interface by mapping HTTP verb to class method
         */
        public function map(
            string|array $url,
            string|object $class,
            int $ttl = 0,
            int $kbps = 0,
        ): void {
            if (\is_array($url)) {
                foreach ($url as $item)
                    $this->map($item, $class, $ttl, $kbps);
                return;
            }
            foreach (Verb::names() as $method)
                $this->route(
                    $method.' '.$url,
                    \is_string($class)
                        ? $class.'->'.$this->PREMAP.\strtolower($method)
                        : [$class, $this->PREMAP.\strtolower($method)],
                    $ttl,
                    $kbps,
                );
        }

        /**
         * Redirect a route to another URL
         */
        public function redirect(string|array $pattern, string $url, bool $permanent = true): void
        {
            if (is_array($pattern)) {
                foreach ($pattern as $item)
                    $this->redirect($item, $url, $permanent);
                return;
            }
            $this->route(
                $pattern,
                fn(Base $fw)
                    => $fw->reroute($url, $permanent),
            );
        }

        /**
         * Reroute to specified URI
         */
        public function reroute(
            array|string|null $url = null,
            bool $permanent = false,
            bool $exit = true,
        ): void {
            if (!$url)
                $url = $this->REALM;
            if (\is_array($url))
                $url = \call_user_func_array([$this, 'alias'], $url);
            elseif (\preg_match(
                    '/^@([^\/()?#]+)(?:\((.+?)\))*(\?[^#]+)*(#.+)*/',
                    $url,
                    $parts,
                ) && isset($this->ALIASES[$parts[1]]))
                $url = $this->build(
                        $this->ALIASES[$parts[1]],
                        isset($parts[2]) ? $this->parse($parts[2]) : [],
                    ).
                    ($parts[3] ?? '').($parts[4] ?? '');
            else
                $url = $this->build($url);
            if (($handler = $this->ONREROUTE) &&
                $this->call($handler, [$url, $permanent, $exit]) !== false)
                return;
            if ($url[0] != '/' && !\preg_match('/^\w+:\/\//i', $url))
                $url = '/'.$url;
            if ($url[0] == '/' && (empty($url[1]) || $url[1] != '/')) {
                $port = $this->PORT;
                $port = \in_array($port, [80, 443]) ? '' : (':'.$port);
                $url = $this->SCHEME.'://'.$this->HOST.$port.$this->BASE.$url;
            }
            if ($this->CLI && !$this->NONBLOCKING)
                $this->mock('GET '.$url.' [cli]');
            else {
                $this->header('Location: '.$url);
                $this->status($permanent ? 301 : 302);
                if ($exit) {
                    throw new \F3\ResponseException();
                }
            }
        }

        /**
         * Applies the specified URL mask and returns parameterized matches
         */
        public function mask(string $pattern, ?string $url = null): array
        {
            if (!$url)
                $url = $this->rel($this->URI);
            $case = $this->CASELESS ? 'i' : '';
            $wild = \preg_quote($pattern, '/');
            $i = 0;
            while (\is_int($pos = \strpos($wild, '\*'))) {
                $wild = \substr_replace($wild, '(?P<_'.$i.'>[^\?]*)', $pos, 2);
                ++$i;
            }
            \preg_match(
                '/^'.
                \preg_replace(
                    '/((\\\{)?@(\w+\b)(?(2)\\\}))/',
                    '(?P<\3>[^\/\?]+)',
                    $wild,
                ).'\/?$/'.$case.'um',
                $url,
                $args,
            );
            foreach (\array_keys($args) as $key) {
                if (\preg_match('/^_\d+$/', $key)) {
                    if (empty($args['*']))
                        $args['*'] = $args[$key];
                    else {
                        if (\is_string($args['*']))
                            $args['*'] = [$args['*']];
                        $args['*'][] = $args[$key];
                    }
                    unset($args[$key]);
                } elseif (\is_numeric($key) && $key)
                    unset($args[$key]);
            }
            return $args;
        }

        /**
         * Execute route handler with hooks (supports 'class->method' format)
         */
        public function callRoute(
            callable|string|array $func,
            array $args = [],
            array $hooks = [],
        ): mixed {
            // Grab the real handler behind the string representation
            if (!\is_callable($func) && !\is_callable($func = $this->grab($func, $args))) {
                // No route handler found
                $allowed = [];
                if (\is_array($func))
                    $allowed = \array_intersect(
                        \array_map('strtoupper', \get_class_methods($func[0])),
                        Verb::names(),
                    );
                $this->header('Allow: '.\implode(',', $allowed));
                $this->error(405);
                return false;
            }
            $obj = \is_array($func);
            // Execute pre-route hook if any
            if ($obj & \in_array($hook = 'beforeRoute', $hooks) &&
                \method_exists($func[0], $hook) &&
                $this->call([$func[0], $hook], $args) === false)
                return false;
            // Execute route handler
            if (($out = $this->call($func, $args)) === false)
                return false;
            // Execute post-route hook if any
            if ($obj & \in_array($hook = 'afterRoute', $hooks) &&
                \method_exists($func[0], $hook) &&
                $this->call([$func[0], $hook], $args) === false)
                return false;
            return $out;
        }

        /**
         * Match routes against incoming URI
         */
        public function run(): mixed
        {
            if ($this->blacklisted($this->IP)) {
                // Spammer detected
                $this->error(403, throw: false);
                return $this->RESPONSE;
            }
            if (!$this->ROUTES)
                // No routes defined
                throw new \Exception(self::E_Routes);
            // Match specific routes first
            $paths = [];
            foreach ($keys = \array_keys($this->ROUTES) as $key) {
                $path = \preg_replace('/@\w+/', '*@', $key);
                if (!\str_ends_with($path, '*'))
                    $path .= '+';
                $paths[] = $path;
            }
            $vals = \array_values($this->ROUTES);
            \array_multisort($paths, SORT_DESC, $keys, $vals);
            $this->ROUTES = \array_combine($keys, $vals);
            // Convert to BASE-relative URL
            $req = \urldecode($this->PATH);
            $preflight = false;
            if ($cors = (isset($this->HEADERS['Origin']) &&
                $this->CORS['origin'])) {
                $cors = $this->CORS;
                $this->header('Access-Control-Allow-Origin: '.$cors['origin']);
                $this->header(
                    'Access-Control-Allow-Credentials: '.
                    $this->export($cors['credentials']),
                );
                $preflight =
                    isset($this->HEADERS['Access-Control-Request-Method']);
            }
            $allowed = [];
            foreach ($this->ROUTES as $pattern => $routes) {
                if (!$args = $this->mask($pattern, $req))
                    continue;
                \ksort($args);
                $route = null;
                $ptr = $this->CLI ? self::REQ_CLI : $this->AJAX + 1;
                if (isset($routes[$ptr][$this->VERB]) ||
                    ($preflight && isset($routes[$ptr])) ||
                    isset($routes[$ptr = 0]))
                    $route = $routes[$ptr];
                if (!$route)
                    continue;
                if (isset($route[$this->VERB]) && !$preflight) {
                    $response = $stream = null;
                    // capture response exceptions
                    try {
                        if ($this->REROUTE_TRAILING_SLASH &&
                            $this->VERB == 'GET' &&
                            \preg_match('/.+\/$/', $this->PATH))
                            $this->reroute(
                                \substr($this->PATH, 0, -1).
                                ($this->QUERY ? ('?'.$this->QUERY) : ''),
                            );
                        [$handler, $ttl, $kbps, $alias] = $route[$this->VERB];
                        // Capture values of route pattern tokens
                        $this->PARAMS = $args;
                        // Save matching route
                        $this->ALIAS = $alias;
                        $this->PATTERN = $pattern;
                        if ($cors && $cors['expose'])
                            $this->header(
                                'Access-Control-Expose-Headers: '.
                                (\is_array($cors['expose']) ?
                                    \implode(',', $cors['expose']) : $cors['expose']),
                            );
                        if (\is_string($handler)) {
                            // Replace route pattern tokens in handler if any
                            $handler = \preg_replace_callback(
                                '/({)?@(\w+\b)(?(1)})/',
                                function ($id) use ($args) {
                                    $pid = \count($id) > 2 ? 2 : 1;
                                    return $args[$id[$pid]] ?? $id[0];
                                },
                                $handler,
                            );
                            if (\preg_match('/(.+)\h*(?:->|::)/', $handler, $match) &&
                                !\class_exists($match[1])) {
                                $this->error(404);
                                return null;
                            }
                        }
                        // Process request
                        $body = '';
                        $isPsr = false;
                        $now = \microtime(true);
                        if (\preg_match('/GET|HEAD/', $this->VERB) && $ttl) {
                            // Only GET and HEAD requests are cacheable
                            $headers = $this->HEADERS;
                            $cache = Cache::instance();
                            $cached = $cache->exists(
                                $hash = $this->hash(
                                        $this->VERB.' '.
                                        $this->URI,
                                    ).'.url',
                                $data,
                            );
                            if ($cached) {
                                if (isset($headers['If-Modified-Since']) &&
                                    \strtotime($headers['If-Modified-Since']) +
                                    $ttl > $now) {
                                    $this->status(304);
                                    return null;
                                }
                                // Retrieve from cache backend
                                [$headers, $body, $response] = $data;
                                if (!$this->CLI)
                                    \array_walk($headers, 'header');
                                $this->expire((int) ($cached[0] + $ttl - $now));
                            } else
                                // Expire HTTP client-cached page
                                $this->expire($ttl);
                        } else
                            $this->expire(0);
                        if (!\strlen($body)) {
                            // get input data from requests PUT,PATCH and POST (as json) etc.
                            if (!$this->RAW && !$this->BODY)
                                $this->BODY = \file_get_contents('php://input');
                            \ob_start();
                            // Call route handler
                            $response = $this->callRoute($handler, [
                                'f3' => $this,
                                'params' => $args,
                                'args' => $args, // alias to params
                                'handler' => $handler,
                            ], ['beforeRoute', 'afterRoute']);
                            $body = \ob_get_clean();
                            if ($isPsr = \is_object($response)
                                && \is_a($response, 'Psr\Http\Message\ResponseInterface')) {
                                if ($stream = $response->getBody())
                                    $stream->rewind();
                                // use psr object to service response
                                if (!$this->CLI && !$this->NONBLOCKING) {
                                    // only send headers here when response is NOT returned
                                    // and processed elsewhere
                                    $this->header(
                                        \sprintf(
                                            'HTTP/%s %d %s',
                                            $response->getProtocolVersion(),
                                            $response->getStatusCode(),
                                            $response->getReasonPhrase(),
                                        ),
                                    );
                                    foreach ($response->getHeaders() as $name => $values) {
                                        $this->header($name.": ".\implode(", ", $values));
                                    }
                                }
                            }
                            if (isset($cache) && $this->CACHE && !\error_get_last()) {
                                // prepare headers for cache item
                                $headers = $this->RESPONSE_HEADERS;
                                if ($isPsr) {
                                    $body = $stream ? $stream->getContents() : '';
                                    $headers = [
                                        // status header is not returned by headers_list()
                                        \sprintf(
                                            'HTTP/%s %d %s',
                                            $response->getProtocolVersion(),
                                            $response->getStatusCode(),
                                            $response->getReasonPhrase(),
                                        ),
                                        ...$headers,
                                    ];
                                }
                                // Save to cache backend
                                $cache->set($hash, [
                                    // Remove cookies
                                    \preg_grep(
                                        '/Set-Cookie:/',
                                        $headers,
                                        PREG_GREP_INVERT,
                                    ),
                                    $body,
                                    $isPsr ? null : $response,
                                ], $ttl);
                            }
                        }
                        $this->RESPONSE = $isPsr ? $response : $body;
                    } catch (ResponseException $r) {
                        // handle response from error handler
                        $out = \ob_get_clean();
                        if (!empty($out) && !$this->RESPONSE)
                            $this->RESPONSE = $out;
                        $isPsr = \is_object($this->RESPONSE)
                            && \is_a($this->RESPONSE, 'Psr\Http\Message\ResponseInterface');
                        if ($isPsr) {
                            $response = $this->RESPONSE;
                            if ($stream = $response->getBody())
                                $stream->rewind();
                        } else {
                            $body = $this->RESPONSE;
                        }
                    } catch (\Throwable $t) {
                        $this->RESPONSE = \ob_get_clean();
                        !$this->NONBLOCKING && throw $t;
                        // handle response from error handler
                        $response = $this->error(
                            $t->getCode(),
                            $t->getMessage(),
                            $t->getTrace(),
                            throw: false,
                        );
                    }
                    if (!$this->QUIET) {
                        if (!$this->NONBLOCKING)
                            $this->send_headers();
                        $stream?->rewind();
                        if (isset($kbps) && $kbps > 0) {
                            $ctr = $i = 0;
                            if (!$stream)
                                $max = \count($chunks = \str_split($body, 1024));
                            while ($stream
                                ? ($bytes = $stream->read(1024)) !== ''
                                : $i < $max) {
                                // Throttle output
                                ++$ctr;
                                if ($ctr / $kbps > ($elapsed = \microtime(true) - $now) &&
                                    !\connection_aborted())
                                    \usleep(\round(1e6 * ($ctr / $kbps - $elapsed)));
                                echo $stream ? $bytes : $chunks[$i++];
                            }
                        } else
                            echo $stream?->getContents() ?? $body;
                    }
                    if ($response || $this->VERB != 'OPTIONS')
                        return $response;
                }
                $allowed = \array_merge($allowed, \array_keys($route));
            }
            if (!$allowed) {
                // URL doesn't match any route
                return $this->error(404, throw: false);
            } elseif (!$this->CLI || $this->NONBLOCKING) {
                // allowed but no route handler executed
                if (!\preg_grep('/Allow:/', $this->RESPONSE_HEADERS))
                    // Unhandled HTTP method
                    $this->header('Allow: '.\implode(',', \array_unique($allowed)));
                if ($cors) {
                    if (!\preg_grep('/Access-Control-Allow-Methods:/', $this->RESPONSE_HEADERS))
                        $this->header(
                            'Access-Control-Allow-Methods: OPTIONS,'.
                            \implode(',', $allowed),
                        );
                    if ($cors['headers'] &&
                        !\preg_grep('/Access-Control-Allow-Headers:/', $this->RESPONSE_HEADERS))
                        $this->header(
                            'Access-Control-Allow-Headers: '.
                            (\is_array($cors['headers']) ?
                                \implode(',', $cors['headers']) :
                                $cors['headers']),
                        );
                    if ($cors['ttl'] > 0)
                        $this->header('Access-Control-Max-Age: '.$cors['ttl']);
                }
                if (!$this->NONBLOCKING)
                    $this->send_headers();
                if ($this->VERB != 'OPTIONS') {
                    return $this->error(405, throw: false);
                }
            }
            return false;
        }

        /**
         * add header line to the response header queue
         */
        public function header(string $header, bool $replace = true): void
        {
            if ($replace && \str_contains($header, ':')) {
                $key = \explode(':', $header, 2);
                $this->header_remove($key[0]);
            }
            $this->push('RESPONSE_HEADERS', $header);
        }

        /**
         * remove a specified response header, or all
         * @param string|null $name
         * @return void
         */
        public function header_remove(?string $name = null): void
        {
            if (!$name) {
                $this->clear('RESPONSE_HEADERS');
                return;
            }
            $this->RESPONSE_HEADERS = \preg_grep(
                '/^'.\preg_quote($name).'.*/i',
                $this->RESPONSE_HEADERS,
                PREG_GREP_INVERT,
            );
        }

        /**
         * send all headers to client
         */
        public function send_headers(): void
        {
            if (PHP_SAPI == 'cli' || headers_sent())
                return;
            foreach ($this->RESPONSE_HEADERS as $hl)
                \header($hl);
        }

    }
}


