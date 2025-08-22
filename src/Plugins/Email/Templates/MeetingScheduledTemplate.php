<?php
// Path: src/Plugins/Email/Templates/MeetingScheduledTemplate.php

namespace App\Plugins\Email\Templates;

class MeetingScheduledTemplate
{
    public static function render(array $data): string
    {
        // Extract variables with defaults
        $guestName = $data['guest_name'] ?? 'Guest';
        $meetingName = $data['meeting_name'] ?? 'Meeting';
        $meetingDate = $data['meeting_date'] ?? $data['date'] ?? 'TBD';
        $meetingTime = $data['meeting_time'] ?? $data['time'] ?? 'TBD';
        $meetingDuration = $data['meeting_duration'] ?? $data['duration'] ?? '30 minutes';
        $meetingLocation = $data['meeting_location'] ?? $data['location'] ?? 'Online';
        $meetingLink = $data['meeting_link'] ?? '';
        $organizerName = $data['organizer_name'] ?? 'Host';
        $companyName = $data['company_name'] ?? '';
        $rescheduleLink = $data['reschedule_link'] ?? '#';
        $calendarLink = $data['calendar_link'] ?? $rescheduleLink;
        
        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meeting Scheduled</title>'
    . EmailStyles::getStyles() . 
'</head>
<body>
    <div class="container">'
        . EmailStyles::getHeader('Meeting Scheduled!', '🗓️') . '
        <div class="content">
            <p class="greeting">Hi ' . $guestName . ',</p>
            
            <p class="message">
                Great news! Your meeting with <strong>' . $organizerName . '</strong>';
            
        // Add company name if provided
        if ($companyName) {
            $html .= ' from <strong>' . $companyName . '</strong>';
        }
        
        $html .= ' has been successfully scheduled.</p>
            
            <div class="success">
                <strong>✓ Meeting confirmed</strong><br>
                We\'ve sent calendar invitations to all participants.
            </div>
            
            <div class="details">
                <div class="detail-row">
                    <strong>Meeting:</strong> ' . $meetingName . '
                </div>
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
                
        // Add meeting link if provided
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
            </div>
            
            <div style="margin-top: 30px; padding: 15px; background: #f8f9fa; border-radius: 6px;">
                <p style="margin: 0; color: #718096; font-size: 14px;">
                    <strong>Need to make changes?</strong><br>
                    You can <a href="' . $rescheduleLink . '" style="color: #667eea;">reschedule</a> or cancel this meeting at any time.
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