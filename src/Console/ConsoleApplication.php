<?php

declare(strict_types=1);

namespace Nora\Console;

use Nora\Database\MigrationRunner;
use Nora\Database\PdoConnection;
use Nora\Config\EnvironmentLoader;
use PDO;
use RuntimeException;
use Throwable;

final class ConsoleApplication
{
    /**
     * @param resource|null $output
     * @param resource|null $error
     */
    public function __construct(
        private readonly string $frameworkRoot,
        private readonly string $workingDirectory = '',
        private mixed $output = null,
        private mixed $error = null,
    ) {
        $this->output ??= STDOUT;
        $this->error ??= STDERR;
    }

    /**
     * @param list<string> $arguments
     */
    public function run(array $arguments): int
    {
        try {
            return $this->dispatch($arguments);
        } catch (Throwable $exception) {
            fwrite($this->error, 'nora: ' . $exception->getMessage() . PHP_EOL);

            return 1;
        }
    }

    /**
     * @param list<string> $arguments
     */
    private function dispatch(array $arguments): int
    {
        $command = $arguments[0] ?? '--help';
        $parameters = array_slice($arguments, 1);

        if (in_array($command, ['--help', '-h', 'help'], true)) {
            $this->write($this->help());

            return 0;
        }

        if (in_array($command, ['--version', '-V'], true)) {
            $this->write("Nora Framework development build" . PHP_EOL);

            return 0;
        }

        return match ($command) {
            'new' => $this->newProject($parameters),
            'make:controller' => $this->makeClass($parameters, 'Controllers', 'Controller'),
            'make:model' => $this->makeClass($parameters, 'Models', ''),
            'make:component' => $this->makeComponent($parameters),
            'migrate' => $this->migrate(false),
            'migrate:status' => $this->migrate(true),
            'serve' => $this->serve($parameters),
            default => throw new RuntimeException('Unknown command "' . $command . '". Use --help.'),
        };
    }

