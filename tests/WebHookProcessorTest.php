<?php

namespace App\Tests;

use App\Entity\Email;
use App\Entity\EmailEvent;
use App\Utils\WebHookProcessor;
use PHPUnit\Framework\TestCase;

class WebHookProcessorTest extends TestCase
{
    public function testCreateEmail()
    {
        $webHookProcessor = new WebHookProcessor();

        // The payload formats this with a literal "Z", so it has to be built in UTC or
        // the assertions only pass on a UTC machine.
        $date = new \DateTime('now', new \DateTimeZone('UTC'));

        $email = $webHookProcessor->createEmailFromJson(
            [
                'mail' => [
                    'messageId' => '1a',
                    'destination' => ['test@test.com'],
                    'source' => 'site@site.com',
                    'timestamp' => $date->format('Y-m-d\TH:i:s.u\Z'),
                    'commonHeaders' => [
                        'subject' => 'Test',
                    ],
                ],
            ]
        );

        $this->assertSame($email->getMessageId(), '1a', 'Message ID is incorrect');
        $this->assertSame($email->getDestination(), ['test@test.com'], 'Email Destination is incorrect');
        $this->assertSame($email->getSource(), 'site@site.com', 'Email source is incorrect');
        $this->assertEquals($email->getSubject(), 'Test', 'Email subject is incorrect');
        $this->assertEquals($email->getTimestamp(), $date, 'Email dates parsed wrong');
        $this->assertSame($email->getStatus(), Email::EMAIL_STATUS_SENT, 'Email status should be Sent');
    }

    public function testCreateEmailWithoutTags()
    {
        $email = (new WebHookProcessor())->createEmailFromJson($this->mailPayload());

        $this->assertNull($email->getConfigurationSet(), 'Configuration set should be null when SES sends no tags');
        $this->assertNull($email->getSourceIp(), 'Source IP should be null when SES sends no tags');
        $this->assertNull($email->getFromDomain(), 'From domain should be null when SES sends no tags');
    }

    public function testCreateEmailWithTags()
    {
        $payload = $this->mailPayload();
        $payload['mail']['tags'] = [
            'ses:configuration-set' => ['my-config-set'],
            'ses:source-ip' => ['203.0.113.10'],
            'ses:from-domain' => ['example.com'],
        ];

        $email = (new WebHookProcessor())->createEmailFromJson($payload);

        $this->assertSame('my-config-set', $email->getConfigurationSet());
        $this->assertSame('203.0.113.10', $email->getSourceIp());
        $this->assertSame('example.com', $email->getFromDomain());
    }

    public function testTagsAreBackfilledByLaterEvents()
    {
        $webHookProcessor = new WebHookProcessor();

        // The Send event arrives without tags...
        $email = $webHookProcessor->createEmailFromJson($this->mailPayload());
        $this->assertNull($email->getFromDomain());

        // ...and a later Delivery event carries them.
        $payload = $this->mailPayload();
        $payload['eventType'] = 'Delivery';
        $payload['mail']['tags'] = ['ses:from-domain' => ['example.com']];

        $webHookProcessor->createEvent($email, $payload);

        $this->assertSame('example.com', $email->getFromDomain(), 'Tags should be backfilled from later events');
    }

    private function mailPayload(): array
    {
        return [
            'mail' => [
                'messageId' => '1a',
                'destination' => ['test@test.com'],
                'source' => 'site@site.com',
                'timestamp' => (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z'),
                'commonHeaders' => [
                    'subject' => 'Test',
                ],
            ],
        ];
    }

    public function testEventTypeGetter()
    {
        $type = WebHookProcessor::getEventType([
            'eventType' => 'Send'
        ]);
        $this->assertEquals($type, 'Send');

        $type = WebHookProcessor::getEventType([
            'notificationType' => 'Send'
        ]);
        $this->assertEquals($type, 'Send');


        $this->expectException(\Exception::class);
        WebHookProcessor::getEventType([
            'oops' => 'Send'
        ]);
    }

    public function testCreateEvent()
    {
        $email = new Email();

        $webHookProcessor = new WebHookProcessor();

        $date = new \DateTime('now', new \DateTimeZone('UTC'));

        $emailEvent = $webHookProcessor->createEvent($email, [
            'eventType' => 'Send',
            'send' => [
                'timestamp' => $date->format('Y-m-d\TH:i:s.u\Z'),
            ]
        ]);

        $this->assertEquals(EmailEvent::EVENT_SEND, $emailEvent->getEvent(), 'Event type is wrong');
        $this->assertEquals($emailEvent->getTimestamp(), $date, 'Email event date parsed wrong');
    }
}
