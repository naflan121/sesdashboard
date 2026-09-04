<?php


namespace App\Controller;


use App\Entity\Project;
use App\Repository\EmailRepository;
use App\Security\SnsRequestValidator;
use App\Utils\WebHookProcessor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class WebHookController extends BaseController
{
    /**
     * @Route("/webhook/{token}", name="app_webhook", methods={"POST"})
     */
    public function index(Project $project,
                          Request $request,
                          EntityManagerInterface $em,
                          EmailRepository $emailRepository,
                          WebHookProcessor $processor,
                          HttpClientInterface $httpClient,
                          SnsRequestValidator $snsValidator)
    {

        $jsonData = json_decode($request->getContent(), true);

        // json_decode() returns null on a malformed body, and a scalar for valid-but-useless
        // JSON such as `"x"` or `5`.
        if (!is_array($jsonData)) {
            return new Response('Error', Response::HTTP_BAD_REQUEST);
        }

        // An SNS envelope is only present when "raw message delivery" is disabled; that is
        // also the only case in which there is a signature to verify.
        $isSnsEnvelope = isset($jsonData['SignatureVersion'], $jsonData['Signature']);

        if ($isSnsEnvelope && !$snsValidator->isValid($jsonData)) {
            return new Response('Invalid SNS signature', Response::HTTP_FORBIDDEN);
        }

        // Auto subscribe to SNS topic.
        if (!empty($jsonData['Type']) && $jsonData['Type'] == 'SubscriptionConfirmation') {
            $response = $httpClient->request(
                'GET',
                $jsonData['SubscribeURL']
            );
            if ($response->getStatusCode() == Response::HTTP_OK) {
                return new Response('Ok');
            }
            return new Response('Not Ok', Response::HTTP_BAD_REQUEST);
        }

        // Unwrap the envelope: without raw message delivery the SES event arrives as a
        // JSON string inside the SNS "Message" field.
        if (isset($jsonData['Message']) && is_string($jsonData['Message'])) {
            $jsonData = json_decode($jsonData['Message'], true);

            if (!is_array($jsonData)) {
                return new Response('Error', Response::HTTP_BAD_REQUEST);
            }
        }

        if (empty($jsonData['mail']['messageId']) || empty($jsonData['mail']['timestamp'])) {
            return new Response('Unexpected payload', Response::HTTP_BAD_REQUEST);
        }

        // Process mail.
        // Try to find mail.
        $email = $emailRepository->findOneBy([
            'project' => $project,
            'messageId' => $jsonData['mail']['messageId'],
        ]);

        // Create new mail.
        if (!$email) {
            $email = $processor->createEmailFromJson($jsonData);
            $email->setProject($project);
            $em->persist($email);
        }

        try {
            $emailEvent = $processor->createEvent($email, $jsonData);
        } catch (\Exception $e) {
            return new Response($e->getMessage(), Response::HTTP_BAD_REQUEST);
        }

        $em->persist($emailEvent);

        $em->flush();

        return new Response('Ok');
    }
}
