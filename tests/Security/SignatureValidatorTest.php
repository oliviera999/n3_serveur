<?php

declare(strict_types=1);

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

    public function testFutureTimestampOutsideWindowRejected(): void
    {
        $secret = 'mysecret';
        $timestamp = time() + 1000;
        $sig = SignatureValidator::createSignature($timestamp, $secret);

        $this->assertFalse(SignatureValidator::isValid((string) $timestamp, $sig, $secret, 300));
    }

    public function testNonNumericTimestampRejected(): void
    {
        $sig = SignatureValidator::createSignature(123456789, 'mysecret');
        $this->assertFalse(SignatureValidator::isValid('not-a-number', $sig, 'mysecret', 300));
    }

    public function testValidSignatureWithNonce(): void
    {
        $secret = 'mysecret';
        $timestamp = time();
        $nonce = 'post-id-abc-123';
        $sig = SignatureValidator::createSignatureWithNonce($timestamp, $nonce, $secret);

        $this->assertTrue(SignatureValidator::isValidWithNonce(
            (string) $timestamp,
            $nonce,
            $sig,
            $secret,
            300
        ));
    }

    public function testNonceTamperedRejected(): void
    {
        $secret = 'mysecret';
        $timestamp = time();
        $sig = SignatureValidator::createSignatureWithNonce($timestamp, 'nonce-A', $secret);

        // Si quelqu'un essaie de rejouer la signature avec un autre nonce, refusee.
        $this->assertFalse(SignatureValidator::isValidWithNonce(
            (string) $timestamp,
            'nonce-B',
            $sig,
            $secret,
            300
        ));
    }

    public function testEmptyNonceRejected(): void
    {
        $secret = 'mysecret';
        $timestamp = time();
        $sig = SignatureValidator::createSignatureWithNonce($timestamp, 'whatever', $secret);
        $this->assertFalse(SignatureValidator::isValidWithNonce(
            (string) $timestamp,
            '',
            $sig,
            $secret,
            300
        ));
    }

    public function testNonceSignatureIsDifferentFromNoNonce(): void
    {
        // Garantie qu'on ne peut pas reutiliser une signature sans-nonce pour la variante avec-nonce.
        $secret = 'mysecret';
        $timestamp = time();
        $nonce = 'X';

        $sigNoNonce = SignatureValidator::createSignature($timestamp, $secret);
        $sigWithNonce = SignatureValidator::createSignatureWithNonce($timestamp, $nonce, $secret);
        $this->assertNotSame($sigNoNonce, $sigWithNonce);

        $this->assertFalse(SignatureValidator::isValidWithNonce(
            (string) $timestamp,
            $nonce,
            $sigNoNonce,
            $secret,
            300
        ));
    }
}
