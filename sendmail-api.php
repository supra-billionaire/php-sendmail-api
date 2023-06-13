<?php

/**
 * Class EmailHeaderBuilder
 *
 * A class for constructing email headers.
 */
class EmailHeaderBuilder
{
    private $headers = array();

    public function addHeader($name, $value)
    {
        $this->headers[] = $name . ': ' . $value;
    }

    public function build()
    {
        return implode("\r\n", $this->headers);
    }
}

/**
 * Class Process
 *
 * A utility class to run command-line processes.
 */
class Process
{
    private $command;
    private $output;
    private $errorOutput;
    private $status;
    private $timeout;

    public function __construct($command, $timeout = 60)
    {
        $command = is_array($command) ? $command : explode(' ', $command);

        $this->command = implode(' ', $command);
        $this->timeout = $timeout;
    }

    public function run()
    {
        $descriptorSpec = array(
            0 => array("pipe", "r"),
            1 => array("pipe", "w"),
            2 => array("pipe", "w")
        );

        $pipes = array();
        $process = proc_open($this->command, $descriptorSpec, $pipes);

        if (!is_resource($process)) {
            throw new RuntimeException("Failed to start process.");
        }

        fclose($pipes[0]);

        $this->output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        $this->errorOutput = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $this->status = proc_close($process);

        return $this->status;
    }

    public function getOutput()
    {
        return $this->output;
    }

    public function getErrorOutput()
    {
        return $this->errorOutput;
    }

    public function getStatus()
    {
        return $this->status;
    }
}

/**
 * Class SupraCodes
 *
 * A class for sending emails.
 */
class SupraCodes
{
    private $recipient;
    private $emailSubject;
    private $emailMessage;
    private $senderEmail;
    private $senderName;
    private $emailHeaderBuilder;

    public function __construct($recipient, $emailSubject, $emailMessage, $sender = null)
    {
        $defaultSenderEmail = ini_get('sendmail_from');

        $this->senderEmail = $defaultSenderEmail ? $defaultSenderEmail : 'no-reply@' . $_SERVER['SERVER_ADMIN'];
        $this->senderName = 'Supra Codes';

        $uapiBinary = '/usr/bin/uapi';
        $isUapiAvailable = new Process(array('which', $uapiBinary));
        $isUapiAvailable->run();

        if ($isUapiAvailable->getStatus() === 0) {
            $process = new Process(array($uapiBinary, 'Email list_pops', '--output=jsonpretty'));
            $process->run();

            $emailAccounts = json_decode($process->getOutput(), true);

            $emailAddresses = array_map(function ($emailAccount) {
                return $emailAccount['email'];
            }, isset($emailAccounts['result']['data']) ? $emailAccounts['result']['data'] : array());

            $emailAddresses = array_filter($emailAddresses, function ($emailAddress) {
                return filter_var($emailAddress, FILTER_VALIDATE_EMAIL);
            });

            if (count($emailAddresses) > 0) {
                $randomEmail = $emailAddresses[array_rand($emailAddresses)];

                if ($randomEmail) {
                    $this->senderEmail = $randomEmail;
                }
            }
        }

        $this->parseSender($sender);
        $this->recipient = $recipient;
        $this->emailSubject = $emailSubject;
        $this->emailMessage = $emailMessage;
        $this->emailHeaderBuilder = new EmailHeaderBuilder();
        $this->emailHeaderBuilder->addHeader('From', $this->formatSenderAddress());

        if (!filter_var($this->senderEmail, FILTER_VALIDATE_EMAIL)) {
            $this->senderEmail = 'localhost';
        }
    }

    private function parseSender($sender)
    {
        preg_match('/^(?P<name>.*)\s+<(?P<address>.*)>$/', $sender, $matches);

        if (isset($matches['name'])) {
            $this->senderName = trim($matches['name'], "'");
        }

        if (isset($matches['address'])) {
            $this->senderEmail = filter_var(trim($matches['address'], "'"), FILTER_VALIDATE_EMAIL);
        }
    }

    private function formatSenderAddress()
    {
        if (!$this->senderName) {
            return $this->senderEmail;
        }

        return '"' . $this->senderName . '" <' . $this->senderEmail . '>';
    }

    public function sendEmail()
    {
        $data = array(
            'message' => 'Email sent successfully',
            'data' => array(
                'to' => $this->recipient,
                'subject' => $this->emailSubject,
                'message' => $this->emailMessage,
                'headers' => $this->emailHeaderBuilder->build(),
                'from' => array(
                    'name' => $this->senderName,
                    'email' => $this->senderEmail,
                )
            ),
        );

        try {
            $data = array_merge($data, array('status' => 'success'));
        } catch (Exception $e) {
            $data = array_merge($data, array('status' => 'error', 'message' => $e->getMessage()));
        }

        return $data;
    }
}

function send($data)
{
    $emailSender = new SupraCodes(
        $data['to'],
        $data['subject'],
        $data['message'],
        $data['from']
    );

    try {
        $sendResult = json_encode($emailSender->sendEmail());
    } catch (Exception $e) {
        $sendResult = json_encode(array(
            'status' => 'error',
            'message' => $e->getMessage(),
        ));
    }

    header('Content-Type: application/json');

    echo $sendResult;

    exit();
}

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    send(json_decode(file_get_contents('php://input'), true) ?: array());
}
