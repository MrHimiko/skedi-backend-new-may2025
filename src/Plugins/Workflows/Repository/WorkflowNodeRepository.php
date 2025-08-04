<?php 

namespace App\Plugins\Workflows\Repository;

use App\Plugins\Workflows\Entity\WorkflowNodeEntity;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class WorkflowNodeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkflowNodeEntity::class);
    }
}