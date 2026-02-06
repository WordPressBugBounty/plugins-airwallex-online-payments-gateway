<?php declare(strict_types=1);

namespace Tests\Logger;

use Airwallex\PayappsPlugin\CommonLibrary\Configuration\Init;
use Airwallex\PayappsPlugin\CommonLibrary\Logger\FileLogger;
use PHPUnit\Framework\TestCase;

final class FileLoggerTest extends TestCase
{
    private $testLogDir;

    protected function setUp(): void
    {
        $this->testLogDir = sys_get_temp_dir() . '/airwallex_test_logs_' . uniqid() . '/';

        if (!is_dir($this->testLogDir)) {
            mkdir($this->testLogDir, 0755, true);
        }

        Init::getInstance()->updateConfig(['log_dir' => $this->testLogDir]);
    }

    protected function tearDown(): void
    {
        $this->cleanupTestDirectory();
    }

    private function cleanupTestDirectory()
    {
        if (is_dir($this->testLogDir)) {
            $files = array_diff(scandir($this->testLogDir), ['.', '..']);
            foreach ($files as $file) {
                unlink($this->testLogDir . $file);
            }
            rmdir($this->testLogDir);
        }
    }

    public function testSingletonPattern()
    {
        $instance1 = FileLogger::getInstance();
        $instance2 = FileLogger::getInstance();

        $this->assertSame($instance1, $instance2);
        $this->assertInstanceOf(FileLogger::class, $instance1);
    }

    public function testLogInfo()
    {
        $logger = FileLogger::getInstance();
        $message = 'Test info message';
        $context = ['user_id' => 123, 'action' => 'login'];

        $logger->logInfo($message, $context);

        $this->assertLogFileContains('info', $message, $context);
    }

    public function testLogError()
    {
        $logger = FileLogger::getInstance();
        $message = 'Test error message';
        $context = ['error_code' => 500, 'details' => 'Database connection failed'];

        $logger->logError($message, $context);

        $this->assertLogFileContains('error', $message, $context);
    }

    public function testLogWarning()
    {
        $logger = FileLogger::getInstance();
        $message = 'Test warning message';
        $context = ['warning_type' => 'deprecated_function'];

        $logger->logWarning($message, $context);

        $this->assertLogFileContains('warning', $message, $context);
    }

    public function testLogDebug()
    {
        $logger = FileLogger::getInstance();
        $message = 'Test debug message';
        $context = ['debug_info' => 'Variable state', 'value' => 42];

        $logger->logDebug($message, $context);

        $this->assertLogFileContains('debug', $message, $context);
    }

    public function testStaticInfoMethod()
    {
        $message = 'Static info test';
        $context = ['static' => true];

        FileLogger::info($message, $context);

        $this->assertLogFileContains('info', $message, $context);
    }

    public function testStaticErrorMethod()
    {
        $message = 'Static error test';
        $context = ['static' => true, 'level' => 'error'];

        FileLogger::error($message, $context);

        $this->assertLogFileContains('error', $message, $context);
    }

    public function testStaticWarningMethod()
    {
        $message = 'Static warning test';
        $context = ['static' => true, 'level' => 'warning'];

        FileLogger::warning($message, $context);

        $this->assertLogFileContains('warning', $message, $context);
    }

    public function testStaticDebugMethod()
    {
        $message = 'Static debug test';
        $context = ['static' => true, 'level' => 'debug'];

        FileLogger::debug($message, $context);

        $this->assertLogFileContains('debug', $message, $context);
    }

    public function testLogWithEmptyContext()
    {
        $message = 'Message without context';

        FileLogger::info($message);

        $this->assertLogFileContains('info', $message, []);
    }

    public function testLogWithComplexContext()
    {
        $message = 'Complex context test';
        $context = [
            'user' => [
                'id' => 123,
                'name' => 'John Doe',
                'roles' => ['admin', 'user']
            ],
            'request' => [
                'method' => 'POST',
                'url' => '/api/test',
                'data' => ['key' => 'value']
            ],
            'unicode' => 'Test with unicode: 测试中文',
            'special_chars' => 'Special chars: !@#$%^&*()'
        ];

        FileLogger::info($message, $context);

        $this->assertLogFileContains('info', $message, $context);
    }

    public function testMultipleLevelsCreateSeparateFiles()
    {
        FileLogger::info('Info message');
        FileLogger::error('Error message');
        FileLogger::warning('Warning message');
        FileLogger::debug('Debug message');

        $date = date('Y-m-d');

        $this->assertFileExists($this->testLogDir . "airwallex_info_{$date}.log");
        $this->assertFileExists($this->testLogDir . "airwallex_error_{$date}.log");
        $this->assertFileExists($this->testLogDir . "airwallex_warning_{$date}.log");
        $this->assertFileExists($this->testLogDir . "airwallex_debug_{$date}.log");
    }

    private function assertLogFileContains(string $level, string $message, array $context)
    {
        $date = date('Y-m-d');
        $expectedFile = $this->testLogDir . "airwallex_{$level}_{$date}.log";

        $this->assertFileExists($expectedFile);

        $content = file_get_contents($expectedFile);
        $this->assertContains($message, $content);
        $this->assertContains(strtoupper($level), $content);

        if (!empty($context)) {
            $expectedJson = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $this->assertContains($expectedJson, $content);
        }
    }
}
