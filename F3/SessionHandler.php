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
    public \Closure|null|array|string $onSuspect = null;

    /**
     * callback when session is successfully read and started
     */
    public \Closure|null|array|string $onRead = null;

    /**
     * level at which the session is treated as suspicious
     */
    public int $threatLevelThreshold = 3;

    /**
     * inactivity threshold that adds to threat level
     */
    public int $inactivityThreshold = 3600;

    public string $csrfKey {
        set {
            \F3\Base::instance()->set($value, $this->_csrf);
        }
    }

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
     * calculate the threat level of the current session user
     */
    protected function getThreatLevel(string $knownIP, string $knownAgent): int
    {
        $threatLevel = 0;
        if ($knownAgent !== $this->agent())
            $threatLevel += 2;
        if ($knownIP !== $this->ip())
            $threatLevel += 2;
        if (\time() - $this->stamp() > $this->inactivityThreshold)
            $threatLevel += 1;
        return $threatLevel;
    }

    /**
     * default handler when a suspicious session usage is detected
     */
    protected function handleSuspiciousSession(): void
    {
        $fw = Base::instance();
        if (!$this->onSuspect || $fw->call($this->onSuspect, [$this, $this->sid]) === false) {
            // NB: `session_destroy` can't be called at that stage (`session_start` not completed)
            // and will cause error "Cannot call session save handler in a recursive manner",
            // hence do not use $f3->clear('SESSION') within this callback.
            // this error could be omitted, though this SessionHandlers destroy method isn't called then
            $this->destroy($this->sid);
            $this->close();
            unset($fw->{'COOKIE.'.\session_name()});
            $fw->error(403);
        }
    }

    /**
     * validate session cookie
     */
    protected function checkSessionId(): void
    {
        $fw = Base::instance();
        if ($fw->exists('COOKIE.PHPSESSID', $sId)
            && !\preg_match('/^[a-zA-Z0-9]{24,256}$/', $sId))
            $fw->clear('COOKIE.PHPSESSID');
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

    protected function register(): void
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
        // RFC 7230, 3.2.4. §8, only consume printable ASCII charset
        $this->_agent = \preg_replace('/\s+/', ' ',
            \preg_replace('/[^ -~]/', '',
                $fw->HEADERS['User-Agent'] ?? ''),
        );
        $this->_ip = $fw->IP;
        $this->checkSessionId();
    }
}