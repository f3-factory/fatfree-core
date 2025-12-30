<?php

namespace F3;

trait SessionHandler {

    /**
     * Session ID
     */
    protected ?string $sid = null;
    /**
     * Anti-CSRF token
     */
    protected string $_csrf;
    /**
     * User agent
     */
    protected string $_agent;
    /**
     * IP
     */
    protected string $_ip;
    /**
     * Suspect callback
     */
    protected ?\Closure $onSuspect = null;

    /**
     * Open session
     */
    public function open(string $path, string $name): bool
    {
        return true;
    }

    /**
     * Close session
     */
    public function close(): bool
    {
        $this->sid = null;
        return true;
    }

    /**
     * default handler when a suspicious session usage is detected
     */
    protected function handleSuspiciousSession(): void
    {
        $fw = Base::instance();
        if (!$this->onSuspect || $fw->call($this->onSuspect, [$this, $this->sid]) === false) {
            //NB: `session_destroy` can't be called at that stage (`session_start` not completed)
            $this->destroy($this->sid);
            $this->close();
            unset($fw->{'COOKIE.'.\session_name()});
            $fw->error(403);
        }
    }

    /**
     * Return session id (if session has started)
     */
    public function sid(): ?string
    {
        return $this->sid;
    }

    /**
     * Return anti-CSRF token
     */
    public function csrf(): string
    {
        return $this->_csrf;
    }

    /**
     * Return IP address
     */
    public function ip(): string
    {
        return $this->_ip;
    }

    /**
     * Return HTTP user agent
     */
    public function agent(): string
    {
        return $this->_agent;
    }

    protected function register(?string $CsrfKeyName = null): void
    {
        \session_set_save_handler($this);
        \register_shutdown_function('session_commit');
        $fw = Base::instance();
        $this->_csrf = $fw->hash(
            $fw->SEED.
            \extension_loaded('openssl') ?
                \implode(\unpack('L', \openssl_random_pseudo_bytes(4))) :
                \mt_rand(),
        );
        if ($CsrfKeyName)
            $fw->$CsrfKeyName = $this->_csrf;
        $this->_agent = $fw->HEADERS['User-Agent'] ?? '';
        $this->_ip = $fw->IP;
    }
}