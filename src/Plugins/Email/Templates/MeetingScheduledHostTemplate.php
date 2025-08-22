<?php
// Path: src/Plugins/Email/Templates/MeetingScheduledHostTemplate.php

namespace App\Plugins\Email\Templates;

class MeetingScheduledHostTemplate
{
    public static function render(array $data): string
    {
        // Extract variables with defaults
        $hostName = $data['host_name'] ?? 'Host';
        $guestName = $data['guest_name'] ?? 'Guest';
        $guestEmail = $data['guest_email'] ?? '';
        $guestPhone = $data['guest_phone'] ?? '';
        $meetingName = $data['meeting_name'] ?? 'Meeting';
        $meetingDate = $data['meeting_date'] ?? $data['date'] ?? 'TBD';
        $meetingTime = $data['meeting_time'] ?? $data['time'] ?? 'TBD';
        $meetingDuration = $data['meeting_duration'] ?? $data['duration'] ?? '30 minutes';
        $meetingLocation = $data['meeting_location'] ?? $data['location'] ?? 'Online';
        $meetingLink = $data['meeting_link'] ?? '';
        $guestMessage = $data['guest_message'] ?? '';
        $bookingUrl = $data['booking_url'] ?? '#';
        $cancelUrl = $data['cancel_url'] ?? '#';
        $rescheduleUrl = $data['reschedule_url'] ?? '#';
        
        // Custom fields from booking form
        $customFields = $data['custom_fields'] ?? [];
        
        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Meeting Scheduled</title>'
    . EmailStyles::getStyles() . 
'</head>
<body>
    <div class="container">'
        . EmailStyles::getHeader('New Meeting Scheduled!', '📅') . '
        <div class="content">
            <p class="greeting">Hi ' . $hostName . ',</p>
            
            <p class="message">
                <strong>' . $guestName . '</strong> has scheduled a meeting with you.
            </p>
            
            <div class="success">
                <strong>✓ New booking received</strong><br>
                The meeting has been added to your calendar.
            </div>
            
            <div class="details">
                <div class="detail-row">
                    <strong>Meeting:</strong> ' . $meetingName . '
                </div>
                <div class="detail-row">
                    <strong>Guest Name:</strong> ' . $guestName . '
                </div>';
        
        if ($guestEmail) {
            $html .= '
                <div class="detail-row">
                    <strong>Guest Email:</strong> <a href="mailto:' . $guestEmail . '" style="color: #667eea;">' . $guestEmail . '</a>
                </div>';
        }
        
        if ($guestPhone) {
            $html .= '
                <div class="detail-row">
                    <strong>Guest Phone:</strong> ' . $guestPhone . '
                </div>';
        }
        
        $html .= '
                <div class="detail-row">
                    <strong>Date:</strong> ' . $meetingDate . '
                </div>
                <div class="detail-row">
                    <strong>Time:</strong> ' . $meetingTime . '
                </div>
                <div class="detail-row">
                    <strong>Duration:</strong> ' . $meetingDuration . '
                </div>
                <div class="detail-row">
                    <strong>Location:</strong> ' . $meetingLocation . '
                </div>';
        
        if ($meetingLink) {
            $html .= '
                <div class="detail-row">
                    <strong>Meeting Link:</strong> <a href="' . $meetingLink . '" style="color: #667eea;">Join Meeting</a>
                </div>';
        }
        
        // Add custom fields if any
        foreach ($customFields as $fieldName => $fieldValue) {
            if ($fieldValue) {
                $html .= '
                <div class="detail-row">
                    <strong>' . ucfirst(str_replace('_', ' ', $fieldName)) . ':</strong> ' . htmlspecialchars($fieldValue) . '
                </div>';
            }
        }
        
        $html .= '
            </div>';
        
        // Show guest message if provided
        if ($guestMessage) {
            $html .= '
            <div class="alert">
                <strong>Message from ' . $guestName . ':</strong><br>
                <div style="margin-top: 10px; padding: 10px; background: white; border-radius: 4px;">
                    ' . nl2br(htmlspecialchars($guestMessage)) . '
                </div>
            </div>';
        }
        
        $html .= '
            <div class="center">
                <a href="' . $bookingUrl . '" class="button">View Booking Details</a>
                <br>
                <a href="' . $rescheduleUrl . '" class="button-secondary">Reschedule</a>
                <a href="' . $cancelUrl . '" class="button-secondary">Cancel</a>
            </div>
            
            <div style="margin-top: 30px; padding: 15px; background: #f8f9fa; border-radius: 6px;">
                <p style="margin: 0; color: #718096; font-size: 14px;">
                    <strong>Quick Actions:</strong><br>
                    • Reply to this email to contact ' . $guestName . '<br>
                    • <a href="' . $bookingUrl . '" style="color: #667eea;">View all booking details</a><br>
                    • <a href="' . $rescheduleUrl . '" style="color: #667eea;">Suggest a different time</a>
                </p>
            </div>'
            . EmailStyles::getFooter() . '
        </div>
    </div>
</body>
</html>';
        
        return $html;
    }
}