    /**
     * @param list<string> $arguments
     */
    private function newProject(array $arguments): int
    {
        if (count($arguments) !== 1 || !$this->isSafeProjectName($arguments[0])) {
            throw new RuntimeException('Usage: nora new <project-name> (a single safe directory name).');
        }

        $name = $arguments[0];
        $target = $this->workingDirectory() . DIRECTORY_SEPARATOR . $name;
        if (file_exists($target)) {
            throw new RuntimeException('Project destination already exists: ' . $target);
        }

        if (!mkdir($target, 0755)) {
            throw new RuntimeException('Unable to create project directory: ' . $target);
        }

        $relativeFrameworkPath = $this->relativePath($target, $this->frameworkRoot);
        if (!mkdir($target . DIRECTORY_SEPARATOR . 'migrations', 0755)) {
            rmdir($target);
            throw new RuntimeException('Unable to create project migration directory.');
        }
        $files = [
            'composer.json' => json_encode([
                'name' => 'app/' . strtolower($name),
                'type' => 'project',
                'repositories' => [[
                    'type' => 'path',
                    'url' => $relativeFrameworkPath,
                    'options' => ['symlink' => true],
                ]],
                'require' => ['morave/nora' => '*@dev'],
                'autoload' => ['psr-4' => ['App\\' => 'src/']],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL,
            '.env.example' => "APP_ENV=development\nAPP_DEBUG=true\nNORA_DSN=\nNORA_DB_USERNAME=\nNORA_DB_PASSWORD=\n",
            'src/routes.php' => $this->routeTemplate(),
            'src/Reactive/CounterPage.php' => $this->reactiveComponentTemplate(),
            'public/index.php' => $this->publicIndexTemplate(),
            'public/router.php' => $this->serverRouterTemplate(),
            'README.md' => $this->projectReadmeTemplate($name, $relativeFrameworkPath),
        ];
        $createdFiles = [];

        try {
            foreach ($files as $relative => $contents) {
                $path = $target . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
                $directory = dirname($path);
                if (!is_dir($directory) && !mkdir($directory, 0755, true)) {
                    throw new RuntimeException('Unable to create project directory: ' . $directory);
                }

                $stream = fopen($path, 'x');
                if ($stream === false) {
                    throw new RuntimeException('Unable to safely create project file: ' . $relative);
                }
                $createdFiles[] = $path;
                $written = fwrite($stream, $contents);
                fclose($stream);
                if ($written !== strlen($contents)) {
                    throw new RuntimeException('Unable to completely write project file: ' . $relative);
                }
            }
        } catch (Throwable $exception) {
            foreach ($createdFiles as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            $this->removeEmptyDirectories($target);

            throw $exception;
        }

        $this->write('Created ' . $target . PHP_EOL);
        $this->write('Next: cd ' . $name . ' && composer install && copy .env.example .env' . PHP_EOL);

        return 0;
    }

    /**
     * @param list<string> $arguments
     */
    private function makeClass(array $arguments, string $directory, string $suffix): int
    {
        if (count($arguments) !== 1 || preg_match('/^[A-Z][A-Za-z0-9]*$/D', $arguments[0]) !== 1) {
            throw new RuntimeException('Usage: nora make:' . strtolower(rtrim($directory, 's')) . ' <ClassName>.');
        }

        $name = $arguments[0];
        if ($suffix !== '' && !str_ends_with($name, $suffix)) {
            $name .= $suffix;
        }

        $base = $this->workingDirectory() . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . $directory;
        if (!is_dir($base) && !mkdir($base, 0755, true)) {
            throw new RuntimeException('Unable to create source directory: ' . $base);
        }

        $path = $base . DIRECTORY_SEPARATOR . $name . '.php';
        if (file_exists($path)) {
            throw new RuntimeException('File already exists or could not be created: ' . $path);
        }
        $stream = @fopen($path, 'x');
        if ($stream === false) {
            throw new RuntimeException('File already exists or could not be created: ' . $path);
        }

        $body = $directory === 'Controllers'
            ? $this->controllerTemplate($name)
            : $this->modelTemplate($name);
        $written = fwrite($stream, $body);
        fclose($stream);
        if ($written !== strlen($body)) {
            throw new RuntimeException('Unable to completely write generated class: ' . $path);
        }

        $this->write('Created ' . $path . PHP_EOL);

        return 0;
    }

    /**
     * @param list<string> $arguments
     */
    private function makeComponent(array $arguments): int
    {
        if (count($arguments) !== 1 || preg_match('/^[A-Z][A-Za-z0-9]*$/D', $arguments[0]) !== 1) {
            throw new RuntimeException('Usage: nora make:component <ClassName>.');
        }

        $name = $arguments[0];
        $base = $this->workingDirectory() . DIRECTORY_SEPARATOR . 'src'
            . DIRECTORY_SEPARATOR . 'Reactive';
        if (!is_dir($base) && !mkdir($base, 0755, true)) {
            throw new RuntimeException('Unable to create component source directory: ' . $base);
        }
        $path = $base . DIRECTORY_SEPARATOR . $name . '.php';
        $stream = @fopen($path, 'x');
        if ($stream === false) {
            throw new RuntimeException('File already exists or could not be created: ' . $path);
        }

        $contents = "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Reactive;\n\n"
            . "use Nora\\Reactive\\Component;\nuse Nora\\Reactive\\ReactiveAction;\n"
            . "use Nora\\Reactive\\SafeHtml;\nuse Nora\\Reactive\\State;\nuse Nora\\Reactive\\UI;\n\n"
            . "final class {$name} extends Component\n{\n"
            . "    public function initialState(): array\n    {\n        return [];\n    }\n\n"
            . "    protected function view(State \$state): SafeHtml\n    {\n"
            . "        return UI::element('section', [], '{$name}');\n    }\n\n"
            . "    public function actions(): array\n    {\n        return [];\n    }\n}\n";
        $written = fwrite($stream, $contents);
        fclose($stream);
        if ($written !== strlen($contents)) {
            if (is_file($path) && !unlink($path)) {
                throw new RuntimeException('Unable to remove an incomplete component file: ' . $path);
            }
            throw new RuntimeException('Unable to completely write component class: ' . $path);
        }

        $this->write('Created ' . $path . PHP_EOL);

        return 0;
    }

    private function migrate(bool $showStatus): int
    {
        $this->loadEnvironmentFile();
        $dsn = getenv('NORA_DSN');
        if ($dsn === false || $dsn === '') {
            throw new RuntimeException('Set NORA_DSN in the environment or .env file before running migrations.');
        }
        $username = getenv('NORA_DB_USERNAME');
        $password = getenv('NORA_DB_PASSWORD');
        $connection = PdoConnection::connect(
            $dsn,
            $username === false ? null : $username,
            $password === false ? null : $password,
        );
        $runner = new MigrationRunner(
            $connection->pdo(),
            $this->workingDirectory() . DIRECTORY_SEPARATOR . 'migrations',
        );

        if ($showStatus) {
            $status = $runner->status();
            if ($status === []) {
                $this->write('No migrations found.' . PHP_EOL);
            }
            foreach ($status as $item) {
                $state = $item['applied'] ? 'applied' : 'pending';
                $this->write($item['migration'] . ' ' . $state . PHP_EOL);
            }
            return 0;
        }

        $migrated = $runner->migrate();
        $this->write($migrated === [] ? 'Nothing to migrate.' . PHP_EOL : implode(PHP_EOL, $migrated) . PHP_EOL);

        return 0;
    }

    /**
     * @param list<string> $arguments
     */
    private function serve(array $arguments): int
    {
        if (count($arguments) > 2) {
            throw new RuntimeException('Usage: nora serve [host] [port].');
        }

        $host = $arguments[0] ?? '127.0.0.1';
        $port = isset($arguments[1]) ? filter_var($arguments[1], FILTER_VALIDATE_INT) : 8000;
        if (filter_var($host, FILTER_VALIDATE_IP) === false
            || $port === false
            || $port < 1
            || $port > 65535
        ) {
            throw new RuntimeException('Serve host must be an IP address and port must be between 1 and 65535.');
        }

        $public = $this->workingDirectory() . DIRECTORY_SEPARATOR . 'public';
        $index = $public . DIRECTORY_SEPARATOR . 'index.php';
        $router = $public . DIRECTORY_SEPARATOR . 'router.php';
        if (!is_file($index) || !is_file($router)) {
            throw new RuntimeException('No generated Nora app found. Run nora new <project-name> first.');
        }

        $address = str_contains($host, ':') ? '[' . $host . ']:' . $port : $host . ':' . $port;
        $command = [
            PHP_BINARY,
            '-S',
            $address,
            '-t',
            $public,
            $router,
        ];
        $process = proc_open($command, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, $this->workingDirectory());
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start the PHP development server.');
        }

        return proc_close($process);
    }

    private function loadEnvironmentFile(): void
    {
        $path = $this->workingDirectory() . DIRECTORY_SEPARATOR . '.env';
        if (is_file($path)) {
            (new EnvironmentLoader())->load($path);
        }
    }

    private function workingDirectory(): string
    {
        if ($this->workingDirectory !== '') {
            return $this->workingDirectory;
        }

        $directory = getcwd();
        if ($directory === false) {
            throw new RuntimeException('Unable to determine the current working directory.');
        }

        return $directory;
    }

    private function isSafeProjectName(string $name): bool
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/D', $name) !== 1 || $name === '.' || $name === '..') {
            return false;
        }

        return preg_match('/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|$)/i', $name) !== 1;
    }

