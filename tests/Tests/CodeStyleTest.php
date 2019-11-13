<?php

namespace Loco\Tests\Utils\Swizzle;

/**
 * Tests PSR-2 code style of all files.
 *
 * @group meta
 */
class CodeStyleTest extends \PHPUnit\Framework\TestCase
{
    /**
     * Find path to php-cs-fixer executable
     * @return string
     */
    private function findLinter()
    {
        foreach (['cs-fixer','php-cs-fixer','php-cs-fixer.phar'] as $name) {
            if ($command = rtrim(shell_exec('which '.$name))) {
                return $command;
            }
        }
        $this->markTestSkipped('Install php-cs-fixer or run with --exclude-group meta');
    }

    public function testCodeStyleLinterPasses()
    {
        $executable = $this->findLinter();
        foreach (['src','tests','example'] as $name) {
            $path = realpath(__DIR__.'/../../'.$name);
            $this->assertTrue(is_dir($path), 'Failed to find "'.$name.'" directory');
            $command = $executable.' fix '.escapeshellarg($path).' --dry-run --quiet';
            exec($command, $output, $code);
            $this->assertSame(0, $code, 'Invalid PSR-2: fix with `'.basename($executable).' fix '.$name.'`');
        }
    }
}
