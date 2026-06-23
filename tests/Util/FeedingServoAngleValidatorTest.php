<?php

declare(strict_types=1);

namespace Tests\Util;

use App\Util\FeedingServoAngleValidator;
use PHPUnit\Framework\TestCase;

class FeedingServoAngleValidatorTest extends TestCase
{
    public function testAcceptsValidAngles(): void
    {
        FeedingServoAngleValidator::assertAngleRanges([
            'angleReposGros' => 88,
            'angleDistribPetits' => 140,
        ]);
        $this->addToAssertionCount(1);
    }

    public function testRejectsOutOfRange(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FeedingServoAngleValidator::assertAngleRanges(['angleInterGros' => 200]);
    }

    public function testRejectsNonNumeric(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FeedingServoAngleValidator::assertAngleRanges(['angleReposPetits' => 'abc']);
    }
}
