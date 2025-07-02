<?php

namespace App\Plugins\Email\Service\Providers;

use App\Plugins\Email\Service\EmailProviderInterface;
use SendGrid;
use SendGrid\Mail\Mail;
use SendGrid\Mail\To;
use SendGrid\Mail\Personalization;
use Psr\Log\LoggerInterface;

class SendGridProvider implements EmailProviderInterface
{
    private ?string $apiKey;
    private ?SendGrid $client;
    private LoggerInterface $logger;
    private array $templateMap;
    
    public function __construct(
        string $apiKey,
        LoggerInterface $logger,
        array $templateMap = []
    ) {
        $this->apiKey = $apiKey;
        $this->logger = $logger;
        $this->templateMap = $templateMap;
        $this->client = !empty($apiKey) ? new SendGrid($apiKey) : null;
    }
    
    public function send(string $to, string $templateId, array $data = [], array $options = []): array
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('SendGrid is not properly configured');
        }
        
        try {
            $email = new Mail();
            
            // Set from address
            $fromEmail = $options['from'] ?? $_ENV['DEFAULT_FROM_EMAIL'] ?? 'apis@skedi.com';
            $fromName = $options['from_name'] ?? $_ENV['DEFAULT_FROM_NAME'] ?? 'Skedi';
            $email->setFrom($fromEmail, $fromName);
            
            // Set template ID (use mapping if available)
            $sendGridTemplateId = $this->templateMap[$templateId] ?? $templateId;
            $email->setTemplateId($sendGridTemplateId);
            
            // Create personalization
            $personalization = new Personalization();
            $personalization->addTo(new To($to, $options['to_name'] ?? null));
            
            // Add CC if provided
            if (!empty($options['cc'])) {
                foreach ((array)$options['cc'] as $cc) {
                    $personalization->addCc(new To($cc));
                }
            }
            
            // Add BCC if provided
            if (!empty($options['bcc'])) {
                foreach ((array)$options['bcc'] as $bcc) {
                    $personalization->addBcc(new To($bcc));
                }
            }
            
            // Add dynamic template data to personalization
            foreach ($data as $key => $value) {
                $personalization->addDynamicTemplateData($key, $value);
            }
            
            // Add personalization to email
            $email->addPersonalization($personalization);
            
            // Set reply-to if provided
            if (!empty($options['reply_to'])) {
                $email->setReplyTo($options['reply_to']);
            }
            
            // Add attachments if provided
            if (!empty($options['attachments'])) {
                foreach ($options['attachments'] as $attachment) {
                    $email->addAttachment(
                        $attachment['content'],
                        $attachment['type'],
                        $attachment['filename'],
                        $attachment['disposition'] ?? 'attachment',
                        $attachment['content_id'] ?? null
                    );
                }
            }
            
            // Log the data being sent for debugging
            $this->logger->info('Sending SendGrid email', [
                'to' => $to,
                'template' => $templateId,
                'sendgrid_template' => $sendGridTemplateId,
                'data' => $data
            ]);
            
            // Send the email
            $response = $this->client->send($email);
            
            $result = [
                'success' => $response->statusCode() >= 200 && $response->statusCode() < 300,
                'message_id' => $this->extractMessageId($response->headers()),
                'status_code' => $response->statusCode(),
                'provider' => $this->getName()
            ];
            
            if (!$result['success']) {
                $body = json_decode($response->body(), true);
                $errorMessage = '';
                
                if (isset($body['errors']) && is_array($body['errors'])) {
                    foreach ($body['errors'] as $error) {
                        $errorMessage .= $error['message'] . ' ';
                    }
                } else {
                    $errorMessage = $response->body();
                }
                
                $this->logger->error('SendGrid email failed', [
                    'to' => $to,
                    'template' => $templateId,
                    'sendgrid_template' => $sendGridTemplateId,
                    'status' => $response->statusCode(),
                    'body' => $response->body(),
                    'data' => $data
                ]);
                
                throw new \Exception("SendGrid error ({$response->statusCode()}): {$errorMessage}");
            }
            
            return $result;
            
        } catch (\Exception $e) {
            $this->logger->error('SendGrid email error', [
                'to' => $to,
                'template' => $templateId,
                'error' => $e->getMessage(),
                'data' => $data
            ]);
            
            throw $e;
        }
    }
    
    public function sendBulk(array $recipients, string $templateId, array $globalData = []): array
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('SendGrid is not properly configured');
        }
        
        try {
            $email = new Mail();
            
            // Set from address
            $fromEmail = $globalData['from'] ?? $_ENV['DEFAULT_FROM_EMAIL'] ?? 'noreply@skedi.com';
            $fromName = $globalData['from_name'] ?? $_ENV['DEFAULT_FROM_NAME'] ?? 'Skedi';
            $email->setFrom($fromEmail, $fromName);
            
            // Set template ID
            $sendGridTemplateId = $this->templateMap[$templateId] ?? $templateId;
            $email->setTemplateId($sendGridTemplateId);
            
            // Add personalizations for each recipient
            foreach ($recipients as $recipient) {
                $personalization = new Personalization();
                $personalization->addTo(new To(
                    $recipient['email'],
                    $recipient['name'] ?? null
                ));
                
                // Add dynamic template data
                $recipientData = array_merge($globalData, $recipient['data'] ?? []);
                foreach ($recipientData as $key => $value) {
                    $personalization->addDynamicTemplateData($key, $value);
                }
                
                $email->addPersonalization($personalization);
            }
            
            // Send the email
            $response = $this->client->send($email);
            
            return [
                'success' => $response->statusCode() >= 200 && $response->statusCode() < 300,
                'status_code' => $response->statusCode(),
                'provider' => $this->getName(),
                'recipient_count' => count($recipients)
            ];
            
        } catch (\Exception $e) {
            $this->logger->error('SendGrid bulk email error', [
                'template' => $templateId,
                'recipient_count' => count($recipients),
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }
    
    public function getName(): string
    {
        return 'sendgrid';
    }
    
    public function isConfigured(): bool
    {
        return !empty($this->apiKey) && $this->client !== null;
    }
    
    private function extractMessageId(array $headers): ?string
    {
        foreach ($headers as $header => $value) {
            if (strtolower($header) === 'x-message-id') {
                return is_array($value) ? $value[0] : $value;
            }
        }
        return null;
    }
}