<?php

namespace App\Plugins\Billing\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use App\Plugins\Billing\Service\BillingService;
use App\Plugins\Email\Service\EmailService;
use Doctrine\ORM\EntityManagerInterface;
use App\Plugins\Organizations\Entity\OrganizationEntity;

class CheckOrganizationComplianceCommand extends Command
{
    protected static $defaultName = 'app:check-organization-compliance';
    protected static $defaultDescription = 'Check organizations for seat limit compliance';

    public function __construct(
        private BillingService $billingService,
        private EmailService $emailService,
        private EntityManagerInterface $entityManager
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription(self::$defaultDescription)
            ->setHelp('This command checks all organizations for compliance with their seat limits and sends warnings');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $io->title('Checking Organization Compliance');
        
        // Get non-compliant organizations
        $nonCompliant = $this->billingService->getNonCompliantOrganizations();
        
        if (empty($nonCompliant)) {
            $io->success('All organizations are compliant with their seat limits.');
            return Command::SUCCESS;
        }
        
        $io->warning(sprintf('Found %d non-compliant organizations', count($nonCompliant)));
        
        foreach ($nonCompliant as $item) {
            $organization = $item['organization'];
            $compliance = $item['compliance'];
            
            $io->section($organization->getName());
            $io->listing([
                sprintf('Total seats: %d', $compliance['seat_info']['total']),
                sprintf('Used seats: %d', $compliance['seat_info']['used']),
                sprintf('Overage: %d seats', $compliance['overage_count']),
            ]);
            
            // Send warning email to organization admins
            $this->sendComplianceWarning($organization, $compliance);
            
            // Log compliance issue
            $this->logComplianceIssue($organization, $compliance);
        }
        
        $io->success('Compliance check completed. Warnings sent to non-compliant organizations.');
        
        return Command::SUCCESS;
    }
    
    private function sendComplianceWarning(OrganizationEntity $organization, array $compliance): void
    {
        // Get organization admins
        $admins = $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from('App\Plugins\Account\Entity\UserEntity', 'u')
            ->join('App\Plugins\Organizations\Entity\UserOrganizationEntity', 'uo', 'WITH', 'uo.user = u')
            ->where('uo.organization = :organization')
            ->andWhere('uo.role = :role')
            ->setParameter('organization', $organization)
            ->setParameter('role', 'admin')
            ->getQuery()
            ->getResult();
            
        foreach ($admins as $admin) {
            // Send email warning
            $this->emailService->send(
                $admin->getEmail(),
                'compliance_warning',
                [
                    'organization_name' => $organization->getName(),
                    'admin_name' => $admin->getName(),
                    'total_seats' => $compliance['seat_info']['total'],
                    'used_seats' => $compliance['seat_info']['used'],
                    'overage_count' => $compliance['overage_count'],
                    'required_seats' => $compliance['required_additional_seats'],
                    'billing_url' => $_ENV['APP_URL'] . '/organizations/' . $organization->getId() . '/billing'
                ]
            );
        }
    }
    
    private function logComplianceIssue(OrganizationEntity $organization, array $compliance): void
    {
        // You could create a compliance_logs table to track these issues
        // For now, just log to system logs
        error_log(sprintf(
            '[COMPLIANCE] Organization %s (ID: %d) is over seat limit by %d seats',
            $organization->getName(),
            $organization->getId(),
            $compliance['overage_count']
        ));
    }
}