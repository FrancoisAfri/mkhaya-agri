<?php

namespace Tests\Feature\Warehouse;

use PHPUnit\Framework\TestCase;

/** The QA matrix is complete and every test it cites exists (Part D §31). */
class QaMatrixTest extends TestCase
{
    public function test_every_case_is_present_and_every_cited_test_exists(): void
    {
        $cases = QaMatrix::cases();
        $this->assertSame(array_map(fn ($n) => sprintf('QA-%03d', $n), range(1, 28)), array_keys($cases));
        foreach ($cases as $id => $c) {
            $this->assertNotEmpty($c['tests'], "{$id} cites no test");
            foreach (['scenario', 'required', 'setup', 'steps'] as $k) {
                $this->assertNotSame('', trim($c[$k]), "{$id} has no {$k}");
            }
            foreach ($c['tests'] as $t) {
                [$class, $method] = explode('::', $t);
                $this->assertTrue(method_exists($class, $method), "{$id} cites {$t}, which does not exist");
            }
        }
    }
}
