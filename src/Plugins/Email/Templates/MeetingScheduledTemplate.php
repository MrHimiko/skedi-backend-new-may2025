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
        
        // ✅ NEW: Check if booking is pending approval
        $meetingStatus = $data['meeting_status'] ?? 'confirmed';
        
        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meeting Scheduled</title>'
    . EmailStyles::getStyles() . 
'</head>
<body>
    <div class="container">';

        // ✅ Different header based on status
        if ($meetingStatus === 'pending') {
            $html .= EmailStyles::getHeader('Booking Request Sent!', '⏳');
        } else {
            $html .= EmailStyles::getHeader('Meeting Scheduled!', '🗓️');
        }

        $html .= '<div class="content">
            <p class="greeting">Hi ' . $guestName . ',</p>
            
            <p class="message">
                Your meeting with <strong>' . $organizerName . '</strong>';
            
        // Add company name if provided
        if ($companyName) {
            $html .= ' from <strong>' . $companyName . '</strong>';
        }
        
        // ✅ Different message based on status
        if ($meetingStatus === 'pending') {
            $html .= ' is pending approval.</p>
            
            <div class="alert" style="background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; border-radius: 5px; margin: 20px 0;">
                <strong>⏳ Awaiting Approval</strong><br>
                Your booking request has been sent to ' . $organizerName . '. You\'ll receive a confirmation email once approved.
            </div>';
        } else {
            $html .= ' has been successfully scheduled.</p>
            
            <div class="success">
                <strong>✓ Meeting confirmed</strong><br>
                We\'ve sent calendar invitations to all participants.
            </div>';
        }
        
        $html .= '
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
            </div>';
        
        // ✅ Different action buttons based on status
        if ($meetingStatus === 'pending') {
            $html .= '
            <div class="center">
                <p style="color: #718096; font-size: 14px; margin: 20px 0;">
                    We\'ll notify you as soon as your booking is approved or if any changes are needed.
                </p>
            </div>';
        } else {
            $html .= '
            <div class="center">
                <a href="' . $calendarLink . '" class="button">Add to Calendar</a>';
            
            if ($rescheduleLink !== '#') {
                $html .= '
                <br><br>
                <a href="' . $rescheduleLink . '" class="button-secondary">Reschedule</a>';
            }
            
            $html .= '
            </div>';
        }
        
        $html .= '
            <div style="margin-top: 30px; padding: 15px; background: #f8f9fa; border-radius: 6px;">
                <p style="margin: 0; color: #718096; font-size: 14px;">';
        
        if ($meetingStatus === 'pending') {
            $html .= '
                <strong>What happens next?</strong><br>
                • ' . $organizerName . ' will review your booking request<br>
                • You\'ll receive an email confirmation once approved<br>
                • If approved, calendar invitations will be sent automatically';
        } else {
            $html .= '
                <strong>What\'s next?</strong><br>
                • You\'ll receive a reminder before the meeting<br>
                • Join using the meeting link above<br>
                • Contact ' . $organizerName . ' if you need to reschedule';
        }
        
        $html .= '
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