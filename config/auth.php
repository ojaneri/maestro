<?php

class Auth {
    private static $instance = null;
    private $session;

    private function __construct() {
        session_start();
        $this->session = &$_SESSION;
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function login($email, $password) {
        // Implement authentication logic
        // For now, just set session variables
        $this->session['auth'] = true;
        $this->session['user_email'] = $email;
        return true;
    }

    public function logout() {
        session_destroy();
        return true;
    }

    public function isLoggedIn() {
        return isset($this->session['auth']) && $this->session['auth'] === true;
    }

    public function getUserEmail() {
        return $this->session['user_email'] ?? null;
    }

    public function generateCSRFToken() {
        if (empty($this->session['csrf_token'])) {
            $this->session['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $this->session['csrf_token'];
    }

    public function validateCSRFToken($token) {
        return isset($this->session['csrf_token']) && 
               hash_equals($this->session['csrf_token'], $token);
    }

    public function setExternalUser($user) {
        $this->session['external_user'] = $user;
    }

    public function getExternalUser() {
        return $this->session['external_user'] ?? null;
    }

    public function isManager() {
        $user = $this->getExternalUser();
        return $user && ($user['role'] ?? '') === 'manager';
    }
}