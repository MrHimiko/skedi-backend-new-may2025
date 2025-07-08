<?php

namespace App\Plugins\Forms\Service;

use App\Service\CrudManager;
use App\Exception\CrudException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Constraints as Assert;

use App\Plugins\Forms\Entity\FormEntity;
use App\Plugins\Forms\Entity\EventFormEntity;
use App\Plugins\Forms\Exception\FormsException;
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
            // Remove organization from criteria - forms are now global
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

    public function getBySlug(string $slug): ?FormEntity
    {
        return $this->crudManager->findOne(FormEntity::class, null, [
            'slug' => $slug,
            'deleted' => false
        ]);
    }

    public function create(array $data, UserEntity $user): FormEntity
    {
        try {
            $form = new FormEntity();
            $form->setCreatedBy($user);
            // Don't set organization - it's now nullable

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
                    new Assert\Length(['max' => 255])
                ],
                'slug' => [
                    new Assert\NotBlank(['message' => 'Form slug is required.']),
                    new Assert\Length(['max' => 255])
                ],
                'description' => [
                    new Assert\Length(['max' => 65535])
                ],
                'fields' => [
                    new Assert\NotBlank(['message' => 'Form fields are required.']),
                    new Assert\Type('array')
                ],
                'settings' => [
                    new Assert\Type('array')
                ],
                'is_active' => [
                    new Assert\Type('bool')
                ],
                'allow_multiple_submissions' => [
                    new Assert\Type('bool')
                ],
                'requires_authentication' => [
                    new Assert\Type('bool')
                ]
            ];

            $transform = [
                'fields' => function($value) use (&$form) {
                    $fieldsArray = is_array($value) ? $value : [];
                    $form->setFieldsJson($fieldsArray);
                    return $fieldsArray;
                },
                'settings' => function($value) use (&$form) {
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
            $constraints = [
                'name' => [
                    new Assert\Length(['max' => 255])
                ],
                'slug' => [
                    new Assert\Length(['max' => 255])
                ],
                'description' => [
                    new Assert\Length(['max' => 65535])
                ],
                'fields' => [
                    new Assert\Type('array')
                ],
                'settings' => [
                    new Assert\Type('array')
                ],
                'is_active' => [
                    new Assert\Type('bool')
                ],
                'allow_multiple_submissions' => [
                    new Assert\Type('bool')
                ],
                'requires_authentication' => [
                    new Assert\Type('bool')
                ]
            ];

            $transform = [
                'fields' => function($value) use (&$form) {
                    $fieldsArray = is_array($value) ? $value : [];
                    $form->setFieldsJson($fieldsArray);
                    return $fieldsArray;
                },
                'settings' => function($value) use (&$form) {
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

    public function detachFromEvent(EventEntity $event): void
    {
        try {
            $eventFormRepository = $this->entityManager->getRepository(EventFormEntity::class);
            
            $eventForm = $eventFormRepository->findOneBy([
                'event' => $event,
                'isActive' => true
            ]);

            if ($eventForm) {
                $eventForm->setIsActive(false);
                $this->entityManager->flush();
            }
        } catch (\Exception $e) {
            throw new FormsException($e->getMessage());
        }
    }

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
            throw new FormsException($e->getMessage());
        }
    }
}