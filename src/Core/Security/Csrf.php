<?php

declare(strict_types=1);

namespace ClyoNutri\Core\Security;

class Csrf
{
    private const SESSION_KEY = '_clyonutri_csrf_token';

    public static function token(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
            session_start();
        }

        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::SESSION_KEY];
    }

    public static function generate(): string
    {
        return self::token();
    }

    public static function generateToken(): string
    {
        return self::token();
    }

    public static function validate(mixed $token): bool
    {
        if (!is_string($token) || $token === '') {
            return false;
        }

        if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
            session_start();
        }

        if (empty($_SESSION[self::SESSION_KEY])) {
            return false;
        }

        return hash_equals($_SESSION[self::SESSION_KEY], $token);
    }

    public static function validateToken(?string $token): bool
    {
        return self::validate($token);
    }
}
