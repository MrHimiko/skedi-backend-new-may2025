<?php
// Path: src/Plugins/Email/Templates/EmailStyles.php

namespace App\Plugins\Email\Templates;

class EmailStyles
{
    public static function getStyles(): string
    {
        return '
        <style>
            body {
                margin: 0;
                padding: 0;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
                line-height: 1.6;
                color: #333;
                background-color: #f4f6f9;
            }
            .container {
                max-width: 600px;
                margin: 0 auto;
                padding: 20px;
            }
            .header {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
                padding: 30px 20px;
                text-align: center;
                border-radius: 8px 8px 0 0;
            }
            .header h1 {
                margin: 0;
                font-size: 24px;
                font-weight: 600;
            }
            .content {
                background-color: #ffffff;
                padding: 30px;
                border: 1px solid #e2e8f0;
                border-radius: 0 0 8px 8px;
            }
            .greeting {
                font-size: 18px;
                margin-bottom: 10px;
            }
            .message {
                color: #555;
                margin-bottom: 20px;
            }
            .details {
                background-color: #f8f9fa;
                padding: 20px;
                border-left: 4px solid #667eea;
                border-radius: 4px;
                margin: 20px 0;
            }
            .detail-row {
                margin: 12px 0;
                color: #333;
            }
            .detail-row strong {
                display: inline-block;
                min-width: 120px;
                color: #495057;
            }
            .button {
                display: inline-block;
                padding: 12px 30px;
                background-color: #667eea;
                color: white !important;
                text-decoration: none;
                border-radius: 6px;
                font-weight: 600;
                margin: 20px 0;
            }
            .button:hover {
                background-color: #5a67d8;
            }
            .button-secondary {
                display: inline-block;
                padding: 10px 24px;
                background-color: #e2e8f0;
                color: #4a5568 !important;
                text-decoration: none;
                border-radius: 6px;
                margin: 10px 5px;
            }
            .center {
                text-align: center;
            }
            .footer {
                text-align: center;
                margin-top: 30px;
                padding-top: 20px;
                border-top: 1px solid #e2e8f0;
                color: #718096;
                font-size: 14px;
            }
            .footer a {
                color: #667eea;
                text-decoration: none;
                margin: 0 10px;
            }
            .footer a:hover {
                text-decoration: underline;
            }
            .alert {
                background-color: #fef5e7;
                border-left: 4px solid #f39c12;
                padding: 15px;
                margin: 20px 0;
                border-radius: 4px;
            }
            .success {
                background-color: #d4edda;
                border-left: 4px solid #28a745;
                padding: 15px;
                margin: 20px 0;
                border-radius: 4px;
            }
            @media only screen and (max-width: 600px) {
                .container {
                    width: 100% !important;
                    padding: 10px;
                }
                .content {
                    padding: 20px;
                }
                .detail-row strong {
                    display: block;
                    margin-bottom: 5px;
                }
            }
        </style>';
    }
    
    public static function getHeader(string $title, string $emoji = ''): string
    {
        $emojiHtml = $emoji ? $emoji . ' ' : '';
        return '
        <div class="header">
            <h1>' . $emojiHtml . $title . '</h1>
        </div>';
    }
    
    public static function getFooter(string $companyName = 'Skedi', string $companyUrl = 'https://skedi.com'): string
    {
        return '
        <div class="footer">
            <p>Sent by ' . $companyName . '</p>
            <div style="margin-top: 15px;">
                <a href="' . $companyUrl . '/help">Help Center</a>
                <a href="' . $companyUrl . '/privacy">Privacy</a>
                <a href="' . $companyUrl . '/unsubscribe">Unsubscribe</a>
            </div>
            <p style="margin-top: 15px; font-size: 12px;">&copy; ' . date('Y') . ' ' . $companyName . '. All rights reserved.</p>
        </div>';
    }
}