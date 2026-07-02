<?php

declare(strict_types=1);

namespace Drupal\Tests\cybersource_rest\Unit;

use Drupal\cybersource_rest\TransientToken;
use Drupal\Tests\UnitTestCase;

/**
 * Tests decoding a Flex Microform transient token.
 *
 * @coversDefaultClass \Drupal\cybersource_rest\TransientToken
 * @group cybersource_rest
 */
class TransientTokenTest extends UnitTestCase {

  /**
   * Build a fake transient-token JWT carrying the given card payload.
   *
   * @param array<string, mixed> $card
   *   The card sub-array (number, expirationMonth, expirationYear).
   *
   * @return string
   *   A header.payload.signature JWT (signature is irrelevant to decoding).
   */
  protected static function jwt(array $card): string {
    $payload = ['content' => ['paymentInformation' => ['card' => $card]]];
    $encode = static fn (array $d): string => rtrim(strtr(base64_encode(json_encode($d)), '+/', '-_'), '=');
    return $encode(['alg' => 'RS256']) . '.' . $encode($payload) . '.sig';
  }

  /**
   * A well-formed token exposes the masked number, BIN, expiry and last 4.
   *
   * @covers ::fromJwt
   * @covers ::last4
   */
  public function testValidToken(): void {
    $token = TransientToken::fromJwt(self::jwt([
      'number' => ['maskedValue' => '411111XXXXXX1111', 'bin' => '411111'],
      'expirationMonth' => ['value' => '12'],
      'expirationYear' => ['value' => '2031'],
    ]));
    $this->assertSame('411111XXXXXX1111', $token->maskedNumber);
    $this->assertSame('411111', $token->bin);
    $this->assertSame('12', $token->expirationMonth);
    $this->assertSame('2031', $token->expirationYear);
    $this->assertSame('1111', $token->last4());
    // The JWT is preserved verbatim for the payments API.
    $this->assertStringContainsString('.', $token->jwt);
  }

  /**
   * A token that is not three dot-separated parts is rejected.
   *
   * @covers ::fromJwt
   */
  public function testMalformedJwtRejected(): void {
    $this->expectException(\InvalidArgumentException::class);
    TransientToken::fromJwt('not-a-jwt');
  }

  /**
   * A token whose payload carries no card information is rejected.
   *
   * @covers ::fromJwt
   */
  public function testMissingCardRejected(): void {
    $encode = static fn (array $d): string => rtrim(strtr(base64_encode(json_encode($d)), '+/', '-_'), '=');
    $jwt = $encode(['alg' => 'RS256']) . '.' . $encode(['content' => []]) . '.sig';
    $this->expectException(\InvalidArgumentException::class);
    TransientToken::fromJwt($jwt);
  }

  /**
   * A token with a card but no masked number is rejected.
   *
   * @covers ::fromJwt
   */
  public function testMissingMaskedNumberRejected(): void {
    $this->expectException(\InvalidArgumentException::class);
    TransientToken::fromJwt(self::jwt([
      'expirationMonth' => ['value' => '12'],
      'expirationYear' => ['value' => '2031'],
    ]));
  }

}
