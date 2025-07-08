<?php

namespace App\Plugins\Teams\Service;

use App\Service\CrudManager;
use App\Exception\CrudException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Constraints as Assert;

use App\Plugins\Teams\Entity\TeamEntity;
use App\Plugins\Organizations\Entity\OrganizationEntity;
use App\Plugins\Teams\Exception\TeamsException;
use App\Service\SlugService;
use App\Plugins\Events\Service\EventService;

class TeamService
{
    private CrudManager $crudManager;
    private EntityManagerInterface $entityManager;
    private SlugService $slugService;
    private EventService $eventService;

    public function __construct(
        CrudManager $crudManager,
        EntityManagerInterface $entityManager,
        SlugService $slugService,
        EventService $eventService
    ) {
        $this->crudManager = $crudManager;
        $this->entityManager = $entityManager;
        $this->slugService = $slugService;
        $this->eventService = $eventService;
    }

    public function getMany(array $filters, int $page, int $limit, array $criteria = [], ?callable $callback = null): array
    {
        try {
            return $this->crudManager->findMany(TeamEntity::class, $filters, $page, $limit, $criteria, $callback);
        } catch (CrudException $e) {
            throw new TeamsException($e->getMessage());
        }
    }

    public function getOne(int $id, array $criteria = []): ?TeamEntity
    {
        return $this->crudManager->findOne(TeamEntity::class, $id, $criteria);
    }

    public function create(array $data, ?callable $callback = null): TeamEntity
    {
        $team = new TeamEntity();

        if($callback) {
            $callback($team);
        }

        try {
            if(!isset($data['slug']) || !$data['slug']) {
                $data['slug'] = $data['name'] ?? null;
            }

            $data['slug'] = $this->slugService->generateSlug($data['slug']);

            if($this->getBySlug($data['slug'])) {
                throw new TeamsException('Team slug already exist.');
            }

            $contraints = [
                'name' => [
                    new Assert\Type('scalar'),
                    new Assert\Length(['min' => 2, 'max' => 255]),
                ],
                'slug' => [
                    new Assert\Type('scalar'),
                    new Assert\Length(['min' => 2, 'max' => 255]),
                ],
                'color' => new Assert\Optional([
                    new Assert\Type('scalar'),
                    new Assert\Length(['min' => 4, 'max' => 50]),
                ])
            ];

            $this->crudManager->create($team, array_intersect_key($data, array_flip(['name', 'slug', 'color'])), $contraints);

            return $team;
        } 
        catch (CrudException $e) {
            throw new TeamsException($e->getMessage());
        }
    }

    public function update(TeamEntity $team, array $data): void
    {
        $contraints = [
            'name' => new Assert\Optional([
                new Assert\Type('scalar'),
                new Assert\Length(['min' => 2, 'max' => 255]),
            ]),
            'slug' => new Assert\Optional([
                new Assert\NotBlank,
                new Assert\Type('scalar'),
                new Assert\Length(['min' => 2, 'max' => 255]),
            ]),
            'color' => new Assert\Optional([
                new Assert\Type('scalar'),
                new Assert\Length(['min' => 4, 'max' => 50]),
            ])
        ];

        $transform = [
            'slug' => function(string $value) use($team) {
                $value = $this->slugService->generateSlug($value);

                if($this->getBySlug($value) && $team->getSlug() !== $value) {
                    throw new TeamsException('Slug already exist.');
                }

                return $value;
            }
        ];

        try {
            // Handle parent team update
            if(isset($data['parent_team_id'])) {
                if($data['parent_team_id']) {
                    $parentTeam = $this->getOne($data['parent_team_id']);
                    if($parentTeam) {
                        $team->setParentTeam($parentTeam);
                    }
                } else {
                    $team->setParentTeam(null);
                }
                unset($data['parent_team_id']);
            }

            $this->crudManager->update($team, $data, $contraints, $transform);
        } 
        catch (CrudException $e) {
            throw new TeamsException($e->getMessage());
        }
    }

    /**
     * Delete team with cascade soft delete of child teams and events
     */
    public function delete(TeamEntity $team, bool $hard = false): void
    {
        try {
            if (!$hard) {
                // Get all child teams recursively
                $childTeams = $this->getAllChildTeams($team);
                
                // Delete all child teams
                foreach ($childTeams as $childTeam) {
                    $this->crudManager->delete($childTeam, false);
                }
                
                // Delete all events for this team and child teams
                $allTeams = array_merge([$team], $childTeams);
                foreach ($allTeams as $currentTeam) {
                    $events = $this->eventService->getEventsByTeam($currentTeam);
                    foreach ($events as $event) {
                        $this->eventService->delete($event, false);
                    }
                }
            }
            
            // Delete the team itself
            $this->crudManager->delete($team, $hard);
        } catch (CrudException $e) {
            throw new TeamsException($e->getMessage());
        }
    }

    /**
     * Get all child teams recursively
     */
    private function getAllChildTeams(TeamEntity $parentTeam): array
    {
        $allChildren = [];
        
        // Get direct children
        $directChildren = $this->getMany([], 1, 1000, [
            'parentTeam' => $parentTeam,
            'deleted' => false
        ]);
        
        foreach ($directChildren as $child) {
            $allChildren[] = $child;
            // Recursively get children of this child
            $grandChildren = $this->getAllChildTeams($child);
            $allChildren = array_merge($allChildren, $grandChildren);
        }
        
        return $allChildren;
    }

    public function getBySlug(string $slug)
    {
        $teams = $this->getMany([], 1, 1, ['slug' => $slug, 'deleted' => false]);
        return count($teams) ? $teams[0] : null;
    }

    public function getTeamsByOrganization(OrganizationEntity $organization): array
    {
        $organizationEntity = is_object($organization->entity) 
            ? $organization->entity 
            : $organization;
        
        try {
            return $this->getMany([], 1, 1000, ['organization' => $organizationEntity, 'deleted' => false]);
        } catch (CrudException $e) {
            throw new TeamsException($e->getMessage());
        }
    }

    public function getTeamByIdAndOrganization(int $id, OrganizationEntity $organization): ?TeamEntity
    {
        return $this->getOne($id, ['organization' => $organization]);
    }

    public function hasChildTeams(TeamEntity $team): bool
    {
        $children = $this->getMany([], 1, 1, ['parentTeam' => $team, 'deleted' => false]);
        return count($children) > 0;
    }
}