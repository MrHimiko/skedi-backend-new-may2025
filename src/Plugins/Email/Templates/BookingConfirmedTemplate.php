<?php
// Path: src/Plugins/Email/Templates/BookingConfirmedTemplate.php

namespace App\Plugins\Email\Templates;

class BookingConfirmedTemplate
{
    public static function render(array $data): string
    {
        // Extract variables
        $guestName = $data['guest_name'] ?? 'Guest';
        $hostName = $data['host_name'] ?? 'Host';
        $eventName = $data['event_name'] ?? 'Event';
        $eventDate = $data['event_date'] ?? 'TBD';
        $eventTime = $data['event_time'] ?? 'TBD';
        $duration = $data['duration'] ?? '30 minutes';
        $location = $data['location'] ?? 'Online';
        $meetingLink = $data['meeting_link'] ?? '';
        $calendarLink = $data['calendar_link'] ?? '#';
        $rescheduleLink = $data['reschedule_link'] ?? '#';
        $cancelLink = $data['cancel_link'] ?? '#';
        
        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Confirmed</title>'
    . EmailStyles::getStyles() . 
'</head>
<body>
    <div class="container">'
        . EmailStyles::getHeader('Booking Confirmed!', '✅') . '
        <div class="content">
            <p class="greeting">Hi ' . $guestName . ',</p>
            
            <p class="message">Your booking with <strong>' . $hostName . '</strong> has been confirmed.</p>
            
            <div class="success">
                <strong>✓ Your booking is confirmed</strong><br>
                You\'ll receive a reminder before the meeting.
            </div>
            
            <div class="details">
                <div class="detail-row">
                    <strong>Event:</strong> ' . $eventName . '
                </div>
                <div class="detail-row">
                    <strong>Host:</strong> ' . $hostName . '
                </div>
                <div class="detail-row">
                    <strong>Date:</strong> ' . $eventDate . '
                </div>
                <div class="detail-row">
                    <strong>Time:</strong> ' . $eventTime . '
                </div>
                <div class="detail-row">
                    <strong>Duration:</strong> ' . $duration . '
                </div>
                <div class="detail-row">
                    <strong>Location:</strong> ' . $location . '
                </div>';
        
        if ($meetingLink) {
            $html .= '
                <div class="detail-row">
                    <strong>Meeting Link:</strong> <a href="' . $meetingLink . '" style="color: #667eea;">Join Meeting</a>
                </div>';
        }
        
        $html .= '
            </div>
            
            <div class="center">
                <a href="' . $calendarLink . '" class="button">Add to Calendar</a>
                <br>
                <a href="' . $rescheduleLink . '" class="button-secondary">Reschedule</a>
                <a href="' . $cancelLink . '" class="button-secondary">Cancel</a>
            </div>'
            . EmailStyles::getFooter() . '
        </div>
    </div>
</body>
</html>';
        
        return $html;
    }
}