    private function relativePath(string $from, string $to): string
    {
        $fromParts = explode(DIRECTORY_SEPARATOR, rtrim(realpath($from) ?: $from, DIRECTORY_SEPARATOR));
        $toParts = explode(DIRECTORY_SEPARATOR, rtrim(realpath($to) ?: $to, DIRECTORY_SEPARATOR));
        $shared = 0;
        $max = min(count($fromParts), count($toParts));
        while ($shared < $max && strcasecmp($fromParts[$shared], $toParts[$shared]) === 0) {
            $shared++;
        }

        if ($shared === 0) {
            return str_replace('\\', '/', $to);
        }

        $relative = array_merge(
            array_fill(0, count($fromParts) - $shared, '..'),
            array_slice($toParts, $shared),
        );

        return str_replace('\\', '/', implode(DIRECTORY_SEPARATOR, $relative));
    }

    private function removeEmptyDirectories(string $directory): void
    {
        $entries = scandir($directory);
        if ($entries === false) {
            return;
        }
        foreach (array_diff($entries, ['.', '..']) as $entry) {
            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path)) {
                $this->removeEmptyDirectories($path);
            }
        }
        rmdir($directory);
    }

    private function controllerTemplate(string $name): string
    {
        return "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Controllers;\n\n"
            . "use Nora\\Http\\Request;\nuse Nora\\Http\\Response;\n\n"
            . 'final class ' . $name . "\n{\n"
            . "    public function __invoke(Request \$request, array \$parameters): Response\n"
            . "    {\n        return Response::text('Not implemented', 501);\n    }\n}\n";
    }

    private function modelTemplate(string $name): string
    {
        return "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Models;\n\n"
            . 'final class ' . $name . "\n{\n}\n";
    }

    private function routeTemplate(): string
    {
        return "<?php\n\ndeclare(strict_types=1);\n\nuse Nora\\Core\\Application;\n"
            . "use App\\Reactive\\CounterPage;\n"
            . "use Nora\\Reactive\\ReactiveEngine;\nuse Nora\\Security\\SessionManager;\n\n"
            . "return static function (Application \$app): void {\n"
            . "    \$runtime = new ReactiveEngine(new SessionManager("
            . "\$app->config()->get('app.environment') === 'production'));\n"
            . "    \$runtime->register('counter', new CounterPage());\n"
            . "    \$runtime->mount(\$app, 'counter', '/');\n};\n";
    }

    private function reactiveComponentTemplate(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Reactive;

use Nora\Reactive\Component;
use Nora\Reactive\Components\Button;
use Nora\Reactive\Components\Dropdown;
use Nora\Reactive\Components\Modal;
use Nora\Reactive\ReactiveAction;
use Nora\Reactive\SafeHtml;
use Nora\Reactive\State;
use Nora\Reactive\UI;
use Nora\Reactive\Components\Tabs;

final class CounterPage extends Component
{
    public function initialState(): array
    {
        return ['count' => 0];
    }

    protected function view(State $state): SafeHtml
    {
        $count = $state->get('count');
        $counters = UI::each(
            [
                ['key' => 'primary', 'title' => 'Primary counter'],
                ['key' => 'secondary', 'title' => 'Coordinated counter'],
            ],
            static fn (array $counter): string => $counter['key'],
            static fn (array $counter): SafeHtml => UI::element('section', [
                'data-nora-component' => 'counter',
            ], [
                UI::element('h2', [], $counter['title']),
                UI::element('p', [], [
                    'Shared count: ',
                    UI::element('output', [], $count),
                ]),
                Button::render([
                    'action' => 'increment',
                    'arguments' => ['amount' => 1],
                ], 'Increment'),
            ]),
        );

        return UI::fragment([
            UI::element('h1', [], 'Nora reactive counter'),
            UI::element('p', [], 'Change this field, then activate a counter to verify focus and input preservation.'),
            UI::element('label', [], [
                'Unsubmitted note ',
                UI::element('input', ['type' => 'text', 'value' => 'Keep my input']),
            ]),
            $counters,
            Modal::render(
                ['id' => 'counter-help', 'title' => 'About Nora', 'trigger' => 'About'],
                UI::element('p', [], 'This dialog is accessible and uses the browser dialog element.'),
            ),
            Tabs::render('counter-tabs', 'Counter information', [
                ['id' => 'overview', 'label' => 'Overview', 'content' => UI::element('p', [], 'Server-rendered overview.')],
                ['id' => 'details', 'label' => 'Details', 'content' => UI::element('p', [], 'Additional details.')],
            ]),
            Dropdown::render(
                ['label' => 'More options'],
                UI::element('a', ['href' => '/health'], 'Health endpoint'),
            ),
        ]);
    }

    public function actions(): array
    {
        return [
            'increment' => new ReactiveAction(
                ['amount' => 'integer|min:1|max:10'],
                static fn (array $state, array $arguments): array => [
                    'count' => $state['count'] + (int) $arguments['amount'],
                ],
                static fn (array $state, array $arguments): bool => $state['count'] < 10,
            ),
        ];
    }
}
PHP;
    }

    private function publicIndexTemplate(): string
    {
        return "<?php\n\ndeclare(strict_types=1);\n\nrequire dirname(__DIR__) . '/vendor/autoload.php';\n\n"
            . "use Nora\\Core\\ApplicationBootstrap;\n\n"
            . "(new ApplicationBootstrap())->boot(dirname(__DIR__))->run();\n";
    }

    private function serverRouterTemplate(): string
    {
        return "<?php\n\n\$path = parse_url(\$_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);\n"
            . "\$root = realpath(__DIR__);\n\$file = is_string(\$path) ? realpath(__DIR__ . \$path) : false;\n"
            . "if (\$root !== false && \$file !== false && is_file(\$file)\n"
            . "    && (DIRECTORY_SEPARATOR === '\\\\'\n"
            . "        ? str_starts_with(strtolower(\$file), strtolower(\$root . DIRECTORY_SEPARATOR))\n"
            . "        : str_starts_with(\$file, \$root . DIRECTORY_SEPARATOR))) {\n"
            . "    return false;\n}\nrequire __DIR__ . '/index.php';\n";
    }

    private function projectReadmeTemplate(string $name, string $frameworkPath): string
    {
        return '# ' . $name . "\n\n"
            . "Generated with Nora Framework. This starter includes a server-rendered reactive counter page using Nora's first-party browser runtime. The Composer path repository points to the Nora checkout that created this project; adjust it before sharing the project with another machine.\n\n"
            . "```powershell\ncomposer install\ncopy .env.example .env\nphp "
            . $frameworkPath . "/bin/nora serve\n```\n\n"
            . "The two counter displays share server-owned session state. The starter uses Nora's composable PHP UI API; the reactive API remains experimental. See the Nora Reactive UI Engine documentation for limitations and security guidance.\n";
    }

    private function help(): string
    {
        return "Nora Framework CLI\n\n"
            . "Usage: php bin/nora <command> [arguments]\n\n"
            . "  --help                 Show this help\n"
            . "  --version              Show the framework development version\n"
            . "  new <name>             Create a safe starter application\n"
            . "  make:controller <name> Generate a controller under src/Controllers\n"
            . "  make:model <name>      Generate a model under src/Models\n"
            . "  make:component <name>  Generate a reactive PHP component under src/Reactive\n"
            . "  migrate                Apply pending migrations using NORA_DSN\n"
            . "  migrate:status         Show migration state using NORA_DSN\n"
            . "  serve [host] [port]    Run the generated app with PHP's development server\n";
    }

    private function write(string $message): void
    {
        fwrite($this->output, $message);
    }
}
