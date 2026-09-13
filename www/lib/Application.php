<?php
declare(strict_types=1);

class Application {
    private static bool $initialized = false;
    
    public static function init(): void {
        if (self::$initialized) {
            return;
        }
        
        // Start session if not already started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // All "today" logic (due pills, email schedules) runs in the app's
        // timezone, not the server's (the CLI runner does the same).
        try {
            require_once __DIR__ . '/../settings.php';
            date_default_timezone_set(Settings::timezone());
        } catch (\Throwable $e) {
            // Settings table unavailable (e.g. mid-install): keep server default.
        }

        self::$initialized = true;
    }
}
