<?php

namespace Tests;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Concerns\InteractsWithTechIndex;

/**
 * Pour les tests qui ont besoin de données réellement validées en base :
 * InnoDB ne met à jour les index FULLTEXT qu'au COMMIT, une recherche plein
 * texte ne voit donc pas les lignes d'une transaction de test. Les tables
 * sont vidées avant et après chaque test.
 */
abstract class CommittedDataTestCase extends BaseTestCase
{
    use DatabaseTruncation, InteractsWithTechIndex;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTechIndex();
    }

    protected function tearDown(): void
    {
        $this->truncateDatabaseTables();

        parent::tearDown();
    }
}
