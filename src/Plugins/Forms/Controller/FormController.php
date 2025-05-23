<?php

namespace App\Plugins\Forms\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use App\Service\ResponseService;
use App\Plugins\Forms\Service\FormService;
use App\Plugins\Forms\Exception\FormsException;
use App\Plugins\Organizations\Service\UserOrganizationService;
use App\Plugins\Events\Service\EventService;

#[Route('/api/organizations/{organization_id}', requirements: ['organization_id' => '\d+'])]
class FormController extends AbstractController
{
    private ResponseService $responseService;
    private FormService $formService;
    private UserOrganizationService $userOrganizationService;
    private EventService $eventService;

    public function __construct(
        ResponseService $responseService,
        FormService $formService,
        UserOrganizationService $userOrganizationService,
        EventService $eventService
    ) {
        $this->responseService = $responseService;
        $this->formService = $formService;
        $this->userOrganizationService = $userOrganizationService;
        $this->eventService = $eventService;
    }

    #[Route('/forms', name: 'forms_get_many#', methods: ['GET'])]
    public function getForms(int $organization_id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $filters = $request->attributes->get('filters');
        $page = $request->attributes->get('page');
        $limit = $request->attributes->get('limit');

        try {
            if (!$organization = $this->userOrganizationService->getOrganizationByUser($organization_id, $user)) {
                return $this->responseService->json(false, 'Organization was not found.');
            }

            $forms = $this->formService->getMany($filters, $page, $limit, [
                'organization' => $organization->entity
            ]);

            $result = [];
            foreach ($forms as $form) {
                $result[] = $form->toArray();
            }

            return $this->responseService->json(true, 'Forms retrieved successfully.', $result);
        } catch (FormsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e, null, 500);
        }
    }

    #[Route('/forms/{id}', name: 'forms_get_one#', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function getFormById(int $organization_id, int $id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');

        try {
            if (!$organization = $this->userOrganizationService->getOrganizationByUser($organization_id, $user)) {
                return $this->responseService->json(false, 'Organization was not found.');
            }

            $form = $this->formService->getOne($id, ['organization' => $organization->entity]);

            if (!$form) {
                return $this->responseService->json(false, 'Form was not found.');
            }

            return $this->responseService->json(true, 'Form retrieved successfully.', $form->toArray());
        } catch (FormsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e, null, 500);
        }
    }

    #[Route('/forms', name: 'forms_create#', methods: ['POST'])]
    public function createNewForm(int $organization_id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $data = $request->attributes->get('data');

        try {
            if (!$organization = $this->userOrganizationService->getOrganizationByUser($organization_id, $user)) {
                return $this->responseService->json(false, 'Organization was not found.');
            }

            $form = $this->formService->create($data, $organization->entity, $user);

            return $this->responseService->json(true, 'Form created successfully.', $form->toArray(), 201);
        } catch (FormsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e, null, 500);
        }
    }

    #[Route('/forms/{id}', name: 'forms_update#', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function updateForm(int $organization_id, int $id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $data = $request->attributes->get('data');

        try {
            if (!$organization = $this->userOrganizationService->getOrganizationByUser($organization_id, $user)) {
                return $this->responseService->json(false, 'Organization was not found.');
            }

            $form = $this->formService->getOne($id, ['organization' => $organization->entity]);

            if (!$form) {
                return $this->responseService->json(false, 'Form was not found.');
            }

            $this->formService->update($form, $data);

            return $this->responseService->json(true, 'Form updated successfully.', $form->toArray());
        } catch (FormsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e, null, 500);
        }
    }

    #[Route('/forms/{id}', name: 'forms_delete#', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function deleteForm(int $organization_id, int $id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');

        try {
            if (!$organization = $this->userOrganizationService->getOrganizationByUser($organization_id, $user)) {
                return $this->responseService->json(false, 'Organization was not found.');
            }

            $form = $this->formService->getOne($id, ['organization' => $organization->entity]);

            if (!$form) {
                return $this->responseService->json(false, 'Form was not found.');
            }

            $this->formService->delete($form);

            return $this->responseService->json(true, 'Form deleted successfully.');
        } catch (FormsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e, null, 500);
        }
    }

    #[Route('/events/{event_id}/forms', name: 'event_forms_attach#', methods: ['POST'], requirements: ['event_id' => '\d+'])]
    public function attachFormToEvent(int $organization_id, int $event_id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $data = $request->attributes->get('data');

        try {
            if (!$organization = $this->userOrganizationService->getOrganizationByUser($organization_id, $user)) {
                return $this->responseService->json(false, 'Organization was not found.');
            }

            if (empty($data['form_id'])) {
                return $this->responseService->json(false, 'Form ID is required.');
            }

            $event = $this->eventService->getEventByIdAndOrganization($event_id, $organization->entity);
            if (!$event) {
                return $this->responseService->json(false, 'Event was not found.');
            }

            $form = $this->formService->getOne($data['form_id'], ['organization' => $organization->entity]);
            if (!$form) {
                return $this->responseService->json(false, 'Form was not found.');
            }

            $eventForm = $this->formService->attachToEvent($form, $event);

            return $this->responseService->json(true, 'Form attached to event successfully.', $eventForm->toArray());
        } catch (FormsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e, null, 500);
        }
    }

    #[Route('/events/{event_id}/forms', name: 'event_forms_detach#', methods: ['DELETE'], requirements: ['event_id' => '\d+'])]
    public function detachFormFromEvent(int $organization_id, int $event_id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');

        try {
            if (!$organization = $this->userOrganizationService->getOrganizationByUser($organization_id, $user)) {
                return $this->responseService->json(false, 'Organization was not found.');
            }

            $event = $this->eventService->getEventByIdAndOrganization($event_id, $organization->entity);
            if (!$event) {
                return $this->responseService->json(false, 'Event was not found.');
            }

            $this->formService->detachFromEvent($event);

            return $this->responseService->json(true, 'Form detached from event successfully.');
        } catch (FormsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e, null, 500);
        }
    }

    #[Route('/events/{event_id}/forms', name: 'event_forms_get#', methods: ['GET'], requirements: ['event_id' => '\d+'])]
    public function getEventForm(int $organization_id, int $event_id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');

        try {
            if (!$organization = $this->userOrganizationService->getOrganizationByUser($organization_id, $user)) {
                return $this->responseService->json(false, 'Organization was not found.');
            }

            $event = $this->eventService->getEventByIdAndOrganization($event_id, $organization->entity);
            if (!$event) {
                return $this->responseService->json(false, 'Event was not found.');
            }

            $form = $this->formService->getFormForEvent($event);

            if (!$form) {
                return $this->responseService->json(false, 'No form attached to this event.');
            }

            return $this->responseService->json(true, 'Event form retrieved successfully.', $form->toArray());
        } catch (FormsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e, null, 500);
        }
    }
}