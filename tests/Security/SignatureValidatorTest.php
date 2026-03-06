<?php

namespace Tests\Security;

use App\Security\SignatureValidator;
use PHPUnit\Framework\TestCase;

class SignatureValidatorTest extends TestCase
{
    public function testValidSignature(): void
    {
        $secret = 'mysecret';
        $timestamp = time();
        $sig = SignatureValidator::createSignature($timestamp, $secret);

        $this->assertTrue(SignatureValidator::isValid((string) $timestamp, $sig, $secret, 300));
    }

    public function testInvalidSignature(): void
    {
        $secret = 'mysecret';
        $timestamp = time();
        $sig = 'totally_invalid';

        $this->assertFalse(SignatureValidator::isValid((string) $timestamp, $sig, $secret, 300));
    }

    public function testOldTimestampRejected(): void
    {
        $secret = 'mysecret';
        $timestamp = time() - 1000; // plus vieux que 300 s
        $sig = SignatureValidator::createSignature($timestamp, $secret);

        $this->assertFalse(SignatureValidator::isValid((string) $timestamp, $sig, $secret, 300));
    }
} 