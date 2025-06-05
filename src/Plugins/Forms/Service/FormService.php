<?php

namespace App\Plugins\Forms\Service;

use App\Service\CrudManager;
use App\Exception\CrudException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Constraints as Assert;

use App\Plugins\Forms\Entity\FormEntity;
use App\Plugins\Forms\Entity\EventFormEntity;
use App\Plugins\Forms\Exception\FormsException;
use App\Plugins\Organizations\Entity\OrganizationEntity;
use App\Plugins\Account\Entity\UserEntity;
use App\Plugins\Events\Entity\EventEntity;
use App\Service\SlugService;

class FormService
{
    private CrudManager $crudManager;
    private EntityManagerInterface $entityManager;
    private SlugService $slugService;

    public function __construct(
        CrudManager $crudManager,
        EntityManagerInterface $entityManager,
        SlugService $slugService
    ) {
        $this->crudManager = $crudManager;
        $this->entityManager = $entityManager;
        $this->slugService = $slugService;
    }

    public function getMany(array $filters, int $page, int $limit, array $criteria = []): array
    {
        try {
            return $this->crudManager->findMany(
                FormEntity::class,
                $filters,
                $page,
                $limit,
                $criteria + ['deleted' => false]
            );
        } catch (CrudException $e) {
            throw new FormsException($e->getMessage());
        }
    }

    public function getOne(int $id, array $criteria = []): ?FormEntity
    {
        return $this->crudManager->findOne(FormEntity::class, $id, $criteria + ['deleted' => false]);
    }

    public function getBySlug(string $slug, OrganizationEntity $organization): ?FormEntity
    {
        return $this->crudManager->findOne(FormEntity::class, null, [
            'slug' => $slug,
            'organization' => $organization,
            'deleted' => false
        ]);
    }

    public function create(array $data, OrganizationEntity $organization, UserEntity $user): FormEntity
    {
        try {
            $form = new FormEntity();
            $form->setOrganization($organization);
            $form->setCreatedBy($user);

            // Generate slug if not provided
            if (!array_key_exists('slug', $data)) {
                $data['slug'] = $data['name'] ?? null;
            }

            if ($data['slug']) {
                $data['slug'] = $this->slugService->generateSlug($data['slug']);
            }
            
            // Initialize with default fields if no fields provided
            if (!isset($data['fields']) || empty($data['fields'])) {
                $data['fields'] = $form->getDefaultFields();
            } else {
                // Ensure system fields are included
                $form->setFieldsJson($data['fields']);
                $data['fields'] = $form->getFieldsJson();
            }

            $constraints = [
                'name' => [
                    new Assert\NotBlank(['message' => 'Form name is required.']),
                    new Assert\Type('string'),
                    new Assert\Length(['min' => 2, 'max' => 255]),
                ],
                'slug' => [
                    new Assert\NotBlank(['message' => 'Form slug is required.']),
                    new Assert\Type('string'),
                    new Assert\Length(['min' => 2, 'max' => 255]),
                    new Assert\Regex([
                        'pattern' => '/^[a-z0-9\-]+$/',
                        'message' => 'Slug can only contain lowercase letters, numbers, and hyphens.'
                    ]),
                ],
                'description' => new Assert\Optional([
                    new Assert\Type('string'),
                ]),
                'fields' => new Assert\Optional([
                    new Assert\Type('array'),
                ]),
                'settings' => new Assert\Optional([
                    new Assert\Type('array'),
                ]),
                'is_active' => new Assert\Optional([
                    new Assert\Type('bool'),
                ]),
                'allow_multiple_submissions' => new Assert\Optional([
                    new Assert\Type('bool'),
                ]),
                'requires_authentication' => new Assert\Optional([
                    new Assert\Type('bool'),
                ]),
            ];

            $transform = [
                'slug' => function(string $value) {
                    return $this->slugService->generateSlug($value);
                },
                'fields' => function($value) use ($form) {
                    $fieldsArray = is_array($value) ? $value : [];
                    $form->setFieldsJson($fieldsArray);
                    return $form->getFieldsJson(); // This now ensures system fields
                },
                'settings' => function($value) use ($form) {
                    $settingsArray = is_array($value) ? $value : [];
                    $form->setSettingsJson($settingsArray);
                    return $settingsArray;
                },
            ];

            $this->crudManager->create($form, $data, $constraints, $transform);
            
            return $form;
        } catch (CrudException $e) {
            throw new FormsException($e->getMessage());
        }
    }

