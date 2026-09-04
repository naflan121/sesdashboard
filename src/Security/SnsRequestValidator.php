<?php

namespace App\Security;

use Aws\Sns\Message;
use Aws\Sns\MessageValidator;
use Psr\Log\LoggerInterface;

/**
 * Verifies the cryptographic signature Amazon SNS attaches to every notification.
 *
 * The project token in the webhook URL is a shared secret: anyone who learns it can post
 * fabricated events. Signature verification closes that hole, but it can only work when
 * SNS delivers the full envelope — with "raw message delivery" enabled (which is what the
 * setup docs recommend) there is no signature to check. It is therefore opt-in via
 * SNS_VERIFY_SIGNATURE, and callers must skip it for raw deliveries.
 */
class SnsRequestValidator
{
    private bool $enabled;

    private LoggerInterface $logger;

    public function __construct(bool $enabled, LoggerInterface $logger)
    {
        $this->enabled = $enabled;
        $this->logger = $logger;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * @param array $payload The decoded SNS envelope, not the inner SES event.
     */
    public function isValid(array $payload): bool
    {
        if (!$this->enabled) {
            return true;
        }

        if (!class_exists(MessageValidator::class)) {
            $this->logger->error('SNS_VERIFY_SIGNATURE is enabled but aws/aws-php-sns-message-validator is not installed. Run "composer install".');

            return false;
        }

        try {
            // Build the Message from the already-decoded body: Message::fromRawPostData()
            // re-reads php://input, which Symfony has usually consumed by this point.
            (new MessageValidator())->validate(new Message($payload));
        } catch (\Throwable $e) {
            $this->logger->warning('Rejected SNS notification with an invalid signature: ' . $e->getMessage());

            return false;
        }

        return true;
    }
}
