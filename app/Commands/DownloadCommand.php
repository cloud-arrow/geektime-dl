<?php

declare(strict_types=1);

namespace App\Commands;

use App\Config\AppConfig;
use App\Fsm\FsmRunner;
use App\Geektime\Client;
use Illuminate\Support\Facades\Log;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\text;

/**
 * Main CLI command for downloading Geektime courses.
 *
 * Translated from Go: cmd/root.go
 *
 * This is the default command that runs when no command name is provided.
 * It handles:
 *   - CLI option parsing (matching Go cobra flags)
 *   - Config file loading and merging with CLI options
 *   - Cookie input (interactive prompt if not provided)
 *   - Account login via phone/password
 *   - Signal handling (SIGINT for graceful shutdown)
 *   - Creating and running the FSM runner
 */
class DownloadCommand extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'download
        {--phone= : Phone number for login}
        {--gcid= : GCID cookie value}
        {--gcess= : GCESS cookie value}
        {--o|folder= : Download output directory}
        {--quality=sd : Video quality: ld/sd/hd}
        {--output=2 : Column output type bitmask 1-7 (1=PDF, 2=Markdown, 4=Audio)}
        {--comments=1 : Comment mode 0/1/2 (0=none, 1=first page, 2=all)}
        {--print-pdf-wait=5 : Chrome PDF print wait seconds}
        {--print-pdf-timeout=60 : Chrome PDF timeout seconds}
        {--interval=1 : Download interval seconds}
        {--enterprise : Enterprise mode flag}
        {--log-level=info : Log level (debug, info, warn, error, none)}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Download courses from Geektime';

    private ?FsmRunner $runner = null;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // 1. Load saved config (if any)
        $savedConfig = AppConfig::loadFromFile();

        // 2. Build config from CLI options, using saved config as defaults
        $config = $this->buildConfig($savedConfig);

        // 3. Initialize logging based on config
        $this->configureLogLevel($config->logLevel);

        // 4. Handle cookie/login flow
        $this->resolveCookies($config);

        // 5. Validate config
        try {
            $config->validate();
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        // 6. Save config for next run
        try {
            $config->saveToFile();
        } catch (\RuntimeException $e) {
            Log::warning('Failed to save config file', ['error' => $e->getMessage()]);
        }

        // 7. Create HTTP client with cookies
        $client = new Client(
            gcid: $config->gcid,
            gcess: $config->gcess,
        );

        // 8. Create and run FSM
        $this->runner = new FsmRunner(
            config: $config,
            client: $client,
        );

        // 9. Set up signal handling for graceful shutdown
        $this->registerSignalHandler();

        return $this->runner->run();
    }

    /**
     * Build the AppConfig by merging saved config with CLI options.
     *
     * CLI options take precedence over saved config values.
     * If an option is not explicitly provided on the CLI, the saved value is used.
     */
    private function buildConfig(?AppConfig $savedConfig): AppConfig
    {
        $defaultFolder = $this->getDefaultDownloadFolder();

        return new AppConfig(
            gcid: $this->option('gcid') ?? $savedConfig?->gcid ?? '',
            gcess: $this->option('gcess') ?? $savedConfig?->gcess ?? '',
            downloadFolder: $this->option('folder') ?? $savedConfig?->downloadFolder ?? $defaultFolder,
            quality: (string) $this->option('quality'),
            downloadComments: (int) $this->option('comments'),
            columnOutputType: (int) $this->option('output'),
            printPdfWaitSeconds: (int) $this->option('print-pdf-wait'),
            printPdfTimeoutSeconds: (int) $this->option('print-pdf-timeout'),
            interval: (int) $this->option('interval'),
            isEnterprise: (bool) $this->option('enterprise'),
            logLevel: (string) $this->option('log-level'),
        );
    }

    /**
     * Resolve cookies: either from CLI options, login, or interactive input.
     *
     * Priority:
     *   1. CLI options --gcid / --gcess
     *   2. Phone/password login (if --phone provided)
     *   3. Interactive cookie string input
     */
    private function resolveCookies(AppConfig $config): void
    {
        // If both cookies are already provided, nothing to do
        if ($config->gcid !== '' && $config->gcess !== '') {
            return;
        }

        // If phone is provided, attempt login
        $phone = $this->option('phone');
        if ($phone !== null && $phone !== '') {
            $this->handlePhoneLogin($config, $phone);

            return;
        }

        // Interactive cookie input
        $this->handleCookieInput($config);
    }

    /**
     * Handle phone/password login to obtain cookies.
     *
     * Translated from Go: internal/geektime/account.go Login()
     */
    private function handlePhoneLogin(AppConfig $config, string $phone): void
    {
        $password = text(
            label: '请输入密码',
            required: true,
        );

        try {
            $httpClient = new \GuzzleHttp\Client([
                'timeout' => 10,
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_13_6) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/81.0.4044.92 Safari/537.36',
                    'Origin' => 'https://time.geekbang.org',
                    'Content-Type' => 'application/json',
                ],
            ]);

            Log::info('Login request start');

            $response = $httpClient->post('https://account.geekbang.org/account/ticket/login', [
                'json' => [
                    'country' => 86,
                    'appid' => 1,
                    'platform' => 3,
                    'cellphone' => $phone,
                    'password' => $password,
                ],
            ]);

            $body = json_decode($response->getBody()->getContents(), true);
            $code = (int) ($body['code'] ?? -1);

            if ($code !== 0) {
                $errorCode = (int) ($body['error']['code'] ?? 0);
                if ($errorCode === -3031) {
                    throw new \RuntimeException('密码错误, 请尝试重新登录');
                }
                if ($errorCode === -3005) {
                    throw new \RuntimeException('密码输入错误次数过多，已触发验证码校验，请稍后再试');
                }
                throw new \RuntimeException(sprintf('登录失败: %s', json_encode($body)));
            }

            // Extract GCID and GCESS from response cookies
            $setCookieHeaders = $response->getHeader('Set-Cookie');
            foreach ($setCookieHeaders as $cookieHeader) {
                if (preg_match('/^GCID=([^;]+)/', $cookieHeader, $matches)) {
                    $config->gcid = $matches[1];
                }
                if (preg_match('/^GCESS=([^;]+)/', $cookieHeader, $matches)) {
                    $config->gcess = $matches[1];
                }
            }

            if ($config->gcid === '' || $config->gcess === '') {
                throw new \RuntimeException('登录成功但未获取到Cookie，请尝试手动输入Cookie');
            }

            $this->info('登录成功');
        } catch (\GuzzleHttp\Exception\GuzzleException $e) {
            throw new \RuntimeException('登录请求失败: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Prompt the user to input a cookie string interactively.
     *
     * Accepts format: "GCID=value1; GCESS=value2"
     */
    private function handleCookieInput(AppConfig $config): void
    {
        $cookieString = text(
            label: '请输入极客时间 Cookie (格式: GCID=xxx; GCESS=xxx)',
            required: true,
            validate: function (string $value): ?string {
                $trimmed = trim($value);
                if ($trimmed === '') {
                    return 'Cookie 不能为空';
                }
                if (! str_contains($trimmed, 'GCID=') || ! str_contains($trimmed, 'GCESS=')) {
                    return 'Cookie 格式不正确, 需要包含 GCID 和 GCESS';
                }

                return null;
            },
        );

        $config->readCookiesFromInput($cookieString);
    }

    /**
     * Get the default download folder path.
     *
     * Matches Go behavior: ~/geektime-downloader
     */
    private function getDefaultDownloadFolder(): string
    {
        $home = $_SERVER['HOME'] ?? $_ENV['HOME'] ?? getenv('HOME');

        if ($home === false || $home === '') {
            $home = posix_getpwuid(posix_getuid())['dir'] ?? '/tmp';
        }

        return rtrim($home, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'geektime-downloader';
    }

    /**
     * Configure the log level based on config.
     *
     * Maps config log level strings to Monolog levels.
     */
    private function configureLogLevel(string $logLevel): void
    {
        $level = match ($logLevel) {
            'debug' => 'debug',
            'info' => 'info',
            'warn' => 'warning',
            'error' => 'error',
            'none' => 'emergency', // Effectively disables logging
            default => 'info',
        };

        // Set the log level on the default channel
        config(['logging.channels.single.level' => $level]);
    }

    /**
     * Register SIGINT handler for graceful shutdown.
     */
    private function registerSignalHandler(): void
    {
        if (! function_exists('pcntl_signal')) {
            return;
        }

        pcntl_async_signals(true);

        pcntl_signal(SIGINT, function () {
            if ($this->runner !== null) {
                $this->runner->cancel();
            }
        });
    }
}