    public function update(FormEntity $form, array $data): void
    {
        try {
            // Handle slug updates
            if (!empty($data['slug']) || (!isset($data['slug']) && !empty($data['name']))) {
                if (empty($data['slug']) && !empty($data['name'])) {
                    $data['slug'] = $data['name'];
                }
                $data['slug'] = $this->slugService->generateSlug($data['slug']);
            }
            
            // Validate fields to ensure system fields aren't removed
            if (isset($data['fields'])) {
                $form->setFieldsJson($data['fields']);
                $data['fields'] = $form->getFieldsJson();
            }

            $constraints = [
                'name' => new Assert\Optional([
                    new Assert\NotBlank(['message' => 'Form name is required.']),
                    new Assert\Type('string'),
                    new Assert\Length(['min' => 2, 'max' => 255]),
                ]),
                'slug' => new Assert\Optional([
                    new Assert\NotBlank(['message' => 'Form slug is required.']),
                    new Assert\Type('string'),
                    new Assert\Length(['min' => 2, 'max' => 255]),
                    new Assert\Regex([
                        'pattern' => '/^[a-z0-9\-]+$/',
                        'message' => 'Slug can only contain lowercase letters, numbers, and hyphens.'
                    ]),
                ]),
                'description' => new Assert\Optional([
                    new Assert\Type('string'),
                ]),
                'fields' => new Assert\Optional([
                    new Assert\Type('array'),
                ]),
                'settings' => new Assert\Optional([
                    new Assert\Type('array'),
                ]),
                'is_active' => new Assert\Optional([
                    new Assert\Type('bool'),
                ]),
                'allow_multiple_submissions' => new Assert\Optional([
                    new Assert\Type('bool'),
                ]),
                'requires_authentication' => new Assert\Optional([
                    new Assert\Type('bool'),
                ]),
            ];

            $transform = [
                'slug' => function(string $value) {
                    return $this->slugService->generateSlug($value);
                },
                'fields' => function($value) use ($form) {
                    $fieldsArray = is_array($value) ? $value : [];
                    $form->setFieldsJson($fieldsArray);
                    return $fieldsArray;
                },
                'settings' => function($value) use ($form) {
                    $settingsArray = is_array($value) ? $value : [];
                    $form->setSettingsJson($settingsArray);
                    return $settingsArray;
                },
            ];

            $this->crudManager->update($form, $data, $constraints, $transform);

            if (isset($data['fields'])) {
                $form->setFieldsJson(is_array($data['fields']) ? $data['fields'] : []);
            }
            if (isset($data['settings'])) {
                $form->setSettingsJson(is_array($data['settings']) ? $data['settings'] : []);
            }
            $this->entityManager->flush();

            
        } catch (CrudException $e) {
            throw new FormsException($e->getMessage());
        }
    }

    public function delete(FormEntity $form, bool $hard = false): void
    {
        try {
            $this->crudManager->delete($form, $hard);
        } catch (CrudException $e) {
            throw new FormsException($e->getMessage());
        }
    }

    public function attachToEvent(FormEntity $form, EventEntity $event): EventFormEntity
    {
        try {
            // Check if already attached using repository
            $eventFormRepository = $this->entityManager->getRepository(EventFormEntity::class);
            
            $existing = $eventFormRepository->findOneBy([
                'event' => $event
            ]);

            if ($existing) {
                // Update existing attachment to use the new form
                $existing->setForm($form);
                $existing->setIsActive(true);
                $this->entityManager->flush();
                return $existing;
            }

            // Create new attachment
            $eventForm = new EventFormEntity();
            $eventForm->setForm($form);
            $eventForm->setEvent($event);

            $this->entityManager->persist($eventForm);
            $this->entityManager->flush();

            return $eventForm;
        } catch (\Exception $e) {
            throw new FormsException($e->getMessage());
        }
    }

    // Also fix the getFormForEvent method while we're at it:
    public function getFormForEvent(EventEntity $event): ?FormEntity
    {
        try {
            $eventFormRepository = $this->entityManager->getRepository(EventFormEntity::class);
            
            $eventForm = $eventFormRepository->findOneBy([
                'event' => $event,
                'isActive' => true
            ]);
            
            return $eventForm ? $eventForm->getForm() : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    public function detachFromEvent(EventEntity $event): void
    {
        try {
            $eventForm = $this->crudManager->findOne(EventFormEntity::class, null, [
                'event' => $event
            ]);

            if ($eventForm) {
                $this->entityManager->remove($eventForm);
                $this->entityManager->flush();
            }
        } catch (\Exception $e) {
            throw new FormsException($e->getMessage());
        }
    }


}