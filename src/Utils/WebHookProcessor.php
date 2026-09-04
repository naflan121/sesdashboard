<?php


namespace App\Utils;


use App\Entity\Email;
use App\Entity\EmailEvent;

class WebHookProcessor
{
    private const EMAIL_SUBJECT_NA = 'N/A';

    public function createEmailFromJson($jsonData): Email
    {
        $email = (new Email())
            ->setMessageId($jsonData['mail']['messageId'])
            ->setDestination($jsonData['mail']['destination'] ?? [])
            ->setSource($jsonData['mail']['source'] ?? '')
            ->setSubject($jsonData['mail']['commonHeaders']['subject'] ?? self::EMAIL_SUBJECT_NA)
            ->setTimestamp(new \DateTime($jsonData['mail']['timestamp']))
            ->setStatus(Email::EMAIL_STATUS_SENT);

        $this->setEmailTags($email, $jsonData);

        return $email;
    }

    /**
     * Copy the SES message tags onto the Email.
     *
     * Tags are only published when the message went through a configuration set, and
     * not every event in a message's lifecycle carries them, so each value is optional
     * and only ever written once.
     */
    private function setEmailTags(Email $email, array $jsonData): void
    {
        $tags = $jsonData['mail']['tags'] ?? [];

        if ($email->getConfigurationSet() === null) {
            $email->setConfigurationSet(self::firstTag($tags, 'ses:configuration-set'));
        }

        if ($email->getSourceIp() === null) {
            $email->setSourceIp(self::firstTag($tags, 'ses:source-ip'));
        }

        if ($email->getFromDomain() === null) {
            $email->setFromDomain(self::firstTag($tags, 'ses:from-domain'));
        }
    }

    /**
     * SES publishes every tag as a list of values; we only ever want the first.
     */
    private static function firstTag(array $tags, string $name): ?string
    {
        $value = $tags[$name][0] ?? null;

        return ($value === null || $value === '') ? null : (string) $value;
    }

    public function createEmailEventFromJson(Email $email, $jsonData, $event): EmailEvent
    {
        $type = self::getEventType($jsonData);

        $emailEvent = (new EmailEvent($email))
            ->setEvent($type)
            ->setEventData($jsonData[$event] ?? []);

        if (!empty($jsonData[$event]['timestamp'])) {
            $emailEvent->setTimestamp(new \DateTime($jsonData[$event]['timestamp']));
        }
        // Some events don't have an event timestamp. Try to extract date from email object.
        else if (!empty($jsonData['mail']['timestamp'])) {
            $emailEvent->setTimestamp(new \DateTime($jsonData['mail']['timestamp']));
        }
        // Use current timestamp otherwise.
        else {
            $emailEvent->setTimestamp(new \DateTime());
        }

        return $emailEvent;
    }

    public function createEvent(Email $email, $jsonData): EmailEvent
    {
        $type = self::getEventType($jsonData);

        // Try to set Email Subject if empty
        $this->setEmailSubject($email, $jsonData);

        // Backfill tags — the event that created the Email may not have carried them.
        $this->setEmailTags($email, $jsonData);

        switch ($type) {
            case 'Send':
                $emailEvent = $this->createEmailEventFromJson($email, $jsonData, EmailEvent::EVENT_SEND);
                break;

            case 'Delivery':
                $email->setStatus(Email::EMAIL_STATUS_DELIVERED);
                $emailEvent = $this->createEmailEventFromJson($email, $jsonData, EmailEvent::EVENT_DELIVERY);
                break;

            case 'Reject':
                $email->setStatus(Email::EMAIL_STATUS_NOT_DELIVERED);
                $emailEvent = $this->createEmailEventFromJson($email, $jsonData, EmailEvent::EVENT_REJECT);
                break;

            case 'Bounce':
                $email->setStatus(Email::EMAIL_STATUS_NOT_DELIVERED);
                $emailEvent = $this->createEmailEventFromJson($email, $jsonData, EmailEvent::EVENT_BOUNCE);
                break;

            case 'Complaint':
                $email->setStatus(Email::EMAIL_STATUS_DELIVERED);
                $emailEvent = $this->createEmailEventFromJson($email, $jsonData, EmailEvent::EVENT_COMPLAINT);
                break;

            case 'Rendering Failure':
                $email->setStatus(Email::EMAIL_STATUS_NOT_DELIVERED);
                $emailEvent = $this->createEmailEventFromJson($email, $jsonData, EmailEvent::EVENT_FAILURE);
                break;

            case 'Open':
                $email->increaseOpens();
                $emailEvent = $this->createEmailEventFromJson($email, $jsonData, EmailEvent::EVENT_OPEN);
                break;

            case 'Click':
                $email->increaseClicks();
                $emailEvent = $this->createEmailEventFromJson($email, $jsonData, EmailEvent::EVENT_CLICK);
                break;

            default:
                throw new \Exception('Unexpected value');
        }

        return $emailEvent;
    }

    /**
     * @param array $jsonData
     * @return mixed
     * @throws \Exception
     */
    public static function getEventType(array $jsonData)
    {
        if (!empty($jsonData['eventType'])) {
            return $jsonData['eventType'];
        }

        else if (!empty($jsonData['notificationType'])) {
            return $jsonData['notificationType'];
        }

        throw new \Exception('Unexpected value');
    }

    private function setEmailSubject(Email $email, array $jsonData): void
    {
        if ($email->getSubject() === null || $email->getSubject() === self::EMAIL_SUBJECT_NA) {
            $email->setSubject($jsonData['mail']['commonHeaders']['subject'] ?? self::EMAIL_SUBJECT_NA);
        }
    }
}
