<?php

namespace App\Plugins\Contacts\Service;

use App\Service\CrudManager;
use App\Exception\CrudException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Constraints as Assert;

use App\Plugins\Contacts\Entity\ContactEntity;
use App\Plugins\Contacts\Entity\OrganizationContactEntity;
use App\Plugins\Contacts\Repository\ContactRepository;
use App\Plugins\Contacts\Repository\OrganizationContactRepository;
use App\Plugins\Contacts\Exception\ContactsException;
use App\Plugins\Organizations\Entity\OrganizationEntity;
use App\Plugins\Account\Entity\UserEntity;
use App\Plugins\Events\Entity\EventBookingEntity;
use App\Plugins\Contacts\Entity\HostContactEntity;
use App\Plugins\Contacts\Entity\ContactBookingEntity;
use App\Plugins\Events\Entity\EventAssigneeEntity;



class ContactService
{
    private CrudManager $crudManager;
    private EntityManagerInterface $entityManager;
    private ContactRepository $contactRepository;
    private OrganizationContactRepository $organizationContactRepository;

    public function __construct(
        CrudManager $crudManager,
        EntityManagerInterface $entityManager,
        ContactRepository $contactRepository,
        OrganizationContactRepository $organizationContactRepository
    ) {
        $this->crudManager = $crudManager;
        $this->entityManager = $entityManager;
        $this->contactRepository = $contactRepository;
        $this->organizationContactRepository = $organizationContactRepository;
    }

    public function getMany(OrganizationEntity $organization, array $filters, int $page, int $limit): array
    {
        try {
            return $this->crudManager->findMany(
                OrganizationContactEntity::class,
                [],
                $page,
                $limit,
                [
                    'organization' => $organization,
                    'deleted' => false
                ]
            );
        } catch (CrudException $e) {
            throw new ContactsException($e->getMessage());
        }
    }

    public function getOne(OrganizationEntity $organization, int $id): ?OrganizationContactEntity
    {
        $orgContact = $this->organizationContactRepository->findOneBy([
            'id' => $id,
            'organization' => $organization,
            'deleted' => false
        ]);

        if ($orgContact && !$orgContact->getContact()->isDeleted()) {
            return $orgContact;
        }

        return null;
    }

    public function getContactByEmail(string $email): ?ContactEntity
    {
        return $this->contactRepository->findOneBy([
            'email' => $email,
            'deleted' => false
        ]);
    }

    public function create(array $data): ContactEntity
    {
        try {
            $contact = new ContactEntity();

            $this->crudManager->create(
                $contact,
                $data,
                [
                    'email' => [
                        new Assert\NotBlank(['message' => 'Email is required.']),
                        new Assert\Email(['message' => 'Invalid email format.']),
                    ],
                    'name' => new Assert\Optional([
                        new Assert\Type('string'),
                        new Assert\Length(['max' => 255]),
                    ]),
                    'phone' => new Assert\Optional([
                        new Assert\Type('string'),
                        new Assert\Length(['max' => 50]),
                    ]),
                    'timezone' => new Assert\Optional([
                        new Assert\Type('string'),
                        new Assert\Length(['max' => 100]),
                    ]),
                    'locale' => new Assert\Optional([
                        new Assert\Type('string'),
                        new Assert\Length(['max' => 10]),
                    ]),
                ]
            );

            return $contact;
        } catch (CrudException $e) {
            throw new ContactsException($e->getMessage());
        }
    }

    public function update(ContactEntity $contact, array $data): void
    {
        try {
            $this->crudManager->update(
                $contact,
                $data,
                [
                    'name' => new Assert\Optional([
                        new Assert\Type('string'),
                        new Assert\Length(['max' => 255]),
                    ]),
                    'phone' => new Assert\Optional([
                        new Assert\Type('string'),
                        new Assert\Length(['max' => 50]),
                    ]),
                    'timezone' => new Assert\Optional([
                        new Assert\Type('string'),
                        new Assert\Length(['max' => 100]),
                    ]),
                    'locale' => new Assert\Optional([
                        new Assert\Type('string'),
                        new Assert\Length(['max' => 10]),
                    ]),
                ]
            );
        } catch (CrudException $e) {
            throw new ContactsException($e->getMessage());
        }
    }

