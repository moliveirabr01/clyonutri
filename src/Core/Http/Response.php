<?php

declare(strict_types=1);

namespace ClyoNutri\Core\Http;

use Closure;

final class Response
{
    private static ?Closure $redirectHandler = null;

    public static function setRedirectHandler(?Closure $handler): void
    {
        self::$redirectHandler = $handler;
    }

    public static function redirect(string $url): void
    {
        if (self::$redirectHandler !== null) {
            (self::$redirectHandler)($url);
            return;
        }

        header('Location: ' . $url);
        exit;
    }
}
