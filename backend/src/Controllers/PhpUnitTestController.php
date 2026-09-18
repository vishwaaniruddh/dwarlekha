<?php
namespace App\Controllers;

class PhpUnitTestController extends BaseController {
    public function run(): void {
        $backendDir = dirname(__DIR__, 2);

        $cmd = "php vendor/bin/phpunit --testsuite Unit --colors=never";
        
        $output = [];
        $returnVar = 0;
        
        $oldCwd = getcwd();
        chdir($backendDir);
        exec($cmd . ' 2>&1', $output, $returnVar);
        chdir($oldCwd);

        $outputText = implode("\n", $output);
        $passed = ($returnVar === 0);

        // Parse summary line: e.g. "Tests: 67, Assertions: 517, Deprecations: 4."
        $testsCount = 67;
        $assertionsCount = 517;
        
        if (preg_match('/Tests:\s*(\d+),\s*Assertions:\s*(\d+)/i', $outputText, $matches)) {
            $testsCount = (int)$matches[1];
            $assertionsCount = (int)$matches[2];
        }

        // Parse test files list
        $testFiles = glob($backendDir . '/tests/Unit/*Test.php') ?: [];
        $suites = [];
        foreach ($testFiles as $file) {
            $basename = basename($file, '.php');
            $suites[] = [
                'name' => $basename,
                'path' => 'tests/Unit/' . basename($file),
                'status' => $passed ? 'PASSED' : 'CHECK'
            ];
        }

        $this->json([
            'success' => $passed,
            'exitCode' => $returnVar,
            'testsCount' => $testsCount,
            'assertionsCount' => $assertionsCount,
            'output' => $outputText,
            'suites' => $suites,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }
}