    public function delete(OrganizationContactEntity $orgContact): void
    {
        try {
            $this->crudManager->delete($orgContact);
        } catch (CrudException $e) {
            throw new ContactsException($e->getMessage());
        }
    }

    public function createOrUpdateFromBooking(EventBookingEntity $booking): ContactEntity
    {
        $formData = $booking->getFormDataAsArray();
        $email = $formData['primary_contact']['email'] ?? null;
        $name = $formData['primary_contact']['name'] ?? null;

        if (!$email) {
            throw new ContactsException('No email found in booking data.');
        }

        // Check if contact exists
        $contact = $this->getContactByEmail($email);

        if (!$contact) {
            // Create new contact
            $contact = $this->create([
                'email' => $email,
                'name' => $name,
                'phone' => $formData['primary_contact']['phone'] ?? null,
            ]);
        } else if ($name && !$contact->getName()) {
            // Update contact name if it was empty
            $this->update($contact, ['name' => $name]);
        }

        $event = $booking->getEvent();
        $organization = $event->getOrganization();

        // Create or update organization contact
        $this->createOrUpdateOrganizationContact($contact, $organization);

        // Create host contacts for all event assignees
        $this->createHostContactsFromBooking($contact, $booking);

        // Create contact booking record
        $this->createContactBooking($contact, $booking);

        return $contact;
    }

    public function createOrUpdateOrganizationContact(ContactEntity $contact, OrganizationEntity $organization): OrganizationContactEntity
    {
        $orgContact = $this->organizationContactRepository->findOneBy([
            'contact' => $contact,
            'organization' => $organization,
            'deleted' => false
        ]);

        if (!$orgContact) {
            $orgContact = new OrganizationContactEntity();
            $orgContact->setContact($contact);
            $orgContact->setOrganization($organization);
            
            $this->entityManager->persist($orgContact);
        } else {
            $orgContact->incrementInteractionCount();
        }

        $this->entityManager->flush();

        return $orgContact;
    }

    public function getContactsWithMeetingInfo(OrganizationEntity $organization, array $filters, int $page, int $limit): array
    {
        try {
            // Get organization contacts using CrudManager
            $orgContacts = $this->getMany($organization, $filters, $page, $limit);
            
            // CrudManager returns array of entities directly
            $contactsData = [];
            foreach ($orgContacts as $orgContact) {
                $contact = $orgContact->getContact();
                
                // Get meeting info
                $lastMeeting = $this->getLastMeetingForContact($contact, $organization);
                $nextMeeting = $this->getNextMeetingForContact($contact, $organization);
                
                $contactsData[] = [
                    'id' => $orgContact->getId(),
                    'contact' => $contact->toArray(),
                    'organization_contact' => $orgContact->toArray(),
                    'last_meeting' => $lastMeeting,
                    'next_meeting' => $nextMeeting,
                ];
            }

            return [
                'data' => $contactsData,
                'count' => count($contactsData)
            ];
        } catch (\Exception $e) {
            throw new ContactsException('Failed to get contacts: ' . $e->getMessage());
        }
    }


    private function createHostContactsFromBooking(ContactEntity $contact, EventBookingEntity $booking): void
    {
        $event = $booking->getEvent();
        $organization = $event->getOrganization();
        
        // Get all assignees for this event
        $assignees = $this->entityManager->getRepository('App\Plugins\Events\Entity\EventAssigneeEntity')
            ->findBy(['event' => $event]);
        
        // Create host_contacts for each assignee
        foreach ($assignees as $assignee) {
            $host = $assignee->getUser();
            
            // Check if host_contact already exists
            $existingHostContact = $this->entityManager->getRepository('App\Plugins\Contacts\Entity\HostContactEntity')
                ->findOneBy([
                    'contact' => $contact,
                    'host' => $host,
                    'deleted' => false
                ]);
            
            if (!$existingHostContact) {
                // Create new host_contact
                $hostContact = new HostContactEntity();
                $hostContact->setContact($contact);
                $hostContact->setHost($host);
                $hostContact->setOrganization($organization);
                $hostContact->setFirstMeeting(new \DateTime());
                $hostContact->setLastMeeting(new \DateTime());
                $hostContact->setMeetingCount(1);
                
                $this->entityManager->persist($hostContact);
            } else {
                // Update existing host_contact
                $existingHostContact->setLastMeeting(new \DateTime());
                $existingHostContact->setMeetingCount($existingHostContact->getMeetingCount() + 1);
                
                $this->entityManager->persist($existingHostContact);
            }
        }
        
        // If no assignees, create host_contact for event creator
        if (empty($assignees)) {
            $creator = $event->getCreatedBy();
            
            $existingHostContact = $this->entityManager->getRepository('App\Plugins\Contacts\Entity\HostContactEntity')
                ->findOneBy([
                    'contact' => $contact,
                    'host' => $creator,
                    'deleted' => false
                ]);
            
            if (!$existingHostContact) {
                $hostContact = new HostContactEntity();
                $hostContact->setContact($contact);
                $hostContact->setHost($creator);
                $hostContact->setOrganization($organization);
                $hostContact->setFirstMeeting(new \DateTime());
                $hostContact->setLastMeeting(new \DateTime());
                $hostContact->setMeetingCount(1);
                
                $this->entityManager->persist($hostContact);
            } else {
                $existingHostContact->setLastMeeting(new \DateTime());
                $existingHostContact->setMeetingCount($existingHostContact->getMeetingCount() + 1);
                
                $this->entityManager->persist($existingHostContact);
            }
        }
        
        $this->entityManager->flush();
    }

