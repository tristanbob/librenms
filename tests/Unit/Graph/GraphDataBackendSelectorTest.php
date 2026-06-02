<?php

namespace LibreNMS\Tests\Unit\Graph;

use App\Facades\LibrenmsConfig;
use LibreNMS\Graph\GraphDataBackendSelector;
use LibreNMS\Graph\GraphDataProvider;
use LibreNMS\Graph\GraphDataResult;
use LibreNMS\Graph\GraphQuery;
use LibreNMS\Tests\TestCase;

final class GraphDataBackendSelectorTest extends TestCase
{
    private GraphQuery $query;

    protected function setUp(): void
    {
        parent::setUp();
        $this->query = GraphQuery::fromRequest(
            'device', 'device_poller_perf', ['device_id' => 1],
            time() - 3600, time()
        );
    }

    public function testDelegatesToRrdWhenQueryDisabled(): void
    {
        LibrenmsConfig::set('victoriametrics.query_enabled', false);

        $rrdResult = $this->makeResult('rrd');
        $rrd = $this->makeProvider($rrdResult);
        $vm = $this->makeSpy(); // must NOT be called

        $selector = new GraphDataBackendSelector($rrd, $vm);
        $result = $selector->query($this->query);

        $this->assertSame($rrdResult, $result);
        $this->assertFalse($vm->wasCalled(), 'VM provider must not be called when query_enabled is false');
    }

    public function testDelegatesToVmWhenQueryEnabled(): void
    {
        LibrenmsConfig::set('victoriametrics.query_enabled', true);

        $vmResult = $this->makeResult('victoriametrics');
        $rrd = $this->makeProvider($this->makeResult('rrd'));
        $vm = $this->makeProvider($vmResult);

        $selector = new GraphDataBackendSelector($rrd, $vm);
        $result = $selector->query($this->query);

        $this->assertSame($vmResult, $result);
    }

    public function testFallsBackToRrdOnVmFailure(): void
    {
        LibrenmsConfig::set('victoriametrics.query_enabled', true);

        $rrdResult = $this->makeResult('rrd');
        $rrd = $this->makeProvider($rrdResult);
        $vm = $this->makeProvider(null, new \RuntimeException('VM unavailable'));

        $selector = new GraphDataBackendSelector($rrd, $vm);
        $result = $selector->query($this->query);

        $this->assertSame($rrdResult, $result);

        $arr = $result->toArray();
        $this->assertTrue($arr['graph']['meta']['fallback_used']);
        $this->assertNotEmpty($arr['graph']['meta']['warnings']);
    }

    private function makeResult(string $source): GraphDataResult
    {
        $r = new GraphDataResult('id', 'type', 'title', 'subtitle', 'unit', time() - 3600, time(), 300);
        $r->setSource($source);

        return $r;
    }

    private function makeProvider(?GraphDataResult $result, ?\RuntimeException $throws = null): GraphDataProvider
    {
        return new class($result, $throws) implements GraphDataProvider {
            public function __construct(
                private readonly ?GraphDataResult $result,
                private readonly ?\RuntimeException $throws,
            ) {
            }

            public function query(GraphQuery $query): GraphDataResult
            {
                if ($this->throws !== null) {
                    throw $this->throws;
                }

                return $this->result;
            }
        };
    }

    /** Returns a spy that records whether it was called. */
    private function makeSpy(): object
    {
        return new class implements GraphDataProvider {
            private bool $called = false;

            public function query(GraphQuery $query): GraphDataResult
            {
                $this->called = true;
                throw new \LogicException('Spy provider should not have been called.');
            }

            public function wasCalled(): bool
            {
                return $this->called;
            }
        };
    }
}
