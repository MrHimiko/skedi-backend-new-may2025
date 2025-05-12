<?php
// src/Plugins/Integrations/Command/SyncGoogleCalendarsCommand.php

namespace App\Plugins\Integrations\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use App\Plugins\Integrations\Repository\IntegrationRepository;
use App\Plugins\Integrations\Service\GoogleCalendarService;
use Psr\Log\LoggerInterface;
use DateTime;

#[AsCommand(
    name: 'app:sync-google-calendars',
    description: 'Sync Google calendars for all users',
)]
class SyncGoogleCalendarsCommand extends Command
{
    private IntegrationRepository $integrationRepository;
    private GoogleCalendarService $googleCalendarService;
    private LoggerInterface $logger;
    
    public function __construct(
        IntegrationRepository $integrationRepository,
        GoogleCalendarService $googleCalendarService,
        LoggerInterface $logger
    ) {
        $this->integrationRepository = $integrationRepository;
        $this->googleCalendarService = $googleCalendarService;
        $this->logger = $logger;
        
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Syncing Google calendars for all users');
        
        // Get all active Google Calendar integrations
        $integrations = $this->integrationRepository->findBy([
            'provider' => 'google_calendar',
            'status' => 'active'
        ]);
        
        $io->progressStart(count($integrations));
        $successCount = 0;
        $failureCount = 0;
        
        foreach ($integrations as $integration) {
            try {
                // Only sync calendars that haven't been synced in the last hour
                $lastSynced = $integration->getLastSynced();
                $oneHourAgo = new DateTime('-1 hour');
                
                if (!$lastSynced || $lastSynced < $oneHourAgo) {
                    // Sync the next 14 days by default
                    $startDate = new DateTime('today');
                    $endDate = new DateTime('+14 days');
                    
                    $events = $this->googleCalendarService->syncEvents($integration, $startDate, $endDate);
                    
                    $this->logger->info('Synced Google Calendar events', [
                        'integration_id' => $integration->getId(),
                        'user_id' => $integration->getUser()->getId(),
                        'events_count' => count($events)
                    ]);
                    
                    $successCount++;
                }
            } catch (\Exception $e) {
                $this->logger->error('Failed to sync calendar: ' . $e->getMessage(), [
                    'integration_id' => $integration->getId(),
                    'user_id' => $integration->getUser()->getId()
                ]);
                
                $failureCount++;
            }
            
            $io->progressAdvance();
        }
        
        $io->progressFinish();
        
        $io->success(sprintf('Synced %d calendars successfully. Failed: %d', $successCount, $failureCount));
        
        return Command::SUCCESS;
    }
}