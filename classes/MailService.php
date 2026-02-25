<?php

class MailService
{
    /**
     * Support / admin e-mail address of the organisation.
     * Adjust this value or load it from a config file as needed.
     */
    private string $supportEmail;

    public function __construct(string $supportEmail = 'support@example.com')
    {
        $this->supportEmail = $supportEmail;
    }

    /**
     * Sends a plain-text e-mail.
     *
     * @param string $to      Recipient address.
     * @param string $subject E-mail subject.
     * @param string $body    Plain-text message body.
     * @param string $from    Sender address shown in the e-mail headers.
     *
     * @return bool True on success, false on failure.
     */
    public function sendMail(string $to, string $subject, string $body, string $from = ''): bool
    {
        $headers = [];
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';

        if ($from !== '') {
            $headers[] = 'From: ' . $from;
        }

        return mail($to, $subject, $body, implode("\r\n", $headers));
    }

    /**
     * Returns the support e-mail address.
     */
    public function getSupportEmail(): string
    {
        return $this->supportEmail;
    }
}
