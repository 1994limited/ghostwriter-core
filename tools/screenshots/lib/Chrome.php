<?php

/**
 * Starts headless Chrome on a free port with a throwaway profile, waits for
 * it to listen, and hands back a DevTools socket to its first tab. Chrome
 * and the profile go when the script ends.
 */
class Chrome
{
    public const DEFAULT_BINARY = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';

    /**
     * @param  array<int, string>  $flags  Extra Chrome flags.
     */
    public static function launch(?string $chrome = null, array $flags = []): DevToolsSocket
    {
        $chrome ??= getenv('GW_SHOT_CHROME') ?: self::DEFAULT_BINARY;

        if (! is_file($chrome)) {
            throw new RuntimeException("Chrome not found at {$chrome}; set GW_SHOT_CHROME.");
        }

        $port = 9222 + random_int(1, 500);
        $profile = sys_get_temp_dir().'/ghostwriter-shots-chrome-'.getmypid();
        $process = proc_open(
            [$chrome, '--headless=new', "--remote-debugging-port={$port}", "--user-data-dir={$profile}", '--window-size=1600,1000', '--hide-scrollbars', '--force-device-scale-factor=1', '--ignore-certificate-errors', '--disable-features=Translate', '--no-first-run', '--no-default-browser-check', '--allow-file-access-from-files', ...$flags, 'about:blank'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', "{$profile}.log", 'a'], 2 => ['file', "{$profile}.log", 'a']],
            $pipes,
        );

        register_shutdown_function(function () use ($process, $profile): void {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }

            usleep(300000);
            exec('rm -rf '.escapeshellarg($profile).' 2>/dev/null');
            @unlink("{$profile}.log");
        });

        $version = null;
        // HTTP/1.1 with a short timeout: newer Chrome leaves an HTTP/1.0 request hanging.
        $http = stream_context_create(['http' => ['protocol_version' => 1.1, 'timeout' => 2, 'header' => "Connection: close\r\n"]]);

        for ($i = 0; $i < 50 && ! $version; $i++) {
            usleep(200000);
            $version = @file_get_contents("http://127.0.0.1:{$port}/json/version", false, $http);
        }

        if (! $version) {
            throw new RuntimeException("Chrome did not start; see {$profile}.log.");
        }

        // The port can answer before the first tab is ready to be listed.
        $target = null;

        for ($i = 0; $i < 50 && ! $target; $i++) {
            usleep(300000);
            $targets = (array) json_decode((string) @file_get_contents("http://127.0.0.1:{$port}/json/list", false, $http), true);
            $target = current(array_filter($targets, fn ($candidate) => is_array($candidate) && ($candidate['type'] ?? null) === 'page')) ?: null;
        }

        if (! $target) {
            throw new RuntimeException("Chrome opened no page to drive; see {$profile}.log.");
        }

        return new DevToolsSocket($target['webSocketDebuggerUrl']);
    }
}
