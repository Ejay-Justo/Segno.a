<?php
declare(strict_types=1);

function segnoGmailHttpStatus(array $headers): int
{
    $status = 0;
    foreach ($headers as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $matches)) {
            $status = (int)$matches[1];
        }
    }
    return $status;
}

function segnoSendGmailInquiry(
    string $recipient,
    string $fromEmail,
    string $replyTo,
    string $name,
    string $message
): bool {
    $clientId = getenv('SEGNO_GMAIL_CLIENT_ID');
    $clientSecret = getenv('SEGNO_GMAIL_CLIENT_SECRET');
    $refreshToken = getenv('SEGNO_GMAIL_REFRESH_TOKEN');
    if (
        !is_string($clientId) || $clientId === '' ||
        !is_string($clientSecret) || $clientSecret === '' ||
        !is_string($refreshToken) || $refreshToken === ''
    ) {
        error_log('Segno Gmail OAuth2 credentials are incomplete.');
        return false;
    }

    $tokenContext = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query([
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'refresh_token' => $refreshToken,
                'grant_type' => 'refresh_token',
            ]),
            'ignore_errors' => true,
            'timeout' => 20,
        ],
    ]);
    $tokenResponse = @file_get_contents('https://oauth2.googleapis.com/token', false, $tokenContext);
    if ($tokenResponse === false) {
        $error = error_get_last();
        error_log('Segno Gmail OAuth2 token request failed: ' . ($error['message'] ?? 'No response.'));
        return false;
    }

    $tokenStatus = segnoGmailHttpStatus($http_response_header ?? []);
    $tokenData = json_decode($tokenResponse, true);
    if (
        $tokenStatus !== 200 ||
        !is_array($tokenData) ||
        !isset($tokenData['access_token']) ||
        !is_string($tokenData['access_token'])
    ) {
        error_log('Segno Gmail OAuth2 token request returned HTTP ' . $tokenStatus . '.');
        return false;
    }

    $body = "New inquiry from Segno Editions\r\n\r\n"
        . "Name: {$name}\r\n"
        . "Email: {$replyTo}\r\n\r\n"
        . "Message:\r\n{$message}\r\n";
    $rawMessage = "From: Segno Editions <{$fromEmail}>\r\n"
        . "To: {$recipient}\r\n"
        . "Reply-To: {$replyTo}\r\n"
        . "Subject: New Segno Editions inquiry\r\n"
        . "MIME-Version: 1.0\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($body), 76, "\r\n");
    $raw = rtrim(strtr(base64_encode($rawMessage), '+/', '-_'), '=');

    $sendContext = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Authorization: Bearer {$tokenData['access_token']}\r\n"
                . "Content-Type: application/json\r\n",
            'content' => json_encode(['raw' => $raw], JSON_THROW_ON_ERROR),
            'ignore_errors' => true,
            'timeout' => 20,
        ],
    ]);
    $sendResponse = @file_get_contents(
        'https://gmail.googleapis.com/gmail/v1/users/me/messages/send',
        false,
        $sendContext
    );
    if ($sendResponse === false) {
        $error = error_get_last();
        error_log('Segno Gmail API request failed: ' . ($error['message'] ?? 'No response.'));
        return false;
    }

    $sendStatus = segnoGmailHttpStatus($http_response_header ?? []);
    if ($sendStatus !== 200) {
        error_log('Segno Gmail API returned HTTP ' . $sendStatus . '.');
        return false;
    }

    return true;
}
