<?php

namespace App\Plugins\Email\Service;

use App\Plugins\Email\Entity\EmailQueueEntity;
use App\Plugins\Email\Repository\EmailQueueRepository;
use App\Service\CrudManager;
use Doctrine\ORM\EntityManagerInterface;

class EmailQueueService
{
    private EntityManagerInterface $entityManager;
    private EmailQueueRepository $queueRepository;
    private CrudManager $crudManager;
    
    public function __construct(
        EntityManagerInterface $entityManager,
        EmailQueueRepository $queueRepository,
        CrudManager $crudManager
    ) {
        $this->entityManager = $entityManager;
        $this->queueRepository = $queueRepository;
        $this->crudManager = $crudManager;
    }
    
    /**
     * Add email to queue
     */
    public function add($to, string $template, array $data = [], array $options = []): int
    {
        $queueItem = new EmailQueueEntity();
        $queueItem->setTo(is_array($to) ? json_encode($to) : $to);
        $queueItem->setTemplate($template);
        $queueItem->setData($data);
        $queueItem->setOptions($options);
        $queueItem->setStatus('pending');
        $queueItem->setPriority($options['priority'] ?? 5);
        
        // Set scheduled time if provided
        if (isset($options['send_at'])) {
            $queueItem->setScheduledAt(new \DateTime($options['send_at']));
        }
        
        $this->entityManager->persist($queueItem);
        $this->entityManager->flush();
        
        return $queueItem->getId();
    }
    
    /**
     * Get pending emails from queue
     */
   public function getPending(int $limit = 50): array
    {
        // Using findMany instead of getMany
        $results = $this->crudManager->findMany(
            EmailQueueEntity::class,
            [], // filters
            1,  // page
            $limit, // limit
            [
                'status' => 'pending' // basic criteria
            ]
        );
        
        // Filter for pending or retry status
        return array_filter($results, function($item) {
            return in_array($item->getStatus(), ['pending', 'retry']);
        });
    }
    
    /**
     * Mark email as sent
     */
    public function markAsSent(EmailQueueEntity $queueItem, ?string $messageId = null): void
    {
        $queueItem->setStatus('sent');
        $queueItem->setSentAt(new \DateTime());
        $queueItem->setMessageId($messageId);
        $queueItem->setAttempts($queueItem->getAttempts() + 1);
        
        $this->entityManager->flush();
    }
    
    /**
     * Mark email as failed
     */
    public function markAsFailed(EmailQueueEntity $queueItem, string $error): void
    {
        $queueItem->setAttempts($queueItem->getAttempts() + 1);
        $queueItem->setLastError($error);
        
        // If max attempts reached, mark as failed permanently
        if ($queueItem->getAttempts() >= 3) {
            $queueItem->setStatus('failed');
        } else {
            $queueItem->setStatus('retry');
            // Exponential backoff for retry
            $nextAttempt = new \DateTime();
            $nextAttempt->modify('+' . pow(2, $queueItem->getAttempts()) . ' minutes');
            $queueItem->setScheduledAt($nextAttempt);
        }
        
        $this->entityManager->flush();
    }
    
    /**
     * Get queue statistics
     */
    public function getStatistics(): array
    {
        $stats = [];
        $statuses = ['pending', 'sent', 'failed', 'retry'];
        
        foreach ($statuses as $status) {
            $count = $this->crudManager->count(
                EmailQueueEntity::class,
                ['status' => $status]
            );
            $stats[$status] = $count;
        }
        
        return $stats;
    }
}