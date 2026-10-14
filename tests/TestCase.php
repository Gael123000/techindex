<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Concerns\InteractsWithTechIndex;

/**
 * Chaque test s'exécute dans une transaction annulée à la fin.
 */
abstract class TestCase extends BaseTestCase
{
    use InteractsWithTechIndex, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTechIndex();
    }
}
