<?php

declare(strict_types=1);

namespace Drupal\cybersource_rest\Exception;

/**
 * Wraps a failed Cybersource REST API call (transport / HTTP error).
 *
 * Decouples the gateway from the SDK's own \CyberSource\ApiException and carries
 * the parsed reason/message so callers can log and map to a Commerce exception
 * without re-parsing the SDK response body.
 */
final class CybersourceApiException extends \RuntimeException {

  /**
   * Constructs a CybersourceApiException.
   *
   * @param string $message
   *   The exception message (safe for logs; not shown verbatim to customers).
   * @param int $httpStatus
   *   The HTTP status code, or 0 if unknown.
   * @param string $reason
   *   The Cybersource error "reason" code, if any (e.g. PROCESSOR_DECLINED).
   * @param \Throwable|null $previous
   *   The underlying SDK exception.
   */
  public function __construct(
    string $message,
    protected int $httpStatus = 0,
    protected string $reason = '',
    ?\Throwable $previous = NULL,
  ) {
    parent::__construct($message, 0, $previous);
  }

  /**
   * The HTTP status code (0 if unknown).
   */
  public function getHttpStatus(): int {
    return $this->httpStatus;
  }

  /**
   * The Cybersource error reason code ('' if none).
   */
  public function getReason(): string {
    return $this->reason;
  }

}
