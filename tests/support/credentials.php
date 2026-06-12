<?php

declare(strict_types=1);

/**
 * Loads integration-test credentials from the environment or a tests/.env file.
 *
 * Recognised variables:
 *   SCREENOVER_API_KEY     (required for integration) the API key / secret
 *   SCREENOVER_IDENTIFIER  public identifier or e-mail  (default: "sdk-tests")
 *   SCREENOVER_DOMAIN      backend domain               (default: screenover.com)
 *   SCREENOVER_PROJECT     project id (uuid) to scope to (optional; auto-selected otherwise)
 *   SCREENOVER_AUTH_MODE   "apikey" (default) or "login"
 *   SCREENOVER_UPLOAD_FILE absolute path of a local file to exercise uploadMedia()
 *   SCREENOVER_INSECURE    "1" to disable SSL verification (local/self-signed only)
 */
final class Credentials
{
    /** @var array<string,string> */
    private static array $values = [];
    private static bool $loaded = false;

    public static function load(): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;

        $envFile = __DIR__ . '/../.env';
        if (is_file($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                    continue;
                }
                [$key, $value] = explode('=', $line, 2);
                self::$values[trim($key)] = trim($value, " \t\"'");
            }
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        self::load();
        $env = getenv($key);
        if ($env !== false && $env !== '') {
            return $env;
        }
        return self::$values[$key] ?? $default;
    }

    public static function hasApiKey(): bool
    {
        return self::get('SCREENOVER_API_KEY') !== null;
    }
}
