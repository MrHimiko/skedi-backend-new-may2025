<?php 

namespace App\Plugins\Workflows\Repository;

use App\Plugins\Workflows\Entity\WorkflowConnectionEntity;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class WorkflowConnectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkflowConnectionEntity::class);
    }
}