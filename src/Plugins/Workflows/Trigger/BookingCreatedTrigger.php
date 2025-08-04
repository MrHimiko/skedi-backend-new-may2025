<?php
// src/Plugins/Workflows/Trigger/BookingCreatedTrigger.php

namespace App\Plugins\Workflows\Trigger;

use App\Plugins\Workflows\Interface\TriggerInterface;

class BookingCreatedTrigger implements TriggerInterface
{
    public function getId(): string
    {
        return 'booking.created';
    }

    public function getName(): string
    {
        return 'Booking Created';
    }

    public function getDescription(): string
    {
        return 'Triggered when a new booking is created';
    }

    public function getCategory(): string
    {
        return 'booking';
    }

    public function getVariables(): array
    {
        return [
            'booking.id' => 'Booking ID',
            'booking.start_time' => 'Booking start time',
            'booking.end_time' => 'Booking end time',
            'booking.status' => 'Booking status',
            'booking.customer_name' => 'Customer name',
            'booking.customer_email' => 'Customer email',
            'booking.form_data' => 'Form data submitted',
            'event.id' => 'Event ID',
            'event.name' => 'Event name',
            'event.duration' => 'Event duration',
            'event.location' => 'Event location',
            'organization.name' => 'Organization name',
        ];
    }

    public function getConfigSchema(): array
    {
        return []; // No configuration needed for this trigger
    }

    public function shouldFire($event, array $config): bool
    {
        // This trigger fires for all booking created events
        return true;
    }

    public function extractData($event): array
    {
        $booking = $event->getBooking();
        $eventEntity = $booking->getEvent();
        $formData = $booking->getFormDataAsArray() ?? [];

        return [
            'booking' => [
                'id' => $booking->getId(),
                'start_time' => $booking->getStartTime()->format('Y-m-d H:i:s'),
                'end_time' => $booking->getEndTime()->format('Y-m-d H:i:s'),
                'status' => $booking->getStatus(),
                'customer_name' => $formData['name'] ?? '',
                'customer_email' => $formData['email'] ?? '',
                'form_data' => $formData,
            ],
            'event' => [
                'id' => $eventEntity->getId(),
                'name' => $eventEntity->getName(),
                'duration' => $eventEntity->getDuration(),
                'location' => $eventEntity->getLocation(),
            ],
            'organization' => [
                'id' => $eventEntity->getOrganization()->getId(),
                'name' => $eventEntity->getOrganization()->getName(),
            ],
        ];
    }
}