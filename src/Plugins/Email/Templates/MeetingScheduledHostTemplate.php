<?php
// Path: src/Plugins/Email/Templates/MeetingScheduledHostTemplate.php

namespace App\Plugins\Email\Templates;

class MeetingScheduledHostTemplate
{
    public static function render(array $data): string
    {
        // ✅ DEBUG: Log that this template was called
        error_log('🔍 DEBUG: MeetingScheduledHostTemplate (HOST) was called with status: ' . ($data['meeting_status'] ?? 'not_set'));
        
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
        
        // ✅ NEW: Check if booking is pending approval
        $meetingStatus = $data['meeting_status'] ?? 'confirmed';
        $bookingId = $data['booking_id'] ?? '';
        $organizationId = $data['organization_id'] ?? '';
        
        // ✅ DEBUG: Log what status we received
        error_log('MeetingScheduledHostTemplate received status: ' . $meetingStatus . ' for booking: ' . $bookingId);
        
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
    <div class="container">';

        // ✅ Different header based on status
        if ($meetingStatus === 'pending') {
            $html .= EmailStyles::getHeader('Approval Required!', '⏳');
        } else {
            $html .= EmailStyles::getHeader('New Meeting Scheduled!', '📅');
        }

        $html .= '<div class="content">
            <p class="greeting">Hi ' . $hostName . ',</p>';
            
        // ✅ Different message based on status  
        if ($meetingStatus === 'pending') {
            $html .= '
            <p class="message">
                <strong>' . $guestName . '</strong> has requested a meeting with you and is awaiting your approval.
            </p>
            
            <div class="alert" style="background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; border-radius: 5px; margin: 20px 0;">
                <strong>⏳ Action Required</strong><br>
                Please approve or decline this booking request by clicking one of the buttons below.
            </div>';
            
            // ✅ Add approve/decline buttons for pending bookings
            if ($bookingId && $organizationId) {
                $frontendUrl = $_ENV['FRONTEND_URL'] ?? 'https://app.skedi.com';
                $html .= '
            <div style="text-align: center; margin: 30px 0;">
                <a href="' . $frontendUrl . '/booking/' . $bookingId . '?action=approve&organization_id=' . $organizationId . '" 
                   style="background: #28a745; color: white; padding: 15px 30px; text-decoration: none; border-radius: 5px; margin-right: 15px; display: inline-block; font-weight: 600;">
                    ✅ Approve Booking
                </a>
                <a href="' . $frontendUrl . '/booking/' . $bookingId . '?action=decline&organization_id=' . $organizationId . '" 
                   style="background: #dc3545; color: white; padding: 15px 30px; text-decoration: none; border-radius: 5px; display: inline-block; font-weight: 600;">
                    ❌ Decline Booking
                </a>
            </div>
            
            <div style="text-align: center; margin: 20px 0;">
                <a href="' . $frontendUrl . '/booking/' . $bookingId . '?organization_id=' . $organizationId . '" 
                   style="color: #667eea; text-decoration: none; font-size: 14px;">
                    View Full Booking Details →
                </a>
            </div>';
            }
        } else {
            $html .= '
            <p class="message">
                <strong>' . $guestName . '</strong> has scheduled a meeting with you.
            </p>
            
            <div class="success">
                <strong>✓ New booking received</strong><br>
                The meeting has been added to your calendar.
            </div>';
        }
            
        $html .= '
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
                </div>
                <div class="detail-row">
                    <strong>🔍 DEBUG - Status:</strong> ' . $meetingStatus . '
                </div>
                <div class="detail-row">
                    <strong>🔍 DEBUG - Booking ID:</strong> ' . $bookingId . '
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
        
        // ✅ Different footer based on status
        if ($meetingStatus === 'pending') {
            $html .= '
            <div style="margin-top: 30px; padding: 15px; background: #f8f9fa; border-radius: 6px;">
                <p style="margin: 0; color: #718096; font-size: 14px;">
                    <strong>What happens next:</strong><br>
                    • Click "Approve" to confirm this booking and send calendar invitations<br>
                    • Click "Decline" to reject this request with an optional reason<br>
                    • The guest will be notified of your decision via email
                </p>
            </div>';
        } else {
            $html .= '
            <div style="margin-top: 30px; padding: 15px; background: #f8f9fa; border-radius: 6px;">
                <p style="margin: 0; color: #718096; font-size: 14px;">
                    <strong>Quick Actions:</strong><br>
                    • Reply to this email to contact ' . $guestName . '<br>
                    • Calendar invitation has been sent automatically<br>
                    • Meeting details and links are included above
                </p>
            </div>';
        }
        
        $html .= EmailStyles::getFooter() . '
        </div>
    </div>
</body>
</html>';

        return $html;
    }
}