<?php

declare(strict_types=1);

namespace Drupal\cybersource_rest;

/**
 * A Cybersource Flex Microform transient token.
 *
 * The Microform JS returns a single-use JWT (valid ~15 min) that REPRESENTS the
 * card the customer typed into Cybersource's hosted iframes — the PAN/CVV never
 * reach this server. We decode the (unencrypted) JWS payload only to read the
 * non-sensitive display metadata Commerce needs for the saved payment method
 * (masked number, BIN for brand detection, expiry).
 *
 * SECURITY: we deliberately do NOT treat the decoded payload as trusted. The
 * authoritative validation of the token happens at Cybersource when the payment
 * is created (a tampered/expired token is rejected there), and the charged
 * amount/currency come from the order, never from the token. So these fields
 * are
 * used for display only and never drive an authorization or amount decision.
 */
final class TransientToken {

  /**
   * Constructs a TransientToken.
   *
   * @param string $jwt
   *   The raw transient-token JWT, passed verbatim to the payments API.
   * @param string $maskedNumber
   *   The masked card number (e.g. "411111XXXXXX1111").
   * @param string $bin
   *   The card BIN (first digits), used for brand detection.
   * @param string $expirationMonth
   *   Two-digit expiry month.
   * @param string $expirationYear
   *   Four-digit expiry year.
   * @param string $jti
   *   The JWT id claim — the token reference the RISK (payer authentication)
   *   endpoints expect in tokenInformation.transientToken (the payments
   *   endpoint takes the full JWT instead).
   */
  private function __construct(
    public readonly string $jwt,
    public readonly string $maskedNumber,
    public readonly string $bin,
    public readonly string $expirationMonth,
    public readonly string $expirationYear,
    public readonly string $jti,
  ) {}

  /**
   * Parse a transient-token JWT.
   *
   * @param string $jwt
   *   The transient-token JWT from the Microform JS.
   *
   * @return self
   *   The parsed token.
   *
   * @throws \InvalidArgumentException
   *   If the token is not a well-formed JWT carrying the expected card payload.
   */
  public static function fromJwt(string $jwt): self {
    $jwt = trim($jwt);
    $parts = explode('.', $jwt);
    if (count($parts) !== 3 || $parts[1] === '') {
      throw new \InvalidArgumentException('Transient token is not a well-formed JWT.');
    }
    $payload = json_decode(self::base64UrlDecode($parts[1]), TRUE);
    if (!is_array($payload)) {
      throw new \InvalidArgumentException('Transient token payload could not be decoded.');
    }
    $card = $payload['content']['paymentInformation']['card'] ?? NULL;
    if (!is_array($card)) {
      throw new \InvalidArgumentException('Transient token is missing card payment information.');
    }
    $masked = (string) ($card['number']['maskedValue'] ?? '');
    $bin = (string) ($card['number']['bin'] ?? '');
    if ($masked === '') {
      throw new \InvalidArgumentException('Transient token is missing the masked card number.');
    }
    return new self(
      $jwt,
      $masked,
      $bin,
      (string) ($card['expirationMonth']['value'] ?? ''),
      (string) ($card['expirationYear']['value'] ?? ''),
      (string) ($payload['jti'] ?? ''),
    );
  }

  /**
   * The last four digits of the card number.
   *
   * @return string
   *   The last four digits (from the masked value).
   */
  public function last4(): string {
    return substr($this->maskedNumber, -4);
  }

  /**
   * Decode a base64url-encoded string (JWT segment).
   *
   * @param string $data
   *   The base64url string.
   *
   * @return string
   *   The decoded bytes (empty string if invalid).
   */
  private static function base64UrlDecode(string $data): string {
    $remainder = strlen($data) % 4;
    if ($remainder) {
      $data .= str_repeat('=', 4 - $remainder);
    }
    return (string) base64_decode(strtr($data, '-_', '+/'), TRUE);
  }

}
