<?php

namespace Tests\Feature;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * @internal
 */
final class PingTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    public function testPingRespondeJsonExacto(): void
    {
        $result = $this->get('api/v1/ping');

        $result->assertStatus(200);
        $result->assertJSONExact(['data' => ['pong' => true]]);
        $result->assertHeader('Content-Type', 'application/json; charset=UTF-8');
    }
}
