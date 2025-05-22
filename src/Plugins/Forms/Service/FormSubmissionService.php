<?php

namespace App\Plugins\Forms\Service;

use App\Service\CrudManager;
use App\Exception\CrudException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Constraints as Assert;

use App\Plugins\Forms\Entity\FormEntity;
use App\Plugins\Forms\Entity\FormSubmissionEntity;
use App\Plugins\Forms\Exception\FormsException;
use App\Plugins\Events\Entity\EventEntity;
use App\Plugins\Events\Entity\EventBookingEntity;
use App\Plugins\Account\Entity\UserEntity;

class FormSubmissionService
{
    private CrudManager $crudManager;
    private EntityManagerInterface $entityManager;

    public function __construct(
        CrudManager $crudManager,
        EntityManagerInterface $entityManager
    ) {
        $this->crudManager = $crudManager;
        $this->entityManager = $entityManager;
    }

    public function getMany(array $filters, int $page, int $limit, array $criteria = []): array
    {
        try {
            return $this->crudManager->findMany(
                FormSubmissionEntity::class,
                $filters,
                $page,
                $limit,
                $criteria
            );
        } catch (CrudException $e) {
            throw new FormsException($e->getMessage());
        }
    }

    public function getOne(int $id, array $criteria = []): ?FormSubmissionEntity
    {
        return $this->crudManager->findOne(FormSubmissionEntity::class, $id, $criteria);
    }

    public function create(array $data): FormSubmissionEntity
    {
        try {
            if (empty($data['form_id'])) {
                throw new FormsException('Form ID is required');
            }

            $form = $this->entityManager->getRepository(FormEntity::class)->find($data['form_id']);
            if (!$form) {
                throw new FormsException('Form not found');
            }

            // Check if form allows multiple submissions
            if (!$form->isAllowMultipleSubmissions()) {
                $existingSubmission = null;
                
                if (!empty($data['submitter_email'])) {
                    $existingSubmission = $this->crudManager->findOne(FormSubmissionEntity::class, null, [
                        'form' => $form,
                        'submitterEmail' => $data['submitter_email']
                    ]);
                }

                if ($existingSubmission) {
                    throw new FormsException('Multiple submissions are not allowed for this form.');
                }
            }

            $submission = new FormSubmissionEntity();
            $submission->setForm($form);

            // Set optional relationships
            if (!empty($data['event_id'])) {
                $event = $this->entityManager->getRepository(EventEntity::class)->find($data['event_id']);
                if ($event) {
                    $submission->setEvent($event);
                }
            }

            if (!empty($data['booking_id'])) {
                $booking = $this->entityManager->getRepository(EventBookingEntity::class)->find($data['booking_id']);
                if ($booking) {
                    $submission->setBooking($booking);
                }
            }

            if (!empty($data['submitter_user_id'])) {
                $user = $this->entityManager->getRepository(UserEntity::class)->find($data['submitter_user_id']);
                if ($user) {
                    $submission->setSubmitterUser($user);
                }
            }

            $constraints = [
                'data' => [
                    new Assert\NotBlank(['message' => 'Submission data is required.']),
                    new Assert\Type('array'),
                ],
                'submitter_email' => new Assert\Optional([
                    new Assert\Email(['message' => 'Invalid email format']),
                ]),
                'submitter_name' => new Assert\Optional([
                    new Assert\Type('string'),
                    new Assert\Length(['max' => 255]),
                ]),
                'ip_address' => new Assert\Optional([
                    new Assert\Type('string'),
                ]),
                'user_agent' => new Assert\Optional([
                    new Assert\Type('string'),
                ]),
                'submission_source' => new Assert\Optional([
                    new Assert\Type('string'),
                ]),
            ];

            $transform = [
                'data' => function($value) {
                    return is_array($value) ? $value : [];
                },
            ];

            $this->crudManager->create($submission, $data, $constraints, $transform);

            return $submission;
        } catch (CrudException $e) {
            throw new FormsException($e->getMessage());
        }
    }

    public function getSubmissionsForForm(FormEntity $form, array $filters = [], int $page = 1, int $limit = 50): array
    {
        return $this->getMany($filters, $page, $limit, ['form' => $form]);
    }

    public function getSubmissionsForEvent(EventEntity $event, array $filters = [], int $page = 1, int $limit = 50): array
    {
        return $this->getMany($filters, $page, $limit, ['event' => $event]);
    }

    public function delete(FormSubmissionEntity $submission): void
    {
        try {
            $this->entityManager->remove($submission);
            $this->entityManager->flush();
        } catch (\Exception $e) {
            throw new FormsException($e->getMessage());
        }
    }
}