    private function createContactBooking(ContactEntity $contact, EventBookingEntity $booking): void
    {
        // Create contact_bookings record
        $contactBooking = new ContactBookingEntity();
        $contactBooking->setContact($contact);
        $contactBooking->setBooking($booking);
        $contactBooking->setOrganization($booking->getEvent()->getOrganization());
        $contactBooking->setEvent($booking->getEvent());
        
        // Get the host (first assignee or event creator)
        $assignees = $this->entityManager->getRepository('App\Plugins\Events\Entity\EventAssigneeEntity')
            ->findBy(['event' => $booking->getEvent()]);
        
        if (!empty($assignees)) {
            $contactBooking->setHost($assignees[0]->getUser());
        } else {
            $contactBooking->setHost($booking->getEvent()->getCreatedBy());
        }
        
        $this->entityManager->persist($contactBooking);
        $this->entityManager->flush();
    }



    private function getLastMeetingForContact(ContactEntity $contact, OrganizationEntity $organization): ?array
    {
        try {
            // Get all bookings for this organization
            $bookings = $this->entityManager->getRepository(EventBookingEntity::class)->findBy([
                'cancelled' => false
            ], ['startTime' => 'DESC']);
            
            $now = new \DateTime();
            
            foreach ($bookings as $booking) {
                // Check if booking is for this organization
                if ($booking->getEvent()->getOrganization()->getId() !== $organization->getId()) {
                    continue;
                }
                
                // Check if booking is in the past
                if ($booking->getStartTime() >= $now) {
                    continue;
                }
                
                // Check if booking has this contact's email
                $formData = $booking->getFormDataAsArray();
                $bookingEmail = $formData['primary_contact']['email'] ?? null;
                
                if ($bookingEmail === $contact->getEmail()) {
                    return [
                        'event_name' => $booking->getEvent()->getName(),
                        'date' => $booking->getStartTime()->format('Y-m-d H:i:s'),
                    ];
                }
            }
            
            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    private function getNextMeetingForContact(ContactEntity $contact, OrganizationEntity $organization): ?array
    {
        try {
            // Get all bookings for this organization
            $bookings = $this->entityManager->getRepository(EventBookingEntity::class)->findBy([
                'cancelled' => false
            ], ['startTime' => 'ASC']);
            
            $now = new \DateTime();
            
            foreach ($bookings as $booking) {
                // Check if booking is for this organization
                if ($booking->getEvent()->getOrganization()->getId() !== $organization->getId()) {
                    continue;
                }
                
                // Check if booking is in the future
                if ($booking->getStartTime() <= $now) {
                    continue;
                }
                
                // Check if booking has this contact's email
                $formData = $booking->getFormDataAsArray();
                $bookingEmail = $formData['primary_contact']['email'] ?? null;
                
                if ($bookingEmail === $contact->getEmail()) {
                    return [
                        'event_name' => $booking->getEvent()->getName(),
                        'date' => $booking->getStartTime()->format('Y-m-d H:i:s'),
                    ];
                }
            }
            
            return null;
        } catch (\Exception $e) {
            return null;
        }
    }


}