<?php
declare(strict_types=1);

/**
 * Isolated Application API Test Client.
 *
 * Invokes real application API endpoints through subprocess execution, ensuring:
 * - Real server-side authorization check is executed.
 * - Real CSRF validation is executed.
 * - Real session management is exercised.
 * - Multi-step transactions, row locking, and triggers are exercised.
 * - Real HTTP response codes and JSON bodies are returned.
 */

function runApiSubprocess(
    string $apiFile,
    string $method,
    array $getParams,
    array $body,
    array $session,
    string $dbName
): array {
    $phpExe = 'D:\\wamp64\\bin\\php\\php8.2.29\\php.exe';
    $runnerScript = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'api_runner_' . bin2hex(random_bytes(6)) . '.php';
    $inputJson = json_encode($body, JSON_UNESCAPED_UNICODE);

    $sessionId = bin2hex(random_bytes(16));
    $sessionSavePath = addslashes(sys_get_temp_dir());
    $endpointFullPath = addslashes(realpath(__DIR__ . '/../api/' . $apiFile));
    $csrfHeader = isset($body['csrf_token']) ? addslashes((string)$body['csrf_token']) : '';
    $sessionJson = addslashes(json_encode($session));
    $getJson = addslashes(json_encode($getParams));
    $inputJsonEscaped = addslashes($inputJson);

    $runnerCode = <<<PHP
<?php
declare(strict_types=1);

class MockPhpStream {
    public \$context;
    private static string \$data = '';
    private int \$position = 0;
    public static function setContent(string \$c): void { self::\$data = \$c; }
    public function stream_open(string \$path, string \$mode, int \$options, ?string &\$opened_path): bool {
        \$this->position = 0;
        return true;
    }
    public function stream_read(int \$count): string {
        \$ret = substr(self::\$data, \$this->position, \$count);
        \$this->position += strlen(\$ret);
        return \$ret;
    }
    public function stream_tell(): int { return \$this->position; }
    public function stream_eof(): bool { return \$this->position >= strlen(self::\$data); }
    public function stream_stat(): array { return ['size' => strlen(self::\$data)]; }
}
stream_wrapper_unregister('php');
stream_wrapper_register('php', MockPhpStream::class);
MockPhpStream::setContent('{$inputJsonEscaped}');

putenv('APP_ENV=development');
putenv('LOAN_DB_NAME={$dbName}');
putenv('LEGACY_TEST_DB={$dbName}');
putenv('LOAN_DB_HOST=127.0.0.1');
putenv('LOAN_DB_USER=root');
putenv('LOAN_DB_PASS=');
session_save_path('{$sessionSavePath}');
session_id('{$sessionId}');
session_start();
\$_SESSION = json_decode('{$sessionJson}', true) ?: [];
session_write_close();

\$_SERVER['REQUEST_METHOD'] = '{$method}';
\$_SERVER['HTTP_X_CSRF_TOKEN'] = '{$csrfHeader}';
\$_GET = json_decode('{$getJson}', true) ?: [];
\$_COOKIE[session_name()] = '{$sessionId}';

require '{$endpointFullPath}';
PHP;

    file_put_contents($runnerScript, $runnerCode);

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $env = array_merge(getenv(), [
        'APP_ENV' => 'development',
        'LOAN_DB_NAME' => $dbName,
        'LEGACY_TEST_DB' => $dbName,
        'LOAN_DB_HOST' => '127.0.0.1',
        'LOAN_DB_PORT' => '3306',
        'LOAN_DB_USER' => 'root',
        'LOAN_DB_PASS' => '',
        'LEGACY_TEST_HOST' => '127.0.0.1',
        'LEGACY_TEST_PORT' => '3306',
        'LEGACY_TEST_USER' => 'root',
        'LEGACY_TEST_PASSWORD' => '',
    ]);

    $process = proc_open(
        "\"{$phpExe}\" \"{$runnerScript}\"",
        $descriptors,
        $pipes,
        dirname(__DIR__),
        $env
    );

    if (!is_resource($process)) {
        @unlink($runnerScript);
        throw new RuntimeException("Failed to spawn process for {$apiFile}");
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);
    @unlink($runnerScript);

    // Filter xdebug warnings if any
    $cleanStdout = preg_replace('/^Xdebug:.*?\n/m', '', $stdout);
    $json = json_decode(trim($cleanStdout), true);
    return [
        'exitCode' => $exitCode,
        'stdout' => $cleanStdout,
        'stderr' => $stderr,
        'json' => $json,
        'success' => $json['success'] ?? false,
        'message' => $json['message'] ?? ($json['error'] ?? ''),
        'data' => $json['data'] ?? null
    ];
}
